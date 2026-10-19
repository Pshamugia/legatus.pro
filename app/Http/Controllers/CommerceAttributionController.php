<?php

namespace App\Http\Controllers;

use App\Models\CommerceAttribution;
use Illuminate\Http\RedirectResponse;

class CommerceAttributionController extends Controller
{
    public function visit(CommerceAttribution $attribution): RedirectResponse
    {
        return redirect()->away($attribution->destination_url, 302, [
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
