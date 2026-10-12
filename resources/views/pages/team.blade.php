@extends('layouts.app')
@section('title', 'Team & Assignments')
@section('content')
<div class="lf-page-head"><h1>Team &amp; Assignments</h1><div class="lf-actions"><button class="btn btn-light btn-sm" id="newTeam"><i class="bi bi-diagram-2"></i> New team</button><button class="btn btn-primary btn-sm" id="newUser"><i class="bi bi-person-plus"></i> Add user</button></div></div>
<div class="row g-3">
    <div class="col-xl-8"><div class="card"><div class="card-header">Users</div><div class="table-wrap"><table class="table table-hover align-middle"><thead><tr><th>Name</th><th>Roles</th><th>Team</th><th class="text-end">Open leads</th><th>Assignable</th><th>Last sign-in</th><th>Status</th><th></th></tr></thead><tbody id="usersBody"></tbody></table></div></div></div>
    <div class="col-xl-4"><div class="card"><div class="card-header">Teams</div><ul class="list-group list-group-flush" id="teamsList"></ul>
        <div class="card-body small text-muted">A team's manager sees and assigns leads owned by team members (with the "team" lead visibility permission).</div></div></div>
</div>

<div class="modal fade" id="userModal" tabindex="-1" aria-labelledby="userModalTitle" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" id="userForm" novalidate>
    <div class="modal-header"><h2 class="modal-title fs-5" id="userModalTitle">User</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><div class="row g-2">
        <div class="col-md-6"><label class="form-label" for="u-name">Name</label><input class="form-control" id="u-name" name="name" maxlength="120" required></div>
        <div class="col-md-6"><label class="form-label" for="u-email">Email</label><input class="form-control" type="email" id="u-email" name="email" maxlength="191" required autocomplete="off"></div>
        <div class="col-md-6"><label class="form-label" for="u-phone">Phone</label><input class="form-control" id="u-phone" name="phone" maxlength="32"></div>
        <div class="col-md-6"><label class="form-label" for="u-team">Team</label><select class="form-select" id="u-team" name="team_id"></select></div>
        <div class="col-12"><label class="form-label" for="u-pass">Password <span class="small text-muted" id="u-pass-hint">(min 10 characters, letters and numbers)</span></label><input class="form-control" type="password" id="u-pass" name="password" autocomplete="new-password"></div>
        <div class="col-12"><div class="form-label">Roles</div><div id="u-roles"></div></div>
        <div class="col-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="u-assign" name="accepts_assignments" value="1"><label class="form-check-label" for="u-assign">Receives lead assignments</label></div></div>
        <div class="col-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="u-active" name="is_active" value="1"><label class="form-check-label" for="u-active">Active</label></div></div>
    </div></div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
</form></div></div>

<div class="modal fade" id="teamModal" tabindex="-1" aria-labelledby="teamModalTitle" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" id="teamForm" novalidate>
    <div class="modal-header"><h2 class="modal-title fs-5" id="teamModalTitle">Team</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><label class="form-label" for="t-name">Name</label><input class="form-control mb-2" id="t-name" name="name" maxlength="120" required>
        <label class="form-label" for="t-manager">Manager</label><select class="form-select" id="t-manager" name="manager_user_id"></select></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-danger me-auto hidden" id="t-delete">Delete team</button><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
</form></div></div>
@endsection
@push('scripts')<script src="/js/pages/team.js?v={{ config('app.asset_version') }}"></script>@endpush
