<?php

namespace App\Http\Controllers\Api\V1;

/**
 * Honest integration status. No adapter is wired yet (Phase 4), so nothing reports as connected.
 */
class IntegrationController extends ApiController
{
    public function status()
    {
        abort_unless($this->user()->hasAnyPermission('integrations.manage', 'settings.manage'), 403);

        return $this->ok([
            [
                'key' => 'whatsapp_cloud', 'name' => 'WhatsApp Business Platform (Cloud API)', 'status' => 'not_configured',
                'available_in' => 'Phase 4', 'last_success_at' => null, 'last_error' => null,
                'requirements' => ['Meta Business portfolio (verified for higher limits)', 'WhatsApp Business Account + registered phone number', 'Meta app with whatsapp_business_messaging and whatsapp_business_management permissions', 'System-user access token', 'Public HTTPS webhook URL with verify token and app secret'],
            ],
            [
                'key' => 'meta_marketing', 'name' => 'Meta Marketing API (campaigns, spend)', 'status' => 'not_configured',
                'available_in' => 'Phase 4', 'last_success_at' => null, 'last_error' => null,
                'requirements' => ['Ad account access in Business Manager', 'Meta app with ads_read (and ads_management for Custom Audiences)', 'System-user token with access to the ad account'],
            ],
            [
                'key' => 'meta_lead_ads', 'name' => 'Meta Lead Ads (Instant Forms)', 'status' => 'not_configured',
                'available_in' => 'Phase 4', 'last_success_at' => null, 'last_error' => null,
                'requirements' => ['Page access with leads_retrieval and pages_manage_metadata', 'leadgen webhook subscription on the Page'],
            ],
        ]);
    }
}
