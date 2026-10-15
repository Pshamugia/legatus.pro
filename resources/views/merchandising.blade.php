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
            <div style="margin-top:18px">{{ $products->links() }}</div>
        </section>
    </main>
</div>
<style nonce="{{ request()->attributes->get('csp_nonce') }}">@media(max-width:900px){.main .panel form{grid-template-columns:1fr!important}}</style>
@endsection
