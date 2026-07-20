# Legatus security and AI-control model

Legatus is designed so the language model can reason and communicate, while Laravel retains authority over identity, tenant data, commercial facts, and state-changing actions.

## Implemented controls

| Risk | Implemented control |
|---|---|
| API-key exposure | OpenAI credentials are read server-side from environment configuration and are never intentionally returned to the browser. |
| Cross-tenant data access | Authenticated resources are resolved against the current organization/agent; ownership checks reject foreign conversations and knowledge sources. |
| Invented or mislabeled commercial fact | Strict structured output requires a `factual_claims` ledger. The server infers factual needs from response text independently of the model-selected intent, then validates product IDs/names, prices, stock quantities, delivery, policy, discounts, reservations, offers, and budgets against exact successful tenant-scoped tool results. Missing or mismatched coverage fails closed. |
| Unsafe discount | The configured discount allowance is enforced server-side; requests needing approval are handed to an operator. |
| Unsupported delivery promise | Delivery is calculated by an approved tool from tenant-owned timezone, cutoff, local-city, and business-day settings; the result is labeled as an indicative window. Missing policy hands off rather than guessing. |
| Accidental order completion | Offers are non-binding; reservations are short-lived and `pending`; final payment/order confirmation is outside the agent’s authority. |
| Low-confidence answer | The server compares returned confidence with the agent threshold and creates a handoff when the response is not sufficiently reliable. |
| Prompt injection in imported content | Catalogue, PDF, and website content is explicitly treated as untrusted data, not model instructions. Retrieval returns evidence rather than executable behavior. |
| Malicious public URL / SSRF | URL ingestion accepts HTTP/HTTPS on ports 80/443 only; rejects credentials, loopback, private, link-local, reserved, and unresolvable destinations; and pins cURL to the validated public IP to resist DNS rebinding. |
| Partial or poisoned refresh | Knowledge refreshes run transactionally. Failed parsing or incomplete embedding rolls back to the last-known-good source data; source-owned products are deactivated when they disappear or the source is deleted. |
| Unsafe customer input | Public messages are validated and length-limited. Every live OpenAI generation requires a successful moderation result first; the separately labeled deterministic local/eval path does not claim equivalent moderation coverage. |
| Unbounded agent activity or spend | Function arguments use strict schemas; tool rounds, output tokens, connection/request timeouts, and retries are bounded. Daily per-agent run and token ceilings stop further automated work and hand off safely. Only named server-side tools may execute. |
| Public conversation impersonation | The server issues a 90-day HMAC-signed, agent-specific visitor token on the first message; subsequent messages, history, and feedback are bound to it. Caller-supplied raw visitor IDs, expired/tampered tokens, and feedback for another visitor are rejected. |
| Duplicate or concurrent public requests | Optional UUID request IDs are stored on customer messages under a per-conversation database uniqueness constraint. Durable response snapshots/reconstruction survive cache loss; cache entries accelerate replay, locks serialize work, and visitor-scoped throttles constrain abuse. |
| Lost context during escalation | Handoff stores a reason, summary, priority, and suggested operator reply; AI replies stop while a human owns the conversation. |
| Silent model failure | Moderation/provider/tool/schema/quota failures are recorded and fail closed to a human handoff. The deterministic fallback is configuration-gated and intended for local/offline evaluation, not production. |
| Hidden AI behavior | Responses and run records expose confidence, evidence sources, tool names, model status, tokens, and latency where appropriate. |
| Unnecessary lead-contact retention | Lead creation requires explicit consent and the exact contact value in the same customer message. Keyed, non-reversible HMAC fingerprints verify the value after immediate transcript redaction; the lead receives a 90-day deadline and a scheduled command removes expired name/email/phone fields while retaining anonymized outcome counts. |
| Browser script injection / framing | HTML responses receive a fresh per-request CSP nonce. `script-src` accepts same-origin files and nonce-bearing inline blocks only and does not enable `unsafe-inline`; widget `frame-ancestors` is restricted by a validated origin list in production. |
| Unbounded semantic scoring | Embedding retrieval scores only the newest configurable candidate pool (default 2,000, server-clamped to 50-5,000) before thresholding and final ranking. |
| Forged or replayed social events | Meta webhook bodies require the `X-Hub-Signature-256` HMAC, provider message IDs are deduplicated in the database, and the webhook routes are stateless. Original provider payloads are not retained. |
| Social-channel credential exposure | Meta Page tokens and pending account-selection candidates use encrypted model casts. OAuth state is session-bound, short-lived, tenant/user-bound, and account selection is explicit instead of silently connecting every managed Page. |
| Duplicate or uncertain Meta delivery | A durable channel outbox, unique queued jobs, database status transitions, and a scheduled recovery sweep protect queue-insertion failures. Ambiguous network/5xx sends are marked `delivery_unknown` and are not automatically resent; permanent 4xx failures are surfaced to the operator. |
| Untrusted commerce connector | Connections require a public HTTPS origin, DNS/IP validation and pinning, no redirects, HMAC-signed nonces, bounded JSON, full authoritative snapshots, strict product/live-fact schemas, atomic reconciliation, and last-known-good preservation. |

## Data handling

