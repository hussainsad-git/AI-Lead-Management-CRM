<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

abstract class ApiController extends Controller
{
    protected function ok(mixed $data, array $meta = [], int $status = 200): JsonResponse
    {
        $body = ['data' => $data];
        if ($meta) {
            $body['meta'] = $meta;
        }

        return response()->json($body, $status);
    }

    protected function paginated(LengthAwarePaginator $p, string $resource, array $extraMeta = []): JsonResponse
    {
        return response()->json([
            'data' => $resource::collection($p->getCollection())->resolve(),
            'meta' => ['page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'last_page' => $p->lastPage()] + $extraMeta,
        ]);
    }

    protected function user(): User
    {
        return request()->user();
    }

    /** 404 (not 403) for leads outside the user's visibility so existence is not disclosed. */
    protected function visibleLead(Lead $lead): Lead
    {
        abort_unless(app(LeadVisibility::class)->canSee($this->user(), $lead), 404);

        return $lead;
    }

    protected function perPage(): int
    {
        return min(max((int) request('per_page', 25), 5), 100);
    }
}
