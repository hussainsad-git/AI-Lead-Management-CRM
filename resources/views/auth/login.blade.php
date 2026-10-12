<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · LeadFlow CRM</title>
    <link rel="stylesheet" href="/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/css/app.css?v={{ config('app.asset_version') }}">
</head>
<body>
<div class="login-wrap">
    <div class="login-card">
        <div class="text-center text-white mb-4">
            <div class="fs-3 fw-bold"><i class="bi bi-funnel-fill text-info"></i> LeadFlow</div>
            <div class="small opacity-75">Lead management for WhatsApp &amp; Meta Ads teams</div>
        </div>
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h5 mb-3">Sign in</h1>
                <form method="post" action="/login" novalidate>
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="email">Email</label>
                        <input class="form-control @error('email') is-invalid @enderror" id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password">Password</label>
                        <input class="form-control" id="password" type="password" name="password" autocomplete="current-password" required>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="remember" id="remember" value="1">
                        <label class="form-check-label" for="remember">Keep me signed in on this device</label>
                    </div>
                    <button class="btn btn-primary w-100" type="submit">Sign in</button>
                </form>
            </div>
        </div>
        <p class="text-center small text-muted mt-3">Access is managed by your organization administrator.</p>
    </div>
</div>
</body>
</html>
