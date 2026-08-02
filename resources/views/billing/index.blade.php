@extends('layouts.app')
@section('title', 'Choose your Legatus plan')
@section('body')
<div class="chatpage" style="display:block;padding:40px 24px"><main style="max-width:1050px;margin:auto">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:20px"><a class="brand" href="{{ route('landing') }}"><span class="mark">L</span> Legatus</a><form method="post" action="{{ route('logout') }}">@csrf<button class="btn ghost">Log out</button></form></div>
    <section style="text-align:center;margin:55px auto 34px;max-width:700px"><span class="tag"><span class="dot"></span>2-day free trial</span><h1 style="font-size:44px;letter-spacing:-2px;margin:18px 0 10px">Activate {{ $organization->name }}</h1><p style="color:var(--muted);font-size:17px">Add a payment method today. You will not be charged until the 2-day trial ends.</p></section>
    @if($subscription)<div class="panel" style="margin-bottom:20px;text-align:center">Subscription status: <strong>{{ ucfirst(str_replace('_', ' ', $subscription->status)) }}</strong>@if($subscription->trial_ends_at) · Trial ends {{ $subscription->trial_ends_at->format('M j, Y H:i') }}@endif</div>@endif
    @if(!$paddleClientToken || collect($plans)->contains(fn($plan) => blank($plan['price_id'])))<div class="panel" style="margin-bottom:20px;color:#9b5b12">Paddle Sandbox configuration is incomplete. Add the client token and all three price IDs to <code>.env</code>.</div>@endif
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:18px" class="billing-grid">
    @foreach($plans as $key => $plan)
        <article class="panel" style="padding:28px;position:relative;{{ $key === 'yearly' ? 'border:2px solid var(--green)' : '' }}">@if($key === 'yearly')<span class="pill" style="position:absolute;right:18px;top:18px">Best value</span>@endif<span class="eyebrow">{{ $plan['label'] }}</span><h2 style="font-size:38px;margin:16px 0 4px">{{ $plan['price'] }}</h2><p style="color:var(--muted);min-height:22px">{{ $plan['period'] }}</p>@if($plan['saving'])<p class="up"><strong>{{ $plan['saving'] }}</strong> compared with monthly</p>@else<p class="channel">Flexible monthly billing</p>@endif<ul style="color:var(--muted);line-height:1.9;padding-left:20px;margin:22px 0"><li>2-day free trial</li><li>Full admin access</li><li>AI sales employee setup</li></ul><button class="btn {{ $key === 'yearly' ? 'lime' : '' }} paddle-checkout" style="width:100%" data-price-id="{{ $plan['price_id'] }}" @disabled(blank($plan['price_id']) || blank($paddleClientToken))>Start free trial</button></article>
    @endforeach
    </div>
    <p id="paddle-message" style="text-align:center;color:var(--muted);margin-top:22px">Secure checkout powered by Paddle. Access is activated by the verified Paddle webhook.</p>
</main></div>
<div id="paddle-config" data-token="{{ $paddleClientToken }}" data-environment="{{ $paddleEnvironment }}" data-email="{{ auth()->user()->email }}" data-billing-reference="{{ $billingReference }}" data-success-url="{{ route('billing.index', ['checkout' => 'complete']) }}"></div>
<style>@media(max-width:800px){.billing-grid{grid-template-columns:1fr!important}}</style>
<script src="https://cdn.paddle.com/paddle/v2/paddle.js"></script>
<script>
(() => {
    const config = document.getElementById('paddle-config');
    const message = document.getElementById('paddle-message');
    if (!config?.dataset.token || !window.Paddle) return;
    if (config.dataset.environment === 'sandbox') window.Paddle.Environment.set('sandbox');
    window.Paddle.Initialize({
        token: config.dataset.token,
        eventCallback(event) {
            if (event.name === 'checkout.completed' && message) message.textContent = 'Payment details received. Activating your workspace…';
            if ((event.name === 'checkout.error' || event.name === 'checkout.payment.error') && message) message.textContent = 'Checkout could not be completed. Please try again.';
        },
    });
    document.querySelectorAll('.paddle-checkout').forEach((button) => button.addEventListener('click', () => window.Paddle.Checkout.open({
        items: [{ priceId: button.dataset.priceId, quantity: 1 }],
        customer: { email: config.dataset.email },
        customData: { billing_reference: config.dataset.billingReference },
        settings: { variant: 'one-page', successUrl: config.dataset.successUrl },
    })));
})();
</script>
@endsection
