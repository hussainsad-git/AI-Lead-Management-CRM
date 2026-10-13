<?php

namespace App\Http\Controllers\Api\V1;

use App\Jobs\IngestKbDocument;
use App\Models\KbDocument;
use App\Services\Ai\KnowledgeBase;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class KnowledgeBaseController extends ApiController
{
    public function __construct(private KnowledgeBase $kb) {}

    private function guard(bool $write = false): void
    {
        abort_unless($this->user()->hasAnyPermission(...($write ? ['kb.manage'] : ['kb.manage', 'ai.manage'])), 403);
    }

    public function index(Request $request)
    {
        $this->guard();
        $q = KbDocument::with('creator:id,name')->latest('id');
        if ($t = $request->input('type')) {
            $q->where('source_type', $t);
        }
        if ($s = trim((string) $request->input('q'))) {
            $q->whereRaw('LOWER(title) LIKE ?', ['%'.mb_strtolower($s).'%']);
        }
        $page = $q->paginate($this->perPage());

        return response()->json([
            'data' => $page->getCollection()->map(fn ($d) => $this->present($d)),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage(),
                'stats' => ['documents' => KbDocument::count(), 'ready' => KbDocument::where('status', 'ready')->count(),
                    'chunks' => (int) KbDocument::sum('chunk_count'), 'embedded' => KbDocument::whereNotNull('embedding_model')->count(),
                    'failed' => KbDocument::where('status', 'failed')->count()]],
        ]);
    }

    public function store(Request $request, AuditLogger $audit)
    {
        $this->guard(true);
        if ($request->hasFile('file')) {
            $request->validate(['file' => ['file', 'max:'.config('ai.kb.max_file_kb')], 'title' => ['nullable', 'string', 'max:191'], 'language' => ['nullable', 'string', 'max:12']]);
            $doc = $this->kb->addFile($request->file('file'), $request->input('title'), $request->input('language'));
        } else {
            $data = $request->validate([
                'type' => ['required', 'in:text,faq'], 'title' => ['required_if:type,text', 'nullable', 'string', 'max:191'],
                'content' => ['required_if:type,text', 'nullable', 'string', 'max:200000'],
                'question' => ['required_if:type,faq', 'nullable', 'string', 'max:1000'], 'answer' => ['required_if:type,faq', 'nullable', 'string', 'max:5000'],
                'language' => ['nullable', 'string', 'max:12'],
            ]);
            $doc = $data['type'] === 'faq'
                ? $this->kb->addText('FAQ: '.mb_substr($data['question'], 0, 150), "Q: {$data['question']}\nA: {$data['answer']}", 'faq', $data['language'] ?? null)
                : $this->kb->addText($data['title'], $data['content'], 'text', $data['language'] ?? null);
        }
        $audit->log('kb.document_added', $doc, [], ['title' => $doc->title, 'type' => $doc->source_type]);

        return $this->ok($this->present($doc->fresh()), [], 201);
    }

    public function show(KbDocument $document)
    {
        $this->guard();

        return $this->ok($this->present($document) + ['chunks' => $document->chunks()->limit(200)->get(['id', 'position', 'content', 'times_retrieved'])]);
    }

    public function update(Request $request, KbDocument $document)
    {
        $this->guard(true);
        $document->update($request->validate(['title' => ['sometimes', 'string', 'max:191'], 'is_enabled' => ['sometimes', 'boolean']]));

        return $this->ok($this->present($document));
    }

    public function reindex(KbDocument $document)
    {
        $this->guard(true);
        $document->update(['status' => 'pending']);
        IngestKbDocument::dispatch($document->id, $document->organization_id);

        return $this->ok($this->present($document->fresh()));
    }

    public function destroy(KbDocument $document, AuditLogger $audit)
    {
        $this->guard(true);
        $audit->log('kb.document_deleted', $document, [], ['title' => $document->title]);
        if ($document->stored_path) {
            Storage::disk('local')->delete($document->stored_path);
        }
        $document->delete();

        return $this->ok(['deleted' => true]);
    }

    public function search(Request $request)
    {
        $this->guard();
        $q = $request->validate(['q' => ['required', 'string', 'max:500']])['q'];

        return $this->ok(array_map(fn ($h) => ['id' => $h['chunk']->id, 'document_id' => $h['chunk']->kb_document_id, 'title' => $h['title'],
            'content' => $h['chunk']->content, 'score' => $h['score'], 'lexical' => $h['lexical'], 'semantic' => $h['semantic']], $this->kb->search($q, 8)));
    }

    private function present(KbDocument $d): array
    {
        return ['id' => $d->id, 'title' => $d->title, 'type' => $d->source_type, 'file' => $d->original_filename, 'language' => $d->language,
            'status' => $d->status, 'error' => $d->error, 'chunks' => $d->chunk_count, 'chars' => $d->char_count, 'enabled' => $d->is_enabled,
            'embedded' => (bool) $d->embedding_model, 'by' => $d->creator?->name, 'created_at' => $d->created_at?->toIso8601ZuluString()];
    }
}
