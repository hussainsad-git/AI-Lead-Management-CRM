<?php

namespace App\Models;

/** Anonymous/aggregate ad activity. Never linked to a contact. */
class CampaignEvent extends TenantModel
{
    protected function casts(): array
    {
        return [];
    }
}
