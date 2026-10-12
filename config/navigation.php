<?php

// [key, label, bootstrap icon, path, any-of permissions, available phase (null = available now)]
return [
    ['dashboard', 'Dashboard', 'speedometer2', '/', ['dashboard.view'], null],
    ['leads', 'Leads', 'person-lines-fill', '/leads', ['leads.view_all', 'leads.view_team', 'leads.view_own'], null],
    ['contacts', 'Contacts', 'person-vcard', '/contacts', ['contacts.view'], null],
    ['pipeline', 'Sales Pipeline', 'kanban', '/pipeline', ['leads.view_all', 'leads.view_team', 'leads.view_own'], null],
    ['whatsapp', 'WhatsApp Inbox', 'whatsapp', '/whatsapp', ['whatsapp.view'], 4],
    ['follow-ups', 'Calls & Follow-ups', 'telephone', '/follow-ups', ['followups.manage_own', 'followups.manage_all'], null],
    ['calendar', 'Calendar & Appointments', 'calendar3', '/calendar', ['followups.manage_own', 'followups.manage_all'], null],
    ['campaigns', 'Campaigns', 'megaphone', '/campaigns', ['campaigns.view'], null],
    ['audiences', 'Audience Segments', 'diagram-3', '/audiences', ['audiences.manage'], 5],
    ['retargeting', 'Retargeting', 'bullseye', '/retargeting', ['audiences.manage'], 5],
    ['reports', 'Reports & Analytics', 'graph-up', '/reports', ['reports.view'], 5],
    ['team', 'Team & Assignments', 'people', '/team', ['users.manage'], null],
    ['imports', 'Import / Export', 'arrow-down-up', '/imports', ['imports.run', 'leads.export'], null],
    ['integrations', 'Integrations', 'plug', '/integrations', ['integrations.manage'], null],
    ['automation', 'Automation Rules', 'lightning-charge', '/automation', ['automation.manage'], 3],
    ['audit', 'Audit Logs', 'shield-check', '/audit', ['audit.view'], null],
    ['settings', 'Settings', 'gear', '/settings', ['settings.manage', 'roles.manage'], null],
];
