# Legatus — Build log and Codex collaboration record

This log documents how Legatus evolved during OpenAI Build Week, which product and engineering decisions were made, what Codex contributed, and which claims can be demonstrated from the repository.

## Collaboration model

The human collaborator set the product direction:

- solve the unanswered-message problem for small online businesses;
- build a standalone product rather than extending an existing bookstore site;
- include both an AI sales employee and a Personal Shopping Agent;
- keep a human in control of uncertain or commercially sensitive decisions;
- target OpenAI Build Week quality rather than a disposable prototype;
- choose **Legatus** — Latin for envoy, ambassador, or messenger — as the final brand;
- provide and control the private OpenAI API configuration;
- retain responsibility for hosting, credentials, recording, and final submission.

Codex acted as the engineering and product-design collaborator: inspecting the workspace, implementing and reviewing code, running tests and live health checks, iterating the UX and judging story, and documenting limitations without representing unfinished integrations as complete.

## Decision log

### 1. Standalone product boundary

**Decision:** Create a separate Laravel application at `ai-sales-employee` as an independent product.

**Why:** The concept is a reusable SaaS product for many businesses. Coupling it to one marketplace would weaken tenancy, onboarding, deployment, and the global story.

### 2. Laravel as the authority layer

**Decision:** Keep Laravel as the system of record and GPT‑5.6 as the reasoning/language layer.

**Why:** Price, stock, discounts, reservations, offers, leads, user roles, and ownership need deterministic server-side rules. A model may decide which approved action is useful, but it must not directly mutate arbitrary business state or invent a commercial fact.

### 3. Responses API with strict tools

**Decision:** Use the OpenAI Responses API, strict JSON tool schemas, and schema-constrained final output.

**Why:** The golden path needs visible agentic behavior and machine-checkable output: intent, confidence, handoff decision, selected products, and sources. Strict tools also make traces and evaluations meaningful.

### 4. One strong customer channel first

**Decision:** Implement a real embeddable web channel before attempting Meta integration.

**Why:** A dependable end-to-end channel is more valuable for judging than several unfinished connectors. Instagram and Messenger remain central to the market story, but live Meta adapters require external apps, credentials, webhooks, review, and a public HTTPS endpoint.

### 5. Human handoff is a product feature

**Decision:** Treat handoff as a first-class state transition, not a final chatbot sentence.

**Why:** The operator needs ownership, full transcript, reason, summary, priority, and a suggested reply. The customer should never repeat the conversation, and the AI must stop responding while a human owns it.

### 6. Evidence belongs in the UX

**Decision:** Display compact sources, confidence, and tool actions while storing richer traces for Analytics.

**Why:** A shopper needs understandable trust signals, not raw JSON. A judge or operator needs enough evidence to verify that GPT‑5.6 used real business tools.

### 7. Fail closed in production; keep offline evaluation honest

**Decision:** Keep a deterministic offline path behind `LEGATUS_OFFLINE_FALLBACK`, disable it in the recommended production configuration, and separate offline/live evaluations.

**Why:** Local development, deterministic evaluations, and UI review should not depend on API availability or spend. A production customer must never receive a weaker, unverified answer because moderation, the provider, a verification tool, a quota, or the structured response failed. Those paths now hand off safely. The demo must identify live GPT‑5.6 evidence and never present fallback behavior as a live model run.

## Implementation iterations

### Iteration 0 — Runtime recovery

The first browser run failed because the Composer vendor tree was incomplete: Symfony Finder could not be included, then Laravel failed while rendering the bootstrap exception. Codex traced the error to the interrupted/corrupted dependency installation and restored the Composer dependencies before evaluating application behavior.

**Evidence:** the Laravel application now boots, Artisan commands execute, and the automated suite can run.

### Iteration 1 — From scripted chat to an orchestrator

The early demo response service was useful for UI validation but did not prove agentic depth. Codex added a Responses API orchestrator that:

- sends recent conversation history;
- exposes strict server-side functions;
- executes bounded function-call rounds;
- returns a strict sales-response schema;
- stores the OpenAI response ID, model, tool calls/results, tokens, latency, and status;
- records failure/moderation/fallback outcomes.

The local fallback was retained as an explicitly configured development/evaluation layer. Live-path exceptions and disabled/missing live service now create a recorded fail-closed handoff.

### Iteration 2 — Commerce tools and grounded facts

The agent gained 11 bounded tools:

1. `search_products`
2. `search_knowledge`
3. `save_shopping_preferences`
4. `recommend_products`
5. `compare_products`
6. `check_stock`
7. `calculate_delivery`
8. `create_lead`
9. `request_human`
10. `reserve_product`
11. `build_offer`

Tools are tenant-scoped and return structured results. Reservations are pending and expiring; offers are non-binding. The model has no payment or final-order tool.

