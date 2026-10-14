<?php

use App\Http\Controllers\Api\V1;
use Illuminate\Support\Facades\Route;

// Mounted at /api/v1 with web (session + CSRF), auth, org and throttle middleware — see bootstrap/app.php.

Route::get('lookups', V1\LookupController::class)->name('lookups');
Route::get('dashboard', V1\DashboardController::class)->middleware('perm:dashboard.view')->name('dashboard');

Route::middleware('perm:leads.view_all|leads.view_team|leads.view_own')->group(function () {
    Route::get('leads', [V1\LeadController::class, 'index']);
    Route::post('leads', [V1\LeadController::class, 'store']);
    Route::post('leads/bulk', [V1\LeadController::class, 'bulk'])->middleware('throttle:sensitive');
    Route::post('leads/export', [V1\LeadController::class, 'export'])->middleware('throttle:sensitive');
    Route::get('leads/{lead}', [V1\LeadController::class, 'show']);
    Route::patch('leads/{lead}', [V1\LeadController::class, 'update']);
    Route::get('leads/{lead}/timeline', [V1\LeadController::class, 'timeline']);
    Route::post('leads/{lead}/activities', [V1\LeadController::class, 'addNote']);
    Route::post('leads/{lead}/status', [V1\LeadController::class, 'changeStatus']);
    Route::post('leads/{lead}/assign', [V1\LeadController::class, 'assign']);
    Route::post('leads/{lead}/archive', [V1\LeadController::class, 'archive']);
    Route::post('leads/{lead}/follow-ups', [V1\LeadController::class, 'scheduleFollowUp']);
    Route::post('leads/{lead}/calls', [V1\LeadController::class, 'logCall']);
    Route::post('leads/{lead}/appointments', [V1\LeadController::class, 'createAppointment']);
    Route::get('pipeline', V1\PipelineController::class);
    Route::get('contacts/match', [V1\ContactController::class, 'match']);
});

Route::get('contacts', [V1\ContactController::class, 'index']);
Route::get('contacts/{contact}', [V1\ContactController::class, 'show']);
Route::patch('contacts/{contact}', [V1\ContactController::class, 'update']);
Route::get('contacts/{contact}/duplicates', [V1\ContactController::class, 'duplicates']);
Route::post('contacts/{contact}/merge', [V1\ContactController::class, 'merge'])->middleware('throttle:sensitive');
Route::post('contacts/{contact}/erase', [V1\ContactController::class, 'erase'])->middleware('throttle:sensitive');
Route::post('contacts/{contact}/consents', [V1\ContactController::class, 'recordConsent']);
Route::post('contacts/{contact}/opt-out', [V1\ContactController::class, 'optOut']);
Route::post('suppressions/{suppression}/lift', [V1\ContactController::class, 'liftSuppression'])->middleware('throttle:sensitive');

Route::get('call-tasks', [V1\CallTaskController::class, 'index']);
Route::post('call-tasks/{task}/complete', [V1\CallTaskController::class, 'complete']);
Route::post('call-tasks/{task}/reschedule', [V1\CallTaskController::class, 'reschedule']);
Route::post('call-tasks/{task}/cancel', [V1\CallTaskController::class, 'cancel']);
Route::get('appointments', [V1\AppointmentController::class, 'index']);
Route::patch('appointments/{appointment}', [V1\AppointmentController::class, 'update']);

Route::get('campaigns', [V1\CampaignController::class, 'index']);
Route::post('campaigns', [V1\CampaignController::class, 'store']);
Route::patch('campaigns/{campaign}', [V1\CampaignController::class, 'update']);
Route::get('campaigns/{campaign}/spend', [V1\CampaignController::class, 'spend']);
Route::post('campaigns/{campaign}/spend', [V1\CampaignController::class, 'recordSpend']);
Route::delete('campaigns/{campaign}/spend/{spend}', [V1\CampaignController::class, 'deleteSpend']);

Route::get('imports', [V1\ImportController::class, 'index']);
Route::post('imports', [V1\ImportController::class, 'store'])->middleware('throttle:sensitive');
Route::get('imports/{import}', [V1\ImportController::class, 'show']);
Route::post('imports/{import}/sheet', [V1\ImportController::class, 'sheet']);
Route::post('imports/{import}/configure', [V1\ImportController::class, 'configure']);
Route::get('imports/{import}/rows', [V1\ImportController::class, 'rows']);
Route::post('imports/{import}/resolve', [V1\ImportController::class, 'resolve']);
Route::post('imports/{import}/commit', [V1\ImportController::class, 'commit']);
Route::get('imports/{import}/report', [V1\ImportController::class, 'errorReport']);

Route::get('views', [V1\SavedViewController::class, 'index']);
Route::post('views', [V1\SavedViewController::class, 'store']);
Route::delete('views/{view}', [V1\SavedViewController::class, 'destroy']);

