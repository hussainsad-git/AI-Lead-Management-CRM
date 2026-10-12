# Integrations — what Phase 4 will need

Nothing in the current build talks to Meta or WhatsApp. The Integrations page reports every connection as *Not configured*. These are the business assets and approvals to prepare now (names per Meta's developer platform; confirm against current Meta docs when Phase 4 starts, as permissions and API versions change):

## WhatsApp Business Platform (Cloud API)
- Meta Business portfolio (business verification raises messaging limits).
- WhatsApp Business Account with a phone number registered to the Cloud API (a number used in the WhatsApp Business *app* must be migrated or a new one used).
- Meta app with WhatsApp product; permissions `whatsapp_business_messaging`, `whatsapp_business_management`.
- A **system-user** access token (not a personal token) scoped to the WABA.
- Public HTTPS webhook URL; LeadFlow will verify the subscription handshake with a verify token and authenticate every POST with the app-secret `X-Hub-Signature-256` HMAC.
- Approved message templates for any message outside the 24-hour customer-service window.

What it will (and won't) give us: inbound messages carry the sender's WhatsApp number and, for click-to-WhatsApp ad conversations, referral data (ad/source ids) on the first message. A person who taps the ad but never sends a message produces **no contact data** — those clicks stay as anonymous aggregate counts from Ads insights.

## Meta Marketing API
- Ad account access in Business Manager for the system user.
- App permission `ads_read` (insights, campaigns, ad sets, ads); `ads_management` only if Custom Audience upload is enabled (Phase 5).
- Spend will be imported per campaign per day with currency and sync timestamp; manual spend for the same campaigns should then stop.

## Meta Lead Ads (Instant Forms)
- Page access with `leads_retrieval`, `pages_show_list`, `pages_manage_metadata`; subscribe the Page to the `leadgen` webhook.
- Leads will be ingested idempotently by leadgen id (already enforced by the unique `(organization_id, external_lead_id)` key).

## Custom Audiences (Phase 5)
Customer-list uploads require accepting Meta's Custom Audience terms, consent appropriate to your jurisdiction, SHA-256 hashing of normalized identifiers before upload, and exclusion of opted-out/suppressed contacts. Match rates and minimum audience sizes are decided by Meta and cannot be guaranteed.
