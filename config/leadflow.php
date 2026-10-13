<?php

/*
| Defaults provisioned for every new organization. After provisioning, each
| organization owns its copy and can rename, reorder, add or disable entries.
*/

return [

    'permissions' => [
        // key => [group, description]
        'dashboard.view' => ['dashboard', 'View dashboard metrics (scoped to lead visibility)'],
        'leads.view_all' => ['leads', 'View every lead in the organization'],
        'leads.view_team' => ['leads', 'View leads assigned to teams the user manages'],
        'leads.view_own' => ['leads', 'View leads assigned to the user'],
        'leads.create' => ['leads', 'Create leads'],
        'leads.update' => ['leads', 'Update visible leads, change status, log activity'],
        'leads.assign' => ['leads', 'Assign or reassign leads'],
        'leads.bulk_update' => ['leads', 'Run bulk actions on leads'],
        'leads.archive' => ['leads', 'Archive leads'],
        'leads.export' => ['leads', 'Export lead data (audited)'],
        'contacts.view' => ['contacts', 'View contacts linked to visible leads'],
        'contacts.update' => ['contacts', 'Edit contact details'],
        'contacts.merge' => ['contacts', 'Merge duplicate contacts (audited)'],
        'consent.manage' => ['contacts', 'Record consent and opt-outs'],
        'followups.manage_own' => ['followups', 'Manage own calls, follow-ups and appointments'],
        'followups.manage_all' => ['followups', 'Manage follow-ups on all visible leads'],
        'campaigns.view' => ['campaigns', 'View campaigns and campaign metrics'],
        'campaigns.manage' => ['campaigns', 'Create/edit campaigns and record ad spend'],
        'imports.run' => ['data', 'Import leads from CSV/XLSX'],
        'reports.view' => ['reports', 'View reports and analytics'],
        'users.manage' => ['admin', 'Manage users and teams'],
        'roles.manage' => ['admin', 'Change role permissions (audited)'],
        'settings.manage' => ['admin', 'Manage organization settings, statuses, sources, outcomes'],
        'integrations.manage' => ['admin', 'Configure integrations'],
        'audit.view' => ['admin', 'View audit logs'],
        'automation.manage' => ['admin', 'Manage automation rules'],
        'audiences.manage' => ['marketing', 'Manage audience segments and retargeting exports (Phase 5)'],
        'whatsapp.view' => ['whatsapp', 'View WhatsApp conversations (own/team/all per lead visibility)'],
        'whatsapp.send' => ['whatsapp', 'Send WhatsApp messages and use AI suggestions'],
        'ai.manage' => ['ai', 'Configure the AI assistant, prompts, auto-replies and review AI learning'],
        'kb.manage' => ['ai', 'Manage the knowledge base and teach the assistant'],
    ],

    'roles' => [
        'super_admin' => ['Super Administrator', 'Full configuration and access', ['*']],
        'business_admin' => ['Business Administrator', 'Manages the business workspace', ['*']],
        'sales_manager' => ['Sales Manager', 'Runs a sales team', [
            'dashboard.view', 'leads.view_team', 'leads.create', 'leads.update', 'leads.assign', 'leads.bulk_update',
            'leads.archive', 'leads.export', 'contacts.view', 'contacts.update', 'contacts.merge', 'consent.manage',
            'followups.manage_own', 'followups.manage_all', 'campaigns.view', 'imports.run', 'reports.view', 'whatsapp.view', 'whatsapp.send', 'kb.manage',
        ]],
        'sales_rep' => ['Sales Representative', 'Works assigned leads', [
            'dashboard.view', 'leads.view_own', 'leads.create', 'leads.update', 'contacts.view', 'contacts.update',
            'consent.manage', 'followups.manage_own', 'whatsapp.view', 'whatsapp.send',
        ]],
        'marketing_manager' => ['Marketing Manager', 'Campaigns, audiences and reporting', [
            'dashboard.view', 'leads.view_all', 'contacts.view', 'campaigns.view', 'campaigns.manage', 'imports.run',
            'reports.view', 'leads.export', 'audiences.manage', 'ai.manage', 'kb.manage', 'whatsapp.view',
        ]],
        'analyst' => ['Read-Only Analyst', 'Aggregated reporting only, no lead PII', [
            'dashboard.view', 'reports.view', 'campaigns.view',
        ]],
    ],

    'lead_sources' => [
        // key => [name, platform, is_paid]
        'facebook_ads' => ['Facebook Ads', 'facebook', true],
        'instagram_ads' => ['Instagram Ads', 'instagram', true],
        'whatsapp' => ['WhatsApp', 'whatsapp', false],
        'meta_instant_form' => ['Meta Instant Forms', 'meta', true],
        'website_form' => ['Website form', 'web', false],
        'landing_page' => ['Landing page', 'web', false],
        'referral' => ['Referral', 'offline', false],
        'phone_call' => ['Phone call', 'offline', false],
        'walk_in' => ['Walk-in', 'offline', false],
        'import' => ['Imported Excel/CSV', 'import', false],
        'other' => ['Other', null, false],
    ],

    'lead_statuses' => [
        // key => [name, semantic, color, requires_reason]
        'new' => ['New Lead', 'new', 'primary', 'none'],
        'contact_pending' => ['Contact Pending', 'contact_pending', 'info', 'none'],
        'contact_attempted' => ['Contact Attempted', 'contact_attempted', 'info', 'none'],
        'no_response' => ['No Response', 'no_response', 'warning', 'none'],
        'responded' => ['Responded', 'responded', 'teal', 'none'],
        'interested' => ['Interested', 'interested', 'teal', 'none'],
        'qualified' => ['Qualified', 'qualified', 'indigo', 'none'],
        'appointment' => ['Appointment / Site Visit Scheduled', 'appointment', 'indigo', 'none'],
        'proposal' => ['Proposal / Negotiation', 'proposal', 'purple', 'none'],
        'won' => ['Won / Converted', 'won', 'success', 'none'],
        'lost' => ['Lost', 'lost', 'danger', 'lost_reason'],
        'not_interested' => ['Not Interested', 'not_interested', 'secondary', 'lost_reason'],
        'do_not_contact' => ['Do Not Contact', 'do_not_contact', 'dark', 'opt_out_reason'],
    ],

    'call_outcomes' => [
        // key => [name, is_connected, creates_callback, advances_to_semantic]
        'answered' => ['Answered', true, false, 'responded'],
        'busy' => ['Busy', false, false, 'contact_attempted'],
        'switched_off' => ['Switched off', false, false, 'contact_attempted'],
        'unreachable' => ['Unreachable', false, false, 'contact_attempted'],
        'no_answer' => ['No answer', false, false, 'contact_attempted'],
        'callback_requested' => ['Callback requested', true, true, 'responded'],
        'interested' => ['Interested', true, false, 'interested'],
        'not_interested' => ['Not interested', true, false, 'not_interested'],
        'wrong_number' => ['Wrong number', false, false, 'lost'],
        'converted' => ['Converted', true, false, 'won'],
    ],

    'phone_regions' => ['PK', 'GB', 'US', 'SA', 'AE'],

    'import' => [
        'max_rows' => (int) env('IMPORT_MAX_ROWS', 50000),
        'batch_size' => 200,
        'max_upload_kb' => (int) env('IMPORT_MAX_UPLOAD_KB', 20480),
    ],

    'followups' => [
        // pending tasks this many hours past due become "missed" (scheduler job)
        'missed_after_hours' => (int) env('FOLLOWUP_MISSED_AFTER_HOURS', 24),
    ],

    /*
    | Notification types. "email" is the default for users who haven't changed their preferences;
    | in-app notifications are always on. Email is sent only when MAIL_MAILER is a real transport.
    */
    'notification_types' => [
        'lead_assigned' => ['New lead assigned to me', true],
        'whatsapp_in' => ['New WhatsApp message on my lead', false],
        'handoff' => ['Customer asked for a human (AI handoff)', true],
        'ai_suggestion' => ['AI drafted a reply for review', false],
        'callback_due' => ['Callback / follow-up due in 15 minutes', true],
        'appointment_soon' => ['Appointment within the hour', true],
        'escalation' => ['Escalations (SLA missed, overdue follow-ups)', true],
        'automation' => ['Automation rule alerts', false],
        'import_done' => ['Import finished', false],
        'integration_error' => ['Integration failure (admins)', true],
        'audience_sync' => ['Audience sync result', false],
    ],
];