- Organizations own their agents, catalogues, sources, conversations, traces, and outcomes.
- Customer messages are persisted because the operator Inbox and conversation continuity require them.
- Contact details are stored in lead fields only after explicit consent in the same customer-authored message. The exact supplied email/phone is checked against keyed HMAC fingerprints stored with the already-redacted message; the lead stores that message ID as evidence and receives a 90-day retention deadline.
- Email addresses and phone numbers are redacted before a customer message is first persisted. The unredacted current input exists only in request memory long enough for moderation/orchestration and exact consent processing; it is substituted only for the latest redacted history item sent to the model and is not written to the transcript. Structured redaction also covers tool-call arguments/results, run errors, conversation context, handoff reason/summary, and suggested replies; `create_lead` traces redact name as well as contact fields.
- `legatus:purge-expired-data` removes expired lead name, email, phone, original notes, and consent timestamp; clears customer names and shopping profiles; and re-redacts the linked messages, traces, context, and handoff fields while preserving anonymized outcome measurement. The consent-message foreign key may remain as an audit link to the now-redacted message.
- Shopping preferences are scoped to the conversation.
- The application does not process payments or store payment-card data.
- OpenAI requests can contain recent conversation context and retrieved business data. A production operator must disclose this appropriately and configure retention/deletion policies for its jurisdiction and OpenAI account.

Before processing real customers, publish a privacy notice and define retention for conversation transcripts as well as deletion/export workflows and a lawful basis for processing. The implemented lead anonymization is one control, not a compliance certification.

## Fail-closed and fallback boundary

Moderation is a safety layer, not a guarantee. The live orchestration path fails closed: when moderation flags input or the moderation service is unavailable, automatic generation stops, the event is traced, and the conversation is routed to human review. Provider exceptions, quota exhaustion, unsuccessful verification tools, unverifiable commercial claims, incomplete tool rounds, and invalid/missing structured output follow the same safe-handoff boundary.

The deterministic fallback exists for local development and credit-free offline evaluations. It is intentionally narrower than the live GPT‑5.6 path, is enabled only by `LEGATUS_OFFLINE_FALLBACK=true`, and should not be presented as equivalent reasoning quality. Production must set this flag to `false` so an unavailable AI path never silently becomes a weaker sales path.

## Public surfaces

The public demo and widget endpoints are intentionally accessible to shoppers. Signed visitor ownership protects transcript history and feedback. The public message, history, and feedback JSON routes explicitly remove cookie encryption/queuing, session startup/error sharing, and CSRF middleware, so identity never falls back to a browser session. Database-backed request IDs, response snapshots, cache, and locks protect retries; Laravel throttles protect login, registration, chat, history, and feedback routes. Application middleware adds CSP, MIME-sniffing, referrer, permissions, cross-origin-resource, and frame controls. Each HTML response gets a new script nonce and `script-src` has no `unsafe-inline` allowance. Widget framing defaults to `*` for local/demo convenience but production must set `LEGATUS_WIDGET_FRAME_ANCESTORS` to reviewed storefront origins. Production operators should still add infrastructure-level rate limiting, bot protection, abuse monitoring, and cost alerts. Source ingestion, team management, settings, Analytics, and Inbox operations remain authenticated.

The current implementation does not claim completion of:

- Meta Business Verification/App Review or production credentials for a deployer-owned Meta app
- real-account Facebook/Instagram acceptance until recorded provider message IDs prove both directions on the public deployment
- payment processing
- autonomous order fulfillment
- compliance certification
- a claim of completed penetration testing

## Production hardening checklist

- Use HTTPS everywhere; set `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, and `SESSION_ENCRYPT=true`.
- Set `LEGATUS_REGISTRATION_ENABLED=false`, `LEGATUS_DEMO_LOGIN_ENABLED=false`, and `LEGATUS_OFFLINE_FALLBACK=false` unless a reviewed launch requirement explicitly needs otherwise.
- Configure appropriate daily AI run/token limits, bounded OpenAI output/tool settings, and alerts before exposing public endpoints.
- Rotate all credentials used during development.
- Do not expose the local `demo@legatus.ai` / `password` pair. Production seeding uses a random password unless a strong `LEGATUS_DEMO_PASSWORD` is intentionally provided.
- Use a managed database with encrypted backups and tested restoration.
- Apply least-privilege database and filesystem permissions.
- Configure trusted proxies and edge rate limits; verify the application security headers and widget frame policy through the final HTTPS proxy.
- Set `LEGATUS_WIDGET_FRAME_ANCESTORS` to the exact reviewed storefront origin(s); do not leave the demo `*` value in a normal production launch.
- Verify that the final proxy preserves the per-response CSP nonce, does not weaken `script-src`, and does not add a conflicting framing policy to the widget response.
- Set `LEGATUS_SEMANTIC_CANDIDATE_LIMIT` for the largest reviewed tenant corpus; the application clamps it to 50-5,000 candidates, but database/vector-search infrastructure is still recommended for materially larger corpora.
- Restrict log access and define retention for messages, leads, and traces.
- Alert on repeated login failures, ingestion errors, moderation failures, tool failures, latency, and OpenAI spend.
- Test tenant isolation after every authorization or route-model-binding change.
- Review imported website domains and uploaded files before using real business data.
- Re-run offline and live evaluations after model, prompt, tool-schema, or catalogue changes.

## Responsible disclosure

Report a suspected vulnerability privately to the repository owner. Do not include API keys, passwords, customer messages, personal data, or exploit details in a public issue.