Route::get('users', [V1\UserController::class, 'index']);
Route::post('users', [V1\UserController::class, 'store'])->middleware('throttle:sensitive');
Route::patch('users/{user}', [V1\UserController::class, 'update'])->middleware('throttle:sensitive');
Route::post('users/{user}/reset-two-factor', [V1\UserController::class, 'resetTwoFactor'])->middleware('throttle:sensitive');
Route::get('teams', [V1\TeamController::class, 'index']);
Route::post('teams', [V1\TeamController::class, 'store']);
Route::patch('teams/{team}', [V1\TeamController::class, 'update']);
Route::delete('teams/{team}', [V1\TeamController::class, 'destroy']);
Route::get('roles', [V1\RoleController::class, 'index']);
Route::patch('roles/{role}', [V1\RoleController::class, 'update'])->middleware('throttle:sensitive');

Route::get('settings', [V1\SettingsController::class, 'show']);
Route::patch('settings/organization', [V1\SettingsController::class, 'updateOrganization']);
Route::post('settings/statuses', [V1\SettingsController::class, 'storeStatus']);
Route::patch('settings/statuses/{status}', [V1\SettingsController::class, 'updateStatus']);
Route::post('settings/statuses/reorder', [V1\SettingsController::class, 'reorderStatuses']);
Route::post('settings/sources', [V1\SettingsController::class, 'storeSource']);
Route::patch('settings/sources/{source}', [V1\SettingsController::class, 'updateSource']);
Route::post('settings/outcomes', [V1\SettingsController::class, 'storeOutcome']);
Route::patch('settings/outcomes/{outcome}', [V1\SettingsController::class, 'updateOutcome']);

Route::get('audit-logs', V1\AuditLogController::class);
Route::get('integrations/status', [V1\IntegrationController::class, 'status']);

// ----- WhatsApp conversations -----
Route::get('conversations', [V1\ConversationController::class, 'index']);
Route::get('conversations/{conversation}', [V1\ConversationController::class, 'show']);
Route::get('conversations/{conversation}/poll', [V1\ConversationController::class, 'poll']);
Route::patch('conversations/{conversation}', [V1\ConversationController::class, 'update']);
Route::post('conversations/{conversation}/messages', [V1\ConversationController::class, 'send'])->middleware('throttle:messaging');
Route::post('conversations/{conversation}/suggest', [V1\ConversationController::class, 'suggest'])->middleware('throttle:ai');
Route::post('conversations/{conversation}/read', [V1\ConversationController::class, 'markRead']);
Route::post('ai-interactions/{interaction}/feedback', [V1\ConversationController::class, 'feedback']);

// ----- AI assistant & knowledge base -----
Route::get('ai/assistant', [V1\AiAssistantController::class, 'show']);
Route::put('ai/assistant', [V1\AiAssistantController::class, 'update']);
Route::post('ai/assistant/preset', [V1\AiAssistantController::class, 'preset']);
Route::get('ai/models', [V1\AiAssistantController::class, 'models']);
Route::post('ai/playground', [V1\AiAssistantController::class, 'playground'])->middleware('throttle:ai');
Route::get('ai/learning', [V1\AiAssistantController::class, 'learning']);
Route::post('ai/learning/{interaction}/dismiss', [V1\AiAssistantController::class, 'dismissLearning']);
Route::post('ai/rules', [V1\AiAssistantController::class, 'storeRule']);
Route::patch('ai/rules/{rule}', [V1\AiAssistantController::class, 'updateRule']);
Route::delete('ai/rules/{rule}', [V1\AiAssistantController::class, 'destroyRule']);
Route::get('kb/documents', [V1\KnowledgeBaseController::class, 'index']);
Route::post('kb/documents', [V1\KnowledgeBaseController::class, 'store'])->middleware('throttle:sensitive');
Route::get('kb/documents/{document}', [V1\KnowledgeBaseController::class, 'show']);
Route::patch('kb/documents/{document}', [V1\KnowledgeBaseController::class, 'update']);
Route::post('kb/documents/{document}/reindex', [V1\KnowledgeBaseController::class, 'reindex']);
Route::delete('kb/documents/{document}', [V1\KnowledgeBaseController::class, 'destroy']);
Route::get('kb/search', [V1\KnowledgeBaseController::class, 'search']);