### Iteration 3 — Knowledge ingestion

Codex implemented:

- CSV UTF-8 BOM/delimiter/localized-number normalization, deduplication, and upsert;
- PDF text extraction and chunking;
- public URL retrieval and readable-content extraction;
- supported JSON-LD product/offer/currency extraction;
- content hashing, source progress/status, and actionable errors;
- embeddings and cosine-similarity retrieval with lexical fallback;
- atomic source refresh with last-known-good rollback when parsing or embedding is incomplete;
- source-owned product deactivation when a source changes or is removed;
- an SSRF boundary that rejects credentials, nonstandard ports, private/loopback/link-local/reserved destinations, then pins cURL to the validated public IP against DNS rebinding;
- onboarding website ingestion plus manual/scheduled resynchronization.

### Iteration 4 — Personal Shopping Agent

The product moved beyond FAQ answers. The shopping path now records preferences, applies budget and availability constraints, ranks candidates, compares finalists, explains fit and trade-offs, and records recommendation events. A live configured-model health check was used to validate a Georgian under-30-GEL mystery/modern-book request against demo inventory.

### Iteration 5 — Human operator workflow

Codex added conversation ownership and the complete operator lifecycle: queue, priority, take-over, reply, release to AI, and close. Handoff records the reason and summary; final polish added an operator-ready suggested reply.

### Iteration 6 — Real web distribution

Codex added a one-line script that mounts a responsive iframe widget and continues the same conversation through an agent-bound, 90-day HMAC-signed visitor token. The public history endpoint synchronizes operator replies exactly once, feedback verifies visitor ownership, UUID request IDs deduplicate retries, and a per-conversation lock prevents concurrent duplicate processing. The later release hardening made those JSON endpoints cookie/session-free and persisted request IDs plus response snapshots so replay remains safe after cache loss. This made the project embeddable without claiming an unfinished Meta connector.

### Iteration 7 — Multi-tenant SaaS controls

Organizations, memberships, authentication, roles, settings, and tenant-scoped resource checks were introduced. Cross-tenant feature tests verify that one organization cannot fetch another organization’s resources through protected endpoints.

### Iteration 8 — Observability and evaluations

Codex added real metrics, model/tool traces, token/latency reporting, recommendation/lead outcomes, seeded evaluation cases, and two evaluation modes. Judge-facing totals exclude evaluation traffic, and fictional seed traces/channels are labeled `simulated`, `simulated_instagram`, or `simulated_messenger` instead of masquerading as production activity:

- `php artisan legatus:eval` — deterministic, credit-free baseline;
- `php artisan legatus:eval --live` — configured-model behavior including required tool expectations.

### Iteration 9 — Final guardrails and Legatus brand

The final hardening pass added server-side checks for low confidence, required successful factual tools, verified product IDs/money/percentages/stock, and discount approval; tenant-configured delivery policy; richer handoff data; onboarding URL ingestion; real dashboard metrics; polished demo data; and the Legatus brand throughout the product and documentation. A subsequent guard pass required a strict `factual_claims` ledger and inferred verification needs from the response text, preventing a generic or incorrect model intent from bypassing commercial checks.

### Iteration 10 — Consent, privacy, cost, and operational hardening

The release-candidate pass added:

- exact lead-consent evidence linked to the customer-authored message and verified with keyed HMAC contact fingerprints;
- pre-persistence email/phone redaction for messages plus centralized redaction of tool traces, errors, context, and handoff fields, with names also removed from `create_lead` traces; only the current raw input is passed ephemerally to orchestration;
- scheduled 90-day lead-contact anonymization that also clears the customer name/shopping profile and re-redacts retained conversation evidence;
- daily per-agent OpenAI run/token ceilings, output-token caps, bounded reasoning/tool settings, and safe quota handoff;
- transactional, idempotent pending reservations that account for other live holds, plus the every-minute `legatus:expire-reservations` command;
- application security headers and fully stateless public message/history/feedback JSON endpoints with cookie, session, and CSRF middleware removed;
- production-default controls for registration, demo login, and offline fallback.

The project now exposes nine Legatus Artisan commands: demo bootstrap, evaluation, knowledge sync, OpenAI verification, privacy purge, reservation expiry, commerce connection/sync, and durable channel-outbox recovery.

### Iteration 11 — Model-independent claims and public-channel durability

The final release audit tightened the boundaries that matter when a demo becomes an internet-facing product:

