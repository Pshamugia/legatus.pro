# Legatus production deployment

The application is deployment-ready at repository level, but this repository does not claim that a public deployment has already been performed. A host, domain, TLS certificate, production database, and secret configuration still need to be supplied by the project owner.

## 1. Runtime requirements

- PHP 8.2 or later
- Composer 2
- Nginx with PHP-FPM or Apache with `mod_rewrite`
- PostgreSQL or MySQL for production; SQLite is intended for the local demo
- PHP extensions: PDO, `mbstring`, OpenSSL, tokenizer, XML, cURL, and fileinfo
- HTTPS domain with its document root set to the Laravel `public/` directory
- Cron or an equivalent scheduler trigger
- Persistent writable storage for Laravel logs, cache, sessions, and uploaded knowledge files
- Outbound HTTPS access to OpenAI and approved knowledge-source hosts; cURL must support `CURLOPT_RESOLVE` for DNS-pinned URL ingestion

Knowledge ingestion currently runs synchronously when a source is added or manually synchronized. A queue worker is not required for the current implementation. Moving large imports to background jobs is recommended before high-volume use.

## 2. Production environment

Copy `.env.example` into the host’s secret/environment configuration and set at least:

```env
APP_NAME=Legatus
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.example
APP_TIMEZONE=Asia/Tbilisi
APP_KEY=base64:generated_value

DB_CONNECTION=pgsql
DB_HOST=your_database_host
DB_PORT=5432
DB_DATABASE=legatus
DB_USERNAME=legatus
DB_PASSWORD=use_a_secret_manager

OPENAI_API_KEY=use_a_secret_manager
OPENAI_MODEL=gpt-5.6-sol
OPENAI_MODERATION_MODEL=omni-moderation-latest
OPENAI_EMBEDDING_MODEL=text-embedding-3-small
OPENAI_TIMEOUT=45
OPENAI_CONNECT_TIMEOUT=10
OPENAI_RETRIES=2
OPENAI_MAX_TOOL_ROUNDS=4
OPENAI_MAX_OUTPUT_TOKENS=900
OPENAI_REASONING_EFFORT=low

LEGATUS_REGISTRATION_ENABLED=false
LEGATUS_DEMO_LOGIN_ENABLED=false
LEGATUS_OFFLINE_FALLBACK=false
LEGATUS_DAILY_AI_RUN_LIMIT=200
LEGATUS_DAILY_AI_TOKEN_LIMIT=250000
LEGATUS_SEMANTIC_SIMILARITY_THRESHOLD=0.35
LEGATUS_SEMANTIC_CANDIDATE_LIMIT=2000
LEGATUS_WIDGET_FRAME_ANCESTORS=https://shop.example

SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
CACHE_STORE=database
```

Use the host’s secret manager. Never commit the production `.env`, API keys, database credentials, `APP_KEY`, session data, or customer exports.

Recommended Laravel settings depend on the host, but production should use durable database/Redis-backed sessions and cache rather than local ephemeral storage. Public request idempotency remains database-backed if cache entries disappear; a durable shared cache still improves replay latency and is required for effective cross-process locks/rate limiting. The daily AI ceilings above are starting values, not universal capacity recommendations; set them from the judging/load budget and attach cost alerts. Semantic candidate scoring defaults to the newest 2,000 embedded chunks and is clamped by the application to 50-5,000; review this limit and move large corpora to database/vector-native search before high-volume use.

The seeded `demo@legatus.ai` / `password` pair is intended only for local/testing and must never be exposed from an internet-accessible non-production environment. Under `APP_ENV=production`, the seeder generates a random password unless `LEGATUS_DEMO_PASSWORD` is supplied. Keep demo login disabled for a normal launch. If a public Build Week workspace intentionally needs it, set a strong unique `LEGATUS_DEMO_PASSWORD`, enable the flag only for the judging window, and rotate/disable it immediately afterward.

## 3. Release procedure

Run from the release directory:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan migrate --force
php artisan storage:link
php artisan legatus:eval
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Do not run `migrate:fresh` in production: it deletes existing data.

Run every pending migration before enabling traffic. In particular, `2026_07_20_000012_add_request_id_to_messages.php` adds the nullable customer-message `request_id` and the per-conversation unique index used for durable retry safety.

Do not deploy the development `.env`, SQLite database, logs, sessions, caches, or private uploaded-source files. Do not run `db:seed` or `legatus:bootstrap-demo-tenant` against a real tenant database unless the fictional Build Week dataset is explicitly desired.

Point the web server to `/path/to/legatus/public`, not the repository root. Ensure the web process can write only to Laravel’s required `storage/` and `bootstrap/cache/` directories.

## 4. Scheduler

Legatus schedules recurring knowledge synchronization, anonymization of expired lead contact details, and expiry of pending reservations. Invoke Laravel’s scheduler once per minute:

```cron
* * * * * cd /path/to/legatus && php artisan schedule:run >> /dev/null 2>&1
```

Confirm all three scheduled commands run under the same release and environment as the web process:

- `legatus:expire-reservations` — every minute;
- `legatus:sync-knowledge` — daily at 03:15 application time;
- `legatus:purge-expired-data` — daily at 03:45 application time.

## 5. Reverse proxy and HTTPS

