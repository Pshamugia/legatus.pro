@extends('layouts.app')

@section('title', 'Legatus — AI Shopping Assistant, Social Media Manager & Copywriter')
@section('description', 'Legatus combines an AI shopping assistant, automated Facebook and Instagram publishing, and an AI copywriter in one platform.')

@section('body')
@php
    if (auth()->check()) {
        $primaryRoute = route('onboarding');
        $primaryLabel = 'Configure Legatus →';
    } elseif (config('legatus.registration_enabled')) {
        $primaryRoute = route('register');
        $primaryLabel = 'Create a workspace →';
    } elseif ($demoAgent) {
        $primaryRoute = route('chat.show', $demoAgent);
        $primaryLabel = 'Try the live demo ↗';
    } else {
        $primaryRoute = route('login');
        $primaryLabel = 'Sign in →';
    }
@endphp

<div class="wrap">
    <nav class="nav">
        <a class="brand" href="{{ route('landing') }}"><span class="mark">L</span> Legatus</a>
        <div class="navlinks">
            <a href="#product">Product</a>
            <a href="#trust">Trust</a>
            <a href="#pricing">Pricing</a>
            <a href="#contact">Contact</a>
            @auth
                <a href="{{ route('onboarding') }}">Business setup</a>
            @else
                <a href="{{ route('login') }}">Sign in</a>
            @endauth
            <a class="btn" href="{{ $primaryRoute }}">{{ $primaryLabel }}</a>
        </div>
    </nav>

    <main class="hero" id="product">
        <section>
            <span class="tag"><span class="dot"></span> AI team for sales, content &amp; social media</span>
            <h1>Sell more. Post smarter. <em>Write faster.</em></h1>
            <p>Legatus is your AI Shopping Assistant, Social Media Manager, and Copywriter — all in one platform. It helps customers choose the right products, creates content in three distinct styles, and automatically publishes posts to Facebook and Instagram.</p>
            <div class="actions">
                <a class="btn lime" href="{{ $primaryRoute }}">{{ $primaryLabel }}</a>
                <a class="btn ghost" href="#how-it-works">See how it works ↓</a>
            </div>
            <div class="proof">
                <span><b>24/7</b><br>Customer support</span>
                <span><b>Automatic</b><br>Social publishing</span>
                <span><b>3 styles</b><br>Simple · Creative · Informative</span>
                <span><b>KA · EN</b><br>Bilingual assistant</span>
            </div>
        </section>

        <section class="demo-card product-demo" aria-label="Illustrative product demonstration">
            <div class="demo-head">
                <div class="person">
                    <span class="avatar">L</span>
                    <div><strong>One platform. Three AI teammates.</strong><small>Shopping · Social · Copy</small></div>
                </div>
                <span class="tag">Illustrative demo · seeded catalog</span>
            </div>
            <div class="demo-tabs" role="tablist" aria-label="Legatus capabilities">
                <button class="demo-tab is-active" type="button" role="tab" aria-selected="true" data-demo-tab="shopping">Shopping</button>
                <button class="demo-tab" type="button" role="tab" aria-selected="false" data-demo-tab="social">Social</button>
                <button class="demo-tab" type="button" role="tab" aria-selected="false" data-demo-tab="copywriter">Copywriter</button>
            </div>
            <div class="chatbody demo-pane is-active" data-demo-pane="shopping">
                <div class="bubble user">I am looking for a contemporary novel like The Master and Margarita, under $30.</div>
                <div class="bubble ai">
                    <b>Piranesi</b> is the best match — it has a mysterious atmosphere and plays with the boundaries of reality. It costs $27.50, with 7 copies in stock.
                    <div class="product-row">
                        <div class="product-mini"><b>Piranesi</b><span>$27.50 · 7 in stock</span></div>
                        <div class="product-mini"><b>Before the Coffee Gets Cold</b><span>$26.90 · 9 in stock</span></div>
                    </div>
                    <div style="display:flex;gap:5px;flex-wrap:wrap;margin-top:10px">
                        <span class="tag">Seeded catalog snapshot</span>
                        <span class="tag">Confidence · 94%</span>
                        <span class="tag">check_stock</span>
                    </div>
                </div>
                <div class="bubble user">Can you offer an 18% discount on 10 copies for corporate gifts?</div>
                <div class="bubble ai">There are 14 in stock, but that discount exceeds my 10% approval limit. I will pass the quantity and full offer context to a manager.</div>
            </div>
            <div class="demo-pane social-demo" data-demo-pane="social" hidden>
                <div class="social-demo-head"><div><span class="eyebrow">September schedule</span><strong>Content planned and ready</strong></div><span class="pill">Active</span></div>
                <div class="social-calendar">
                    <div><b>08</b><span>Facebook</span><small>09:00 · Product post</small></div>
                    <div><b>08</b><span>Instagram</span><small>09:00 · Product post</small></div>
                    <div><b>09</b><span>Facebook</span><small>18:30 · Product post</small></div>
                </div>
                <div class="social-demo-note"><span>✓</span><p><b>Published automatically</b><br>Same verified product, image, and message across selected channels.</p></div>
            </div>
            <div class="demo-pane copy-demo" data-demo-pane="copywriter" hidden>
                <div class="copy-product"><span class="copy-cover">L</span><div><span class="eyebrow">Verified product</span><strong>One product. Three ready-to-publish voices.</strong></div></div>
                <article><span>Simple</span><p>A clear, concise introduction focused on what customers need to know.</p></article>
                <article><span>Creative</span><p>A vivid, memorable story that gives the product a distinctive social voice.</p></article>
                <article><span>Informative</span><p>A precise, composed description built from verified product details.</p></article>
            </div>
        </section>
    </main>

    <section class="metrics capability-metrics" aria-label="Legatus capabilities">
        <div class="metric"><b>AI Shopping Assistant</b><span>Answers questions, recommends verified products, and helps customers choose 24/7.</span></div>
        <div class="metric"><b>Social Media Manager</b><span>Plans and automatically publishes product posts to Facebook and Instagram.</span></div>
        <div class="metric"><b>AI Copywriter</b><span>Creates Simple, Creative, and Informative copy for every scheduled product.</span></div>
    </section>

    <section class="trust-section" id="trust" aria-labelledby="trust-title">
        <div class="trust-copy"><span class="eyebrow">Why you can trust Legatus</span><h2 id="trust-title">AI automation with verified business data and human control.</h2></div>
        <div class="metrics trust-metrics">
        <div class="metric"><b>Grounded</b><span>Prices and availability come only from verified business data</span></div>
        <div class="metric"><b>Observable</b><span>Sources, confidence, tools, latency, and escalation reasons for every flow</span></div>
        <div class="metric"><b>Human-led</b><span>Policy exceptions and uncertain cases are handed to a person</span></div>
        </div>
    </section>

    <section class="launch-steps" id="how-it-works" aria-labelledby="launch-steps-title">
        <div class="launch-steps-copy">
            <span class="eyebrow">Simple setup</span>
            <h2 id="launch-steps-title">Launch in 3 simple steps</h2>
        </div>
        <div class="launch-steps-grid">
            <article class="panel"><span>01</span><h3>Connect your website</h3><p>Legatus instantly learns your products and business rules.</p></article>
            <article class="panel"><span>02</span><h3>Turn on your channels</h3><p>Chat starts working automatically on your website, Facebook, and Instagram.</p></article>
            <article class="panel"><span>03</span><h3>Delegate the routine</h3><p>AI answers customers with verified information and plans posts months in advance.</p></article>
        </div>
    </section>

    <section id="pricing" style="padding:70px 0">
        <div style="text-align:center;max-width:680px;margin:0 auto 30px">
            <span class="eyebrow">Simple pricing</span>
            <h2 style="font-size:38px;margin:10px 0">Start with chat. Add social when you need it.</h2>
            <p style="color:var(--muted)">Choose a billing period, then add automated social publishing to your Legatus Chat subscription.</p>
        </div>
        <div class="billing-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:18px">
            @foreach([
                ['id' => 'monthly', 'key' => 'monthly', 'label' => 'Monthly', 'chat' => '$30', 'social' => '$60', 'chat_note' => 'billed every month', 'social_note' => 'Chat + Social · billed every month', 'addon' => '+$30/month', 'featured' => false],
                ['id' => 'six-months', 'key' => 'six_months', 'label' => '6 months', 'chat' => '$162', 'social' => '$324', 'chat_note' => 'billed every 6 months · save $18', 'social_note' => 'Chat + Social · billed every 6 months · save $36', 'addon' => '+$162/6 months', 'featured' => false],
                ['id' => 'annual', 'key' => 'yearly', 'label' => 'Annual', 'chat' => '$288', 'social' => '$576', 'chat_note' => 'billed every year · save $72', 'social_note' => 'Chat + Social · billed every year · save $144', 'addon' => '+$288/year', 'featured' => true],
            ] as $period)
                @php
                    $checkoutRoute = auth()->check()
                        ? route('billing.index', ['period' => $period['key'], 'package' => 'chat'])
                        : (config('legatus.registration_enabled')
                            ? route('register', ['period' => $period['key'], 'package' => 'chat'])
                            : $primaryRoute);
                @endphp
                <article class="panel billing-option{{ $period['featured'] ? ' billing-option-featured' : '' }}" data-chat-price="{{ $period['chat'] }}" data-social-price="{{ $period['social'] }}" data-chat-note="{{ $period['chat_note'] }}" data-social-note="{{ $period['social_note'] }}" data-checkout-base="{{ $checkoutRoute }}" data-period="{{ $period['key'] }}">
                    <span class="eyebrow">{{ $period['label'] }}</span>
                    <details class="billing-package-picker">
                        <summary><span class="billing-package-name">Legatus Chat</span><span aria-hidden="true">⌄</span></summary>
                        <label for="social-addon-{{ $period['id'] }}">
                            <input id="social-addon-{{ $period['id'] }}" class="social-addon-toggle" type="checkbox">
                            <span><strong>Add Social media manager</strong><small>Automated Facebook and Instagram publishing</small></span>
                            <b>{{ $period['addon'] }}</b>
                        </label>
                    </details>
                    <h3 aria-live="polite">{{ $period['chat'] }}</h3>
                    <p>{{ $period['chat_note'] }}</p>
                    <a class="btn billing-checkout-link{{ $period['featured'] ? ' lime' : '' }}" href="{{ $checkoutRoute }}">Start free trial</a>
                </article>
            @endforeach
        </div>
        <article class="creative-preview panel">
            <div><span class="eyebrow">Included with Social</span><h3>Automated publishing + AI Copywriter</h3><p>Schedule verified products for Facebook and Instagram, then publish original copy or choose Simple, Creative, or Informative AI-generated text.</p></div>
            <span class="creative-badge">Available now</span>
        </article>
    </section>

    <footer id="contact" class="panel" style="margin:0 0 40px;padding:28px;display:flex;justify-content:space-between;gap:24px;flex-wrap:wrap">
        <div><strong>Questions or support?</strong><p style="color:var(--muted);margin:7px 0 0"><a href="mailto:{{ config('legatus.privacy_email') }}">{{ config('legatus.privacy_email') }}</a></p></div>
        <div style="display:flex;gap:18px;flex-wrap:wrap"><a href="{{ route('terms') }}">Terms of Service</a><a href="{{ route('privacy') }}">Privacy Policy</a><a href="{{ route('refund-policy') }}">Refund Policy</a></div>
    </footer>