// ----- integrations (replaces the status-only endpoint) -----
Route::put('integrations/{provider}', [V1\IntegrationController::class, 'update'])->middleware('throttle:sensitive');
Route::post('integrations/{provider}/test', [V1\IntegrationController::class, 'test'])->middleware('throttle:sensitive');
Route::post('integrations/{provider}/rotate-webhook', [V1\IntegrationController::class, 'rotateWebhook'])->middleware('throttle:sensitive');
Route::post('integrations/{provider}/verify-token', [V1\IntegrationController::class, 'revealVerifyToken'])->middleware('throttle:sensitive')->where('provider', 'whatsapp_cloud|meta_marketing');
Route::post('integrations/meta_marketing/sync', [V1\IntegrationController::class, 'metaSync'])->middleware('throttle:sensitive');
Route::post('integrations/meta_marketing/subscribe-page', [V1\IntegrationController::class, 'metaSubscribePage'])->middleware('throttle:sensitive');
Route::post('integrations/whatsapp_cloud/templates/sync', [V1\IntegrationController::class, 'syncTemplates'])->middleware('throttle:sensitive');
Route::get('whatsapp/templates', [V1\IntegrationController::class, 'templates']);
Route::get('web-forms', [V1\WebFormController::class, 'index']);
Route::post('web-forms', [V1\WebFormController::class, 'store'])->middleware('throttle:sensitive');
Route::patch('web-forms/{form}', [V1\WebFormController::class, 'update']);
Route::post('web-forms/{form}/rotate-key', [V1\WebFormController::class, 'rotate'])->middleware('throttle:sensitive');
Route::delete('web-forms/{form}', [V1\WebFormController::class, 'destroy']);
Route::get('web-forms/{form}/submissions', [V1\WebFormController::class, 'submissions']);

// ----- analytics, notifications, profile, business settings, platform admin -----
Route::get('analytics', [V1\AnalyticsController::class, 'index']);
Route::get('analytics/export', [V1\AnalyticsController::class, 'export'])->middleware('throttle:sensitive');
Route::get('notifications', [V1\NotificationController::class, 'index']);
Route::post('notifications/read', [V1\NotificationController::class, 'read']);
Route::get('profile', [V1\ProfileController::class, 'show']);
Route::patch('profile', [V1\ProfileController::class, 'update']);
Route::get('profile/two-factor', [V1\ProfileController::class, 'twoFactorStatus']);
Route::post('profile/two-factor/begin', [V1\ProfileController::class, 'twoFactorBegin'])->middleware('throttle:sensitive');
Route::post('profile/two-factor/confirm', [V1\ProfileController::class, 'twoFactorConfirm'])->middleware('throttle:sensitive');
Route::post('profile/two-factor/disable', [V1\ProfileController::class, 'twoFactorDisable'])->middleware('throttle:sensitive');
Route::post('profile/two-factor/recovery-codes', [V1\ProfileController::class, 'twoFactorRecoveryCodes'])->middleware('throttle:sensitive');
Route::put('profile/notifications', [V1\ProfileController::class, 'notifications']);
Route::post('profile/password', [V1\ProfileController::class, 'password'])->middleware('throttle:sensitive');
Route::patch('settings/business', [V1\SettingsController::class, 'updateBusiness']);
Route::post('settings/logo', [V1\SettingsController::class, 'uploadLogo'])->middleware('throttle:sensitive');
Route::get('platform', [V1\PlatformAdminController::class, 'index']);
Route::post('platform/organizations', [V1\PlatformAdminController::class, 'store'])->middleware('throttle:sensitive');
Route::patch('platform/organizations/{organization}', [V1\PlatformAdminController::class, 'update'])->middleware('throttle:sensitive');

// ----- automation rules -----
Route::get('automation/meta', [V1\AutomationController::class, 'meta']);
Route::get('automation/rules', [V1\AutomationController::class, 'index']);
Route::post('automation/rules', [V1\AutomationController::class, 'store']);
Route::patch('automation/rules/{rule}', [V1\AutomationController::class, 'update']);
Route::delete('automation/rules/{rule}', [V1\AutomationController::class, 'destroy']);
Route::post('automation/rules/{rule}/test', [V1\AutomationController::class, 'test']);
Route::get('automation/executions', [V1\AutomationController::class, 'executions']);

// ----- audience segments & retargeting -----
Route::get('audiences/meta', [V1\AudienceController::class, 'meta']);
Route::get('audiences/jobs', [V1\AudienceController::class, 'jobs']);
Route::get('audiences/meta-status', [V1\AudienceController::class, 'metaStatus']);
Route::post('audiences/estimate', [V1\AudienceController::class, 'estimate']);
Route::get('audiences', [V1\AudienceController::class, 'index']);
Route::post('audiences', [V1\AudienceController::class, 'store']);
Route::get('audiences/{segment}', [V1\AudienceController::class, 'show']);
Route::patch('audiences/{segment}', [V1\AudienceController::class, 'update']);
Route::delete('audiences/{segment}', [V1\AudienceController::class, 'destroy']);
Route::post('audiences/{segment}/refresh', [V1\AudienceController::class, 'refresh']);
Route::get('audiences/{segment}/export', [V1\AudienceController::class, 'export'])->middleware('throttle:sensitive');
Route::post('audiences/{segment}/sync', [V1\AudienceController::class, 'sync'])->middleware('throttle:sensitive');

// ----- personal API tokens (browser session only) -----
Route::get('api-tokens', [V1\ApiTokenController::class, 'index']);
Route::post('api-tokens', [V1\ApiTokenController::class, 'store'])->middleware('throttle:sensitive');
Route::delete('api-tokens/{token}', [V1\ApiTokenController::class, 'destroy']);
