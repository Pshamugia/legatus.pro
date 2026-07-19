# Legatus — AI Sales Employee

> **Legatus** means *envoy, ambassador, or messenger* in Latin. It is the trusted link between a business and every customer who sends a message.

In Georgia, many small online businesses sell through Instagram and Messenger conversations. The same person sources products, packs orders, publishes content, and answers every customer by hand. When that owner is busy or asleep, a simple unanswered question — “How much is it?”, “Is it in stock?”, “Can it arrive tomorrow?” — can become a lost sale.

Legatus gives that business an always-available AI sales employee. It learns from the business’s catalogue and policies, recommends suitable products, checks commercial facts through approved server tools, qualifies opportunities, and knows when a human should take over.

This is a standalone OpenAI Build Week project, designed as an independent SaaS product.

## What Legatus does

### Agentic sales and personal shopping

- Uses the OpenAI Responses API with GPT‑5.6, strict function schemas, and structured output.
- Chooses among 11 server-side tools for product search, knowledge retrieval, shopping preferences, recommendations, comparisons, stock, delivery, lead capture, human handoff, pending reservations, and non-binding offers.
- Remembers the shopper’s budget, occasion, mood, likes, dislikes, and recipient inside the conversation.
- Ranks available products against hard budget and stock constraints, explains the fit, exposes trade-offs, and compares finalists.
- Preserves conversation history so a customer does not need to repeat context.

### Grounded business knowledge

- Imports product catalogues from CSV.
- Extracts business knowledge from PDF files and public website URLs.
- Detects structured product data from supported website pages.
- Chunks and embeds knowledge with `text-embedding-3-small`, with lexical retrieval as a fallback; semantic scoring uses a configurable bounded candidate pool (`LEGATUS_SEMANTIC_CANDIDATE_LIMIT`, default 2,000) rather than loading an unbounded tenant corpus.
- Normalizes UTF-8 BOMs, comma/semicolon/tab CSVs, localized prices, and supported JSON-LD offers/currencies.
- Refreshes each source atomically: a failed refresh rolls back to the last-known-good chunks/products instead of leaving partial data.
- Deduplicates catalogue records, deactivates products removed with their source, and records source status, freshness, and ingestion errors.
- Pins validated public-URL requests to the resolved public IP to close DNS-rebinding gaps in the SSRF boundary.
- Can automatically ingest the website supplied during onboarding and resynchronize configured sources.

### Human control and transparent AI

- Shows the sources, confidence, and tools behind an AI response.
- Requires a strict `factual_claims` ledger in every model response and verifies each customer-facing product, price, stock, delivery, policy, discount, reservation, offer, and budget claim against exact successful tool results. Text-level checks infer required tools independently of the model-selected intent, so changing an intent label cannot bypass verification.
- Applies server-side confidence and discount-approval guardrails; any missing, mismatched, or ungrounded fact fails closed to human handoff.
- Escalates uncertain, policy-sensitive, discount, or explicitly requested cases to a human.
- Creates a handoff reason, concise summary, and suggested operator reply.
- Provides an operator Inbox with take-over, reply, release-to-AI, and close workflows.
- Records model runs, tool traces, tokens, latency, status, and evaluation results.

### SaaS foundation

- Includes registration, login, organizations, team roles, settings, and tenant-scoped resources.
- Provides a real one-line embeddable website widget with agent-bound, expiring signed visitor tokens and persistent conversation history. Its public message, history, and feedback JSON routes are stateless and do not depend on browser cookies or a Laravel session.
- Synchronizes human Inbox replies back into the customer chat and protects retries with visitor-scoped UUID request IDs, a database uniqueness constraint, durable response snapshots, cache-assisted replay, and per-conversation locks. The same completed request can be replayed after a cache loss without creating another customer message or model run.
- Sends a per-request CSP nonce with every HTML response and permits inline scripts only when they carry that nonce; `script-src` does not enable `unsafe-inline`.
- Reports real conversation, automation, handoff, lead, influenced-value, recommendation, helpfulness, token, and latency metrics.
- Includes deterministic offline evaluations and optional live GPT‑5.6 evaluations.
- Ships with a Georgian-first, responsive demo experience and curated demo data.

The implemented customer channel is the web demo/widget. Instagram and Messenger webhook adapters are deliberately listed as future integrations; the current product does not claim a live Meta connection. Seeded rows labeled `simulated_instagram` or `simulated_messenger`, and runs labeled `simulated`, are fictional judge-facing examples and are excluded or identified separately in judge-facing metrics.

## Safety model

Legatus treats GPT‑5.6 as the reasoning and language layer, not the source of commercial truth:

- Price and product data must resolve through the verified catalogue tools.
- Explicit availability claims must be checked through the stock tool.
- Delivery windows are marked as indicative and calculated from each tenant's server-side timezone, cutoff, city, and business-day policy.
- Offers are calculated server-side and are explicitly non-binding.
- Reservations remain `pending`, carry a short expiry time, and require customer confirmation.
- Discounts outside the configured allowance are routed for human approval.
- Creating a lead from contact details requires explicit consent in the same customer message. The server verifies that the exact email/phone appeared there by comparing keyed HMAC contact fingerprints, links the lead to that message, and assigns a 90-day retention deadline.
- Email addresses and phone numbers are redacted before the customer message is first written to the database. Only the current raw input is passed ephemerally to the live orchestration request; historical context comes from redacted storage. Tool traces, handoff/context fields, and error text are also redacted, while consented lead contact fields remain only under the retention rule.
- Per-agent daily OpenAI run/token limits, bounded tool rounds, capped output tokens, timeouts, and retry limits constrain spend and activity.
- Payment and final order completion are outside the AI’s authority.
- Low-confidence or insufficiently grounded answers are escalated.
- Catalogue and website text are treated as untrusted data, never as instructions.

