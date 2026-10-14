<?php

// [key, label, bootstrap icon, path, any-of permissions, planned phase (null = available)]
return [
    ['dashboard', 'Dashboard', 'speedometer2', '/', ['dashboard.view'], null],
    ['whatsapp', 'WhatsApp Inbox', 'whatsapp', '/whatsapp', ['whatsapp.view'], null],
    ['leads', 'Leads', 'person-lines-fill', '/leads', ['leads.view_all', 'leads.view_team', 'leads.view_own'], null],
    ['contacts', 'Contacts', 'person-vcard', '/contacts', ['contacts.view'], null],
    ['pipeline', 'Sales Pipeline', 'kanban', '/pipeline', ['leads.view_all', 'leads.view_team', 'leads.view_own'], null],
    ['follow-ups', 'Calls & Follow-ups', 'telephone', '/follow-ups', ['followups.manage_own', 'followups.manage_all'], null],
    ['calendar', 'Calendar & Appointments', 'calendar3', '/calendar', ['followups.manage_own', 'followups.manage_all'], null],
    ['ai-assistant', 'AI Assistant', 'robot', '/ai-assistant', ['ai.manage'], null],
    ['knowledge-base', 'Knowledge Base', 'journal-richtext', '/knowledge-base', ['kb.manage', 'ai.manage'], null],
    ['campaigns', 'Campaigns', 'megaphone', '/campaigns', ['campaigns.view'], null],
    ['analytics', 'Analytics & Reports', 'graph-up', '/analytics', ['reports.view'], null],
    ['audiences', 'Audience Segments', 'diagram-3', '/audiences', ['audiences.manage'], null],
    ['retargeting', 'Retargeting', 'bullseye', '/retargeting', ['audiences.manage'], null],
    ['team', 'Team & Assignments', 'people', '/team', ['users.manage'], null],
    ['imports', 'Import / Export', 'arrow-down-up', '/imports', ['imports.run', 'leads.export'], null],
    ['integrations', 'Integrations', 'plug', '/integrations', ['integrations.manage'], null],
    ['automation', 'Automation Rules', 'lightning-charge', '/automation', ['automation.manage'], null],
    ['audit', 'Audit Logs', 'shield-check', '/audit', ['audit.view'], null],
    ['settings', 'Settings', 'gear', '/settings', ['settings.manage', 'roles.manage'], null],
];
