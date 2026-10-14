# Audience segments & retargeting (Phase 5)

## Segments
A segment is a saved query over leads + contacts: `{match: all|any, groups: [{match: all|any, rules: [{field, op, value}]}]}` — groups combine with the outer match, rules inside a group with the inner one (two-level AND/OR). Fields and operators are in `config/audiences.php` (`SegmentQuery` turns them into one SQL query; `SegmentValidator` checks types and that referenced campaign/tag ids belong to the organization). Twelve suggested segments are seeded per business (`DefaultSegments`, idempotent by `template_key`), including a **Do Not Contact exclusions** list that is attached to every seeded audience.

**Manual additions.** The Leads list bulk action *Add to audience* (needs `audiences.manage`) records the selected leads in `audience_manual_members`; they count as matching the segment regardless of its rules (`manual OR (rule groups)`), but eligibility is still applied, so an opted-out person added by hand is shown as "in segment, not eligible" and never exported or uploaded.

Two kinds: **audience** (exportable/uploadable) and **exclusion** (only ever subtracted from audiences; never uploaded).

## Eligibility — what may leave the CRM
`AudienceService::refresh()` evaluates the query and writes a membership snapshot (`audience_segment_memberships`: one row per lead, `eligible` + `ineligible_reason`, `removed_at` for leavers; joins/leaves appear on the lead timeline). For audience segments every member is checked, in this order:

1. **suppressed** — contact is Do Not Contact or has an active suppression on `all` / `ads_audience`. Cannot be disabled.
2. **consent** — segment policy `not_withdrawn` (default: excluded only if the latest `ads_audience` consent record is *withdrawn*) or `granted` (included only with a recorded *granted* `ads_audience` consent).
3. **excluded** — contact matches an attached exclusion segment.
4. **no_identifier** — no E.164 phone/WhatsApp and no email.

Exports and uploads use `eligibleContacts()`: eligible rows de-duplicated by contact (one person with three leads is one row). Previews mask identifiers. Members-vs-eligible and the per-reason breakdown are shown everywhere so nobody mistakes a CRM count for an audience size.

## CSV export
`GET audiences/{id}/export[?hashed=1]` — needs `audiences.manage` **and** `leads.export`; refreshes the segment first, records an `audience_sync_jobs` row (`csv_export`) and an audit entry with the row count. Plain CSV carries lead id, name, phone, WhatsApp, email, city, country (formula-neutralised); `hashed=1` gives a Meta-ready customer file with SHA-256 `phone,email,country` only.

## Meta Custom Audiences
Requires the Meta Marketing API integration connected with a token that has `ads_management`, and the ad account must have accepted the Custom Audience terms (Graph errors 2654/2655 are explained in the job error). Flow (`MetaAudienceSync`, queued as `SyncAudienceToMeta`, one job per segment at a time):

1. Create the audience once: `POST /act_{id}/customaudiences` (`subtype=CUSTOM`, `customer_file_source=USER_PROVIDED_ONLY`); the id is stored on the segment. Deleting a segment never deletes the Meta audience.
2. Refresh the segment; record members/eligible/skipped on the job.
3. Hash rows server-side: phone = digits of E.164 (no `+`) → SHA-256; email = trimmed lowercase → SHA-256; country = 2-letter lowercase → SHA-256. Names are not sent.
4. Upload with the documented **replace** session (`POST /{audience_id}/usersreplace`, batches of 5,000, `last_batch_flag` on the final batch) so each sync is a full refresh and opt-outs since the last sync fall out of the audience.
5. Job → `success` with `uploaded`; the requester is notified. Failures are stored on the job with Meta's message and notify admins; nothing is marked synced.

The Retargeting page shows, per linked audience, Meta's own `approximate_count_lower/upper_bound`, delivery and operation status read live from `GET /{audience_id}` — the CRM never estimates match rates. `audiences:sync` (daily 03:10) refreshes every segment and queues a replace for segments with **auto-sync** on.

## Endpoints
`GET audiences/meta` (builder catalogue), `GET/POST audiences`, `GET/PATCH/DELETE audiences/{id}`, `POST audiences/estimate`, `POST audiences/{id}/refresh`, `GET audiences/{id}/export`, `POST audiences/{id}/sync` (202, queued), `GET audiences/jobs`, `GET audiences/meta-status`. All need `audiences.manage`; export also needs `leads.export`.