- strict structured output now enumerates every customer-facing fact in `factual_claims`, while independent response-text checks bind product names/IDs, money, stock, delivery, policy, discounts, reservations, offers, and budgets to exact successful tool output;
- a customer message is redacted before its first database write, the current raw value exists only ephemerally for moderation/orchestration, and non-reversible keyed HMAC fingerprints let the lead tool prove the exact consented email/phone without restoring it to the transcript;
- the public JSON channel is cookie/session-free, signed-visitor scoped, and retry-safe through a database `request_id` uniqueness migration, durable response snapshots/reconstruction, cache-assisted replay, and conversation locks;
- HTML responses use fresh CSP nonces and `script-src` no longer allows unsafe inline script execution;
- semantic retrieval scores a configurable, server-clamped candidate pool (2,000 by default) instead of loading an unbounded embedded corpus.

## Georgian-language edge cases

Georgian was treated as product behavior, not translated decoration.

### Unicode and casing

Product matching and fallback routing use multibyte-aware string operations. Georgian catalogue names and descriptions are stored and emitted as UTF‑8. JSON serialization preserves Unicode rather than escaping it into unreadable sequences where practical.

### Intent variations

The deterministic path and eval prompts account for natural variants such as:

- price: `ფასი`, `ღირს`;
- delivery: `მიწოდება`, `ხვალ`, `ჩამომივა`;
- recommendation: `ვეძებ`, `მირჩიე`, `ჰგავს`, `მსგავსი`;
- human control: `ოპერატორი`, `კონსულტანტი`, `ადამიანი`;
- wholesale: `საბითუმო`.

An English eval wording exposed that `tomorrow` alone did not identify delivery in the deterministic path; the evaluation was made explicit and the bilingual behavior was reviewed rather than silently accepting a false pass.

### Georgian morphology

Exact keyword matching is intentionally only a fallback. Georgian suffixes and free word order make rigid phrase matching brittle, which is one reason the live path delegates intent understanding to GPT‑5.6 while keeping factual actions deterministic.

### GEL and demo clarity

Prices are returned from numeric database values and presented in GEL (`₾`). The recommendation demo uses a hard 30-GEL ceiling so a judge can immediately see whether the system respected the constraint.

### Windows console mojibake

PowerShell/XAMPP output sometimes rendered valid Georgian UTF‑8 bytes as mojibake because of console code-page settings. Codex checked files as UTF‑8 and avoided “fixing” display artifacts by corrupting source text. Final documentation was rewritten as UTF‑8.

## Security review record

| Review area | Finding | Result |
|---|---|---|
| Tenant isolation | Route-model binding alone could expose a foreign resource if controllers did not verify ownership. | Protected controllers resolve/check resources against the current tenant; cross-tenant tests cover denial. |
| Commercial hallucination | Prompt instructions and model-selected intent alone were insufficient for price, stock, delivery, policy, and discounts. | A strict `factual_claims` ledger plus intent-independent text checks bind each fact to exact successful tool output; missing/mismatched coverage hands off. |
| Low confidence | A schema field without a server rule could be ignored by UX state. | The configured threshold is checked after the model response and can force handoff. |
| Prompt injection | Imported websites/catalogues may contain adversarial instructions. | Retrieved content is treated as untrusted evidence, while the system/tool contract remains separate. |
| SSRF / DNS rebinding | URL ingestion could target localhost, metadata, private services, or change DNS after validation. | Credentials/nonstandard ports and private/reserved resolutions are rejected; retrieval is pinned to the validated public IP. |
| Partial ingestion | A failed refresh could replace good knowledge with incomplete chunks or products. | Refresh is transactional, embeddings must complete, and failure restores the last-known-good source state. |
| State-changing claims | An AI could imply that payment or final order succeeded. | No payment/finalization tool exists; reservations are pending and offers non-binding. |
| Tool abuse/cost | Unbounded calls could loop or spend unexpectedly. | Strict schemas, maximum tool rounds/output tokens, connection/request timeout, retry bounds, per-agent daily run/token ceilings, traces, and usage metrics were added. |
| Sensitive data | A sales assistant can receive personal contact details. | Messages are redacted before persistence; only current raw input is ephemeral; keyed HMAC evidence proves exact consented contact values; trace/context/handoff redaction and scheduled anonymization protect retained data. Broader transcript policy still belongs to the operator. |
| Public identity/retries | A caller could guess a visitor ID, read history, submit feedback, or duplicate an expensive request. | Cookie/session-free JSON routes, agent-bound signed tokens, ownership checks, database-unique UUID idempotency keys, durable response snapshots, cache, conversation locks, and public route throttles were added. |
| Browser script injection | Static CSP without nonce discipline could require unsafe inline execution. | Each response gets a fresh nonce; rendered inline scripts carry it and `script-src` omits `unsafe-inline`, with a narrowly intentional widget framing exception. |
| Retrieval resource growth | In-memory semantic scoring could grow with every embedded chunk. | The newest configurable candidate pool is capped and clamped to 50-5,000 before similarity filtering/ranking. |
| Public abuse | Demo/widget requests can generate model usage. | Laravel throttles login, registration, chat, history, and feedback; the deployment checklist adds edge throttling, bot protection, monitoring, and cost alerts. |

