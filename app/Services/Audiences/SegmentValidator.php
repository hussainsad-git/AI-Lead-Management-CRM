<?php

namespace App\Services\Audiences;

use App\Models\AudienceSegment;
use App\Models\Campaign;
use App\Models\Tag;
use Illuminate\Validation\ValidationException;

class SegmentValidator
{
    public function validate(array $in, ?AudienceSegment $editing = null): array
    {
        $cfg = config('audiences');
        $err = [];
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 150);
        if ($name === '') {
            $err['name'] = 'Give the segment a name.';
        }
        $kind = in_array($in['kind'] ?? 'audience', ['audience', 'exclusion'], true) ? $in['kind'] ?? 'audience' : 'audience';
        $policy = isset($cfg['consent_policies'][$in['consent_policy'] ?? '']) ? $in['consent_policy'] : 'not_withdrawn';

        $groups = [];
        foreach (array_values((array) ($in['definition']['groups'] ?? [])) as $gi => $g) {
            $rules = [];
            foreach (array_values((array) ($g['rules'] ?? [])) as $ri => $r) {
                $field = $cfg['fields'][$r['field'] ?? ''] ?? null;
                if (! $field) {
                    $err["groups.$gi.rules.$ri"] = 'Unknown field.';
                    continue;
                }
                $type = $field[1];
                $op = (string) ($r['op'] ?? '');
                if (! isset($cfg['operators'][$type][$op])) {
                    $err["groups.$gi.rules.$ri"] = 'Invalid operator for '.$field[0].'.';
                    continue;
                }
                $v = $r['value'] ?? null;
                $v = match ($type) {
                    'enum' => array_values(array_filter(array_map('strval', (array) $v), fn ($x) => $x !== '')),
                    'number', 'days' => is_numeric($v) ? (float) $v : null,
                    'bool' => filter_var($v, FILTER_VALIDATE_BOOLEAN),
                    default => in_array($op, ['empty', 'not_empty'], true) ? null : mb_substr(trim((string) $v), 0, 120),
                };
                if ($type === 'enum' && is_string($field[2] ?? null)) { // ids must belong to this organization
                    $v = match ($field[2]) {
                        'campaigns' => array_map('strval', Campaign::whereIn('id', $v)->pluck('id')->all()),
                        'tags' => array_map('strval', Tag::whereIn('id', $v)->pluck('id')->all()),
                        default => $v,
                    };
                }
                if (($type === 'enum' && ! $v) || (in_array($type, ['number', 'days'], true) && $v === null) || ($type === 'text' && $op === 'contains' && $v === '')) {
                    $err["groups.$gi.rules.$ri"] = 'Enter a value for '.$field[0].'.';
                    continue;
                }
                $rules[] = ['field' => $r['field'], 'op' => $op, 'value' => $v];
            }
            if (count($rules) > $cfg['max_rules_per_group']) {
                $err["groups.$gi"] = 'Too many conditions in one group.';
            }
            if ($rules) {
                $groups[] = ['match' => ($g['match'] ?? 'all') === 'any' ? 'any' : 'all', 'rules' => $rules];
            }
        }
        if (count($groups) > $cfg['max_groups']) {
            $err['definition'] = 'At most '.$cfg['max_groups'].' groups.';
        }
        if (! $groups) {
            $err['definition'] = 'Add at least one condition — a segment of every lead is not a useful audience.';
        }

        $exclusions = [];
        if ($kind === 'audience') {
            $ids = array_map('intval', (array) ($in['exclusion_segment_ids'] ?? []));
            $exclusions = AudienceSegment::where('kind', 'exclusion')->whereIn('id', $ids)->when($editing, fn ($q) => $q->where('id', '!=', $editing->id))->pluck('id')->all();
        }

        if ($err) {
            throw ValidationException::withMessages($err);
        }

        return [
            'name' => $name, 'description' => mb_substr(trim((string) ($in['description'] ?? '')), 0, 500) ?: null, 'kind' => $kind,
            'definition' => ['match' => ($in['definition']['match'] ?? 'all') === 'any' ? 'any' : 'all', 'groups' => $groups],
            'consent_policy' => $policy, 'exclusion_segment_ids' => $exclusions,
            'auto_sync' => filter_var($in['auto_sync'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }
}
