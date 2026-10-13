# AI assistant & WhatsApp — how it works

## Message flow

```
Customer → WhatsApp → Meta Cloud API ──POST (signed)──▶ /webhooks/whatsapp/{key}
   1. verify X-Hub-Signature-256 with the business's app secret (401 if wrong)
   2. store raw event once (sha256 of body = idempotency key) → 200 to Meta immediately
   3. queue: ProcessWhatsAppWebhook
        • phone_number_id must match this business's number (never crosses tenants)
        • contact: by wa_id identity → exact phone match → new contact (WhatsApp profile name only)
        • message keyed by Meta's wamid (duplicates are no-ops)
        • lead: reuse the open lead, else create one (source = Facebook/Instagram Ads when the
          message carries a click-to-WhatsApp referral, else WhatsApp) with round-robin owner
        • referral → first/last-touch attribution (ad id, headline, ctwa_clid)
        • "STOP" / "unsubscribe" / "band karo" … → WhatsApp opt-out, nothing is sent
        • notify owner (deduplicated) · rescore lead
   4. queue (debounced 4 s, latest message only): RespondToConversation
        handoff keyword? → hand off to a human
        instant auto-reply rule (first message / keyword / after hours, with cooldown)
        AI mode: off | suggest (draft shown to agent) | auto (send, or hand off when unsure)
        lead qualification (extracts only what the customer stated; fills blanks only)
Status webhooks (sent/delivered/read/failed) update outbound messages; never move backwards.
```

Outbound statuses shown in the inbox: `queued` (stored locally) → `accepted` (Meta returned a message id) → `sent` → `delivered` → `read`, or `failed` with Meta's reason. Only Meta's status webhooks set sent/delivered/read.

## Guardrails

| Risk | Control |
|---|---|
| Invented prices, dates, availability | "Answer only from the knowledge base" (default on). The model must return `{reply, answerable, needs_human, off_topic, language}`; unanswerable → hand off with your handoff message. |
| Unparseable / malformed model output | Never sent; conversation is handed to a human. |
| General-purpose chatbot use (barred by WhatsApp Business terms since 15 Jan 2026) | Fixed rule in every prompt: business topics only; off-topic requests are declined. |
| Runaway auto-replies | Per-conversation cap (default 8), debounce, single reply per customer burst, AI pauses after a handoff until an agent clicks "Mark handled". |
| Sensitive data | Prompt forbids asking for passwords, card/ID numbers, medical history; healthcare preset forbids medical advice. |
| Messaging outside policy | 24-hour customer-service window enforced; outside it only approved templates can be sent. Opt-outs block all WhatsApp sends. |
| Learning bad answers | Nothing is learned automatically. Agents approve or correct answers; approved Q&A becomes a knowledge-base entry. |

## Knowledge base ("training")

There is no model fine-tuning. "Training" means retrieval-augmented generation over your content:

* Upload PDF (text-based), DOCX, TXT, MD, CSV, HTML, JSON, or paste text / FAQs. Files are split into ~900-character overlapping chunks.
* Retrieval is hybrid: BM25-style keyword scoring (works in English, Urdu script, Roman Urdu and Arabic) fused with OpenAI embedding similarity (`text-embedding-3-small`) when OpenAI is connected. DeepSeek has no embeddings API, so DeepSeek-only setups use keyword retrieval.
* Vectors are scored in PHP (fine up to ~8k chunks per business). Beyond that, move vectors to a vector store (pgvector, Qdrant) — the `KnowledgeBase::search` method is the single seam to change.
* Scanned PDFs need OCR before upload.

## Continuous learning

Sources for the **Learning queue** (AI Assistant → Learning queue):

1. Questions the AI handed off (not in the knowledge base).
2. AI drafts that an agent edited before sending (stored as "corrected").
3. AI messages marked 👎.

Approving one stores `Q: … / A: …` as a *Learned* knowledge-base entry; the next similar question retrieves it. 👍 / sending a draft unchanged records approval for reporting.

## Lead scoring (transparent)

Score = 20 + matching factors, clamped 0–100. Hot ≥ 60, Warm ≥ 30, Cold < 30; *Needs review* when there is no interaction signal yet. Default weights (editable in Settings → Business):

| Factor | Points |
|---|---|
| Messaged in the last 48 h | +15 |
| Marked Interested or further, or clearly asked to buy/book (AI-extracted) | +25 |
| Some interest in conversation | +10 |
| Budget stated | +15 |
| Callback requested (pending) | +15 |
| Upcoming appointment | +20 |
| 3+ customer messages | +10 |
| No meaningful interaction > 14 days / > 30 days | −15 / −25 |
| 3+ / 5+ unanswered calls in a row | −10 / −20 |

No demographic or protected characteristics are used, and an ad click alone adds nothing. Scores refresh on every message and hourly (`leads:rescore`).

## Providers

| | OpenAI | DeepSeek |
|---|---|---|
| Endpoint | `https://api.openai.com/v1/chat/completions` | `https://api.deepseek.com/chat/completions` |
| Model | choose with **Load models** (reads your account's list) | `deepseek-v4-flash` default (`deepseek-chat`/`deepseek-reasoner` were retired 24 Jul 2026) |
| JSON output | `response_format: json_object` | `response_format: json_object` |
| Embeddings | yes | no |

Any OpenAI-compatible gateway can be used by setting the integration's base URL.
