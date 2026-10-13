<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Lead;
use App\Services\LeadVisibility;
use Illuminate\Http\Request;

/** Renders page shells; all data is loaded through the permission-checked JSON API. */
class PageController extends Controller
{
    public function home(Request $request)
    {
        if (! $request->user()->hasPermission('dashboard.view')) {
            return redirect($this->firstAllowedPath($request) ?? '/profile');
        }

        return $this->page($request, 'dashboard');
    }

    public function show(Request $request, string $key)
    {
        return $this->page($request, $key);
    }

    public function lead(Request $request, Lead $lead, LeadVisibility $visibility)
    {
        abort_unless($visibility->canSee($request->user(), $lead), 404);

        return $this->page($request, 'leads', 'lead-detail', ['bodyData' => ['lead-id' => $lead->id]]);
    }

    public function contact(Request $request, Contact $contact)
    {
        return $this->page($request, 'contacts', 'contact-detail', ['bodyData' => ['contact-id' => $contact->id]]);
    }

    public function profile(Request $request)
    {
        return view('pages.profile', ['nav' => $this->nav($request), 'active' => null]);
    }

    public function logo(Request $request)
    {
        $path = $request->user()->organization->logo_path;
        abort_unless($path && \Illuminate\Support\Facades\Storage::disk('local')->exists($path), 404);

        return response()->file(\Illuminate\Support\Facades\Storage::disk('local')->path($path), ['Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Organization brand colour as a stylesheet (CSP forbids inline styles). */
    public function theme(Request $request)
    {
        $c = $request->user()->organization->brand_color;
        $css = '';
        if ($c && preg_match('/^#([0-9a-fA-F]{6})$/', $c, $m)) {
            [$r, $g, $b] = array_map('hexdec', str_split($m[1], 2));
            $dark = sprintf('#%02x%02x%02x', (int) ($r * .85), (int) ($g * .85), (int) ($b * .85));
            $css = ":root{--lf-accent:$c;--lf-accent-rgb:$r, $g, $b;--lf-accent-dark:$dark}";
        }

        return response($css, 200, ['Content-Type' => 'text/css; charset=UTF-8', 'Cache-Control' => 'private, max-age=300']);
    }

    public function locale(Request $request)
    {
        $data = $request->validate(['locale' => ['required', 'in:'.implode(',', array_keys(config('locales')))]]);
        $request->user()->forceFill(['locale' => $data['locale']])->save();

        return back();
    }

    public function admin(Request $request)
    {
        abort_unless($request->user()->is_platform_admin, 403);

        return view('pages.admin', ['nav' => $this->nav($request), 'active' => 'admin', 'item' => ['admin', 'Platform Admin', 'buildings', '/admin', [], null]]);
    }

    private function page(Request $request, string $key, ?string $view = null, array $data = [])
    {
        $item = collect(config('navigation'))->firstWhere(0, $key);
        abort_unless($item, 404);
        abort_unless($request->user()->hasAnyPermission(...$item[4]), 403);
        $view ??= $item[5] ? 'planned' : $key;

        return view('pages.'.$view, $data + ['nav' => $this->nav($request), 'active' => $key, 'item' => $item]);
    }

    private function nav(Request $request): array
    {
        return collect(config('navigation'))->filter(fn ($i) => $request->user()->hasAnyPermission(...$i[4]))->values()->all();
    }

    private function firstAllowedPath(Request $request): ?string
    {
        return $this->nav($request)[0][3] ?? null;
    }
}
