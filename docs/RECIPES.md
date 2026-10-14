# Integration recipes: website forms, Zapier, Make, your own systems

Three ways data moves between LeadFlow and the outside world, in order of preference:

| Need | Use | Credential |
|---|---|---|
| A form on your website / landing page should create leads | **Form endpoint** (`/webhooks/forms/{key}`) | per-form key, origin-restricted, no login |
| Another system should create or read leads, tasks, notes | **REST API** with a bearer token | personal API token (`Profile → API tokens`, permission `api.tokens`) |
| LeadFlow should push events to Zapier / Make / your backend | **Automation rule → `call_webhook`** | optional HMAC secret you choose |

Never put a personal API token in a public web page. Form endpoints exist so the only thing a browser ever holds is a key that can do one thing: create leads for one business.

## 1. Website / landing-page forms

Integrations → *Website & landing-page forms* → **New form endpoint**. Set the allowed origins (`https://www.example.com`), optionally a default assignee, a campaign to attribute to, tags, and a thank-you page for plain HTML posts. The embed modal gives a copy-paste HTML form and a `fetch` example.

Endpoint: `POST https://crm.example.com/webhooks/forms/<40-char key>` — JSON (`Content-Type: application/json`) or form-encoded. CORS preflight is answered for allowed origins.

Accepted fields (case-insensitive; `-`/spaces become `_`; common aliases are mapped — `full_name`, `mobile`, `tel`, `notes`, `product`, `company_name`, …):

```
name, phone, whatsapp, email, city, company, country (ISO-2, for phone parsing)
message, interest, budget, location, preferred_contact (phone|whatsapp|email)
consent, consent_whatsapp, consent_phone, consent_email   (1/true/yes/on)
utm_source, utm_medium, utm_campaign, utm_content, utm_term, fbclid, gclid, page_url, referrer
website   ← honeypot: must stay EMPTY; a filled value is dropped silently with HTTP 200
```

Behaviour:
- Phone/WhatsApp (or email, if the business allows it in Settings → *Required identifier*) is required and validated for the business's default country; failures return `422 {"ok":false,"error":{"code":"validation","fields":{"phone":"…"}}}`.
- `201 {"ok":true,"status":"received"}` on a new lead; `200` when the same person submitted within 10 minutes (double-click) or already has an open lead — the new message is logged on that lead's timeline instead of creating a duplicate. Lead ids are never returned to the public site.
- Attribution: `utm_campaign` is matched to an existing campaign by name (no campaign is created from public input); `fbclid` → platform facebook, `gclid` → google, `utm_source` instagram/facebook recognised; `page_url`, `referrer`, `utm_content/term` are kept as referral metadata. `utm_medium` defaults to `web_form`.
- Consent checkboxes create consent records with source `web_form` and the page URL as evidence. Without a checkbox, nothing is assumed.
- Throttle: 20 submissions per minute per IP per endpoint; `403` from a non-allowed origin; `404` for unknown, rotated or inactive keys.
- Raw submissions are kept 90 days for diagnostics (`leadflow.web_form_submission_retention_days`), visible in the embed modal; the lead is the durable record.

Page builders (WordPress forms, Webflow, Elementor, Framer, Shopify apps): add the endpoint as the form's *webhook / POST URL*. Name your fields as above or rely on the aliases. Include a hidden `page_url` field if the builder does not send the page URL.

## 2. Zapier (inbound and outbound)

**Inbound — e.g. Google Sheets row / Typeform / Facebook Lead Ads via Zapier → LeadFlow lead.** Use the *Webhooks by Zapier* action, **POST**, Payload type JSON:

```
URL:      https://crm.example.com/api/v1/leads
Headers:  Authorization: Bearer lf_…      Accept: application/json
Data:
  contact__name      {{name}}
  contact__phone     {{phone}}
  contact__email     {{email}}
  lead_source_id     7               ← GET /api/v1/lookups → sources
  product_interest   {{product}}
  note               {{message}}
  attribution__platform   facebook
  attribution__campaign_id 12         ← optional, from lookups.campaigns
  consents__whatsapp true
```

