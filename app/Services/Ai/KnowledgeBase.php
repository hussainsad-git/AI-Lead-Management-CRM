<?php

namespace App\Services\Ai;

use App\Exceptions\DomainRuleException;
use App\Jobs\IngestKbDocument;
use App\Models\AiInteraction;
use App\Models\KbChunk;
use App\Models\KbDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Knowledge base: documents → text → overlapping chunks → (optional) embeddings.
 * Retrieval is hybrid: cosine similarity when OpenAI embeddings exist, plus BM25-style lexical
 * scoring that works for any language/script, so it also runs with DeepSeek-only setups.
 */
class KnowledgeBase
{
    public const FILE_TYPES = ['txt', 'md', 'csv', 'pdf', 'docx', 'html', 'htm', 'json'];

    private const STOP = ['the', 'a', 'an', 'is', 'are', 'was', 'of', 'to', 'in', 'on', 'for', 'and', 'or', 'it', 'this', 'that', 'with', 'as', 'at', 'be',
        'by', 'i', 'you', 'we', 'me', 'my', 'your', 'do', 'does', 'can', 'what', 'how', 'please', 'hi', 'hello', 'ka', 'ki', 'ke', 'hai', 'hain', 'ko', 'se', 'me', 'mein', 'kya', 'aur'];

    public function __construct(private ProviderRegistry $providers, private DocumentTextExtractor $extractor) {}

    public function addText(string $title, string $text, string $type = 'text', ?string $language = null, ?int $interactionId = null): KbDocument
    {
        $text = trim($text);
        if (mb_strlen($text) < 5) {
            throw new DomainRuleException('Add some content first.', 'kb_empty');
        }
        $doc = KbDocument::create([
            'title' => mb_substr($title, 0, 191), 'source_type' => $type, 'language' => $language, 'status' => 'pending',
            'created_by' => auth()->id(), 'source_interaction_id' => $interactionId,
        ]);
        $path = 'kb/'.$doc->organization_id.'/'.Str::uuid().'.txt';
        Storage::disk('local')->put($path, $text);
        $doc->update(['stored_path' => $path]);
        IngestKbDocument::dispatch($doc->id, $doc->organization_id);

        return $doc->fresh();
    }

