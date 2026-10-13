<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DomainRuleException;
use App\Models\AiInteraction;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Ai\AiResponder;
use App\Services\Ai\KnowledgeBase;
use App\Services\AuditLogger;
use App\Services\ConversationAutomation;
use App\Services\LeadVisibility;
use App\Services\WhatsApp\CloudApiClient;
use App\Services\WhatsApp\MessagingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Throwable;

class ConversationController extends ApiController
{
    public function __construct(private LeadVisibility $visibility) {}

    /** Same visibility as leads: reps see their own conversations, managers their team's, admins all. */
    private function scoped(): Builder
    {
        abort_unless($this->user()->hasPermission('whatsapp.view'), 403);
        $q = Conversation::query();
        $ids = $this->visibility->assigneeScope($this->user());
        if ($ids !== null) {
            $q->where(fn ($w) => $ids ? $w->whereIn('conversations.assigned_user_id', $ids)->orWhereHas('lead', fn ($l) => $l->whereIn('assigned_user_id', $ids)) : $w->whereRaw('1 = 0'));
        }

        return $q;
    }

    private function conv(Conversation $c): Conversation
    {
        abort_unless($this->scoped()->whereKey($c->id)->exists(), 404);

        return $c;
    }

    public function index(Request $request)
    {
        $q = $this->scoped()->with(['contact:id,name,company_name,whatsapp_e164,phone_e164,do_not_contact', 'assignee:id,name', 'lead:id,score_label,score,lead_status_id', 'lead.status:id,name,color']);
        match ($request->input('view', 'all')) {
            'mine' => $q->where('assigned_user_id', $this->user()->id),
            'unassigned' => $q->whereNull('assigned_user_id'),
            'handoff' => $q->whereNotNull('handoff_at')->where('status', '!=', 'closed'),
            'unread' => $q->where('unread_count', '>', 0),
            'closed' => $q->where('status', 'closed'),
            default => $q->where('status', '!=', 'closed'),
        };
        if ($s = trim((string) $request->input('q'))) {
            $like = '%'.mb_strtolower($s).'%';
            $digits = ltrim(preg_replace('/\D/', '', $s), '0');
            $q->where(fn ($w) => $w->whereHas('contact', fn ($c) => $c->whereRaw('LOWER(name) LIKE ?', [$like]))
                ->orWhereRaw('LOWER(last_message_preview) LIKE ?', [$like])
                ->when(strlen($digits) >= 4, fn ($x) => $x->orWhere('external_id', 'like', "%$digits%")));
        }
        $page = $q->orderByDesc('last_message_at')->orderByDesc('id')->paginate($this->perPage());
        $base = $this->scoped();

        return response()->json([
            'data' => $page->getCollection()->map(fn ($c) => $this->summary($c)),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage(),
                'counts' => [
                    'unread' => (clone $base)->where('unread_count', '>', 0)->count(),
                    'handoff' => (clone $base)->whereNotNull('handoff_at')->where('status', '!=', 'closed')->count(),
                    'mine' => (clone $base)->where('assigned_user_id', $this->user()->id)->where('status', '!=', 'closed')->count(),
                    'unassigned' => (clone $base)->whereNull('assigned_user_id')->where('status', '!=', 'closed')->count(),
                ]],
        ]);
    }

    public function show(Conversation $conversation, MessagingService $messaging)
    {
        $c = $this->conv($conversation)->load(['contact', 'assignee:id,name', 'lead.status', 'lead.source', 'lead.firstTouch']);
        $lastOut = $c->messages()->where('direction', 'out')->max('occurred_at');
        $suggestion = AiInteraction::where('conversation_id', $c->id)->where('kind', 'suggest')->where('status', 'ok')
            ->when($lastOut, fn ($q) => $q->where('created_at', '>', $lastOut))->latest('id')->first();

        return $this->ok([
            'conversation' => $this->summary($c) + [
                'window_open' => $c->windowOpen(), 'window_closes_at' => $c->windowClosesAt()?->toIso8601ZuluString(),
                'handoff_reason' => $c->handoff_reason, 'ai_reply_count' => $c->ai_reply_count,
            ],
            'contact' => [
                'id' => $c->contact->id, 'name' => $c->contact->displayName(), 'phone' => $c->contact->phone_e164, 'whatsapp' => $c->contact->whatsapp_e164,
                'email' => $c->contact->email, 'city' => $c->contact->city, 'whatsapp_opted_out' => $c->contact->isSuppressed('whatsapp'),
            ],
            'lead' => $c->lead ? [
                'id' => $c->lead->id, 'status' => ['name' => $c->lead->status->name, 'color' => $c->lead->status->color], 'source' => $c->lead->source?->name,
                'product_interest' => $c->lead->product_interest, 'budget' => $c->lead->budget_amount ? $c->lead->budget_currency.' '.$c->lead->budget_amount : null,
                'ai_summary' => $c->lead->ai_summary, 'score' => $c->lead->score, 'score_label' => $c->lead->score_label, 'score_factors' => $c->lead->score_factors,
                'campaign' => $c->lead->firstTouch?->campaign_name_snapshot ?? $c->lead->firstTouch?->ad_name_snapshot,
                'visible' => $this->visibility->canSee($this->user(), $c->lead),
            ] : null,
            'messages' => $this->messages($c, null),
            'suggestion' => $suggestion ? ['interaction_id' => $suggestion->id, 'reply' => $suggestion->response, 'outcome' => $suggestion->outcome] : null,
            'templates' => MessageTemplate::where('status', 'APPROVED')->orderBy('name')->get(['id', 'name', 'language', 'category', 'body_params', 'components'])
                ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'language' => $t->language, 'category' => $t->category, 'params' => $t->body_params,
                    'preview' => $messaging->renderTemplate($t, [])]),
        ]);
    }

    public function messages(Conversation $c, ?int $afterId): array
    {
        $q = $c->messages()->with('user:id,name', 'interaction:id,feedback,outcome');
        $rows = $afterId ? $q->where('id', '>', $afterId)->orderBy('id')->get() : $q->latest('id')->limit(80)->get()->reverse()->values();

        return $rows->map(fn (Message $m) => [
            'id' => $m->id, 'direction' => $m->direction, 'sender_type' => $m->sender_type, 'user' => $m->user?->name, 'type' => $m->type,
            'body' => $m->body, 'template' => $m->template_name, 'status' => $m->status, 'error' => $m->error,
            'at' => $m->occurred_at->toIso8601ZuluString(), 'interaction_id' => $m->ai_interaction_id,
            'feedback' => $m->interaction?->feedback, 'referral' => $m->meta['referral']['headline'] ?? null,
        ])->all();
    }

    public function poll(Request $request, Conversation $conversation)
    {
        $c = $this->conv($conversation);

        return $this->ok(['messages' => $this->messages($c, (int) $request->input('after_id', 0)),
            'statuses' => $c->messages()->where('direction', 'out')->latest('id')->limit(30)->get(['id', 'status', 'error'])]);
    }

    public function send(Request $request, Conversation $conversation, MessagingService $messaging)
    {
        abort_unless($this->user()->hasPermission('whatsapp.send'), 403);
        $c = $this->conv($conversation)->load('contact', 'lead');
        $data = $request->validate([
            'body' => ['required_without:template_id', 'nullable', 'string', 'max:4096'],
            'template_id' => ['nullable', 'integer'],
            'params' => ['nullable', 'array'], 'params.*' => ['string', 'max:500'],
            'interaction_id' => ['nullable', 'integer'],
        ]);
        if (! empty($data['template_id'])) {
            $msg = $messaging->sendTemplate($c, MessageTemplate::findOrFail($data['template_id']), $data['params'] ?? [], $this->user());
        } else {
            $interaction = ! empty($data['interaction_id']) ? AiInteraction::where('conversation_id', $c->id)->find($data['interaction_id']) : null;
            $msg = $messaging->sendText($c, $data['body'], 'user', $this->user(), $interaction);
            if ($interaction && ! $interaction->feedback) {
                // Sending an AI suggestion as-is = approval; an edited one = correction (feeds the learning queue).
                $same = trim((string) $interaction->response) === trim($data['body']);
                $interaction->forceFill(['feedback' => $same ? 'up' : 'corrected', 'corrected_text' => $same ? null : $data['body'],
                    'feedback_by' => $this->user()->id, 'feedback_at' => now()])->save();
            }
        }
        if (! $c->assigned_user_id) {
            $c->forceFill(['assigned_user_id' => $this->user()->id])->save();
        }

        return $this->ok($this->messages($c, $msg->id - 1), [], 201);
    }

    public function suggest(Conversation $conversation, AiResponder $responder)
    {
        abort_unless($this->user()->hasPermission('whatsapp.send'), 403);
        $c = $this->conv($conversation)->load('lead', 'contact');
        $r = $responder->respond($c, 'suggest');
        if (! $r['ok']) {
            throw new DomainRuleException($r['error'] ?: 'The AI could not produce a suggestion.', 'ai_unavailable', 422);
        }

        return $this->ok(['interaction_id' => $r['interaction']->id, 'reply' => $r['reply'], 'needs_human' => $r['needs_human'],
            'off_topic' => $r['off_topic'], 'handoff_reason' => $r['handoff_reason'], 'sources' => $r['sources']]);
    }

    public function update(Request $request, Conversation $conversation, AuditLogger $audit, ActivityLogger $activity)
    {
        $c = $this->conv($conversation);
        $data = $request->validate([
            'ai_mode' => ['sometimes', 'in:inherit,on,off'],
            'status' => ['sometimes', 'in:open,pending,closed'],
            'assigned_user_id' => ['sometimes', 'nullable', 'integer'],
            'resolve_handoff' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('assigned_user_id', $data)) {
            $to = $data['assigned_user_id'];
            $allowed = $this->user()->hasPermission('leads.assign') || $to === $this->user()->id;
            abort_unless($allowed, 403);
            if ($to) {
                $u = User::query()->inCurrentOrganization()->where('is_active', true)->find($to);
                abort_unless($u, 422, 'Invalid user.');
                $scope = $this->visibility->assigneeScope($this->user());
                abort_if($scope !== null && ! in_array($to, $scope, true), 403, 'You can only assign within your team.');
            }
            $c->assigned_user_id = $to;
        }
        if (isset($data['ai_mode'])) {
            $c->ai_mode = $data['ai_mode'];
        }
        if (isset($data['status'])) {
            $c->status = $data['status'];
        }
        if (! empty($data['resolve_handoff'])) {
            $c->handoff_at = null;
            $c->handoff_reason = null;
            $c->ai_reply_count = 0;
            if ($c->status === 'pending') {
                $c->status = 'open';
            }
        }
        $c->save();

        return $this->ok($this->summary($c->fresh(['contact', 'assignee', 'lead.status'])));
    }

    public function markRead(Conversation $conversation)
    {
        $c = $this->conv($conversation);
        $c->forceFill(['unread_count' => 0])->save();
        $last = $c->messages()->where('direction', 'in')->latest('id')->first();
        if ($last && $last->provider_message_id && ! $last->read_at && $c->integration_id) {
            try {
                $i = \App\Models\Integration::find($c->integration_id);
                if ($i && CloudApiClient::for($i)->configured()) {
                    CloudApiClient::for($i)->markRead($last->provider_message_id);
                }
                $last->forceFill(['read_at' => now()])->save();
            } catch (Throwable) {
                // read receipts are best-effort
            }
        }

        return $this->ok(['read' => true]);
    }

    /** Feedback on an AI message/suggestion; "teach" saves the approved answer into the knowledge base. */
    public function feedback(Request $request, AiInteraction $interaction, KnowledgeBase $kb, AuditLogger $audit)
    {
        abort_unless($this->user()->hasAnyPermission('whatsapp.send', 'ai.manage'), 403);
        if ($interaction->conversation_id) {
            $this->conv(Conversation::findOrFail($interaction->conversation_id));
        } else {
            abort_unless($this->user()->hasPermission('ai.manage'), 403);
        }
        $data = $request->validate(['feedback' => ['required', 'in:up,down,corrected'], 'corrected_text' => ['nullable', 'string', 'max:4000'],
            'teach' => ['sometimes', 'boolean'], 'question' => ['nullable', 'string', 'max:1000']]);
        $interaction->forceFill(['feedback' => $data['feedback'], 'corrected_text' => $data['corrected_text'] ?? $interaction->corrected_text,
            'feedback_by' => $this->user()->id, 'feedback_at' => now()])->save();
        $doc = null;
        if (! empty($data['teach'])) {
            abort_unless($this->user()->hasPermission('kb.manage'), 403, 'Only knowledge-base managers can teach the assistant.');
            $answer = trim((string) ($data['corrected_text'] ?? $interaction->corrected_text ?? $interaction->response));
            $question = trim((string) ($data['question'] ?? $interaction->input));
            if ($answer === '' || $question === '') {
                throw new DomainRuleException('Both the question and the approved answer are needed to teach the assistant.', 'teach_incomplete');
            }
            $doc = $kb->learn($question, $answer, $interaction);
            $audit->log('ai.taught', $doc, [], ['interaction_id' => $interaction->id]);
        }

        return $this->ok(['feedback' => $interaction->feedback, 'kb_document_id' => $doc?->id]);
    }

    private function summary(Conversation $c): array
    {
        return [
            'id' => $c->id, 'contact_name' => $c->contact?->displayName(), 'wa_id' => $c->external_id, 'status' => $c->status,
            'ai_mode' => $c->ai_mode, 'handoff' => (bool) $c->handoff_at, 'unread' => $c->unread_count, 'language' => $c->language,
            'assignee' => $c->assignee ? ['id' => $c->assignee->id, 'name' => $c->assignee->name] : null,
            'lead_id' => $c->lead_id, 'score_label' => $c->lead?->score_label,
            'lead_status' => $c->lead?->status ? ['name' => $c->lead->status->name, 'color' => $c->lead->status->color] : null,
            'preview' => $c->last_message_preview, 'last_message_at' => $c->last_message_at?->toIso8601ZuluString(),
            'opted_out' => (bool) $c->contact?->do_not_contact,
        ];
    }
}
