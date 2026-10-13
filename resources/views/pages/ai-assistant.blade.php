@extends('layouts.app')
@section('title', 'AI Assistant')
@section('content')
<div class="lf-page-head">
    <h1>{{ __('AI Assistant') }}</h1>
    <span class="badge text-bg-light border" id="aiStatus"></span>
    <div class="lf-actions"><a class="btn btn-light btn-sm" href="/knowledge-base"><i class="bi bi-journal-richtext"></i> {{ __('Knowledge Base') }}</a></div>
</div>
<div id="aiNotice"></div>
<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#t-config" role="tab">{{ __('Configuration') }}</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-prompt" role="tab">{{ __('Prompt & behaviour') }}</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-play" role="tab">{{ __('Test chat') }}</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-rules" role="tab">{{ __('Instant auto-replies') }}</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-learn" role="tab">{{ __('Learning queue') }} <span class="badge text-bg-warning" id="learnCount"></span></button></li>
</ul>
<form id="aiForm" novalidate>
<div class="tab-content">
    <div class="tab-pane fade show active" id="t-config" role="tabpanel"><div class="card"><div class="card-body"><div class="row g-3">
        <div class="col-md-6"><label class="form-label" for="a-name">{{ __('Assistant name') }}</label><input class="form-control" id="a-name" name="name" maxlength="120"></div>
        <div class="col-md-6"><label class="form-label" for="a-industry">{{ __('Industry preset') }}</label>
            <div class="input-group"><select class="form-select" id="a-industry"></select><button class="btn btn-light" type="button" id="applyPreset">{{ __('Apply preset') }}</button></div>
            <div class="form-text">{{ __('Replaces persona, instructions and qualification fields with the preset.') }}</div></div>
        <div class="col-md-4"><label class="form-label" for="a-provider">{{ __('AI provider') }}</label><select class="form-select" id="a-provider" name="provider"></select></div>
        <div class="col-md-5"><label class="form-label" for="a-model">{{ __('Model') }}</label>
            <div class="input-group"><input class="form-control" id="a-model" name="model" list="modelList" maxlength="80" placeholder="{{ __('Load models or type an ID') }}"><datalist id="modelList"></datalist>
            <button class="btn btn-light" type="button" id="loadModels"><i class="bi bi-arrow-repeat"></i> {{ __('Load models') }}</button></div>
            <div class="form-text" id="modelHint"></div></div>
        <div class="col-md-3"><label class="form-label" for="a-temp">{{ __('Creativity (temperature)') }}</label><input class="form-control" type="number" step="0.05" min="0" max="1.5" id="a-temp" name="temperature"></div>
        <div class="col-md-4"><label class="form-label" for="a-mode">{{ __('Reply mode') }}</label><select class="form-select" id="a-mode" name="mode">
            <option value="off">{{ __('Off') }}</option><option value="suggest">{{ __('Suggest — agents approve every reply') }}</option><option value="auto">{{ __('Auto — reply instantly, hand off when unsure') }}</option></select></div>
        <div class="col-md-4"><label class="form-label" for="a-hours">{{ __('When to auto-reply') }}</label><select class="form-select" id="a-hours" name="active_hours">
            <option value="always">{{ __('Always') }}</option><option value="outside_hours">{{ __('Only outside business hours') }}</option><option value="in_hours">{{ __('Only during business hours') }}</option></select>
            <div class="form-text" id="hoursHint"></div></div>
        <div class="col-md-2"><label class="form-label" for="a-max">{{ __('Max AI replies / chat') }}</label><input class="form-control" type="number" min="1" max="50" id="a-max" name="max_auto_replies"></div>
        <div class="col-md-2"><label class="form-label" for="a-tokens">{{ __('Max reply tokens') }}</label><input class="form-control" type="number" min="100" max="2000" id="a-tokens" name="max_tokens"></div>
        <div class="col-md-8"><div class="form-label">{{ __('Reply languages') }}</div><div class="kv-chips" id="a-langs"></div><div class="form-text">{{ __('The assistant replies in the customer\'s language and script when it is in this list.') }}</div></div>
        <div class="col-md-4"><label class="form-label" for="a-deflang">{{ __('Default language') }}</label><select class="form-select" id="a-deflang" name="default_language"></select></div>
        <div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="a-kbonly" name="kb_only" value="1"><label class="form-check-label" for="a-kbonly">{{ __('Answer only from the knowledge base (hand off otherwise)') }}</label></div></div>
        <div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="a-qualify" name="auto_qualify" value="1"><label class="form-check-label" for="a-qualify">{{ __('Auto-qualify leads from conversations') }}</label></div></div>
        <div class="col-md-8"><div class="form-label">{{ __('Information to collect') }}</div><div class="kv-chips" id="a-fields"></div></div>
        <div class="col-md-4"><label class="form-label" for="a-history">{{ __('Messages of context') }}</label><input class="form-control" type="number" min="2" max="30" id="a-history" name="history_messages"></div>
    </div></div></div></div>

    <div class="tab-pane fade" id="t-prompt" role="tabpanel"><div class="card"><div class="card-body"><div class="row g-3">
        <div class="col-12"><label class="form-label" for="a-persona">{{ __('Persona (who the assistant is)') }}</label><textarea class="form-control prompt-area" id="a-persona" name="persona" rows="3" maxlength="3000" dir="auto"></textarea></div>
        <div class="col-12"><label class="form-label" for="a-instr">{{ __('Business instructions') }}</label><textarea class="form-control prompt-area" id="a-instr" name="instructions" rows="7" maxlength="8000" dir="auto"></textarea>
            <div class="form-text">{{ __('Tone, what to ask, what never to say. Facts (prices, timings, policies) belong in the Knowledge Base, not here.') }}</div></div>
        <div class="col-md-4"><label class="form-label" for="a-greeting">{{ __('Greeting') }}</label><textarea class="form-control" id="a-greeting" name="greeting" rows="3" maxlength="1000" dir="auto"></textarea>
            <div class="form-text">{{ __('Used by the "first message" auto-reply if you create one.') }}</div></div>
        <div class="col-md-4"><label class="form-label" for="a-fallback">{{ __('When the answer is unknown') }}</label><textarea class="form-control" id="a-fallback" name="fallback_message" rows="3" maxlength="1000" dir="auto"></textarea></div>
        <div class="col-md-4"><label class="form-label" for="a-handoff">{{ __('Handoff message') }}</label><textarea class="form-control" id="a-handoff" name="handoff_message" rows="3" maxlength="1000" dir="auto"></textarea></div>
        <div class="col-12"><label class="form-label" for="a-hkw">{{ __('Handoff keywords') }}</label><input class="form-control" id="a-hkw" name="handoff_keywords_csv" dir="auto"><div class="form-text">{{ __('Comma separated. When a customer writes one of these, the AI stops and a human is notified.') }}</div></div>
        <div class="col-12"><details><summary class="small">{{ __('Fixed safety rules added to every prompt') }}</summary><ul class="small text-muted mt-2 mb-0">
            <li>{{ __('Stays on this business only (WhatsApp Business terms bar general-purpose AI assistants).') }}</li>
            <li>{{ __('Never invents prices, availability, dates or contact details — hands off instead.') }}</li>
            <li>{{ __('Never asks for passwords, card or ID numbers; does not claim to be human.') }}</li>
            <li>{{ __('Replies in a JSON envelope so unusable answers are never sent.') }}</li></ul></details></div>
    </div></div></div></div>

    <div class="tab-pane fade" id="t-play" role="tabpanel"><div class="row g-3">
        <div class="col-lg-7"><div class="card"><div class="card-header d-flex">{{ __('Test chat') }}<button class="btn btn-sm btn-link ms-auto p-0" type="button" id="playReset">{{ __('Reset') }}</button></div><div class="card-body">
            <div class="chat-sim mb-2" id="playLog"></div>
            <div class="d-flex gap-2"><input class="form-control" id="playQ" placeholder="{{ __('Ask as a customer would, in any language…') }}" maxlength="2000" dir="auto" aria-label="{{ __('Test message') }}">
                <button class="btn btn-primary" type="button" id="playSend">{{ __('Send') }}</button></div>
            <div class="form-text">{{ __('Uses saved settings and the live knowledge base. Nothing is sent to WhatsApp.') }}</div>
        </div></div></div>
        <div class="col-lg-5"><div class="card"><div class="card-header">{{ __('Last answer') }}</div><div class="card-body small" id="playMeta"><span class="text-muted">{{ __('Send a test message to see sources, flags and latency.') }}</span></div></div></div>
    </div></div>
