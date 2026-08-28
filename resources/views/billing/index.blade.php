@extends('layouts.app')
@section('title', 'Legatus billing')
@section('body')
@php($hasBillingAccess = $subscription?->grantsAccess() ?? false)
<div class="chatpage" style="display:block;padding:40px 24px"><main style="max-width:1050px;margin:auto">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:20px"><a class="brand" href="{{ route('landing') }}"><span class="mark">L</span> Legatus</a><form method="post" action="{{ route('logout') }}">@csrf<button class="btn ghost">Log out</button></form></div>
    <section style="text-align:center;margin:55px auto 34px;max-width:700px">
        <span class="tag"><span class="dot"></span>{{ $hasBillingAccess ? 'Workspace activated' : '2-day free trial' }}</span>
        <h1 style="font-size:44px;letter-spacing:-2px;margin:18px 0 10px">{{ $hasBillingAccess ? 'You’re all set' : 'Activate '.$organization->name }}</h1>
        <p style="color:var(--muted);font-size:17px">{{ $hasBillingAccess ? $organization->name.' is ready to use.' : 'Add a payment method today. You will not be charged until the 2-day trial ends.' }}</p>
    </section>

    @if($hasBillingAccess)
        <div class="panel" style="padding:34px;text-align:center;max-width:700px;margin:0 auto">
            <span class="eyebrow">Subscription status</span>
            <h2 style="font-size:32px;margin:12px 0 8px">{{ $subscription->status === 'trialing' ? 'Free trial active' : 'Subscription active' }}</h2>
            @if($subscription->trial_ends_at)<p style="color:var(--muted);margin:0 0 24px">Your trial ends {{ $subscription->trial_ends_at->format('M j, Y H:i') }}. Billing starts automatically afterward.</p>@endif
            <a class="btn lime" style="display:inline-block;min-width:240px" href="{{ route('dashboard') }}">Go to admin dashboard</a>
        </div>
    @else
        @if(request('checkout') === 'complete')<div class="panel" id="activation-pending" style="margin-bottom:20px;text-align:center">Payment details received. Activating your workspace…</div>@endif
        @if($subscription)<div class="panel" style="margin-bottom:20px;text-align:center">Subscription status: <strong>{{ ucfirst(str_replace('_', ' ', $subscription->status)) }}</strong></div>@endif
        @if(!$paddleClientToken || collect($plans)->contains(fn($plan) => blank($plan['price_id'])))<div class="panel" style="margin-bottom:20px;color:#9b5b12">Paddle {{ $paddleEnvironment === 'sandbox' ? 'Sandbox' : 'Live' }} Chat configuration is incomplete. Add the client token and all three Chat price IDs to <code>.env</code>.</div>@endif
        @if(collect($plans)->contains(fn($plan) => blank($plan['social_price_id'])))<div class="panel" style="margin-bottom:20px;color:#9b5b12">Chat checkout is available. Chat + Social checkout becomes available after all three Social package price IDs are configured.</div>@endif
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:18px" class="billing-grid">
        @foreach($plans as $key => $plan)
            @php($socialSelected = $selectedPeriod === $key && $selectedPackage === 'chat_social')
            <article class="panel billing-plan" style="padding:28px;position:relative;{{ $key === 'yearly' ? 'border:2px solid var(--green)' : '' }}" data-period="{{ $key }}" data-chat-price="{{ $plan['price'] }}" data-social-price="{{ $plan['social_price'] }}" data-chat-period="{{ $plan['period'] }}" data-social-period="{{ $plan['social_period'] }}" data-chat-saving="{{ $plan['saving'] }}" data-social-saving="{{ $plan['social_saving'] }}" data-chat-price-id="{{ $plan['price_id'] }}" data-social-price-id="{{ $plan['social_price_id'] }}">
                @if($key === 'yearly')<span class="pill" style="position:absolute;right:18px;top:18px">Best value</span>@endif
                <span class="eyebrow">{{ $plan['label'] }}</span>
                <details class="billing-package-picker">
                    <summary><span class="billing-package-name">{{ $socialSelected ? 'Legatus Chat + Social' : 'Legatus Chat' }}</span><span aria-hidden="true">⌄</span></summary>
                    <label for="billing-social-{{ $key }}">
                        <input id="billing-social-{{ $key }}" class="billing-social-toggle" type="checkbox" @checked($socialSelected)>
                        <span><strong>Add Social media manager</strong><small>Automated Facebook and Instagram publishing</small></span>
                        <b>{{ $plan['addon'] }}</b>
                    </label>
                </details>
                <h2 class="billing-price" style="font-size:38px;margin:16px 0 4px">{{ $socialSelected ? $plan['social_price'] : $plan['price'] }}</h2>
                <p class="billing-period" style="color:var(--muted);min-height:22px">{{ $socialSelected ? $plan['social_period'] : $plan['period'] }}</p>
                <p class="billing-saving {{ ($socialSelected ? $plan['social_saving'] : $plan['saving']) ? 'up' : 'channel' }}"><strong>{{ ($socialSelected ? $plan['social_saving'] : $plan['saving']) ?: 'Flexible monthly billing' }}</strong>{{ ($socialSelected ? $plan['social_saving'] : $plan['saving']) ? ' compared with monthly' : '' }}</p>
                <ul style="color:var(--muted);line-height:1.9;padding-left:20px;margin:22px 0"><li>2-day free trial</li><li>Website, Facebook and Instagram chat</li><li class="social-feature" @if(!$socialSelected) hidden @endif>Automated social publishing</li><li>Full admin access</li></ul>
                <button class="btn {{ $key === 'yearly' ? 'lime' : '' }} paddle-checkout" style="width:100%" data-price-id="{{ $socialSelected ? $plan['social_price_id'] : $plan['price_id'] }}" data-package="{{ $socialSelected ? 'chat_social' : 'chat' }}" data-period="{{ $key }}" @disabled(blank($socialSelected ? $plan['social_price_id'] : $plan['price_id']) || blank($paddleClientToken))>Start free trial</button>
            </article>
        @endforeach
        </div>
        <p id="paddle-message" style="text-align:center;color:var(--muted);margin-top:22px">Secure checkout powered by Paddle. Access is activated by the verified Paddle webhook.</p>
    @endif
