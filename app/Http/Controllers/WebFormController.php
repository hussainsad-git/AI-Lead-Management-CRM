<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainRuleException;
use App\Models\WebForm;
use App\Services\WebForms\WebFormCapture;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Public capture endpoint: POST /webhooks/forms/{key} (JSON or form-encoded). No session, no CSRF, origin allow-list,
 * honeypot, per-IP throttle. Responds JSON unless the form has a redirect URL and the client posted a plain HTML form.
 */
class WebFormController extends Controller
{
    private function form(string $key): WebForm
    {
        $f = WebForm::withoutGlobalScopes()->with('organization')->where('key', $key)->first();
        abort_unless($f && $f->is_active, 404);

        return $f;
    }

    public function options(Request $request, string $key)
    {
        $f = $this->form($key);
        $origin = $f->corsOrigin($request->headers->get('Origin'));
        abort_unless($origin, 403);

        return response('', 204)->withHeaders($this->cors($origin));
    }

    public function submit(Request $request, string $key, WebFormCapture $capture)
    {
        $f = $this->form($key);
        $origin = $request->headers->get('Origin');
        $referer = $request->headers->get('Referer');
        if (! $f->allowsOrigin($origin, $referer)) {
            return $this->respond($request, $f, null, ['ok' => false, 'error' => ['code' => 'origin_not_allowed', 'message' => 'This form does not accept submissions from this site.']], 403);
        }
        $corsOrigin = $f->corsOrigin($origin);

        [$fields, $honeypot] = $capture->normalise($request->isJson() ? (array) $request->json()->all() : $request->except(['_token']));
        $ipHash = hash('sha256', $request->ip().'|'.$f->key);
        if ($honeypot) {
            // Pretend success: a bot gets no signal, nothing is stored on a lead.
            return $this->respond($request, $f, $corsOrigin, ['ok' => true, 'status' => 'received'], 200);
        }
        if ($fields === []) {
            return $this->respond($request, $f, $corsOrigin, ['ok' => false, 'error' => ['code' => 'empty', 'message' => 'No fields submitted.']], 422);
        }

        try {
            $r = $capture->capture($f, $fields, $ipHash);
        } catch (ValidationException $e) {
            $errors = [];
            foreach ($e->errors() as $k => $m) {
                $errors[str_replace('contact.', '', $k)] = $m[0];
            }

            return $this->respond($request, $f, $corsOrigin, ['ok' => false, 'error' => ['code' => 'validation', 'message' => reset($errors), 'fields' => $errors]], 422);
        } catch (DomainRuleException $e) {
            return $this->respond($request, $f, $corsOrigin, ['ok' => false, 'error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]], 422);
        }

        // Never expose internal lead ids to the public site.
        return $this->respond($request, $f, $corsOrigin, ['ok' => true, 'status' => $r['status'] === 'duplicate' ? 'received' : 'received'], $r['status'] === 'created' ? 201 : 200);
    }

    private function respond(Request $request, WebForm $f, ?string $corsOrigin, array $body, int $status)
    {
        $wantsHtml = ! $request->expectsJson() && ! $request->isJson() && $f->redirect_url && str_contains((string) $request->headers->get('Accept', ''), 'text/html');
        if ($wantsHtml && $f->allowsOrigin($f->redirect_url, null) && $status < 400) {
            return redirect()->away($f->redirect_url, 303);
        }
        $res = response()->json($body, $status);
        if ($corsOrigin) {
            $res->withHeaders($this->cors($corsOrigin));
        }

        return $res;
    }

    private function cors(string $origin): array
    {
        return ['Access-Control-Allow-Origin' => $origin, 'Access-Control-Allow-Methods' => 'POST, OPTIONS', 'Access-Control-Allow-Headers' => 'Content-Type, Accept', 'Access-Control-Max-Age' => '600', 'Vary' => 'Origin'];
    }
}
