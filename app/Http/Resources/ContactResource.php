<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Contact */
class ContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'display_name' => $this->displayName(),
            'type' => $this->type,
            'name' => $this->name,
            'company_name' => $this->company_name,
            'email' => $this->email,
            'phone' => $this->phone_e164,
            'phone_raw' => $this->phone_raw,
            'whatsapp' => $this->whatsapp_e164,
            'whatsapp_raw' => $this->whatsapp_raw,
            'country_code' => $this->country_code,
            'city' => $this->city,
            'preferred_contact_method' => $this->preferred_contact_method,
            'do_not_contact' => $this->do_not_contact,
            'merged_into_id' => $this->merged_into_id,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
