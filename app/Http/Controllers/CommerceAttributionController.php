<?php

namespace App\Http\Controllers;

use App\Models\CommerceAttribution;
use App\Services\CommerceAttributionService;
use Illuminate\Http\RedirectResponse;

class CommerceAttributionController extends Controller
{
    public function visit(CommerceAttribution $attribution, CommerceAttributionService $attributions): RedirectResponse
    {
        return redirect()->away($attributions->visit($attribution), 302, [
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
