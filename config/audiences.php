<?php

/*
| Audience segment catalogue. Segments are dynamic queries over leads + contacts.
| Eligibility (applied to every non-exclusion segment, always): contact not merged, not Do Not Contact,
| no active suppression on "ads_audience"/"all", and the segment's consent policy.
*/

return [
    'max_groups' => 5,
    'max_rules_per_group' => 8,
    'meta_batch_size' => 5000,      // rows per /usersreplace call (Meta allows up to 10,000)
    'preview_rows' => 20,

    // field => [label, type, options-source]   type: enum | number | text | bool | days
    'fields' => [
        'status' => ['Stage (meaning)', 'enum', 'statuses'],
        'lifecycle' => ['Lifecycle', 'enum', ['open' => 'Open', 'won' => 'Converted', 'lost' => 'Lost / not interested']],
        'source' => ['Lead source', 'enum', 'sources'],
        'platform' => ['Ad platform (first touch)', 'enum', ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'meta' => 'Meta (unspecified)', 'whatsapp' => 'WhatsApp', 'google' => 'Google', 'web' => 'Website', 'other' => 'Other']],
        'campaign' => ['Campaign (first touch)', 'enum', 'campaigns'],
        'priority' => ['Priority', 'enum', ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent']],
        'score_label' => ['Score', 'enum', ['hot' => 'Hot', 'warm' => 'Warm', 'cold' => 'Cold', 'needs_review' => 'Needs review']],
        'tag' => ['Tag', 'enum', 'tags'],
        'assigned' => ['Has an owner', 'bool'],
        'contacted' => ['Has been contacted', 'bool'],
        'responded' => ['Has responded', 'bool'],
        'has_whatsapp' => ['Has a WhatsApp number', 'bool'],
        'has_email' => ['Has an email', 'bool'],
        'created_days' => ['Lead created (days ago)', 'days'],
        'last_activity_days' => ['Last activity (days ago)', 'days'],
        'last_contact_days' => ['Last contact attempt (days ago)', 'days'],
        'contact_attempts' => ['Contact attempts', 'number'],
        'unanswered_attempts' => ['Unanswered calls in a row', 'number'],
        'budget_amount' => ['Budget', 'number'],
        'product_interest' => ['Interested in', 'text'],
        'city' => ['City', 'text'],
    ],

    'operators' => [
        'enum' => ['in' => 'is any of', 'not_in' => 'is none of'],
        'number' => ['gte' => '≥', 'lte' => '≤', 'eq' => '='],
        'days' => ['within' => 'within the last', 'older' => 'more than … ago'],
        'text' => ['contains' => 'contains', 'empty' => 'is empty', 'not_empty' => 'is not empty'],
        'bool' => ['is' => 'is'],
    ],

    'consent_policies' => [
        'not_withdrawn' => 'Not opted out (no withdrawal or suppression on record)',
        'granted' => 'Explicit ads-audience consent recorded (strictest)',
    ],
];
