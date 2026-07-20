# Bukinistebi live acceptance plan

This runbook is the release contract for the first real Legatus business. A green unit test is not treated as a live Facebook, Instagram, or website acceptance test.

## What is connected

- `bukinistebi.ge` exposes a read-only, HMAC-authenticated Universal Commerce API for the authoritative book catalogue, literal product search, live price/stock checks, and indicative delivery quotes.
- Legatus imports the complete authoritative snapshot and deactivates products that are no longer present only after every expected page has been validated.
- The Bukinistebi storefront loads Legatus from one public script URL on approved catalogue/content pages. It is deliberately excluded from login, account, checkout, order, and publisher pages.
- Facebook Messenger and Instagram Direct enter the same Legatus conversation engine as the website widget. Replies use Meta's official OAuth, Graph API, and signed webhooks; uncertain requests appear in the human Inbox.

## 1. Release both applications

Deploy the reviewed commits to both servers, run the pending Legatus migrations, and clear/rebuild Laravel caches. Do not enable the Bukinistebi widget until its exact script URL returns HTTP 200 with `Content-Type: application/javascript`.

Create a unique shared connector secret of at least 32 random bytes. Put the same value in the two server environments, never in browser HTML, Git, screenshots, or chat messages.

Bukinistebi:

```env
LEGATUS_CONNECTOR_ENABLED=true
LEGATUS_CONNECTOR_KEY_ID=bukinistebi-production
LEGATUS_CONNECTOR_SECRET=use_the_shared_secret_manager_value
LEGATUS_WIDGET_ENABLED=false
LEGATUS_WIDGET_SCRIPT_URL=https://legatus.pro/widget/the-real-agent-slug.js
```

Legatus must use production-safe settings, including:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://legatus.pro
OPENAI_MODEL=gpt-5.6-sol
LEGATUS_OFFLINE_FALLBACK=false
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=180
LEGATUS_PRIVACY_EMAIL=privacy@legatus.pro
```

Register/onboard the Bukinistebi workspace in Legatus, enter `https://bukinistebi.ge`, then copy the exact script URL shown on **Channels**. The generated agent slug is authoritative; do not guess it.

Connect the signed commerce source from the Legatus server. The command asks for the secret through hidden input:

```bash
php artisan legatus:connect-commerce REAL_AGENT_SLUG https://bukinistebi.ge bukinistebi-production --name="Bukinistebi live catalogue"
```

The command must finish with a verified product count. Then run one explicit reconciliation:

```bash
php artisan legatus:sync-commerce
```

Only after the script URL is verified should Bukinistebi set `LEGATUS_WIDGET_ENABLED=true` and rebuild its config cache.

## 2. Keep asynchronous replies alive

Meta replies require a continuously running queue worker; the scheduler alone is not a substitute. Run the worker under Supervisor/systemd or the hosting provider's persistent-process feature:

```bash
php artisan queue:work database --queue=default --sleep=2 --tries=3 --timeout=80 --max-time=3600
```

Restart workers after every release. Run `php artisan schedule:run` every minute for catalogue reconciliation, outbox recovery, privacy cleanup, and reservation expiry.

## 3. Configure Meta

In the Meta developer application:

- set the App Domain to `legatus.pro` and configure the public `https://legatus.pro/privacy` and `https://legatus.pro/data-deletion` URLs;
- set the OAuth redirect URLs displayed/configured by Legatus;
- set the webhook callback to `https://legatus.pro/webhooks/meta`;
- use the same strong `META_WEBHOOK_VERIFY_TOKEN` in Meta and Legatus;
- subscribe the required Messenger and Instagram messaging webhook fields;
- add the Bukinistebi Facebook Page and linked Instagram Professional account to the test/business setup;
- publish the Meta app as required for Instagram messaging webhooks and ensure the configured privacy mailbox is monitored;
- complete the permissions, business verification, and App Review required before messaging arbitrary public users.

The business owner then opens **Channels**, clicks **Connect Facebook** and **Connect Instagram**, explicitly selects the Bukinistebi account for each provider, and confirms that both cards show the correct account name. Legatus must never silently connect every Page available to that Meta user.

## 4. Acceptance matrix

Record the timestamp, channel, input, output, tools, source, and outcome for every case.

| Surface | Real action | Pass condition |
|---|---|---|
| Website | Open a safe Bukinistebi book page | One launcher appears; no launcher appears on login or checkout |
| Website | Ask for a specific available book's price and stock | Reply matches the live database and links to the real product |
| Website | Ask for a modern book similar to a named title with a budget | Up to three grounded choices, reasons, trade-offs, and live checks |
| Website | Ask for an unsupported discount | Human handoff with reason and suggested reply |
| Facebook | Send a real visitor message to the Bukinistebi Page | Signed webhook is accepted, one conversation is created, and one reply is delivered |
| Instagram | Send a real DM to the linked Professional account | One grounded reply is delivered within Meta's allowed response window |
| Meta | Send the same webhook twice | No duplicate customer message, OpenAI run, or outbound reply |
| Meta | Reply manually in Meta Business Suite | AI pauses for that thread and the human reply appears in the Legatus Inbox |
| Human Inbox | Take over and reply | The customer receives exactly one message on the originating channel |
| Failure | Stop the queue worker briefly | Webhook returns quickly; outbox recovery sends after the worker resumes without duplication |
| Failure | Make live stock verification unavailable | Legatus does not quote cached price/stock and routes safely to a human |

## 5. Evidence required before calling it live

- HTTP 200 evidence for the exact Bukinistebi widget script and frame, with the iframe CSP allowing only the reviewed Bukinistebi origins.
- A real OpenAI run showing model, tool calls, status, latency, and token usage.
- A real Facebook inbound/outbound message ID and a real Instagram inbound/outbound message ID.
- A screenshot of the correct connected Meta accounts and one human handoff in the Inbox.
- Queue-worker and scheduler health evidence, plus a failed-job count of zero.

Until every row above passes on the public domains, describe the integration as **implemented and staging-tested**, not as a completed public Meta launch.
