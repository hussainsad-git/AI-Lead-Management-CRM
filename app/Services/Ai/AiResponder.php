<?php

namespace App\Services\Ai;

use App\Models\AiAssistant;
use App\Models\AiInteraction;
use App\Models\Conversation;
use App\Support\Tenancy\CurrentOrganization;
use Throwable;

/**
 * Generates a grounded reply for a WhatsApp conversation (or a playground question).
 * The model must answer only from retrieved knowledge and returns a JSON envelope so the
 * caller can decide between replying and handing off to a human.
 */
class AiResponder
{
    public function __construct(
        private ProviderRegistry $providers,
        private KnowledgeBase $kb,
        private AssistantConfig $assistants,
        private CurrentOrganization $org,
    ) {}

    /**
     * @return array{ok: bool, reply: ?string, needs_human: bool, off_topic: bool, answerable: bool, language: ?string,
     *               handoff_reason: ?string, sources: array, interaction: AiInteraction, error: ?string}
     */
    public function respond(?Conversation $conversation, string $kind = 'reply', ?string $question = null, array $history = []): array
    {
        $assistant = $this->assistants->get();
        $client = $this->providers->forAssistant($assistant);
        $model = $this->providers->modelFor($assistant);

        if ($conversation) {
            $msgs = $conversation->messages()->whereIn('type', ['text', 'interactive', 'button', 'image', 'document', 'location', 'template'])
                ->latest('occurred_at')->latest('id')->limit($assistant->history_messages)->get()->reverse()->values();
            $history = $msgs->map(fn ($m) => [
                'role' => $m->direction === 'in' ? 'user' : 'assistant',
                'content' => (string) ($m->body ?? '['.$m->type.']'),
            ])->all();
            $question = (string) $msgs->where('direction', 'in')->last()?->body;
        }
        $question = trim((string) $question);

        $inter = new AiInteraction([
            'conversation_id' => $conversation?->id, 'lead_id' => $conversation?->lead_id, 'user_id' => auth()->id(),
            'kind' => $kind, 'provider' => $assistant->provider, 'model' => $model, 'input' => mb_substr($question, 0, 4000),
        ]);

        if (! $client || ! $model) {
            $inter->fill(['status' => 'skipped', 'error' => ! $client ? 'AI provider not connected' : 'No model selected'])->save();

            return $this->result(false, $inter, error: $inter->error);
        }
        if ($question === '') {
            $inter->fill(['status' => 'skipped', 'error' => 'No customer text to answer'])->save();

            return $this->result(false, $inter, error: $inter->error);
        }

        // Retrieval uses the latest question plus the previous customer turn for follow-ups ("and the price?").
        $prevUser = collect($history)->where('role', 'user')->slice(-2, 1)->pluck('content')->implode(' ');
        $hits = $this->kb->search(trim($question.' '.$prevUser));
        $inter->retrieved_chunk_ids = array_map(fn ($h) => $h['chunk']->id, $hits);

        $messages = [['role' => 'system', 'content' => $this->systemPrompt($assistant, $hits, $conversation)]];
        foreach (array_slice($history, -$assistant->history_messages) as $h) {
            $messages[] = ['role' => $h['role'], 'content' => mb_substr($h['content'], 0, 2000)];
        }
        if (! $conversation) {
            $messages[] = ['role' => 'user', 'content' => mb_substr($question, 0, 2000)];
        }

        try {
            $r = $client->chat($messages, $model, (float) $assistant->temperature, max(200, (int) $assistant->max_tokens), json: true);
            $this->providers->track($assistant->provider);
        } catch (Throwable $e) {
            $this->providers->track($assistant->provider, $e);
            $inter->fill(['status' => 'error', 'error' => mb_substr($e->getMessage(), 0, 500)])->save();

            return $this->result(false, $inter, sources: $hits, error: $inter->error);
        }

        $parsed = self::parseJson($r['content']);
        $inter->fill(['prompt_tokens' => $r['prompt_tokens'], 'completion_tokens' => $r['completion_tokens'], 'latency_ms' => $r['latency_ms'], 'model' => $r['model']]);
        if (! $parsed || ! isset($parsed['reply'])) {
            // Not the agreed envelope: never send it blindly.
            $inter->fill(['status' => 'error', 'error' => 'Unparseable AI response', 'response' => mb_substr($r['content'], 0, 4000), 'outcome' => 'handoff'])->save();

            return $this->result(false, $inter, needsHuman: true, sources: $hits, error: 'The AI response was not in the expected format.');
        }

        $reply = trim((string) $parsed['reply']);
        $needsHuman = (bool) ($parsed['needs_human'] ?? false);
        $offTopic = (bool) ($parsed['off_topic'] ?? false);
        $answerable = (bool) ($parsed['answerable'] ?? ! $needsHuman);
        if ($assistant->kb_only && ! $hits && ! $answerable) {
            $needsHuman = true;
        }
        if ($reply === '') {
            $needsHuman = true;
        }

        $inter->fill([
            'status' => 'ok', 'response' => mb_substr($reply, 0, 4000),
            'outcome' => $offTopic ? 'off_topic' : ($needsHuman ? 'handoff' : 'answered'),
        ])->save();

        return $this->result(true, $inter, $reply ?: null, $needsHuman, $offTopic, $answerable, $parsed['language'] ?? null,
            isset($parsed['handoff_reason']) ? mb_substr((string) $parsed['handoff_reason'], 0, 255) : null, $hits);
    }

