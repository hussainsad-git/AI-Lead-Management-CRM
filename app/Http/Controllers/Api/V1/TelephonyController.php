<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Lead;
use App\Models\TelephonyCall;
use App\Services\Telephony\TelephonyService;

class TelephonyController extends ApiController
{
    public function dial(Lead $lead, TelephonyService $svc)
    {
        $this->visibleLead($lead)->load('contact');
        abort_unless($this->user()->hasPermission('followups.manage') || $this->user()->hasPermission('leads.update'), 403);
        $call = $svc->dial($lead, $this->user());

        return $this->ok($this->present($call), [], 202);
    }

    public function show(TelephonyCall $call)
    {
        $this->visibleLead($call->lead);

        return $this->ok($this->present($call));
    }

    public function recent(Lead $lead)
    {
        $this->visibleLead($lead);

        return $this->ok($lead->telephonyCalls()->with('user:id,name')->latest('id')->limit(20)->get()->map(fn ($c) => $this->present($c)));
    }

    private function present(TelephonyCall $c): array
    {
        return ['id' => $c->id, 'status' => $c->status, 'open' => $c->isOpen(), 'customer_leg' => $c->lead_leg_status, 'to' => $c->to_number, 'agent' => $c->agent_number,
            'by' => $c->relationLoaded('user') ? $c->user?->name : null, 'started_at' => $c->started_at?->toIso8601ZuluString(), 'answered_at' => $c->answered_at?->toIso8601ZuluString(),
            'ended_at' => $c->ended_at?->toIso8601ZuluString(), 'duration_seconds' => $c->duration_seconds, 'talk_seconds' => $c->talk_seconds,
            'recording_url' => $c->recording_url, 'error' => $c->error, 'outcome_logged' => $c->outcome_logged, 'provider' => $c->provider];
    }
}
