@extends('layouts.app')
@section('title', 'Merchandising · Legatus')
@section('body')
@php($canManage = in_array($role, ['owner', 'admin'], true))
<div class="dash-shell">
    @include('partials.workspace-navigation', ['active' => 'merchandising'])
    <main class="main">
        <div class="topline"><div><span class="eyebrow">Sales controls</span><h1>Merchandising</h1><p style="color:var(--muted);margin:6px 0">Promote, lower, or suppress products inside relevant AI recommendations. Rules never override customer intent, stock, budget, or tenant boundaries.</p></div></div>
        @if(session('success'))<div class="panel" style="margin:18px 0;color:#267244">✓ {{ session('success') }}</div>@endif
        @if($errors->any())<div class="panel" style="margin:18px 0;color:#a33">{{ $errors->first() }}</div>@endif
        <form class="panel" method="get" style="display:flex;gap:10px;margin:18px 0;align-items:end;flex-wrap:wrap">
            <label style="flex:1;min-width:220px">Find a product<input name="q" value="{{ $search }}" placeholder="Product name or SKU"></label>
            <button class="btn" type="submit">Search</button>
            @if($search !== '')<a class="btn ghost" href="{{ route('merchandising.index') }}">Clear</a>@endif
        </form>
        <section class="panel">
            @forelse($products as $product)
                @php($rule = $product->merchandisingRule)
                <form method="post" action="{{ route('merchandising.update', $product) }}" style="display:grid;grid-template-columns:minmax(210px,1.3fr) repeat(2,minmax(120px,.5fr)) minmax(180px,1fr) auto;gap:10px;align-items:end;padding:14px 0;border-bottom:1px solid var(--line)">
                    @csrf @method('PUT')
                    <div><b>{{ $product->name }}</b><div class="channel">{{ $product->sku ?: 'No SKU' }} · {{ number_format((float)$product->price, 2) }} · {{ $product->is_active ? 'Active' : 'Inactive' }}</div></div>
                    <label>Status<select name="recommendation_status" @disabled(! $canManage)><option value="normal" @selected(($rule?->recommendation_status ?? 'normal') === 'normal')>Organic</option><option value="promote" @selected($rule?->recommendation_status === 'promote')>Promote</option><option value="exclude" @selected($rule?->recommendation_status === 'exclude')>Exclude</option></select></label>
                    <label>Priority<input type="number" name="priority" min="-100" max="100" value="{{ $rule?->priority ?? 0 }}" @disabled(! $canManage)></label>
                    <label>Internal campaign label<input name="campaign_label" maxlength="120" value="{{ $rule?->campaign_label }}" placeholder="Optional" @disabled(! $canManage)></label>
                    @if($canManage)<div style="display:flex;gap:7px"><button class="btn" type="submit">Save</button>@if($rule)<button class="btn ghost" type="submit" name="reset_rule" value="1">Reset</button>@endif</div>@endif
                    <label style="grid-column:1 / span 3">Proactive product-page message<input name="proactive_message" maxlength="240" value="{{ $rule?->proactive_message }}" placeholder="Optional message shown only on this product page" @disabled(! $canManage)></label>
                    <label style="grid-column:4 / span 2">Suggested customer reply<input name="proactive_prompt" maxlength="120" value="{{ $rule?->proactive_prompt }}" placeholder="For example: Tell me more" @disabled(! $canManage)></label>
                </form>
            @empty<p>No synchronized products found.</p>@endforelse
            @include('partials.compact-pagination', ['paginator' => $products])
        </section>
    </main>
</div>
<style nonce="{{ request()->attributes->get('csp_nonce') }}">
.compact-pagination{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-top:18px;padding-top:16px;border-top:1px solid var(--line)}.compact-pagination p{margin:0;color:var(--muted);font-size:12px}.compact-pagination__links{display:flex;align-items:center;gap:6px;flex-wrap:wrap}.compact-pagination__links a,.compact-pagination__links span{display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:34px;padding:0 10px;border:1px solid var(--line);border-radius:9px;background:#fff;color:var(--ink);font-size:12px;font-weight:700}.compact-pagination__links a:hover{border-color:#9eb8aa;background:#f3f8f4}.compact-pagination__links .is-current{border-color:var(--green);background:var(--green);color:#fff}.compact-pagination__links .is-disabled{color:#a4afaa;background:#f6f7f5}.compact-pagination__links .compact-pagination__ellipsis{min-width:22px;padding:0;border-color:transparent;background:transparent;color:var(--muted)}
@media(max-width:900px){.main .panel form{grid-template-columns:1fr!important}.compact-pagination{align-items:flex-start;flex-direction:column}.compact-pagination__links{width:100%}}
</style>
@endsection
