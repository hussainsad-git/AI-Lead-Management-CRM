<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KbChunk extends TenantModel
{
    protected $hidden = ['embedding', 'search_text'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(KbDocument::class, 'kb_document_id');
    }

    /** @return array<float>|null */
    public function vector(): ?array
    {
        if (! $this->embedding) {
            return null;
        }
        $bin = base64_decode($this->embedding, true);

        return $bin === false ? null : array_values(unpack('g*', $bin));
    }

    public static function packVector(array $v): string
    {
        return base64_encode(pack('g*', ...$v));
    }
}
