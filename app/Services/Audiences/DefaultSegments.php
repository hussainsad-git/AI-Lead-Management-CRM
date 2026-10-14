<?php

namespace App\Services\Audiences;

use App\Models\AudienceSegment;

/** Suggested segments, seeded once per business (idempotent by template_key; edits are never overwritten). */
class DefaultSegments
{
    public function seed(): void
    {
        $g = fn (array $rules, string $match = 'all') => ['match' => 'all', 'groups' => [['match' => $match, 'rules' => $rules]]];
        $r = fn ($f, $op, $v) => ['field' => $f, 'op' => $op, 'value' => $v];
        $defs = [
            'dnc_exclusions' => [__('Do Not Contact exclusions'), 'exclusion', __('Everyone who opted out. Attach to audiences as an extra safety net (opt-outs are excluded automatically anyway).'),
                $g([$r('status', 'in', ['do_not_contact'])])],
            'new_leads' => [__('New leads (7 days)'), 'audience', __('Leads created in the last 7 days.'), $g([$r('created_days', 'within', 7)])],
            'uncontacted' => [__('Uncontacted leads'), 'audience', __('Open leads nobody has tried to reach yet.'), $g([$r('lifecycle', 'in', ['open']), $r('contacted', 'is', false)])],
            'unresponsive' => [__('Unresponsive leads'), 'audience', __('Contacted at least twice, never responded, still open.'), $g([$r('lifecycle', 'in', ['open']), $r('contact_attempts', 'gte', 2), $r('responded', 'is', false)])],
            'responded' => [__('Leads who responded'), 'audience', __('Open leads with a recorded customer response.'), $g([$r('lifecycle', 'in', ['open']), $r('responded', 'is', true)])],
            'interested' => [__('Interested leads'), 'audience', __('Interested stage or hot/warm score.'), ['match' => 'any', 'groups' => [['match' => 'all', 'rules' => [$r('status', 'in', ['interested'])]], ['match' => 'all', 'rules' => [$r('lifecycle', 'in', ['open']), $r('score_label', 'in', ['hot', 'warm'])]]]]],
            'qualified' => [__('Qualified leads'), 'audience', __('Qualified, appointment or proposal stage.'), $g([$r('status', 'in', ['qualified', 'appointment', 'proposal'])])],
            'appointment' => [__('Appointment scheduled'), 'audience', __('Site visit / appointment stage.'), $g([$r('status', 'in', ['appointment'])])],
            'proposal' => [__('Proposal sent'), 'audience', __('Proposal / negotiation stage.'), $g([$r('status', 'in', ['proposal'])])],
            'lost' => [__('Lost opportunities'), 'audience', __('Closed as lost or not interested (excludes Do Not Contact).'), $g([$r('status', 'in', ['lost', 'not_interested'])])],
            'customers' => [__('Converted customers'), 'audience', __('Won deals — for upsell, lookalike seeds or exclusion from acquisition campaigns.'), $g([$r('lifecycle', 'in', ['won'])])],
            're_engage' => [__('Eligible re-engagement prospects'), 'audience', __('Open leads quiet for 14+ days that once responded.'), $g([$r('lifecycle', 'in', ['open']), $r('responded', 'is', true), $r('last_activity_days', 'older', 14)])],
        ];
        $dnc = null;
        foreach ($defs as $key => [$name, $kind, $desc, $def]) {
            $s = AudienceSegment::firstOrCreate(['template_key' => $key], ['name' => $name, 'kind' => $kind, 'description' => $desc, 'definition' => $def,
                'consent_policy' => 'not_withdrawn', 'exclusion_segment_ids' => $kind === 'audience' && $dnc ? [$dnc] : []]);
            if ($key === 'dnc_exclusions') {
                $dnc = $s->id;
            }
        }
    }
}