## Verification coverage

The automated suite covers these behavior families:

- fallback price and human-handoff conversations;
- Responses API tool execution, structured output, and moderation handling with mocked HTTP;
- CSV normalization/deduplication, atomic refresh rollback, complete embedding batches, source-product lifecycle, and unsafe/DNS-pinned URL handling;
- onboarding website/catalogue ingestion with mocked public HTTP;
- shopping preference, hard budget/stock ranking, and comparison behavior;
- operator ownership, replies, release, and close flows;
- authentication, membership roles, and cross-tenant denial;
- widget script/frame, stateless signed visitor/history ownership, human-reply synchronization, feedback authorization, durable cache-loss idempotency, and CSP nonce behavior;
- explicit Meta account selection, OAuth state binding, signed/stateless webhooks, native human echoes, attachment handoff, provider deduplication, durable outbox recovery, monotonic delivery states, and ambiguous-send handling;
- authoritative live-commerce reconciliation, signed connector requests, public-origin/DNS controls, response/schema limits, unchanged-product write avoidance, and live price/stock/delivery validation;
- public Privacy, Terms, and Data Deletion pages required for a deployer-owned Meta application;
- server-side discount, exact HMAC consent evidence, pre-persistence/retention redaction, delivery-policy, quota/failure, low-confidence, strict factual-claim coverage, and model-intent-independent guardrails;
- Analytics tenant scoping and evaluation runs;
- response feedback recording and helpfulness aggregation.

Final verification commands:

```bash
php artisan test
php artisan legatus:bootstrap-demo-tenant
php artisan legatus:eval
php artisan legatus:sync-knowledge
php artisan legatus:verify-openai
php artisan legatus:verify-openai --shopping
php artisan legatus:eval --live
php artisan legatus:purge-expired-data
php artisan legatus:expire-reservations
php artisan legatus:sync-commerce
php artisan legatus:dispatch-channel-outbox
```

Live checks consume OpenAI credits and require the private key in `.env`.

`legatus:bootstrap-demo-tenant` creates/updates fictional judging data and belongs only in a local or disposable demo environment, not a real tenant database.

## Final release-verification snapshot

- `php artisan test`: **140 passed, 873 assertions**.
- `php artisan legatus:eval`: **10 passed, 0 failed**, including price, stock, delivery, shopping, budget, handoff, wholesale, discount approval, and prompt-injection boundaries.
- Live `gpt-5.6-sol` shopping health check: completed in Georgian with `save_shopping_preferences`, `recommend_products`, and `check_stock`; no server guardrail or handoff was triggered.
- Demo database: 12 products, 3 knowledge sources, 4 knowledge chunks, 6 customer stories, 14 story messages, 2 qualified leads, 1 pending reservation, and 10 active eval cases.
- HTTP smoke: landing, Privacy, Terms, Data Deletion, demo chat, widget frame, widget loader, and health endpoint returned 200; the unconfigured Meta verification route failed closed with 503, and the widget loader remained stateless with no `Set-Cookie` header.
- Production cache build, Vite production build, strict Composer validation, Pint, Composer advisory audit, and npm audit all passed; both dependency audits reported zero known vulnerabilities.
- The temporary local smoke-test server was stopped and Laravel's local optimized caches were cleared after verification so deployment cannot inherit stale local configuration.

## Evidence boundaries and unfinished external work — July 20 release

The repository now implements the standalone application, live OpenAI path, one-script website widget, Facebook/Instagram OAuth and signed webhook transport, durable queue/outbox processing, human Inbox, signed commerce connector, tools, ingestion, tenant controls, Analytics, guardrails, evaluations, and Meta-required public legal pages. Seeded social conversations and seeded model runs remain explicitly simulated demo data.

The OpenAI path was executed against `gpt-5.6-sol` and completed a Georgian shopping flow with real tool calls. The Bukinistebi connector was also executed against its real local catalogue database: the authoritative snapshot exposed 2,114 visible products and live search/availability/delivery returned real store values. This is implementation evidence, not a claim that the new release is already deployed publicly.

It does **not** claim that the following external work is complete:

- deployment of this July 20 code snapshot to both public hosts;
- a recorded demo video or final screenshots;
- Meta Business Verification/App Review or real Facebook/Instagram acceptance message IDs;
- a native Shopify/WooCommerce app (the implemented boundary is the signed Universal Commerce API);
- production privacy/compliance certification;
- external penetration testing.

Those boundaries are intentional. They keep the submission technically credible and make the next steps explicit.