Zapier turns `a__b` into nested JSON `{"a":{"b":…}}`. A `409 duplicate_open_lead` means the contact already has an open lead; add a Filter step or a *Paths* branch that calls `POST /api/v1/leads/{existing_lead_id}/activities` with the message instead (the id is in `error.details.existing_lead_id` when the token's user can see that lead). Use the token of a user whose visibility matches what the Zap should be allowed to do; the token inherits that user's permissions exactly.

**Outbound — LeadFlow event → Zapier.** Create a Zap with *Webhooks by Zapier → Catch Hook*, copy its URL, then Automation → New rule → trigger (e.g. *Lead became qualified*) → action **Call a webhook** with that URL. Zapier does not verify HMAC, so leave the secret empty or verify in a Code step:

```js
// Zapier Code by Zapier (JavaScript) — inputData.body is the raw body, inputData.* the headers
const crypto = require('crypto');
const expected = 'sha256=' + crypto.createHmac('sha256', process.env.LF_SECRET).update(inputData.ts + '.' + inputData.body).digest('hex');
if (!crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(inputData.sig))) throw new Error('bad signature');
if (Math.abs(Date.now() / 1000 - Number(inputData.ts)) > 300) throw new Error('stale');
return JSON.parse(inputData.body);
```

The payload is `{"event":"automation.<trigger>","sent_at":…,"rule":{id,name},"trigger","execution_id","context":{…},"lead":{…same shape as GET /api/v1/leads/{id}…},"organization":{id,name},"url"}`. `X-LeadFlow-Delivery` is unique per attempt — dedupe on it (Zapier may receive retries on 5xx).

## 3. Make (Integromat)

**Inbound:** *HTTP → Make a request*: URL `https://crm.example.com/api/v1/leads`, method POST, headers `Authorization: Bearer lf_…` and `Accept: application/json`, Body type *Raw*, Content type *JSON*, request content:

```json
{"contact":{"name":"{{1.name}}","phone":"{{1.phone}}","email":"{{1.email}}"},
 "lead_source_id":7,"note":"{{1.message}}","attribution":{"platform":"facebook","source":"{{1.utm_source}}","medium":"{{1.utm_medium}}"},
 "consents":{"whatsapp":true}}
```

Enable *Parse response* and route on `ok`/status: 201 created, 409 duplicate (branch to `POST /api/v1/leads/{{error.details.existing_lead_id}}/activities`), 422 validation (log it).

**Outbound:** *Webhooks → Custom webhook* gives a URL; use it in a `call_webhook` action. Make can verify the signature with a *Crypto → HMAC* module: input `{{ts}}.{{body}}` (use *Get raw body* in the webhook settings), key = your secret, compare to the `x-leadflow-signature` header after `sha256=`.

## 4. Your own backend (PHP / Node)

Inbound: any HTTP client with the bearer token; the API is documented in `API.md`. Outbound verification:

```php
// PHP (Laravel route or plain)
$ts = $_SERVER['HTTP_X_LEADFLOW_TIMESTAMP'] ?? ''; $sig = $_SERVER['HTTP_X_LEADFLOW_SIGNATURE'] ?? '';
$body = file_get_contents('php://input');
$ok = hash_equals('sha256='.hash_hmac('sha256', $ts.'.'.$body, $secret), $sig) && abs(time() - (int) $ts) < 300;
if (! $ok) { http_response_code(401); exit; }
$event = json_decode($body, true);      // $event['event'], $event['lead']['id'], …
// respond 2xx quickly; do slow work asynchronously
```

```js
// Node (Express, with express.raw({ type: 'application/json' }))
const crypto = require('node:crypto');
app.post('/leadflow', (req, res) => {
  const ts = req.get('x-leadflow-timestamp'), sig = req.get('x-leadflow-signature') || '';
  const expected = 'sha256=' + crypto.createHmac('sha256', process.env.LF_SECRET).update(`${ts}.${req.body}`).digest('hex');
  if (sig.length !== expected.length || !crypto.timingSafeEqual(Buffer.from(sig), Buffer.from(expected)) || Math.abs(Date.now()/1000 - Number(ts)) > 300) return res.sendStatus(401);
  const event = JSON.parse(req.body); res.sendStatus(204); /* then process event */
});
```

Webhook URLs must be public HTTPS (private/loopback addresses are rejected). Delivery is attempted up to 3 times on 5xx/connection errors, never on 4xx; failures show in the rule's execution history.

## 5. What is deliberately not offered

- No "Zapier app" listing or OAuth app: the bearer token + the generic Webhooks modules cover every Zap without a Zapier partner account.
- No public endpoint that returns lead data without authentication, and no form endpoint that updates existing leads' fields (it only appends inquiries) — a leaked form key cannot read or alter your CRM.
- `utm_campaign` never creates campaigns; names from public input would pollute campaign reporting.
