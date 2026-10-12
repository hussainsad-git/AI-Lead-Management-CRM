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
