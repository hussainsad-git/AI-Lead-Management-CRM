@extends('layouts.app')
@section('title', 'Knowledge Base')
@section('content')
<div class="lf-page-head">
    <h1>{{ __('Knowledge Base') }}</h1>
    <div class="lf-actions kb-write">
        <button class="btn btn-light btn-sm" data-add="faq"><i class="bi bi-question-circle"></i> {{ __('Add FAQ') }}</button>
        <button class="btn btn-light btn-sm" data-add="text"><i class="bi bi-file-text"></i> {{ __('Paste text') }}</button>
        <button class="btn btn-primary btn-sm" data-add="file"><i class="bi bi-upload"></i> {{ __('Upload document') }}</button>
    </div>
</div>
<div class="row g-2 mb-3" id="kbStats"></div>
<div class="row g-3">
    <div class="col-xl-8"><div class="card">
        <div class="card-header d-flex align-items-center gap-2">{{ __('Training content') }}
            <select class="form-select form-select-sm w-auto ms-auto" id="kbType" aria-label="{{ __('Type') }}"><option value="">{{ __('All types') }}</option><option value="file">{{ __('Documents') }}</option><option value="text">{{ __('Text') }}</option><option value="faq">FAQ</option><option value="learned">{{ __('Learned from conversations') }}</option></select>
            <input type="search" class="form-control form-control-sm mw-220" id="kbQ" placeholder="{{ __('Filter by title') }}" aria-label="{{ __('Filter by title') }}"></div>
        <div class="table-wrap"><table class="table align-middle table-dense"><thead><tr><th>{{ __('Title') }}</th><th>{{ __('Type') }}</th><th>{{ __('Status') }}</th><th class="text-end">{{ __('Chunks') }}</th><th>{{ __('Search') }}</th><th>{{ __('Enabled') }}</th><th></th></tr></thead><tbody id="kbBody"></tbody></table></div>
        <div class="card-footer bg-white" id="kbPager"></div>
    </div></div>
    <div class="col-xl-4"><div class="card">
        <div class="card-header">{{ __('Test retrieval') }}</div>
        <div class="card-body">
            <div class="input-group mb-2"><input class="form-control" id="kbSearchQ" placeholder="{{ __('e.g. installment plan for 5 marla') }}" dir="auto" aria-label="{{ __('Test question') }}"><button class="btn btn-primary" id="kbSearchBtn">{{ __('Search') }}</button></div>
            <div class="small text-muted mb-2">{{ __('Shows which passages the assistant would read for this question.') }}</div>
            <div id="kbSearchOut"></div>
        </div>
    </div>
    <div class="lf-notice mt-3 small">
        <strong>{{ __('What to add') }}</strong>: {{ __('price lists, project/product details, FAQs, policies (refunds, delivery, admission), timings, locations, payment methods. Keep one topic per document; update or disable outdated content — the assistant only knows what is here.') }}
        <div class="mt-1">{{ __('Supported: PDF (with selectable text), DOCX, TXT, MD, CSV, HTML, JSON.') }}</div>
    </div></div>
</div>

<div class="modal fade" id="kbModal" tabindex="-1" aria-labelledby="kbTitle" aria-hidden="true"><div class="modal-dialog modal-lg"><form class="modal-content" id="kbForm" novalidate>
    <div class="modal-header"><h2 class="modal-title fs-5" id="kbTitle">{{ __('Add content') }}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><div class="row g-2">
        <div class="col-12 k-file"><label class="form-label" for="k-file">{{ __('File') }}</label><input class="form-control" type="file" id="k-file" name="file" accept=".pdf,.docx,.txt,.md,.csv,.html,.htm,.json"></div>
        <div class="col-md-8 k-file k-text"><label class="form-label" for="k-title">{{ __('Title') }}</label><input class="form-control" id="k-title" name="title" maxlength="191" dir="auto"></div>
        <div class="col-md-4"><label class="form-label" for="k-lang">{{ __('Language') }}</label><select class="form-select" id="k-lang" name="language"><option value="">{{ __('Auto / mixed') }}</option><option value="en">English</option><option value="ur">اردو</option><option value="roman_ur">Roman Urdu</option><option value="ar">العربية</option></select></div>
        <div class="col-12 k-text"><label class="form-label" for="k-content">{{ __('Content') }}</label><textarea class="form-control" id="k-content" name="content" rows="12" maxlength="200000" dir="auto"></textarea></div>
        <div class="col-12 k-faq"><label class="form-label" for="k-q">{{ __('Question') }}</label><input class="form-control" id="k-q" name="question" maxlength="1000" dir="auto"></div>
        <div class="col-12 k-faq"><label class="form-label" for="k-a">{{ __('Answer') }}</label><textarea class="form-control" id="k-a" name="answer" rows="5" maxlength="5000" dir="auto"></textarea></div>
    </div></div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">{{ __('Cancel') }}</button><button class="btn btn-primary" type="submit">{{ __('Add & train') }}</button></div>
</form></div></div>

<div class="modal fade" id="chunksModal" tabindex="-1" aria-labelledby="chunksTitle" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title fs-5" id="chunksTitle">{{ __('Chunks') }}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body" id="chunksBody"></div>
</div></div></div>
@endsection
@push('scripts')<script src="/js/pages/knowledge-base.js?v={{ config('app.asset_version') }}"></script>@endpush
