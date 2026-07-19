<?php

namespace App\Http\Controllers;

use App\Services\TenantContext;

class ChannelController extends Controller
{
    public function index(TenantContext $tenant)
    {
        $agent = $tenant->agent();
        $snippet = '<script src="'.route('widget.script', $agent).'" async></script>';

        return view('channels', compact('agent', 'snippet'));
    }
}
