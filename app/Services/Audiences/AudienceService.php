<?php

namespace App\Services\Audiences;

use App\Models\AudienceSegment;
use App\Models\AudienceSegmentMembership;
use App\Models\ConsentRecord;
use App\Models\Lead;
use App\Models\SuppressionRecord;
use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Evaluates segments and maintains the membership snapshot with an eligibility verdict per lead.
 * Eligibility is what makes a row exportable/uploadable; membership alone never leaves the CRM.
 */
class AudienceService
{
    public const REASONS = ['suppressed' => 'Opted out / Do Not Contact', 'consent' => 'Consent policy not met', 'excluded' => 'On an exclusion list', 'no_identifier' => 'No phone or email'];

    public function __construct(private SegmentQuery $query, private ActivityLogger $activity) {}

    public function matches(AudienceSegment $s): Builder
    {
        return $this->query->build($s->definition ?? []);
    }

    /** Re-evaluates the segment: upserts memberships, marks leavers, returns counts. */
    public function refresh(AudienceSegment $s): array
    {
        $now = now();
        $excluded = $this->excludedContactIds($s);
        $seen = [];
        $counts = ['members' => 0, 'eligible' => 0, 'skipped' => array_fill_keys(array_keys(self::REASONS), 0)];

        $this->matches($s)->with('contact')->select('leads.*')->orderBy('leads.id')->chunkById(500, function (Collection $leads) use ($s, $excluded, $now, &$seen, &$counts) {
            $consent = $this->consentState($leads->pluck('contact_id')->unique()->all());
            $rows = [];
            foreach ($leads as $lead) {
                $reason = $s->isExclusion() ? null : $this->ineligibleReason($lead, $consent, $excluded, $s->consent_policy);
                $seen[] = $lead->id;
                $counts['members']++;
                $reason ? $counts['skipped'][$reason]++ : $counts['eligible']++;
                $rows[] = ['organization_id' => $s->organization_id, 'audience_segment_id' => $s->id, 'lead_id' => $lead->id, 'contact_id' => $lead->contact_id,
                    'eligible' => $reason === null, 'ineligible_reason' => $reason, 'first_included_at' => $now, 'last_seen_at' => $now, 'removed_at' => null];
            }
            $existing = AudienceSegmentMembership::where('audience_segment_id', $s->id)->whereIn('lead_id', $leads->pluck('id'))->get()->keyBy('lead_id');
            foreach ($rows as $row) {
                $m = $existing[$row['lead_id']] ?? null;
                if (! $m) {
                    AudienceSegmentMembership::create($row);
                    $this->activity->forLead($leads->firstWhere('id', $row['lead_id']), 'audience_added', null, ['segment' => $s->name, 'segment_id' => $s->id, 'eligible' => $row['eligible']]);
                } else {
                    $rejoined = $m->removed_at !== null;
                    $m->forceFill(['eligible' => $row['eligible'], 'ineligible_reason' => $row['ineligible_reason'], 'last_seen_at' => $now, 'removed_at' => null]
                        + ($rejoined ? ['first_included_at' => $now] : []))->save();
                    if ($rejoined) {
                        $this->activity->forLead($leads->firstWhere('id', $row['lead_id']), 'audience_added', null, ['segment' => $s->name, 'segment_id' => $s->id, 'eligible' => $row['eligible']]);
                    }
                }
            }
        });

        // Leavers: still active in the snapshot but no longer matching.
        AudienceSegmentMembership::where('audience_segment_id', $s->id)->whereNull('removed_at')->whereNotIn('lead_id', $seen)
            ->with('lead')->chunkById(500, function ($gone) use ($s, $now) {
                foreach ($gone as $m) {
                    $m->forceFill(['removed_at' => $now, 'eligible' => false])->save();
                    if ($m->lead) {
                        $this->activity->forLead($m->lead, 'audience_removed', null, ['segment' => $s->name, 'segment_id' => $s->id]);
                    }
                }
            });

        $s->forceFill(['last_count' => $counts['members'], 'last_eligible' => $counts['eligible'], 'last_counted_at' => $now])->saveQuietly();

        return $counts;
    }

