<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductMerchandisingRule;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MerchandisingController extends Controller
{
    public function index(Request $request, TenantContext $tenant)
    {
        $agent = $tenant->agent();
        $search = trim((string) $request->query('q', ''));
        $products = $agent->customerProducts()->with('merchandisingRule')
            ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search): void {
                $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search));
                $nested->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", ["%{$escaped}%"])
                    ->orWhereRaw("LOWER(COALESCE(sku, '')) LIKE ? ESCAPE '!'", ["%{$escaped}%"]);
            }))
            ->orderByDesc('is_active')->orderBy('name')->paginate(40)->withQueryString();

        return view('merchandising', ['agent' => $agent, 'products' => $products, 'search' => $search, 'role' => $tenant->role()]);
    }

    public function update(Request $request, Product $product, TenantContext $tenant)
    {
        $tenant->authorize(['owner', 'admin']);
        $agent = $tenant->agent();
        abort_unless((int) $product->agent_id === (int) $agent->id, 404);
        if ($request->boolean('reset_rule')) {
            ProductMerchandisingRule::where('agent_id', $agent->id)->where('product_id', $product->id)->delete();

            return back()->with('success', 'Product returned to organic ranking.');
        }
        $data = $request->validate([
            'recommendation_status' => ['required', Rule::in(['normal', 'promote', 'exclude'])],
            'priority' => ['required', 'integer', 'min:-100', 'max:100'],
            'campaign_label' => ['nullable', 'string', 'max:120'],
            'proactive_message' => ['nullable', 'string', 'max:240'],
            'proactive_prompt' => ['nullable', 'string', 'max:120'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);
        $label = trim((string) ($data['campaign_label'] ?? ''));
        $message = trim((string) ($data['proactive_message'] ?? ''));
        $prompt = trim((string) ($data['proactive_prompt'] ?? ''));
        ProductMerchandisingRule::updateOrCreate(
            ['agent_id' => $agent->id, 'product_id' => $product->id],
            array_merge($data, [
                'campaign_label' => $label === '' ? null : $label,
                'proactive_message' => $message === '' ? null : $message,
                'proactive_prompt' => $prompt === '' ? null : $prompt,
            ]),
        );

        return back()->with('success', 'Merchandising rule saved.');
    }

    public function destroy(Product $product, TenantContext $tenant)
    {
        $tenant->authorize(['owner', 'admin']);
        $agent = $tenant->agent();
        abort_unless((int) $product->agent_id === (int) $agent->id, 404);
        ProductMerchandisingRule::where('agent_id', $agent->id)->where('product_id', $product->id)->delete();

        return back()->with('success', 'Product returned to organic ranking.');
    }
}
