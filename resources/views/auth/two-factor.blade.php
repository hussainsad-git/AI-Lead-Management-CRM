<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Two-factor code · LeadFlow CRM</title>
    <link rel="stylesheet" href="/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/css/app.css?v={{ config('app.asset_version') }}">
</head>
<body>
<div class="login-wrap">
    <div class="login-card">
        <div class="text-center text-white mb-4">
            <div class="fs-3 fw-bold"><i class="bi bi-funnel-fill text-info"></i> LeadFlow</div>
        </div>
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h5 mb-1">Two-factor code</h1>
                <p class="small text-muted">Enter the 6-digit code from your authenticator app, or one of your recovery codes.</p>
                <form method="post" action="/login/2fa" novalidate>
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="code">Code</label>
                        <input class="form-control form-control-lg text-center @error('code') is-invalid @enderror" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="20" required autofocus>
                        @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <button class="btn btn-primary w-100" type="submit">Verify</button>
                </form>
                <form method="post" action="/login/2fa/cancel" class="mt-2 text-center"><button class="btn btn-link btn-sm" type="submit">Use a different account</button></form>
            </div>
        </div>
        <p class="text-center small text-muted mt-3">Lost your device? Ask your administrator to reset two-factor authentication for your account.</p>
    </div>
</div>
</body>
</html>