- Redirect all HTTP requests to HTTPS.
- Preserve the original host and scheme headers so Laravel generates HTTPS widget URLs.
- Set secure, HTTP-only, same-site session cookies appropriate for the chosen deployment.
- Add request-size limits that still permit the application’s validated knowledge upload size.
- Apply rate limits at the edge to login, registration, public demo-message, and widget-message routes.
- Keep stack traces and server version details out of public error responses.

The widget script is designed to be embedded on another website, while its iframe and API requests are served by the Legatus origin. Set `LEGATUS_WIDGET_FRAME_ANCESTORS` to the reviewed storefront origin(s); use `*` only for local or intentionally open demos. Invalid CSP sources are discarded and an empty valid list fails closed to `frame-ancestors 'none'`. The public conversation identity is an agent-bound HMAC-signed token, not a trusted caller-supplied visitor ID; rotating `APP_KEY` invalidates existing tokens and also changes the keyed fingerprints used for future contact-evidence comparisons. Public message, history, and feedback JSON endpoints are stateless: they do not start a Laravel session, read/write cookies, or rely on CSRF state. Test token persistence, history polling, operator replies, feedback ownership, CSP/frame headers, and browser privacy behavior on the exact production domain before launch. Confirm every HTML response carries a fresh nonce and that `script-src` has no `unsafe-inline` allowance.

## 6. Pre-release verification

Run these against a staging environment with production-like configuration:

```bash
php artisan test
php artisan legatus:eval
php artisan legatus:verify-openai
php artisan legatus:verify-openai --shopping
php artisan legatus:eval --live
```

The live commands consume OpenAI API usage. Inspect the model, tools, intent, and status returned by the health checks; do not treat a process exit alone as sufficient evidence.

## 7. Go-live checklist

- [ ] `/`, `/login`, and the authenticated workspace load over HTTPS.
- [ ] `APP_DEBUG=false`, production logging works, and no secret appears in an error page.
- [ ] Cross-tenant access returns `404`/denied. If registration is needed, enable it briefly, verify that it creates a fresh organization, then return it to the intended launch setting.
- [ ] A small CSV imports its products and reports a successful source status.
- [ ] A PDF and an approved public URL produce searchable knowledge chunks.
- [ ] A website entered during onboarding is ingested or reports an actionable failure.
- [ ] Product, price, stock, delivery, policy, discount, reservation, offer, and budget claims are backed by the strict `factual_claims` ledger and exact successful tool evidence, even when a mocked/model response labels its intent generically.
- [ ] A constrained shopping request stays inside budget and availability rules.
- [ ] A discount above the configured limit creates a human handoff.
- [ ] Low confidence creates a handoff with reason, summary, and suggested reply.
- [ ] The operator can take over, reply, release the conversation, and close it.
- [ ] The one-line widget works on a separate test site, persists signed visitor continuity, restores history, and displays each human Inbox reply exactly once.
- [ ] Retrying a message with the same signed visitor token and UUID `request_id` does not create a duplicate customer message or model run, including after the response cache is cleared or expires; reusing the UUID under another visitor remains isolated.
- [ ] Public message/history/feedback JSON responses work without a session cookie, reject an invalid visitor token, and return private no-store cache headers.
- [ ] CSP contains a unique per-response script nonce, rendered inline scripts carry that nonce, `script-src` omits `unsafe-inline`, and the widget frame permits only the origins reviewed in `LEGATUS_WIDGET_FRAME_ANCESTORS`.
- [ ] A customer message containing a fictional email/phone is redacted in the database immediately, the transcript/tool trace never stores the raw value, and an exactly matching explicitly consented lead still succeeds through HMAC contact evidence.
- [ ] `LEGATUS_SEMANTIC_CANDIDATE_LIMIT` matches the reviewed corpus/load plan and retrieval still returns the expected source at that bound.
- [ ] Analytics show real tenant-scoped data rather than placeholder numbers.
- [ ] Database backups, restoration tests, uptime alerts, log retention, and OpenAI usage alerts are configured.
- [ ] The scheduled 90-day lead-contact anonymization is monitored, and broader conversation retention matches the published privacy notice.
- [ ] Local demo credentials cannot authenticate; demo login and public registration match the intended launch policy.
- [ ] `LEGATUS_OFFLINE_FALLBACK=false`, and a staged provider/quota failure produces a safe human handoff rather than an unverified answer.
- [ ] Seeded `simulated_instagram` / `simulated_messenger` conversations and `simulated` runs are visibly identified as demo data and are never described as live Meta traffic or live OpenAI evidence.

## 8. Build Week publication checklist

- [ ] Choose a short HTTPS URL and verify it on desktop and mobile.
- [ ] Seed only polished fictional demo data; never upload real customer data.
- [ ] Pre-warm the exact live-demo requests.
- [ ] Record the 110-second flow in [DEMO_SCRIPT.md](DEMO_SCRIPT.md).
- [ ] Capture landing, grounded response, handoff Inbox, and Analytics screenshots.
- [ ] Add the public URL, repository URL, video URL, and screenshots to the final submission.

## 9. Rollback

Keep the previous application release and a database backup available. Roll back application code by switching the web server to the previous immutable release. Database rollback must be evaluated migration by migration; do not run broad destructive rollback commands against production without reviewing their data impact.
