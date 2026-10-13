<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AiInteraction;
use App\Models\AutoReplyRule;
use App\Models\KbDocument;
use App\Services\Ai\AiResponder;
use App\Services\Ai\AssistantConfig;
use App\Services\Ai\ProviderRegistry;
use App\Services\AuditLogger;
use App\Services\BusinessHours;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AiAssistantController extends ApiController
{
    public function __construct(private AssistantConfig $assistants, private ProviderRegistry $providers) {}

    private function guard(): void
    {
        abort_unless($this->user()->hasPermission('ai.manage'), 403);
    }

    public function show(BusinessHours $hours)
    {
        $this->guard();
        $a = $this->assistants->get();
        $providers = collect(config('ai.providers'))->map(fn ($p, $k) => [
            'key' => $k, 'label' => $p['label'], 'connected' => (bool) $this->providers->client($k),
            'status' => $this->providers->integration($k)?->status ?? 'not_configured', 'embeddings' => $p['embeddings'],
            'default_model' => $this->providers->integration($k)?->cfg('default_model') ?: $p['default_model'],
        ])->values();

        return $this->ok([
            'assistant' => $a,
            'effective_model' => $this->providers->modelFor($a),
            'providers' => $providers,
            'industries' => collect(config('ai.industries'))->map(fn ($v, $k) => ['key' => $k, 'label' => $v['label']])->values(),
            'languages' => config('ai.languages'),
            'fields' => config('ai.qualification_fields'),
            'rules' => AutoReplyRule::orderBy('priority')->orderBy('id')->get(),
            'business_open_now' => $hours->isOpen(),
            'kb' => ['documents' => KbDocument::where('status', 'ready')->where('is_enabled', true)->count()],
        ]);
    }

    public function update(Request $request, AuditLogger $audit)
    {
        $this->guard();
        $a = $this->assistants->get();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'provider' => ['required', Rule::in(array_keys(config('ai.providers')))],
            'model' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9._:\-\/]+$/'],
            'temperature' => ['required', 'numeric', 'min:0', 'max:1.5'],
            'max_tokens' => ['required', 'integer', 'min:100', 'max:2000'],
            'persona' => ['required', 'string', 'max:3000'],
            'instructions' => ['nullable', 'string', 'max:8000'],
            'greeting' => ['nullable', 'string', 'max:1000'],
            'fallback_message' => ['nullable', 'string', 'max:1000'],
            'handoff_message' => ['nullable', 'string', 'max:1000'],
            'mode' => ['required', 'in:off,suggest,auto'],
            'active_hours' => ['required', 'in:always,outside_hours,in_hours'],
            'kb_only' => ['required', 'boolean'],
            'auto_qualify' => ['required', 'boolean'],
            'languages' => ['required', 'array', 'min:1'], 'languages.*' => [Rule::in(array_keys(config('ai.languages')))],
            'default_language' => ['required', Rule::in(array_keys(config('ai.languages')))],
            'handoff_keywords' => ['nullable', 'array', 'max:40'], 'handoff_keywords.*' => ['string', 'max:40'],
            'qualification_fields' => ['nullable', 'array'], 'qualification_fields.*' => [Rule::in(array_keys(config('ai.qualification_fields')))],
            'max_auto_replies' => ['required', 'integer', 'min:1', 'max:50'],
            'history_messages' => ['required', 'integer', 'min:2', 'max:30'],
        ]);
        $before = $a->only(['provider', 'model', 'mode', 'kb_only']);
        $a->update($data);
        $audit->log('ai.assistant_updated', $a, ['before' => $before, 'after' => $a->only(['provider', 'model', 'mode', 'kb_only'])]);

        return $this->ok($a->fresh());
    }

    public function preset(Request $request)
    {
        $this->guard();
        $data = $request->validate(['industry' => ['required', Rule::in(array_keys(config('ai.industries')))]]);

        return $this->ok($this->assistants->applyPreset($this->assistants->get(), $data['industry']));
    }

    public function models(Request $request)
    {
        $this->guard();
        $p = $request->validate(['provider' => ['required', Rule::in(array_keys(config('ai.providers')))]])['provider'];
        $client = $this->providers->client($p);
        if (! $client) {
            throw new \App\Exceptions\DomainRuleException('Connect '.config("ai.providers.$p.label").' under Integrations first.', 'provider_not_connected', 409);
        }
        try {
            $models = $client->models();
            $this->providers->track($p);
        } catch (\App\Services\Ai\AiException $e) {
            $this->providers->track($p, $e);
            throw new \App\Exceptions\DomainRuleException($e->getMessage(), 'provider_error', 502);
        }
        // Chat-capable models only (embedding/audio/image models are hidden)
        $chat = array_values(array_filter($models, fn ($m) => ! preg_match('/embed|whisper|tts|dall|image|audio|transcribe|moderation|realtime|search|sora/i', $m)));

        return $this->ok($chat);
    }

    public function playground(Request $request, AiResponder $responder)
    {
        $this->guard();
        $data = $request->validate(['question' => ['required', 'string', 'max:2000'], 'history' => ['nullable', 'array', 'max:20'],
            'history.*.role' => ['required', 'in:user,assistant'], 'history.*.content' => ['required', 'string', 'max:2000']]);
        $r = $responder->respond(null, 'playground', $data['question'], array_merge($data['history'] ?? [], [['role' => 'user', 'content' => $data['question']]]));

        return $this->ok(['ok' => $r['ok'], 'reply' => $r['reply'], 'needs_human' => $r['needs_human'], 'off_topic' => $r['off_topic'],
            'answerable' => $r['answerable'], 'language' => $r['language'], 'handoff_reason' => $r['handoff_reason'], 'sources' => $r['sources'],
            'error' => $r['error'], 'interaction_id' => $r['interaction']->id, 'latency_ms' => $r['interaction']->latency_ms,
            'tokens' => ($r['interaction']->prompt_tokens ?? 0) + ($r['interaction']->completion_tokens ?? 0)]);
    }

    /** Continuous learning queue: questions the AI couldn't answer, and answers agents corrected or rejected. */
    public function learning(Request $request)
    {
        $this->guard();
        $taught = KbDocument::whereNotNull('source_interaction_id')->pluck('source_interaction_id');
        $q = AiInteraction::with('conversation.contact:id,name,whatsapp_e164')->whereIn('kind', ['reply', 'suggest', 'playground'])
            ->where(fn ($w) => $w->where('outcome', 'handoff')->orWhereIn('feedback', ['down', 'corrected']))
            ->where(fn ($w) => $w->whereNull('feedback')->orWhere('feedback', '!=', 'dismissed'))
            ->whereNotIn('id', $taught)->latest('id');
        $page = $q->paginate($this->perPage());

        return response()->json([
            'data' => $page->getCollection()->map(fn ($i) => [
                'id' => $i->id, 'question' => $i->input, 'ai_answer' => $i->response, 'corrected' => $i->corrected_text, 'feedback' => $i->feedback,
                'outcome' => $i->outcome, 'conversation_id' => $i->conversation_id, 'contact' => $i->conversation?->contact?->displayName(),
                'at' => $i->created_at->toIso8601ZuluString(),
            ]),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function dismissLearning(AiInteraction $interaction)
    {
        $this->guard();
        $interaction->forceFill(['feedback' => 'dismissed', 'feedback_by' => $this->user()->id, 'feedback_at' => now()])->save();

        return $this->ok(['dismissed' => true]);
    }

    // ----- auto-reply rules -----
    public function storeRule(Request $request)
    {
        $this->guard();

        return $this->ok(AutoReplyRule::create($this->ruleData($request)), [], 201);
    }

    public function updateRule(Request $request, AutoReplyRule $rule)
    {
        $this->guard();
        $rule->update($this->ruleData($request, true));

        return $this->ok($rule);
    }

    public function destroyRule(AutoReplyRule $rule)
    {
        $this->guard();
        $rule->delete();

        return $this->ok(['deleted' => true]);
    }

    private function ruleData(Request $request, bool $partial = false): array
    {
        $s = $partial ? 'sometimes' : 'required';
        $d = $request->validate([
            'name' => [$s, 'string', 'max:120'], 'trigger' => [$s, 'in:keyword,first_message,outside_hours'],
            'match_type' => ['sometimes', 'in:contains,exact,regex'], 'keywords' => ['nullable', 'array', 'max:50'], 'keywords.*' => ['string', 'max:100'],
            'response' => [$s, 'string', 'max:4096'], 'priority' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'cooldown_minutes' => ['sometimes', 'integer', 'min:1', 'max:43200'], 'stop_ai' => ['sometimes', 'boolean'], 'is_active' => ['sometimes', 'boolean'],
        ]);
        if (($d['trigger'] ?? null) === 'keyword' && empty($d['keywords'])) {
            abort(422, 'Keyword rules need at least one keyword.');
        }
        if (($d['match_type'] ?? null) === 'regex') {
            foreach ($d['keywords'] ?? [] as $k) {
                abort_if(@preg_match('/'.str_replace('/', '\/', $k).'/iu', '') === false, 422, 'Invalid pattern: '.$k);
            }
        }

        return $d;
    }
}