    public function systemPrompt(AiAssistant $a, array $hits, ?Conversation $c): string
    {
        $org = $this->org->require();
        $langs = collect($a->languages ?: [$a->default_language])->map(fn ($l) => config("ai.languages.$l", $l))->implode(', ');
        $knowledge = $hits
            ? collect($hits)->map(fn ($h, $i) => '['.($i + 1).'] ('.$h['title'].")\n".$h['chunk']->content)->implode("\n\n")
            : '(no matching knowledge found)';

        $known = [];
        $missing = [];
        if ($c?->lead) {
            $lead = $c->lead->loadMissing('contact');
            $map = [
                'name' => $lead->contact->name, 'email' => $lead->contact->email, 'city' => $lead->contact->city ?: $lead->location,
                'product_interest' => $lead->product_interest, 'budget' => $lead->budget_amount,
            ];
            foreach ((array) $a->qualification_fields as $f) {
                $v = $map[$f] ?? null;
                $v ? $known[] = config("ai.qualification_fields.$f", $f).': '.$v : $missing[] = config("ai.qualification_fields.$f", $f);
            }
        }

        $now = now($org->timezone)->format('l j F Y, H:i');

        return trim(<<<PROMPT
{$a->persona}

Business: {$org->name}. Current local time: {$now} ({$org->timezone}).

## Business instructions
{$a->instructions}

## Rules (always follow)
- You represent {$org->name} only. Politely decline unrelated requests (general knowledge, coding, homework, other companies) and set "off_topic": true.
- Use ONLY facts from the KNOWLEDGE section for prices, availability, policies, dates, addresses and phone numbers. If the answer is not there, do not guess: say a team member will confirm, and set "answerable": false and "needs_human": true.
- Reply in the customer's language and script (e.g. Urdu script, Roman Urdu, Arabic, English). Allowed languages: {$langs}. If unsure, use {$a->default_language}.
- WhatsApp style: short (max ~80 words), friendly, no markdown headings, at most one question per message.
- Never ask for passwords, card numbers, CNIC/ID numbers or medical/financial history.
- If the customer asks for a human, complains, is angry, or the matter needs a person, set "needs_human": true.
- Do not claim to be human. If asked, say you are {$org->name}'s virtual assistant.
PROMPT
            .($missing ? "\n- When natural, collect what is still missing (one item at a time): ".implode(', ', $missing).'.' : '')
            .($known ? "\n\n## Already known about this customer\n".implode("\n", $known) : '')
            ."\n\n## KNOWLEDGE\n".$knowledge
            ."\n\n## Output\nRespond with a single JSON object only:\n"
            .'{"reply": "message to send", "language": "ISO code or roman_ur", "answerable": true|false, "needs_human": true|false, "handoff_reason": "short reason or null", "off_topic": true|false}');
    }

    public static function parseJson(string $s): ?array
    {
        $s = trim($s);
        $s = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $s);
        $d = json_decode($s, true);
        if (! is_array($d) && preg_match('/\{.*\}/s', $s, $m)) {
            $d = json_decode($m[0], true);
        }

        return is_array($d) ? $d : null;
    }

    private function result(bool $ok, AiInteraction $i, ?string $reply = null, bool $needsHuman = false, bool $offTopic = false,
        bool $answerable = false, ?string $language = null, ?string $reason = null, array $sources = [], ?string $error = null): array
    {
        return [
            'ok' => $ok, 'reply' => $reply, 'needs_human' => $needsHuman, 'off_topic' => $offTopic, 'answerable' => $answerable,
            'language' => $language, 'handoff_reason' => $reason, 'interaction' => $i, 'error' => $error,
            'sources' => array_map(fn ($h) => ['id' => $h['chunk']->id, 'title' => $h['title'], 'excerpt' => mb_substr($h['chunk']->content, 0, 220), 'score' => $h['score']], $sources),
        ];
    }
}