</main></div>
@unless($hasBillingAccess)
<div id="paddle-config" data-token="{{ $paddleClientToken }}" data-environment="{{ $paddleEnvironment }}" data-email="{{ auth()->user()->email }}" data-billing-reference="{{ $billingReference }}" data-success-url="{{ route('billing.index', ['checkout' => 'complete']) }}"></div>
<style>
.billing-package-picker{position:relative;margin-top:14px}.billing-package-picker summary{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:11px 13px;border:1px solid var(--line);border-radius:12px;background:#f8faf8;cursor:pointer;font-weight:750;list-style:none}.billing-package-picker summary::-webkit-details-marker{display:none}.billing-package-picker[open] summary{border-color:var(--green);border-radius:12px 12px 0 0}.billing-package-picker label{display:grid;grid-template-columns:auto minmax(0,1fr) auto;align-items:center;gap:10px;padding:13px;border:1px solid var(--green);border-top:0;border-radius:0 0 12px 12px;background:#fff;cursor:pointer}.billing-package-picker input{width:18px;height:18px;accent-color:var(--green)}.billing-package-picker label span{display:flex;flex-direction:column;gap:3px}.billing-package-picker label small{color:var(--muted);font-size:11px;line-height:1.35}.billing-package-picker label b{color:var(--green);font-size:12px;white-space:nowrap}
@media(max-width:800px){.billing-grid{grid-template-columns:1fr!important}}
</style>
<script nonce="{{ request()->attributes->get('csp_nonce') }}" src="https://cdn.paddle.com/paddle/v2/paddle.js"></script>
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(() => {
    if (document.getElementById('activation-pending')) window.setTimeout(() => window.location.reload(), 2000);
    const config = document.getElementById('paddle-config');
    const message = document.getElementById('paddle-message');
    const paddleReady = Boolean(config?.dataset.token && window.Paddle);
    if (paddleReady && config.dataset.environment === 'sandbox') window.Paddle.Environment.set('sandbox');
    if (paddleReady) {
    window.Paddle.Initialize({
        token: config.dataset.token,
        eventCallback(event) {
            if (event.name === 'checkout.completed' && message) message.textContent = 'Payment details received. Activating your workspace…';
            if ((event.name === 'checkout.error' || event.name === 'checkout.payment.error') && message) message.textContent = 'Checkout could not be completed. Please try again.';
        },
    });
    }
    document.querySelectorAll('.billing-plan').forEach((plan) => {
        const toggle = plan.querySelector('.billing-social-toggle');
        const packageName = plan.querySelector('.billing-package-name');
        const price = plan.querySelector('.billing-price');
        const period = plan.querySelector('.billing-period');
        const saving = plan.querySelector('.billing-saving');
        const socialFeature = plan.querySelector('.social-feature');
        const button = plan.querySelector('.paddle-checkout');

        const updatePlan = () => {
            const social = toggle.checked;
            const priceId = social ? plan.dataset.socialPriceId : plan.dataset.chatPriceId;
            packageName.textContent = social ? 'Legatus Chat + Social' : 'Legatus Chat';
            price.textContent = social ? plan.dataset.socialPrice : plan.dataset.chatPrice;
            period.textContent = social ? plan.dataset.socialPeriod : plan.dataset.chatPeriod;
            const savingText = social ? plan.dataset.socialSaving : plan.dataset.chatSaving;
            saving.className = `billing-saving ${savingText ? 'up' : 'channel'}`;
            saving.innerHTML = savingText ? `<strong>${savingText}</strong> compared with monthly` : '<strong>Flexible monthly billing</strong>';
            socialFeature.hidden = !social;
            button.dataset.priceId = priceId || '';
            button.dataset.package = social ? 'chat_social' : 'chat';
            button.disabled = !paddleReady || !priceId;
        };

        toggle.addEventListener('change', updatePlan);
        updatePlan();
        button.addEventListener('click', () => {
            if (!paddleReady || !button.dataset.priceId) return;
            window.Paddle.Checkout.open({
                items: [{ priceId: button.dataset.priceId, quantity: 1 }],
                customer: { email: config.dataset.email },
                customData: { billing_reference: config.dataset.billingReference, billing_package: button.dataset.package, billing_period: button.dataset.period },
                settings: { variant: 'one-page', successUrl: config.dataset.successUrl },
            });
        });
    });
})();
</script>
@endunless
@endsection
