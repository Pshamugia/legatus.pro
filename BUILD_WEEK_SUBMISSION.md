# Legatus — Build Week submission copy

## Title

**Legatus — The trusted AI envoy for every small business**

## One-line pitch

Legatus turns a small business’s catalogue and policies into a grounded AI sales employee and personal shopping agent, with transparent actions and human handoff built in.

## The human story

In Georgia, many small online businesses operate from social-media conversations. One owner may source products, create content, pack orders, coordinate delivery, and answer every Instagram or Messenger message. When that person is busy or asleep, a customer who asks “How much?”, “Is it available?”, or “Can it arrive tomorrow?” often receives no timely answer.

Legatus means *envoy, ambassador, or messenger* in Latin. It gives that owner a trusted representative who is always available, speaks the customer’s language, acts on verified business knowledge, and knows when a human decision matters.

The problem is locally visible and globally common: millions of small merchants sell conversationally without the staff or infrastructure of a large retailer.

## The problem

Generic chatbots can sound helpful while inventing a price, promising unavailable stock, or giving the wrong delivery policy. Traditional FAQ bots cannot discover nuanced needs, compare products, qualify a wholesale lead, or carry context into a human handoff. Small teams need automation, but they cannot surrender commercial control.

## The solution

Legatus ingests a catalogue, PDF policies, and approved public website knowledge. GPT‑5.6 then acts as the reasoning and conversation layer over strictly scoped commerce tools.

For a shopper, Legatus can:

- understand an open-ended need in Georgian or English;
- remember budget, mood, occasion, likes, dislikes, and recipient;
- recommend and compare products under price and availability constraints;
- verify price and stock, then calculate an indicative window from the tenant’s configured delivery timezone, cutoff, city, and business-day policy;
- calculate a non-binding offer and capture a qualified lead after explicit contact-storage consent;
- create a short pending reservation that still requires confirmation;
- escalate low-confidence, policy-sensitive, or discount-approval cases;
- give the operator the transcript, handoff reason, summary, priority, and suggested reply.

The implemented customer surface is a one-line embeddable web widget plus the live demo interface. Instagram and Messenger adapters are planned next integrations, not claimed as completed connectors.

## Why this is an agent, not a chatbot

A chatbot produces text. Legatus completes a bounded sales workflow:

```text
understand need
→ save constraints
→ retrieve verified products/policies
→ compare and check availability
→ calculate delivery/offer
→ capture an outcome
→ continue with AI or hand off to a human
```

Its 11 approved tools cover product search, knowledge search, shopping preferences, recommendations, comparisons, stock, delivery, lead capture, handoff, reservation, and offer calculation. Tool results are stored with model traces so the workflow is inspectable after the conversation.

## How GPT‑5.6 is used

Legatus uses the OpenAI Responses API with GPT‑5.6 function calling and strict structured output. GPT‑5.6 interprets customer language, identifies missing information, selects the appropriate tools, synthesizes retrieved evidence, explains recommendations, and chooses the next conversational step.

Laravel remains authoritative for identity, tenant boundaries, product records, stock, delivery calculations, discounts, reservations, offers, leads, and human ownership. The final response contains a constrained intent, confidence, handoff decision, product identifiers, sources, and a strict `factual_claims` ledger. Server checks also infer factual needs directly from the customer-facing text, independently of the model-selected intent, and bind every enumerated product/price/stock/delivery/policy/discount/reservation/offer/budget claim to exact successful tool output.

Moderation, bounded tool rounds/output, request timeouts/retries, daily run/token limits, fail-closed handoff, server-side commercial verification, and recorded redacted traces make the agent demonstrably controllable. A deterministic fallback is configuration-gated for local/offline evaluation and is disabled in the recommended production configuration.

## How Codex was used

Codex was an implementation partner throughout the project, not a one-off code generator. It helped:

- turn the original product idea into a standalone Laravel architecture;
- repair the initial local dependency/runtime failure;
- implement migrations, models, controllers, services, Blade UI, and tests;
- integrate the Responses API, strict tools, structured output, moderation, and embeddings;
- evolve the first chat demo into a Personal Shopping Agent and operator workflow;
- find Georgian-language keyword and evaluation edge cases;
- review tenant isolation, URL-ingestion SSRF risk, prompt injection, discount authority, and low-confidence behavior;
- harden stateless public-channel identity, database-durable idempotent retries, exact HMAC consent evidence, pre-persistence PII redaction, CSP nonces, quota failure, reservation concurrency, bounded semantic retrieval, and atomic ingestion;
- replace placeholder metrics with traceable outcomes;
- iterate the interface, verification commands, README, security model, deployment checklist, and 110-second demo narrative.

The human collaborator provided the product vision, local-market insight, Build Week goal, OpenAI configuration, brand choice, and final product decisions. The detailed iteration record is included in `BUILD_LOG.md`.

## Technical highlights

