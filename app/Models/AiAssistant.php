<?php

namespace App\Models;

class AiAssistant extends TenantModel
{
    protected function casts(): array
    {
        return [
            'temperature' => 'float', 'kb_only' => 'boolean', 'auto_qualify' => 'boolean',
            'languages' => 'array', 'handoff_keywords' => 'array', 'qualification_fields' => 'array',
        ];
    }
}
