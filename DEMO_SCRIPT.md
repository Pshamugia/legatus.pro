# Legatus — 110-second Build Week demo

## The golden path

The entire demo tells one story: a Georgian small-business owner turns scattered product knowledge into a trustworthy sales employee, Legatus completes useful work, and a human remains in control when judgment is required.

Use a fresh but pre-tested demo workspace, a small catalogue file, and the exact prompts below. Keep a second browser tab open on the Inbox and a third on Analytics. Pre-warm one live GPT‑5.6 request before recording, but do not fake the visible run. Seeded `simulated_instagram` / `simulated_messenger` conversations and `simulated` model traces are backdrop only; the golden path must create a new `web` conversation and a completed live OpenAI trace.

## 0–13 seconds — Start with the person, not the model

**Screen:** Landing page.

**Say:**

> “In Georgia, a small online-business owner often runs the entire store from an Instagram inbox. While they pack an order, meet a supplier, or sleep, a customer waits for a simple answer — and the sale disappears.”

## 13–25 seconds — Create trusted knowledge

**Screen:** Onboarding with a controlled public website URL already entered and the prepared small CSV selected. Click **Create Legatus**, then show the success state and the ready source/product counts.

**Say:**

> “Legatus learns the business’s catalogue and policies. Every price, stock figure, and policy answer stays connected to a verified source.”

Use only a stable public URL that you control and verify immediately before recording. Do not claim that a live Instagram connection is active. The social-inbox context explains the market; the implemented channel shown in the demo is the web experience.

## 25–49 seconds — Personal shopper with evidence

**Screen:** Customer web chat.

**Paste:**

```text
30 ლარამდე ვეძებ მეგობრისთვის თანამედროვე, იდუმალ რომანს. ხვალ თბილისში მჭირდება. რას მირჩევ?
```

**Expected behavior:** Legatus remembers budget/occasion/mood, calls recommendation and catalogue/stock tools, and uses the tenant’s configured delivery policy for the indicative Tbilisi window. It excludes unavailable/out-of-budget choices and explains the fit. Its strict response schema lists every customer-facing factual claim, and Laravel validates those claims against exact tool results independently of the model-selected intent.

**Say:**

> “GPT‑5.6 understands the intent and chooses actions. Laravel owns the facts. Here are the exact tools, sources, and confidence behind the answer.”

Point to no more than three proof chips. The audience should understand the result without reading a trace dump.

## 49–68 seconds — Turn intent into a qualified opportunity

**Paste:**

```text
Convenience Store Woman-ის 10 ცალი მინდა თანამშრომლებისთვის. მომიმზადე შეთავაზება. მე ვარ მარიამი, mariam@example.com. თანახმა ვარ, ეს ელფოსტა ამ მოთხოვნისთვის შეინახოთ და დამიკავშირდეთ.
```

**Expected behavior:** Legatus verifies availability, calculates a non-binding offer from database prices, records the explicitly consented contact and sales intent as a qualified lead, links that lead to the customer’s consent message, and proposes a concrete next step. The chat transcript is redacted before persistence; keyed HMAC evidence proves that the exact email appeared in this same consent message while the raw current input is handled only ephemerally.

**Say:**

> “It does not only answer. It checks, calculates, remembers the opportunity, and gives the owner an actionable lead.”

If the live model asks one useful missing question, answer it immediately with the prepared detail. Do not improvise a long sales conversation.

## 68–87 seconds — Show the human-control moment

**Paste:**

```text
თუ 20%-იან ფასდაკლებას მომცემთ, ახლავე ავიღებ.
```

**Expected behavior:** The requested discount exceeds the configured automatic allowance. Legatus must not promise it; it hands off with a reason, summary, priority, and suggested reply.

**Screen:** Switch to Inbox and open the same conversation.

**Say:**

> “Legatus knows the boundary of its authority. The operator receives the full context and a suggested reply — the customer never starts from zero.”

Briefly click **Take over**. Do not spend demo time composing a long manual answer.

## 87–102 seconds — Prove that it happened

**Screen:** Analytics.

Point to the newly created qualified lead, handoff, completed live model run, redacted persisted transcript/tool trace, strict grounded evidence, token/latency data, and latest evaluation result. Explain that the consented lead field is purposefully retained under its deadline while raw contact data is not retained in the stored transcript or trace. If simulated examples are visible, call them fictional demo context; never use their numbers or `gpt-5.6-sol · simulated` label as proof of the live run.

**Say:**

> “Every run is observable and repeatable: model, tools, tokens, latency, outcomes, and a quality gate we can run offline or against GPT‑5.6.”

## 102–110 seconds — Close on the promise

**Screen:** Return to the landing hero or keep the outcome visible.

**Say:**

> “Legatus means envoy. It gives every small business a trusted representative who never leaves a customer unanswered — and always knows when to call a human.”

## Recording checklist

- Record at 1080p with browser zoom adjusted for readable Georgian text.
- Hide bookmarks, developer tools, `.env`, API keys, email notifications, and personal browser data.
- Use fictional demo contacts only.
- Clear stale conversations so the lead/handoff/trace changes are obvious.
- Verify the discount limit and confidence threshold before recording.
- Verify the tenant delivery policy (timezone, cutoff, local cities, and business-day windows) before recording.
- Verify that the visible customer conversation says `web`, while any seeded social-channel examples are labeled simulated.
- Verify that response CSP uses a fresh script nonce with no `script-src` unsafe-inline allowance, and that the separate-site widget still loads inside its intentional frame policy.
- Rehearse one duplicate retry with the same signed visitor token and UUID `request_id`; it must replay the stored response without another customer message/model run, even after clearing the response cache.
- Run `php artisan legatus:verify-openai --shopping` immediately before the take.
- Keep the final recording between 90 and 120 seconds.
- Capture a silent backup take and four stills: landing, grounded recommendation, handoff Inbox, Analytics.

## Recovery path if the live API is slow

Pause the take and restart; do not pretend a fallback response is a live GPT‑5.6 run. In the recommended production/judging configuration (`LEGATUS_OFFLINE_FALLBACK=false`), an API, quota, moderation, or verification failure creates a safe human handoff. The deterministic fallback is only for local/offline evaluation and must not be presented as live model evidence.
