# Legatus — Build Week implementation changelog

## Product foundation

- Separated the idea into an independent Laravel application rather than coupling it to an existing bookstore project.
- Built the responsive landing, onboarding, workspace, demo chat, and core sales data model.
- Repaired an interrupted Composer/vendor installation that initially caused missing Symfony Finder and Laravel facade bootstrap errors.
- Added a deterministic local response path so the interface and baseline evaluations remain usable without OpenAI credentials.

## Agentic OpenAI workflow

- Integrated the OpenAI Responses API with the configured GPT‑5.6 model.
- Added strict function schemas, schema-constrained final responses, recent conversation context, bounded tool rounds, HTTP retries, timeouts, and persistent run traces.
- Added moderation and failure/fallback recording.
- Implemented 11 tenant-scoped commerce tools: product search, knowledge search, shopping preferences, recommendations, comparisons, stock, delivery, lead capture, handoff, pending reservation, and non-binding offer.
- Verified live price/stock and personal-shopping paths against the configured OpenAI model.

## Knowledge and shopping intelligence

- Added CSV catalogue, PDF knowledge, and approved public-URL ingestion.
- Added deduplication, source lifecycle/status, error reporting, content chunking, and website structured-product extraction.
- Added embeddings with `text-embedding-3-small`, semantic retrieval, and lexical fallback.
- Added URL validation and private/reserved-network SSRF protection.
- Added automatic onboarding website ingestion and recurring knowledge synchronization.
- Added saved shopping preferences, hard budget/availability ranking, product comparison, explanations, and recommendation events.

## Human control and safety

- Added human handoff state, reason, summary, urgency, assignment, take-over, reply, release-to-AI, and close workflows.
- Added server-side low-confidence, strict factual-claim/tool, intent-independent stock/policy, and discount-approval guardrails.
- Added suggested operator replies and preserved conversation context.
- Kept reservations pending and expiring, offers non-binding, and payment/final-order completion outside AI authority.
- Required exact, keyed-HMAC-backed consent for lead contact storage; redacted customer transcripts before persistence and historical operator context before provider transmission; added scheduled anonymization after 90 days.
- Treated imported catalogue/knowledge content as untrusted data rather than instructions.

## SaaS, channel, and evidence

- Added authentication, organizations, owner/admin/agent/viewer roles, settings, and tenant isolation.
- Added a real one-line embeddable website widget with persistent visitor sessions.
- Made public channel JSON transport cookie/session/CSRF-free with signed visitor identity, durable database-backed request idempotency, and throttling.
- Added source, confidence, tool, and escalation evidence to the conversation experience.
- Replaced placeholder dashboard figures with real tenant-scoped metrics and richer fictional demo outcomes.
- Added Analytics for conversations, automation, handoffs, leads, recommendations, tokens, latency, run status, and tool traces.
- Added per-response helpful/unhelpful feedback and helpfulness reporting.
- Added repeatable offline/live evaluations and seeded judging scenarios.

## Build Week polish

- Finalized the product name as **Legatus**, Latin for envoy/ambassador/messenger, and applied it across the product surface.
- Reframed the presentation around Georgian social-commerce owners and the universal cost of an unanswered message.
- Added a focused 110-second golden-path demo, accurate submission copy, security model, deployment checklist, and detailed Codex build log.
- Standardized Georgian UTF‑8 content and documented Windows console mojibake as a display issue rather than corrupting source text.
- Kept unimplemented Meta connectors, public hosting, video, and screenshots explicitly outside the list of completed work.
- Added per-response CSP script nonces, fail-closed relevant knowledge retrieval, PostgreSQL-portable search/CLI resolution, secret-safe demo bootstrap output, and CI coverage for `main`.