</div>
<style>
.hero{grid-template-columns:minmax(0,1fr) minmax(440px,.9fr);gap:48px}.hero h1{font-size:clamp(48px,5vw,68px)}.hero>section:first-child>p{max-width:660px}.proof{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px 24px}.product-demo{transform:none}.demo-tabs{display:grid;grid-template-columns:repeat(3,1fr);gap:7px;padding:0 15px 14px}.demo-tab{border:1px solid var(--line);border-radius:11px;padding:10px 7px;background:#f6f8f5;color:var(--muted);font:700 11px 'DM Sans';cursor:pointer}.demo-tab.is-active{border-color:var(--green);background:var(--green);color:#fff}.demo-pane{min-height:410px}.social-demo,.copy-demo{padding:22px;border-radius:20px;background:#f4f6f2}.social-demo-head{display:flex;align-items:center;justify-content:space-between;gap:14px;padding-bottom:18px}.social-demo-head>div{display:flex;flex-direction:column;gap:5px}.social-calendar{display:grid;gap:10px}.social-calendar>div{display:grid;grid-template-columns:42px 90px 1fr;align-items:center;gap:9px;padding:13px;border:1px solid var(--line);border-radius:13px;background:#fff}.social-calendar b{display:grid;place-items:center;width:36px;height:36px;border-radius:10px;background:var(--lime)}.social-calendar span{font-size:12px;font-weight:800}.social-calendar small{color:var(--muted)}.social-demo-note{display:flex;align-items:center;gap:11px;margin-top:16px;padding:14px;border-radius:13px;background:var(--green);color:#fff}.social-demo-note>span{display:grid;place-items:center;flex:0 0 30px;height:30px;border-radius:50%;background:var(--lime);color:var(--ink);font-weight:900}.social-demo-note p{margin:0;color:#d4e4de;font-size:12px;line-height:1.5}.social-demo-note b{color:#fff}.copy-product{display:flex;align-items:center;gap:12px;margin-bottom:14px}.copy-product>div{display:flex;flex-direction:column;gap:5px}.copy-cover{display:grid;place-items:center;flex:0 0 52px;height:66px;border-radius:7px;background:linear-gradient(145deg,var(--green),#0e2920);color:var(--lime);font:800 20px Manrope}.copy-demo article{padding:13px 14px;border:1px solid var(--line);border-radius:13px;background:#fff}.copy-demo article+article{margin-top:9px}.copy-demo article span{display:inline-flex;padding:4px 8px;border-radius:99px;background:#edf4e8;color:var(--green);font-size:10px;font-weight:800}.copy-demo article p{margin:7px 0 0;color:var(--muted);font-size:12px;line-height:1.5}.capability-metrics{padding-top:15px}.capability-metrics .metric{border-top:3px solid var(--green)}.capability-metrics .metric b{font-size:20px}.trust-section{padding:30px 0 45px;border-top:1px solid var(--line)}.trust-copy{max-width:720px}.trust-copy h2{font-size:36px;margin:10px 0}.trust-metrics{padding:20px 0 0}.trust-metrics .metric b{font-size:25px}
.launch-steps{padding:25px 0 70px}.launch-steps-copy{text-align:center;max-width:680px;margin:0 auto 28px}.launch-steps-copy h2{font-size:38px;margin:10px 0}.launch-steps-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}.launch-steps-grid article>span{display:grid;place-items:center;width:34px;height:34px;border-radius:11px;background:var(--lime);font-weight:800}.launch-steps-grid h3{margin:18px 0 8px}.launch-steps-grid p{color:var(--muted);font-size:14px;line-height:1.65;margin:0}
.billing-option{padding:28px;min-width:0}.billing-option h3{font-size:38px;margin:18px 0 4px}.billing-option>p{color:var(--muted);min-height:48px}.billing-option-featured{border:2px solid var(--green)}.billing-package-picker{position:relative;margin-top:12px}.billing-package-picker summary{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:11px 13px;border:1px solid var(--line);border-radius:12px;background:#f8faf8;cursor:pointer;font-weight:750;list-style:none}.billing-package-picker summary::-webkit-details-marker{display:none}.billing-package-picker[open] summary{border-color:var(--green);border-radius:12px 12px 0 0}.billing-package-picker label{display:grid;grid-template-columns:auto minmax(0,1fr) auto;align-items:center;gap:10px;padding:13px;border:1px solid var(--green);border-top:0;border-radius:0 0 12px 12px;background:#fff;cursor:pointer}.billing-package-picker input{width:18px;height:18px;accent-color:var(--green)}.billing-package-picker label span{display:flex;flex-direction:column;gap:3px}.billing-package-picker label small{color:var(--muted);font-size:11px;line-height:1.35}.billing-package-picker label b{color:var(--green);font-size:12px;white-space:nowrap}.billing-checkout-link{display:block;width:100%;margin-top:14px;text-align:center}.creative-preview{margin-top:18px;padding:24px 28px;display:flex;align-items:center;justify-content:space-between;gap:20px;background:#f3f4f2}.creative-preview h3{font-size:24px;margin:8px 0}.creative-preview p{margin:0;color:var(--muted)}.creative-badge{padding:9px 13px;border:1px solid var(--line);border-radius:999px;background:#fff;font-size:12px;font-weight:800;white-space:nowrap}
@media(max-width:850px){
    .hero{grid-template-columns:minmax(0,1fr);gap:42px}
    .hero>section{min-width:0}
    .demo-head{gap:14px;flex-wrap:wrap}
    .demo-head>.tag{max-width:100%;white-space:normal}
}
@media(max-width:600px){
    .wrap{padding-inline:16px}
    .nav{height:auto;min-height:68px;gap:10px}
    .navlinks{gap:8px}
    .navlinks .btn{padding:10px 12px;font-size:12px}
    .hero{padding:34px 0 58px;gap:32px}
    .hero h1{font-size:clamp(38px,13vw,46px);line-height:1.02;letter-spacing:-2.5px;margin:18px 0}
    .hero p{font-size:16px;line-height:1.65}
    .actions{flex-direction:column;align-items:stretch;margin-top:24px}
    .actions .btn{width:100%}
    .proof{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-top:28px}
    .demo-card{padding:10px;border-radius:21px}
    .demo-head{align-items:flex-start;padding:11px;flex-direction:column}
    .person{min-width:0}
    .person>div{min-width:0}
    .person strong,.person small{overflow-wrap:anywhere}
    .chatbody{padding:13px;min-height:0}
    .demo-pane{min-height:0}.demo-tabs{padding:0 11px 11px}.demo-tab{font-size:10px}.social-demo,.copy-demo{padding:14px}.social-calendar>div{grid-template-columns:38px 78px 1fr;padding:10px}.trust-copy h2{font-size:30px}.capability-metrics .metric b{font-size:19px}
    .bubble{max-width:94%;padding:11px 12px;font-size:13px}
    .metrics{padding:22px 0 55px}
    .metric{padding:20px 12px}
    .launch-steps{padding:10px 0 48px}
    .launch-steps-copy h2{font-size:32px}
    .launch-steps-grid{grid-template-columns:1fr}
    #pricing{padding:48px 0!important}
    #pricing h2{font-size:32px!important}
    .billing-grid{grid-template-columns:1fr!important}
    .creative-preview{align-items:flex-start;flex-direction:column}
}
@media(max-width:380px){
    .brand{font-size:18px}
    .navlinks .btn{padding:9px 10px}
    .proof{grid-template-columns:1fr}
}
</style>
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
document.addEventListener('DOMContentLoaded', () => {
    const demoTabs = document.querySelectorAll('[data-demo-tab]');
    const demoPanes = document.querySelectorAll('[data-demo-pane]');

    demoTabs.forEach((tab) => tab.addEventListener('click', () => {
        demoTabs.forEach((candidate) => {
            const selected = candidate === tab;
            candidate.classList.toggle('is-active', selected);
            candidate.setAttribute('aria-selected', selected ? 'true' : 'false');
        });
        demoPanes.forEach((pane) => {
            const selected = pane.dataset.demoPane === tab.dataset.demoTab;
            pane.hidden = !selected;
            pane.classList.toggle('is-active', selected);
        });
    }));

    const options = document.querySelectorAll('.billing-option');

    options.forEach((option) => {
        const toggle = option.querySelector('.social-addon-toggle');
        const packageName = option.querySelector('.billing-package-name');
        const price = option.querySelector('h3');
        const note = option.querySelector(':scope > p');
        const checkout = option.querySelector('.billing-checkout-link');

        if (!toggle || !packageName || !price || !note || !checkout) return;

        const updatePricing = () => {
            price.textContent = toggle.checked ? option.dataset.socialPrice : option.dataset.chatPrice;
            note.textContent = toggle.checked ? option.dataset.socialNote : option.dataset.chatNote;
            packageName.textContent = toggle.checked ? 'Legatus Chat + Social' : 'Legatus Chat';
            const checkoutUrl = new URL(option.dataset.checkoutBase, window.location.origin);
            checkoutUrl.searchParams.set('period', option.dataset.period);
            checkoutUrl.searchParams.set('package', toggle.checked ? 'chat_social' : 'chat');
            checkout.href = checkoutUrl.toString();
        };

        toggle.addEventListener('change', updatePricing);
        updatePricing();
    });
});
</script>
@endsection
