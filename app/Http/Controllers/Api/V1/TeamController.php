<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Team;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TeamController extends ApiController
{
    public function index()
    {
        abort_unless($this->user()->hasPermission('users.manage'), 403);

        return $this->ok(Team::with('manager:id,name')->withCount('members')->orderBy('name')->get()
            ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'manager' => $t->manager?->only(['id', 'name']), 'members_count' => $t->members_count]));
    }

    public function store(Request $request)
    {
        abort_unless($this->user()->hasPermission('users.manage'), 403);

        return $this->ok(Team::create($this->validated($request)), [], 201);
    }

    public function update(Request $request, Team $team)
    {
        abort_unless($this->user()->hasPermission('users.manage'), 403);
        $team->update($this->validated($request, $team));

        return $this->ok($team);
    }

    public function destroy(Team $team)
    {
        abort_unless($this->user()->hasPermission('users.manage'), 403);
        User::where('team_id', $team->id)->update(['team_id' => null]);
        $team->delete();

        return $this->ok(['deleted' => true]);
    }

    private function validated(Request $request, ?Team $team = null): array
    {
        $org = app(CurrentOrganization::class)->id();

        return $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('teams')->where('organization_id', $org)->ignore($team?->id)],
            'manager_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('organization_id', $org)],
        ]);
    }
}
