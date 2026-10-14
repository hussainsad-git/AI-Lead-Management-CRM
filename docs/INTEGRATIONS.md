# Integrations setup

All credentials are entered in **Integrations** per business, encrypted with `APP_KEY`, and never returned to the browser (only "set" + last-4 hint). A connection shows **Connected** only after **Test connection** succeeds; failures show the provider's error and time.

## WhatsApp Business Platform (Cloud API) — built

1. In Meta Business Suite: a Business portfolio (verify it for higher limits), a WhatsApp Business Account, and a phone number registered on the Cloud API.
2. In developers.facebook.com: an app with the WhatsApp product. Create a **system user** with `whatsapp_business_messaging` and `whatsapp_business_management` on the WABA and generate a **permanent token**.
3. In LeadFlow → Integrations → WhatsApp: enter WABA ID, Phone number ID, token, **App secret** (App settings → Basic), API version (default v24.0). Save, then **Test connection** (reads the number's display name and quality rating).
4. Copy the **Callback URL** and **Verify token** into the app's WhatsApp → Configuration → Webhook and subscribe to the **messages** field. Meta's verification GET must succeed.
5. **Sync templates** to send approved templates outside the 24-hour window.
6. A **queue worker must be running** — inbound messages and AI replies are processed on the queue.

Requirements for production: public HTTPS URL, valid certificate, the endpoint not behind authentication/WAF challenges.

## Website & landing-page forms, Zapier, Make — built

Form endpoints (`Integrations → Website & landing-page forms`) accept POSTs from your own site with an origin allow-list, honeypot and per-IP throttle, and create leads with UTM/fbclid attribution and consent records. Zapier/Make connect through the REST API with a personal bearer token (inbound) and the automation `call_webhook` action (outbound, HMAC-signed). Field names, payloads and verification snippets: `RECIPES.md`.

## OpenAI — built

API key (a project key is recommended). Use **AI Assistant → Load models** to pick a chat model your account has. Embeddings (`text-embedding-3-small`) are used automatically for the knowledge base; after first connecting OpenAI, use *Re-process* on existing documents to add vectors.

## DeepSeek — built

API key from platform.deepseek.com. Default model `deepseek-v4-flash`. No embeddings: pair with OpenAI if you want semantic search.

## Meta Marketing API (campaigns, spend) & Lead Ads (Instant Forms) — built

**What it does.** Hourly (`meta:sync`) and on "Sync now": pulls campaigns, ad sets and ads of one ad account (keyed by Meta ids; a manually entered campaign with the same name is linked, not duplicated), daily campaign-level spend (`campaign_spend.data_source = meta_api`, in the ad account currency) and Meta-reported aggregate actions (`campaign_events`: messaging conversations started, link clicks, form leads — counts only, never people). Where an API row and a manual row exist for the same campaign and day, the API row wins (`CampaignSpend::effective()`), so switching from manual entry never double-counts. Spend in a currency other than the organization's is stored but excluded from CPL/CAC, and the card says so.

**Lead Ads.** With a Page ID and "Create CRM leads from Instant Form submissions" enabled, each `leadgen` webhook (or the hourly backfill through `/{form_id}/leads`, which recovers missed deliveries) reads the submission from `GET /{leadgen_id}` and creates a lead: standard fields → contact (name, phone, email, city, company), custom questions and consent checkboxes → source notes, full attribution (campaign/ad set/ad ids and names, platform fb/ig, form id). The lead's `created_at` is the submission time. Exactly-once per leadgen id (`lead_form_submissions` unique key + `leads.external_lead_id`). A repeat submission by someone with an open lead is attached to that lead as a `form_submitted` timeline event. A submission without a usable phone (or email, when the organization allows email-only identifiers) is recorded as `no_contact`/`failed` with the reason — **no contact is ever fabricated**.

**Setup (Meta side).**
1. Business Manager → System Users → create a system user, assign the ad account (and the Page, for Lead Ads) to it, generate a token with `ads_read` (+ `leads_retrieval`, `pages_show_list`, `pages_read_engagement`, `pages_manage_ads`, `pages_manage_metadata` for Lead Ads). If the system user cannot be given the Page, paste a Page access token separately.
2. Meta App → App settings → copy the **app secret** (webhook signatures).
3. Save on *Integrations → Meta Marketing API & Lead Ads*, then **Test connection** — it reads `/me`, `/act_{id}` and the Page; only then is the card "Connected".
4. Lead Ads: Meta App → Webhooks → **Page** → paste the callback URL and verify token shown on the card, subscribe to `leadgen`; then click **Subscribe Page to leadgen webhook** (POST `/{page_id}/subscribed_apps`). Use the Lead Ads Testing Tool to send a test lead.
5. `leads_retrieval` needs Advanced Access (App Review) for Pages your app does not own; your own Pages work with Standard Access while the app is in development mode only for app roles — switch the app to Live for production.

**Endpoints used.** `GET /me`, `GET /act_{id}`, `GET /act_{id}/campaigns|adsets|ads`, `GET /act_{id}/insights?level=campaign&time_increment=1`, `GET /{page_id}/leadgen_forms`, `GET /{form_id}/leads?filtering=[time_created ≥ …]`, `GET /{leadgen_id}`, `POST /{page_id}/subscribed_apps`. Errors are classified (190 expired token, 10/2xx permission, 4/17/32/613/8000x rate limit); auth errors mark the integration *Error* and notify admins once per hour; transient errors are retried by the queue with backoff.

**Custom Audiences.** Needs `ads_management` on the same token and the Custom Audience terms accepted for the ad account (Ads Manager → Audiences). See docs/AUDIENCES.md for what is uploaded (hashed identifiers only) and how.

**Honesty rules.** Nothing is marked connected without a successful authenticated call. Spend shows its currency, range and last sync time. Attribution snapshots keep names as they were when the lead arrived. Anonymous clicks and conversation starts stay aggregate (`campaign_events`), separate from identified contacts.
