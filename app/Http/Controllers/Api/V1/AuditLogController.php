<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends ApiController
{
    public function __invoke(Request $request)
    {
        abort_unless($this->user()->hasPermission('audit.view'), 403);
        $q = AuditLog::with('user:id,name')->orderByDesc('id')
            ->when($request->input('action'), fn ($q, $a) => $q->where('action', 'like', str_replace(['%', '_'], ['\%', '\_'], $a).'%'))
            ->when($request->input('user_id'), fn ($q, $u) => $q->where('user_id', (int) $u));
        $page = $q->paginate($this->perPage());

        return response()->json([
            'data' => $page->getCollection()->map(fn ($a) => [
                'id' => $a->id, 'action' => $a->action, 'user' => $a->user?->name, 'subject' => $a->auditable_type ? $a->auditable_type.' #'.$a->auditable_id : null,
                'changes' => $a->changes, 'meta' => $a->meta, 'ip' => $a->ip_address, 'at' => $a->created_at->toIso8601ZuluString(),
            ]),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }
}
