# Paddle Sandbox setup

## Catalog prompt

Paste this into Paddle's catalog agent:

> Create my product catalog in my Paddle sandbox account.
>
> Create one product named "Legatus AI Sales Employee" with tax category `saas`.
>
> Attach three recurring USD prices:
> - USD 30.00 every 1 month (`"3000"` in lowest denomination)
> - USD 162.00 every 6 months (`"16200"`; 10% less than six monthly payments)
> - USD 288.00 every 1 year (`"28800"`; 20% less than twelve monthly payments)
>
> Add a 2-day free trial to all three recurring prices.
>
> Do not create country price overrides yet. When done, list the product ID and every price ID, labeled monthly, six-month, and yearly.

Copy the returned `pri_...` values into `.env` as `PADDLE_PRICE_MONTHLY`, `PADDLE_PRICE_SIX_MONTHS`, and `PADDLE_PRICE_YEARLY`.

## Authentication and webhook

In **Developer tools → Authentication**, create a sandbox client-side token and set `PADDLE_CLIENT_TOKEN=test_...`. Keep the API key server-only.

In **Developer tools → Notifications**, create a destination at `https://YOUR-PUBLIC-DOMAIN/webhooks/paddle` and subscribe to `subscription.created`, `subscription.updated`, `subscription.activated`, `subscription.canceled`, `subscription.paused`, and `subscription.resumed`. Set its unique secret as `PADDLE_WEBHOOK_SECRET=pdl_ntfset_...`.

For local tests, expose Laravel through an HTTPS tunnel. Use sandbox card `4242 4242 4242 4242`, any future expiry, and any three-digit CVC. Use `4000 0000 0000 0002` for a declined payment.
