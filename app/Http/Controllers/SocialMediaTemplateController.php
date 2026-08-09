<?php

namespace App\Http\Controllers;

use App\Services\SocialMediaTemplateService;
use App\Services\TenantContext;
use Illuminate\Http\Request;

class SocialMediaTemplateController extends Controller
{
    public function update(Request $request, TenantContext $tenant, SocialMediaTemplateService $templates)
    {
        $tenant->authorize(['owner', 'admin']);
        $data = $request->validate([
            'templates' => ['required', 'array'],
            'templates.facebook' => ['required', 'array'],
            'templates.facebook.body_template' => ['required', 'string', 'max:5000'],
            'templates.facebook.delivery_enabled' => ['nullable', 'boolean'],
            'templates.facebook.delivery_text' => ['nullable', 'string', 'max:600'],
            'templates.instagram' => ['required', 'array'],
            'templates.instagram.body_template' => ['required', 'string', 'max:1800'],
            'templates.instagram.delivery_enabled' => ['nullable', 'boolean'],
            'templates.instagram.delivery_text' => ['nullable', 'string', 'max:600'],
        ]);

        $templates->save($tenant->agent(), $request->user(), $data['templates']);

        return redirect()->route('social-media.index')
            ->with('social_success', 'Facebook and Instagram post templates were saved for this business.');
    }
}
