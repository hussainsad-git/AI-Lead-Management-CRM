<?php

/*
| Automation catalogue. The rule builder UI and server-side validation both read this file.
| Event triggers run right after the event (on the queue); timed triggers run from the scheduler
| every 5 minutes. Every (rule, event) pair runs at most once — see automation_executions.dedupe_key.
*/

return [

    'max_depth' => 3, // actions that cause further events may cascade at most this many levels

    'triggers' => [
        'lead.created' => ['label' => 'New lead created', 'kind' => 'event'],
        'lead.status_changed' => ['label' => 'Lead stage changed', 'kind' => 'event'],
        'lead.qualified' => ['label' => 'Lead became qualified', 'kind' => 'event'],
        'call.logged' => ['label' => 'Call outcome logged', 'kind' => 'event'],
        'lead.opted_out' => ['label' => 'Contact opted out / Do Not Contact', 'kind' => 'event'],
        'lead.uncontacted' => ['label' => 'Lead not contacted within…', 'kind' => 'timed', 'delay_label' => 'minutes after creation'],
        'followup.overdue' => ['label' => 'Follow-up overdue by…', 'kind' => 'timed', 'delay_label' => 'minutes past scheduled time'],
        'lead.inactive' => ['label' => 'No activity on an open lead for…', 'kind' => 'timed', 'delay_label' => 'minutes since last activity'],
    ],

    // field => [label, type, options?]   type: enum | number | text | bool
    'fields' => [
        'status' => ['Stage (meaning)', 'enum', 'statuses'],
        'source' => ['Lead source', 'enum', 'sources'],
        'priority' => ['Priority', 'enum', ['low', 'normal', 'high', 'urgent']],
        'score_label' => ['Score', 'enum', ['hot', 'warm', 'cold', 'needs_review']],
        'platform' => ['Ad platform', 'enum', ['facebook', 'instagram', 'meta', 'whatsapp', 'google', 'web', 'other']],
        'campaign' => ['Campaign', 'enum', 'campaigns'],
        'assigned' => ['Has an owner', 'bool'],
        'has_pending_task' => ['Has an open follow-up', 'bool'],
        'contact_attempts' => ['Contact attempts', 'number'],
        'unanswered_attempts' => ['Unanswered calls in a row', 'number'],
        'call_outcome' => ['Call outcome (call trigger)', 'enum', 'outcomes'],
        'product_interest' => ['Interested in', 'text'],
        'city' => ['City', 'text'],
        'budget_amount' => ['Budget', 'number'],
        'task_priority' => ['Follow-up priority (overdue trigger)', 'enum', ['low', 'normal', 'high', 'urgent']],
    ],

    'operators' => [
        'enum' => ['in' => 'is any of', 'not_in' => 'is none of'],
        'number' => ['gte' => '≥', 'lte' => '≤', 'eq' => '='],
        'text' => ['contains' => 'contains', 'empty' => 'is empty', 'not_empty' => 'is not empty'],
        'bool' => ['is' => 'is'],
    ],

    'actions' => [
        'assign_round_robin' => ['label' => 'Assign round-robin', 'params' => ['team_id' => 'team?']],
        'assign_user' => ['label' => 'Assign to a person', 'params' => ['user_id' => 'user']],
        'create_task' => ['label' => 'Create follow-up task', 'params' => ['purpose' => 'text', 'in_minutes' => 'number', 'priority' => 'priority?']],
        'notify_owner' => ['label' => 'Notify the owner', 'params' => ['message' => 'text']],
        'notify_manager' => ['label' => 'Notify the owner\'s manager', 'params' => ['message' => 'text']],
        'notify_users' => ['label' => 'Notify specific people', 'params' => ['user_ids' => 'users', 'message' => 'text']],
        'set_priority' => ['label' => 'Set priority', 'params' => ['priority' => 'priority']],
        'add_tag' => ['label' => 'Add tag', 'params' => ['tag' => 'text']],
        'move_stage' => ['label' => 'Move to stage (open stages only)', 'params' => ['status_id' => 'open_status']],
        'cancel_tasks' => ['label' => 'Cancel open follow-ups', 'params' => []],
        'send_whatsapp_template' => ['label' => 'Send WhatsApp template', 'params' => ['template_id' => 'template', 'params' => 'text?']],
        'call_webhook' => ['label' => 'Call a webhook (Zapier, Make, your system)', 'params' => ['url' => 'url', 'secret' => 'secret?']],
    ],
];