</div>
<div class="d-flex justify-content-end mt-3 save-bar"><button class="btn btn-primary" type="submit" id="aiSave">{{ __('Save assistant settings') }}</button></div>
</form>

<div class="tab-content">
    <div class="tab-pane fade" id="t-rules" role="tabpanel"><div class="card">
        <div class="card-header d-flex align-items-center">{{ __('Instant auto-replies') }}<button class="btn btn-sm btn-primary ms-auto" type="button" id="addRule"><i class="bi bi-plus-lg"></i> {{ __('Add rule') }}</button></div>
        <div class="card-body small text-muted pb-0">{{ __('Checked before the AI, top to bottom. Use {name} and {business} in replies. Cooldown stops the same rule repeating in a conversation.') }}</div>
        <div class="table-wrap"><table class="table align-middle"><thead><tr><th>{{ __('Name') }}</th><th>{{ __('Trigger') }}</th><th>{{ __('Reply') }}</th><th class="text-end">{{ __('Used') }}</th><th>{{ __('Active') }}</th><th></th></tr></thead><tbody id="rulesBody"></tbody></table></div>
    </div></div>
    <div class="tab-pane fade" id="t-learn" role="tabpanel"><div class="card">
        <div class="card-header">{{ __('Continuous learning') }}</div>
        <div class="card-body small text-muted pb-0">{{ __('Questions the AI handed off, and answers agents rejected or corrected. Approve an answer to add it to the knowledge base — the assistant uses it from the next message. Nothing is learned without human approval.') }}</div>
        <div class="table-wrap"><table class="table align-middle"><thead><tr><th>{{ __('Customer asked') }}</th><th>{{ __('AI answer') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead><tbody id="learnBody"></tbody></table></div>
        <div class="card-footer bg-white" id="learnPager"></div>
    </div></div>
</div>

<div class="modal fade" id="ruleModal" tabindex="-1" aria-labelledby="ruleTitle" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" id="ruleForm" novalidate>
    <div class="modal-header"><h2 class="modal-title fs-5" id="ruleTitle">{{ __('Auto-reply rule') }}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><div class="row g-2">
        <div class="col-12"><label class="form-label" for="r-name">{{ __('Name') }}</label><input class="form-control" id="r-name" name="name" maxlength="120" required></div>
        <div class="col-6"><label class="form-label" for="r-trigger">{{ __('Trigger') }}</label><select class="form-select" id="r-trigger" name="trigger">
            <option value="keyword">{{ __('Message contains keyword') }}</option><option value="first_message">{{ __('First message in a conversation') }}</option><option value="outside_hours">{{ __('Outside business hours') }}</option></select></div>
        <div class="col-6 kw-only"><label class="form-label" for="r-match">{{ __('Match') }}</label><select class="form-select" id="r-match" name="match_type"><option value="contains">{{ __('Contains word') }}</option><option value="exact">{{ __('Exact message') }}</option><option value="regex">{{ __('Pattern (regex)') }}</option></select></div>
        <div class="col-12 kw-only"><label class="form-label" for="r-kw">{{ __('Keywords') }}</label><input class="form-control" id="r-kw" name="keywords_csv" dir="auto" placeholder="price, rate, qeemat, قیمت"></div>
        <div class="col-12"><label class="form-label" for="r-resp">{{ __('Reply') }}</label><textarea class="form-control" id="r-resp" name="response" rows="4" maxlength="4096" dir="auto" required></textarea></div>
        <div class="col-4"><label class="form-label" for="r-prio">{{ __('Order') }}</label><input class="form-control" type="number" min="1" max="1000" id="r-prio" name="priority" value="100"></div>
        <div class="col-4"><label class="form-label" for="r-cool">{{ __('Cooldown (min)') }}</label><input class="form-control" type="number" min="1" id="r-cool" name="cooldown_minutes" value="720"></div>
        <div class="col-4 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" id="r-stop" name="stop_ai" value="1"><label class="form-check-label" for="r-stop">{{ __('Skip AI after') }}</label></div></div>
    </div></div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('Cancel') }}</button><button class="btn btn-primary" type="submit">{{ __('Save') }}</button></div>
</form></div></div>

<div class="modal fade" id="learnModal" tabindex="-1" aria-labelledby="learnTitle" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" id="learnForm" novalidate>
    <div class="modal-header"><h2 class="modal-title fs-5" id="learnTitle">{{ __('Approve an answer') }}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><label class="form-label" for="l-q">{{ __('Customer question') }}</label><textarea class="form-control mb-2" id="l-q" rows="2" dir="auto" required></textarea>
        <label class="form-label" for="l-a">{{ __('Correct answer') }}</label><textarea class="form-control" id="l-a" rows="5" dir="auto" required></textarea></div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('Cancel') }}</button><button class="btn btn-primary" type="submit">{{ __('Teach the assistant') }}</button></div>
</form></div></div>
@endsection
@push('scripts')<script src="/js/pages/ai-assistant.js?v={{ config('app.asset_version') }}"></script>@endpush