See [SECURITY.md](SECURITY.md) for the complete threat and control model.

## Architecture

```text
Customer web chat / widget
          │
          ▼
Laravel conversation service ───────────────► Human operator Inbox
          │                                      ▲
          ▼                                      │ handoff + summary
GPT‑5.6 Responses API                            │
          │                                      │
          ▼                                      │
Strict tool router ──► catalogue / stock / knowledge / delivery
          │            lead / offer / reservation / handoff
          ▼
Structured response + factual_claims + confidence + evidence
          │
          ▼
Run trace / analytics / evaluations
```

Laravel owns authentication, tenancy, products, knowledge, conversation state, tool execution, commercial constraints, and audit history. GPT‑5.6 selects approved actions and composes a schema-constrained response from their results.

## Local setup

Requirements:

- PHP 8.2 or later
- Composer 2
- PDO SQLite for the local demo
- PHP extensions required by Laravel, including `mbstring`, `openssl`, `tokenizer`, `xml`, `curl`, and `fileinfo`

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve
```

On Windows, replace `cp` with:

```powershell
Copy-Item .env.example .env
```

Open `http://127.0.0.1:8000`.

Seeded local-demo credentials:

```text
Email: demo@legatus.ai
Password: password
```

For a manual onboarding/import rehearsal, upload [`samples/demo-catalog.csv`](samples/demo-catalog.csv). The complete judging dataset can be restored idempotently with `php artisan legatus:bootstrap-demo-tenant`.

The fixed `password` credential is intended only for local/testing; never seed or expose it in an internet-accessible non-production environment. Under `APP_ENV=production`, the seeder generates a random password unless `LEGATUS_DEMO_PASSWORD` is deliberately supplied. Keep `LEGATUS_DEMO_LOGIN_ENABLED=false` for a normal production launch; if a public judging login is intentionally enabled, use a strong unique password and remove it after judging.

## OpenAI configuration

Store secrets only in the uncommitted `.env` file:

```env
OPENAI_API_KEY=your_private_key
OPENAI_MODEL=gpt-5.6-sol
OPENAI_MODERATION_MODEL=omni-moderation-latest
OPENAI_EMBEDDING_MODEL=text-embedding-3-small
OPENAI_TIMEOUT=45
OPENAI_CONNECT_TIMEOUT=10
OPENAI_RETRIES=2
OPENAI_MAX_TOOL_ROUNDS=4
OPENAI_MAX_OUTPUT_TOKENS=900
OPENAI_REASONING_EFFORT=low

LEGATUS_OFFLINE_FALLBACK=true
LEGATUS_DAILY_AI_RUN_LIMIT=200
LEGATUS_DAILY_AI_TOKEN_LIMIT=250000
LEGATUS_SEMANTIC_SIMILARITY_THRESHOLD=0.35
LEGATUS_SEMANTIC_CANDIDATE_LIMIT=2000
LEGATUS_WIDGET_FRAME_ANCESTORS=*
```

The deterministic fallback is a local-development and offline-evaluation aid. It runs only when `LEGATUS_OFFLINE_FALLBACK=true`; production should set it to `false`. With fallback disabled, a missing key, exhausted quota, moderation outage, provider error, failed verification tool, or incomplete tool loop fails closed and creates a human handoff instead of returning an unverified sales claim.

`LEGATUS_WIDGET_FRAME_ANCESTORS=*` keeps local/demo embedding convenient. Before a public launch, replace `*` with the reviewed storefront origin (for example, `https://shop.example`); multiple CSP origins may be separated by spaces or commas.

## Artisan commands and verification

The project ships with six Legatus commands:

```bash
php artisan legatus:bootstrap-demo-tenant
php artisan legatus:eval [--live]
php artisan legatus:sync-knowledge [--source=ID]
php artisan legatus:verify-openai [--shopping] [--agent=SLUG_OR_ID]
php artisan legatus:purge-expired-data
php artisan legatus:expire-reservations
```

Run the complete verification set with:

```bash
php artisan legatus:verify-openai
php artisan legatus:verify-openai --shopping
php artisan legatus:sync-knowledge
php artisan legatus:purge-expired-data
php artisan legatus:expire-reservations
php artisan legatus:eval
php artisan legatus:eval --live
php artisan test
```

`legatus:eval` is deterministic and does not spend OpenAI credits. `legatus:eval --live` verifies expected intent, handoff behavior, and required tools against the configured model.

## Build Week material

- [DEMO_SCRIPT.md](DEMO_SCRIPT.md) — the 110-second golden-path presentation
- [BUILD_WEEK_SUBMISSION.md](BUILD_WEEK_SUBMISSION.md) — ready-to-adapt submission copy
- [BUILD_LOG.md](BUILD_LOG.md) — decisions, iterations, Codex collaboration, Georgian edge cases, and verification evidence
- [CHANGELOG_BUILD_WEEK.md](CHANGELOG_BUILD_WEEK.md) — concise implementation history
- [DEPLOYMENT.md](DEPLOYMENT.md) — public HTTPS deployment checklist

Public hosting, a recorded video, and final screenshots are intentionally not included in this repository and must be added by the project owner after choosing the deployment destination. Meta OAuth/webhook adapters, app review, and credentials are future integration work—not a connector waiting only for credentials.