    /** Quick count without touching memberships (used while building a segment). */
    public function estimate(array $definition, string $consentPolicy, array $exclusionIds, string $kind = 'audience'): array
    {
        $q = $this->query->build($definition);
        $members = (clone $q)->count();
        if ($kind === 'exclusion') {
            return ['members' => $members, 'eligible' => $members, 'skipped' => []];
        }
        $excluded = $this->excludedContactIds(null, $exclusionIds);
        $skipped = array_fill_keys(array_keys(self::REASONS), 0);
        $eligible = 0;
        (clone $q)->with('contact')->select('leads.*')->orderBy('leads.id')->chunkById(500, function ($leads) use (&$eligible, &$skipped, $excluded, $consentPolicy) {
            $consent = $this->consentState($leads->pluck('contact_id')->unique()->all());
            foreach ($leads as $lead) {
                $r = $this->ineligibleReason($lead, $consent, $excluded, $consentPolicy);
                $r ? $skipped[$r]++ : $eligible++;
            }
        });

        return ['members' => $members, 'eligible' => $eligible, 'skipped' => $skipped];
    }

    /** Eligible rows de-duplicated by contact (one person may have several leads). */
    public function eligibleContacts(AudienceSegment $s): Builder
    {
        return AudienceSegmentMembership::query()->where('audience_segment_id', $s->id)->whereNull('removed_at')
            ->when(! $s->isExclusion(), fn ($q) => $q->where('eligible', true))
            ->with('contact')->whereIn('id', function ($sub) use ($s) {
                $sub->selectRaw('MIN(id)')->from('audience_segment_memberships')->where('audience_segment_id', $s->id)->whereNull('removed_at')->groupBy('contact_id');
            });
    }

    public function preview(AudienceSegment $s, int $limit = 20): array
    {
        return $this->eligibleContacts($s)->orderBy('id')->limit($limit)->get()->map(fn ($m) => [
            'lead_id' => $m->lead_id, 'name' => $m->contact?->displayName(), 'phone' => self::mask($m->contact?->phone_e164 ?: $m->contact?->whatsapp_e164),
            'email' => self::mask($m->contact?->email, true), 'city' => $m->contact?->city,
        ])->all();
    }

    public static function mask(?string $v, bool $email = false): ?string
    {
        if (! $v) {
            return null;
        }
        if ($email) {
            [$u, $d] = array_pad(explode('@', $v, 2), 2, '');

            return mb_substr($u, 0, 2).'•••@'.$d;
        }

        return mb_substr($v, 0, 4).'•••'.mb_substr($v, -3);
    }

    // ---------------- eligibility ----------------

    private function excludedContactIds(?AudienceSegment $s, ?array $ids = null): array
    {
        $ids ??= (array) ($s?->exclusion_segment_ids ?? []);
        if (! $ids) {
            return [];
        }
        $out = [];
        foreach (AudienceSegment::where('kind', 'exclusion')->whereIn('id', $ids)->get() as $ex) {
            $out = array_merge($out, $this->matches($ex)->pluck('leads.contact_id')->all());
        }

        return array_flip(array_unique($out));
    }

    /** @return array<int, array{suppressed: bool, consent: ?string}> keyed by contact id */
    private function consentState(array $contactIds): array
    {
        $out = array_fill_keys($contactIds, ['suppressed' => false, 'consent' => null]);
        SuppressionRecord::whereIn('contact_id', $contactIds)->whereNull('lifted_at')->whereIn('channel', ['all', 'ads_audience'])
            ->pluck('contact_id')->each(function ($id) use (&$out) {
                $out[$id]['suppressed'] = true;
            });
        // Latest ads_audience consent record per contact.
        ConsentRecord::whereIn('contact_id', $contactIds)->where('channel', 'ads_audience')->orderBy('recorded_at')->orderBy('id')
            ->get(['contact_id', 'status'])->each(function ($r) use (&$out) {
                $out[$r->contact_id]['consent'] = $r->status;
            });

        return $out;
    }

    private function ineligibleReason(Lead $lead, array $consent, array $excluded, string $policy): ?string
    {
        $c = $lead->contact;
        $st = $consent[$lead->contact_id] ?? ['suppressed' => false, 'consent' => null];
        if (! $c || $c->do_not_contact || $st['suppressed']) {
            return 'suppressed';
        }
        if ($policy === 'granted' ? $st['consent'] !== 'granted' : $st['consent'] === 'withdrawn') {
            return 'consent';
        }
        if (isset($excluded[$lead->contact_id])) {
            return 'excluded';
        }
        if (! $c->phone_e164 && ! $c->whatsapp_e164 && ! $c->email_normalized) {
            return 'no_identifier';
        }

        return null;
    }
}