    public function addFile(UploadedFile $file, ?string $title = null, ?string $language = null): KbDocument
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, self::FILE_TYPES, true)) {
            throw new DomainRuleException('Supported files: '.implode(', ', self::FILE_TYPES).'.', 'kb_unsupported');
        }
        $doc = KbDocument::create([
            'title' => mb_substr($title ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 0, 191),
            'source_type' => 'file', 'original_filename' => mb_substr($file->getClientOriginalName(), 0, 191),
            'language' => $language, 'status' => 'pending', 'created_by' => auth()->id(),
        ]);
        $doc->update(['stored_path' => $file->storeAs('kb/'.$doc->organization_id, Str::uuid().'.'.$ext, 'local')]);
        IngestKbDocument::dispatch($doc->id, $doc->organization_id);

        return $doc->fresh();
    }

    /** Human-approved learning: a corrected/approved answer becomes a retrievable FAQ entry. */
    public function learn(string $question, string $answer, ?AiInteraction $from = null): KbDocument
    {
        return $this->addText('Learned: '.Str::limit(trim($question), 120), "Q: ".trim($question)."\nA: ".trim($answer), 'learned', null, $from?->id);
    }

    public function ingest(KbDocument $doc): void
    {
        $doc->update(['status' => 'processing', 'error' => null]);
        try {
            $abs = Storage::disk('local')->path($doc->stored_path);
            $ext = strtolower(pathinfo($doc->stored_path, PATHINFO_EXTENSION));
            $text = $this->extractor->extract($abs, $ext);
            if (mb_strlen(trim($text)) < 5) {
                throw new DomainRuleException('No readable text found (scanned PDFs need OCR first).', 'kb_no_text');
            }
            $chunks = in_array($doc->source_type, ['faq', 'learned'], true) ? [trim($text)] : $this->chunk($text);

            $vectors = [];
            $embedModel = null;
            if ($e = $this->providers->embedder()) {
                [$client, $embedModel] = $e;
                try {
                    foreach (array_chunk($chunks, 64) as $batch) {
                        array_push($vectors, ...$client->embed($batch, $embedModel));
                    }
                    $this->providers->track('openai');
                } catch (Throwable $ex) {
                    $this->providers->track('openai', $ex);
                    $vectors = [];
                    $embedModel = null; // lexical retrieval still works; re-index later
                }
            }

            DB::transaction(function () use ($doc, $chunks, $vectors, $embedModel, $text) {
                $doc->chunks()->delete();
                $now = now();
                $rows = [];
                foreach ($chunks as $i => $content) {
                    $rows[] = [
                        'organization_id' => $doc->organization_id, 'kb_document_id' => $doc->id, 'position' => $i,
                        'content' => $content, 'search_text' => implode(' ', self::tokens($doc->title.' '.$content)),
                        'embedding' => isset($vectors[$i]) ? KbChunk::packVector($vectors[$i]) : null,
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                }
                foreach (array_chunk($rows, 200) as $part) {
                    KbChunk::insert($part);
                }
                $doc->update(['status' => 'ready', 'chunk_count' => count($chunks), 'char_count' => mb_strlen($text), 'embedding_model' => $embedModel]);
            });
        } catch (Throwable $e) {
            if (! $e instanceof DomainRuleException) {
                report($e);
            }
            $doc->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
        }
    }

    /**
     * @return array<int, array{chunk: KbChunk, score: float, title: string}>
     */
    public function search(string $query, int $k = 0): array
    {
        $k = $k ?: config('ai.kb.top_k');
        $qTokens = array_values(array_unique(self::tokens($query)));
        $base = KbChunk::query()->whereHas('document', fn ($d) => $d->where('is_enabled', true)->where('status', 'ready'));

        // ----- lexical candidates (works for every script) -----
        $lexical = [];
        if ($qTokens) {
            $cands = (clone $base)->where(function ($w) use ($qTokens) {
                foreach (array_slice($qTokens, 0, 12) as $t) {
                    $w->orWhere('search_text', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $t).'%');
                }
            })->limit(400)->get(['id', 'kb_document_id', 'content', 'search_text']);
            $n = max((clone $base)->count(), 1);
            $df = [];
            foreach ($qTokens as $t) {
                $df[$t] = max(1, $cands->filter(fn ($c) => in_array($t, explode(' ', $c->search_text), true))->count());
            }
            foreach ($cands as $c) {
                $words = explode(' ', $c->search_text);
                $len = count($words);
                $tf = array_count_values($words);
                $s = 0.0;
                foreach ($qTokens as $t) {
                    if (! isset($tf[$t])) {
                        continue;
                    }
                    $idf = log(1 + ($n - $df[$t] + 0.5) / ($df[$t] + 0.5));
                    $s += $idf * ($tf[$t] * 2.2) / ($tf[$t] + 1.2 * (0.25 + 0.75 * $len / 120));
                }
                if ($s > 0) {
                    $lexical[$c->id] = $s;
                }
            }
        }

        // ----- semantic candidates -----
        $semantic = [];
        if ($e = $this->providers->embedder()) {
            [$client, $model] = $e;
            try {
                $qv = $client->embed([$query], $model)[0] ?? null;
                if ($qv) {
                    (clone $base)->whereNotNull('embedding')->select(['id', 'embedding'])->limit(config('ai.kb.max_vector_scan'))
                        ->get()->each(function (KbChunk $c) use ($qv, &$semantic) {
                            $v = $c->vector();
                            if ($v && count($v) === count($qv)) {
                                $semantic[$c->id] = self::cosine($qv, $v);
                            }
                        });
                }
            } catch (Throwable $ex) {
                $this->providers->track('openai', $ex);
            }
        }

        // ----- fuse (reciprocal rank fusion keeps the two scales comparable) -----
        $fused = [];
        foreach (['lexical' => $lexical, 'semantic' => $semantic] as $kind => $list) {
            arsort($list);
            $rank = 0;
            foreach ($list as $id => $score) {
                if ($kind === 'semantic' && $score < 0.25) {
                    break; // clearly unrelated
                }
                $fused[$id] = ($fused[$id] ?? 0) + 1 / (60 + ++$rank);
            }
        }
        arsort($fused);
        $ids = array_slice(array_keys($fused), 0, $k);
        if (! $ids) {
            return [];
        }
        KbChunk::whereIn('id', $ids)->increment('times_retrieved');
        $chunks = KbChunk::with('document:id,title')->whereIn('id', $ids)->get()->keyBy('id');

        return array_values(array_filter(array_map(fn ($id) => isset($chunks[$id]) ? [
            'chunk' => $chunks[$id], 'title' => $chunks[$id]->document->title,
            'score' => round($fused[$id], 5), 'lexical' => round($lexical[$id] ?? 0, 3), 'semantic' => isset($semantic[$id]) ? round($semantic[$id], 3) : null,
        ] : null, $ids)));
    }

    /** Paragraph-aware chunking with overlap. */
    public function chunk(string $text): array
    {
        $size = config('ai.kb.chunk_chars');
        $overlap = config('ai.kb.chunk_overlap');
        $text = preg_replace("/[ \t]+/u", ' ', str_replace("\r", '', $text));
        $paras = preg_split("/\n{2,}/u", $text);
        $chunks = [];
        $buf = '';
        foreach ($paras as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            while (mb_strlen($p) > $size) { // very long paragraph: split on sentence boundary
                $cut = mb_strrpos(mb_substr($p, 0, $size), '. ') ?: $size;
                $piece = mb_substr($p, 0, $cut + 1);
                if ($buf !== '') {
                    $chunks[] = trim($buf);
                    $buf = '';
                }
                $chunks[] = trim($piece);
                $p = mb_substr($p, max($cut + 1 - $overlap, 1));
            }
            if (mb_strlen($buf) + mb_strlen($p) + 2 > $size && $buf !== '') {
                $chunks[] = trim($buf);
                $buf = mb_substr($buf, -$overlap).' ';
            }
            $buf .= $p."\n\n";
        }
        if (trim($buf) !== '') {
            $chunks[] = trim($buf);
        }

        return array_values(array_filter($chunks, fn ($c) => mb_strlen($c) > 3));
    }

    /** Unicode-aware tokens: lowercased letters/numbers, light English stemming, stopwords removed. */
    public static function tokens(string $s): array
    {
        $s = mb_strtolower($s);
        preg_match_all('/[\p{L}\p{N}]+/u', $s, $m);
        $out = [];
        foreach ($m[0] as $t) {
            if (in_array($t, self::STOP, true) || (mb_strlen($t) < 2 && ! ctype_digit($t))) {
                continue;
            }
            if (preg_match('/^[a-z]{5,}$/', $t)) {
                $t = preg_replace('/(ies|es|s|ing|ed)$/', '', $t);
            }
            $out[] = $t;
        }

        return $out;
    }

    public static function cosine(array $a, array $b): float
    {
        $dot = $na = $nb = 0.0;
        foreach ($a as $i => $x) {
            $y = $b[$i];
            $dot += $x * $y;
            $na += $x * $x;
            $nb += $y * $y;
        }

        return ($na && $nb) ? $dot / (sqrt($na) * sqrt($nb)) : 0.0;
    }
}
