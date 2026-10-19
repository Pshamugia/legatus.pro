<?php

namespace App\Http\Controllers;

use App\Models\CommerceConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WooCommerceWebhookController extends Controller
{
    /** Acknowledge previously registered order hooks without collecting order data. */
    public function __invoke(Request $request, CommerceConnection $connection): Response
    {
        abort_unless($connection->provider === 'woocommerce' && filled($connection->webhook_secret), 404);
        $body = $request->getContent();
        if (strlen($body) > 1_048_576) {
            abort(413);
        }
        $expected = base64_encode(hash_hmac('sha256', $body, $connection->webhook_secret, true));
        abort_unless(hash_equals($expected, (string) $request->header('X-WC-Webhook-Signature')), 401);

        return response('', 204);
    }
}
