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

## OpenAI — built

API key (a project key is recommended). Use **AI Assistant → Load models** to pick a chat model your account has. Embeddings (`text-embedding-3-small`) are used automatically for the knowledge base; after first connecting OpenAI, use *Re-process* on existing documents to add vectors.

## DeepSeek — built

API key from platform.deepseek.com. Default model `deepseek-v4-flash`. No embeddings: pair with OpenAI if you want semantic search.

## Meta Marketing API (campaign & spend sync) / Lead Ads — not built yet

Planned: `ads_read` for campaigns, ad sets, ads and daily spend; `leads_retrieval` + `leadgen` webhook for Instant Forms (leads will be keyed by leadgen id; the unique `(organization_id, external_lead_id)` index already guarantees idempotency). Until then, enter campaigns and daily spend manually; click-to-WhatsApp referrals link to campaigns automatically when the ad's ID is recorded on an ad under the campaign.