- Laravel multi-tenant application with organization roles and resource isolation
- OpenAI Responses API, GPT‑5.6 function calling, and JSON-schema output
- 11 tenant-scoped commerce tools
- Stateful conversation and shopping-preference memory
- Atomic CSV/PDF/public-URL ingestion with last-known-good rollback, deduplication, and source-owned product lifecycle
- `text-embedding-3-small` semantic retrieval with lexical fallback and a configurable bounded candidate pool
- URL validation, standard-port/credential rejection, private/reserved-network checks, and DNS-pinned SSRF protection
- Moderation, prompt-injection boundaries, bounded retries/tool loops/output, daily usage ceilings, and fail-closed handoff
- Strict `factual_claims`, model-intent-independent commercial verification, confidence/discount guardrails, and context-rich human handoff
- Embeddable web widget with stateless agent-bound signed visitor continuity, history synchronization, operator replies, feedback ownership, and database-durable idempotent message retries
- Per-request CSP script nonces without a `script-src 'unsafe-inline'` allowance
- Real outcome analytics, customer helpfulness feedback, model/tool traces, token and latency reporting
- Exact HMAC lead-contact consent evidence, redaction before first message persistence, ephemeral handling of only the current raw input, and scheduled 90-day contact anonymization plus retained transcript/trace re-redaction
- Repeatable offline and live evaluation suite
- Automated tests for core agent, ingestion, shopping, handoff, tenancy, widget, analytics, and evaluation behavior

## Design and UX

The interface is Georgian-first and designed around two people:

1. The shopper sees a warm, fast conversation with recommendations, product cards, and compact proof chips rather than an engineering console.
2. The owner sees sources, confidence, actions, handoff context, a suggested reply, and real outcomes in one workspace.

This keeps AI transparency understandable without forcing either user to inspect raw prompts or JSON.

## Safety and control

- Every customer-facing commercial fact must appear in strict `factual_claims`; price, stock, delivery, policy, discounts, reservations, offers, and budgets are checked against exact successful tools even if the model returns a generic intent.
- Delivery is tool-calculated and remains indicative.
- Discounts above the configured allowance require human approval.
- The AI cannot complete payment or a final order.
- Reservations are pending, expiring, and confirmation-dependent.
- Low confidence or missing policy evidence causes handoff.
- Imported content is untrusted data, never system instruction.
- Public URL ingestion blocks unsafe destinations and pins the validated DNS result during retrieval.
- Tenant resources are scoped to the authenticated organization.
- Creating a lead from contact data requires explicit consent and the exact email/phone in the same customer message. The already-redacted message stores keyed HMAC evidence; the lead stores its link and is scheduled for contact anonymization after 90 days.
- Customer email/phone is redacted before the message is first written to the database. Only the current raw input is passed ephemerally to moderation/orchestration; stored messages, tool traces, errors, conversation context, and handoff fields remain redacted, and `create_lead` traces also redact the contact name.
- Public message/history/feedback JSON routes are cookie/session-free, signed-visitor scoped, and protected by durable database request IDs plus response replay; HTML scripts require per-request CSP nonces.
- Per-agent daily run/token ceilings and provider/tool/schema failures stop automation and hand off instead of guessing.

## Impact

Legatus can help a small team respond after hours, convert complex questions into qualified opportunities, and serve customers in their language without authorizing an AI to invent commercial facts. It brings the workflow discipline of a larger sales operation to a business that cannot staff every inbox around the clock.

The project’s core promise is not “replace the owner.” It is “never waste the owner’s attention, and never lose the customer’s context.”

## What is implemented now

- Standalone working Laravel product
- Live GPT‑5.6 agentic path with production fail-closed handoff and a separately labeled local/offline fallback
- Catalogue/knowledge ingestion
- Personal Shopping Agent
- Human operator Inbox
- Embeddable web channel
- Authentication, roles, and tenant isolation
- Guardrails, traces, Analytics, and evaluations
- Curated demo data and complete local/deployment documentation

The curated social-inbox conversations and model traces are explicitly labeled `simulated_instagram`, `simulated_messenger`, and `simulated`; they demonstrate the UX without claiming a live Meta connector or a live GPT‑5.6 execution. The web demo/widget is the real implemented customer channel.

## What comes next

- Meta Messenger and Instagram webhook/OAuth adapters
- Shopify and WooCommerce catalogue/order connectors
- streaming responses and background ingestion jobs
- billing, plan entitlements, organization-wide budgets, and deeper revenue attribution (basic daily agent run/token ceilings already exist)
- broader Georgian/English multilingual evaluations
- customer export/deletion workflows, configurable transcript retention beyond the implemented lead/contact purge, compliance review, and external security testing

## Suggested submission metadata

**Tagline:** *Every message deserves a trusted answer.*

**Category description:** AI agent / commerce / customer experience / small business

**Public URL:** Add after HTTPS deployment

**Repository URL:** Add when repository visibility is finalized

**Demo video:** Add after recording the script in `DEMO_SCRIPT.md`

**Screenshots:** Add landing, grounded recommendation, human handoff, and Analytics after the final deployment styling check
