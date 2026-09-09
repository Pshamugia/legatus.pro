<?php

namespace App\Http\Controllers;

use App\Services\SocialMediaTemplateService;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
            'templates.facebook.image_style' => ['nullable', Rule::in(SocialMediaTemplateService::IMAGE_STYLES)],
            'templates.instagram' => ['required', 'array'],
            'templates.instagram.body_template' => ['required', 'string', 'max:1800'],
            'templates.instagram.delivery_enabled' => ['nullable', 'boolean'],
            'templates.instagram.delivery_text' => ['nullable', 'string', 'max:600'],
            'templates.instagram.image_style' => ['nullable', Rule::in(SocialMediaTemplateService::IMAGE_STYLES)],
            'templates.linkedin' => ['sometimes', 'array'],
            'templates.linkedin.body_template' => ['required_with:templates.linkedin', 'string', 'max:2800'],
            'templates.linkedin.delivery_enabled' => ['nullable', 'boolean'],
            'templates.linkedin.delivery_text' => ['nullable', 'string', 'max:600'],
            'templates.linkedin.image_style' => ['nullable', Rule::in(SocialMediaTemplateService::IMAGE_STYLES)],
            'preview_product_url' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
        ]);

        $agent = $tenant->agent();
        $updatesPreviewProduct = $request->exists('preview_product_url');
        $previewUrl = trim((string) ($data['preview_product_url'] ?? ''));
        if ($updatesPreviewProduct) {
            if ($previewUrl !== '' && ! $agent->customerProducts()->where('is_active', true)->get()
                ->contains(fn ($product): bool => $product->matchesPublicProductUrl($previewUrl))) {
                throw ValidationException::withMessages([
                    'preview_product_url' => 'Choose a product URL from this business synchronized catalog.',
                ]);
            }
        }

        $templates->save($agent, $request->user(), $data['templates']);

        if ($updatesPreviewProduct) {
            $settings = $agent->settings ?? [];
            $settings['social_preview_product_url'] = $previewUrl !== '' ? $previewUrl : null;
            $agent->update(['settings' => $settings]);
        }

        return redirect()->route('social-media.index')
            ->with('social_success', 'Social post templates were saved for this business.');
    }
}
