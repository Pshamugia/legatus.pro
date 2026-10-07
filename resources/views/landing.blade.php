@extends('layouts.app')

@section('title', __('landing.meta.title'))
@section('description', __('landing.meta.description'))

@section('body')
@php
    $primaryRoute = auth()->check()
        ? route('onboarding')
        : (config('legatus.registration_enabled')
            ? route('register')
            : ($demoAgent ? route('chat.show', $demoAgent) : route('login')));
    $annualRoute = auth()->check()
        ? route('billing.index', ['period' => 'yearly', 'package' => 'chat_social'])
        : (config('legatus.registration_enabled')
            ? route('register', ['period' => 'yearly', 'package' => 'chat_social'])
            : $primaryRoute);
    $planRoute = fn (string $period, string $package) => auth()->check()
        ? route('billing.index', ['period' => $period, 'package' => $package])
        : (config('legatus.registration_enabled')
            ? route('register', ['period' => $period, 'package' => $package])
            : $primaryRoute);
    $plans = [
        ['period' => 'monthly', 'label' => __('landing.pricing.monthly'), 'chat' => '$30', 'social' => '$60', 'chat_saving' => null, 'social_saving' => null, 'featured' => false],
        ['period' => 'six_months', 'label' => __('landing.pricing.six_months'), 'chat' => '$162', 'social' => '$324', 'chat_saving' => __('landing.pricing.save_18'), 'social_saving' => __('landing.pricing.save_36'), 'featured' => false],
        ['period' => 'yearly', 'label' => __('landing.pricing.yearly'), 'chat' => '$288', 'social' => '$576', 'chat_saving' => __('landing.pricing.save_72'), 'social_saving' => __('landing.pricing.save_144'), 'featured' => true],
    ];
@endphp

<div class="wrap landing-wrap">
    <nav class="nav landing-nav">
        <a class="brand" href="{{ route('landing') }}"><span class="mark">L</span><span class="brand-name">Legatus</span></a>
        <div class="landing-nav-primary">
            <a href="#how-it-works">{{ __('landing.nav.how') }}</a>
            <a href="#pricing">{{ __('landing.nav.pricing') }}</a>
            <a href="#ask-legatus">{{ __('landing.nav.ask') }}</a>
        </div>
        <div class="landing-nav-actions">
            @auth
                <a class="landing-account-link" href="{{ route('onboarding') }}">{{ __('landing.nav.setup') }}</a>
            @else
                <a class="landing-account-link" href="{{ route('login') }}">{{ __('landing.nav.sign_in') }}</a>
            @endauth
            @include('partials.ui-locale-switcher')
            <a class="btn landing-nav-cta" href="{{ $primaryRoute }}"><span class="cta-full">{{ __('landing.cta.start') }}</span><span class="cta-short">{{ __('landing.cta.start_short') }}</span></a>
        </div>
    </nav>

    <main>
        <section class="landing-hero" id="product">
            <div class="landing-hero-copy">
                <span class="tag"><span class="dot"></span> {{ __('landing.hero.label') }}</span>
                <h1>{{ __('landing.hero.title') }}</h1>
                <p class="landing-lead">{{ __('landing.hero.line_one') }}</p>
                <p>{{ __('landing.hero.description') }}</p>
                <p>{{ __('landing.hero.closing') }}</p>
                <div class="actions">
                    <a class="btn lime" href="{{ $primaryRoute }}">{{ __('landing.cta.start') }}</a>
                    <a class="btn ghost" href="#how-it-works">{{ __('landing.cta.see_how') }} ↓</a>
                </div>
            </div>
            <div class="landing-hero-visual" aria-hidden="true">
                <article class="showcase-post">
                    <header><span class="showcase-avatar">L</span><div><b>Legatus Social</b><small>{{ __('landing.visual.post_ready') }}</small></div><i>✓</i></header>
                    <div class="showcase-product-art"><span class="showcase-spark spark-one">✦</span><span class="showcase-product">L</span><span class="showcase-spark spark-two">✦</span></div>
                    <footer><span>Facebook</span><span>Instagram</span><b>18:30</b></footer>
                </article>
                <article class="showcase-workflow">
                    <header><span>⚡</span><b>{{ __('landing.visual.auto_publishing') }}</b><i>ON</i></header>
                    <div><em>1</em><span><small>{{ __('landing.visual.product') }}</small>{{ __('landing.visual.product_selected') }}</span></div>
                    <div><em>2</em><span><small>AI</small>{{ __('landing.visual.content_ready') }}</span></div>
                    <div><em>3</em><span><small>{{ __('landing.visual.publish') }}</small>Facebook + Instagram</span></div>
                </article>
                <article class="showcase-inbox">
                    <header><b>{{ __('landing.visual.social_inbox') }}</b><span>2 {{ __('landing.visual.new') }}</span></header>
                    <p><i>IG</i><span>{{ __('landing.communication.question_stock') }}</span><small>now</small></p>
                    <p><i>f</i><span>{{ __('landing.communication.question_price') }}</span><small>2m</small></p>
                    <footer>✦ {{ __('landing.visual.reply_ready') }}</footer>
                </article>
            </div>
        </section>

        <aside class="annual-banner" aria-labelledby="annual-banner-title">
            <div><h2 id="annual-banner-title">{{ __('landing.banner.title') }}</h2><p>{{ __('landing.banner.body') }}</p></div>
            <a href="#annual-offer">{{ __('landing.banner.link') }} →</a>
        </aside>

        <section class="story-section" id="communication">
            <div class="section-heading"><span class="section-number">01</span><h2>{{ __('landing.communication.title') }}</h2></div>
            <div class="story-grid">
                <div class="quote-stack" aria-label="{{ __('landing.communication.questions_label') }}"><span>{{ __('landing.communication.question_price') }}</span><span>{{ __('landing.communication.question_stock') }}</span><span>{{ __('landing.communication.question_recommend') }}</span></div>
                <div class="story-copy"><p>{{ __('landing.communication.problem') }}</p><p>{{ __('landing.communication.solution') }}</p><p class="section-emphasis">{{ __('landing.communication.channels') }}</p></div>
            </div>
            <aside class="installation-callout">
                <div><span class="installation-code" aria-hidden="true">&lt;/&gt;</span><div><h3>{{ __('landing.communication.script_title') }}</h3><p>{{ __('landing.communication.script_body') }}</p></div></div>
                <ul aria-label="{{ __('landing.communication.channels') }}"><li>Website</li><li>Messenger</li><li>Instagram</li><li>WhatsApp</li></ul>
            </aside>
        </section>

        <section class="story-section" id="business-knowledge">
            <div class="section-heading"><span class="section-number">02</span><h2>{{ __('landing.business.title') }}</h2></div>
            <div class="knowledge-layout">
                <div class="split-copy"><p>{{ __('landing.business.intro') }}</p><p>{{ __('landing.business.rules') }}</p></div>
                <div class="knowledge-visual" aria-hidden="true">
                    <div class="knowledge-core"><span>L</span><b>{{ __('landing.visual.business_brain') }}</b><small>{{ __('landing.visual.ready_to_answer') }}</small></div>
                    <span class="knowledge-node node-products">{{ __('landing.visual.products') }} <b>✓</b></span>
                    <span class="knowledge-node node-prices">{{ __('landing.visual.prices') }} <b>✓</b></span>
                    <span class="knowledge-node node-stock">{{ __('landing.visual.stock') }} <b>✓</b></span>
                    <span class="knowledge-node node-tone">{{ __('landing.visual.brand_voice') }} <b>✓</b></span>
                </div>
            </div>
            <strong class="statement">{{ __('landing.business.statement') }}</strong>
        </section>

        <section class="story-section" id="social-media">
            <div class="section-heading"><span class="section-number">03</span><h2>{{ __('landing.social.title') }}</h2></div>
            <div class="story-grid">
                <div class="story-copy"><p>{{ __('landing.social.problem') }}</p><p>{{ __('landing.social.intro') }}</p><ul class="feature-list"><li>{{ __('landing.social.item_products') }}</li><li>{{ __('landing.social.item_copy') }}</li><li>{{ __('landing.social.item_images') }}</li><li>{{ __('landing.social.item_publish') }}</li></ul></div>
                <div class="schedule-visual" aria-hidden="true"><div><b>09:00</b><span>Facebook</span><i></i></div><div><b>09:00</b><span>Instagram</span><i></i></div><div><b>18:30</b><span>Facebook</span><i></i></div></div>
            </div>
            <p class="section-emphasis">{{ __('landing.social.closing') }}</p>
        </section>

        <section class="story-section" id="product-images">
            <div class="section-heading"><span class="section-number">04</span><h2>{{ __('landing.images.title') }}</h2></div>
            <div class="image-story-layout">
                <div class="split-copy"><p>{{ __('landing.images.truth') }}</p><p>{{ __('landing.images.preparation') }}</p></div>
                <div class="image-editor-visual" aria-hidden="true">
                    <div class="image-stage image-stage-before"><small>{{ __('landing.visual.original') }}</small><span class="visual-product">L</span></div>
                    <span class="image-transform">✦</span>
                    <div class="image-stage image-stage-after"><small>{{ __('landing.visual.social_ready') }}</small><i class="visual-orbit"></i><span class="visual-product">L</span><b>AI</b></div>
                </div>
            </div>
            <strong class="statement">{{ __('landing.images.closing') }}</strong>
        </section>

        <section class="annual-offer" id="annual-offer">
            <div class="annual-offer-copy">
                <span class="eyebrow">{{ __('landing.annual.label') }}</span><h2>{{ __('landing.annual.title') }}</h2><p>{{ __('landing.annual.intro') }}</p>
                <p class="annual-highlight">{{ __('landing.annual.highlight') }}</p><h3>{{ __('landing.annual.includes') }}</h3>
                <ul class="feature-list annual-list"><li>{{ __('landing.annual.item_store') }}</li><li>{{ __('landing.annual.item_chat') }}</li><li>{{ __('landing.annual.item_messages') }}</li><li>{{ __('landing.annual.item_content') }}</li><li>{{ __('landing.annual.item_publishing') }}</li></ul>
                <p>{{ __('landing.annual.closing') }}</p><a class="btn lime annual-cta" href="{{ $annualRoute }}">{{ __('landing.cta.annual') }}</a>
            </div>
            <div class="annual-card" aria-hidden="true"><span>12</span><strong>{{ __('landing.annual.card_months') }}</strong><i></i><b>Legatus + Store</b></div>
        </section>

        <section class="pricing-section" id="pricing">
            <div class="section-heading centered-heading"><span class="eyebrow">{{ __('landing.pricing.label') }}</span><h2>{{ __('landing.pricing.title') }}</h2><p>{{ __('landing.pricing.description') }}</p></div>
            <div class="pricing-grid">
                @foreach($plans as $plan)
                    <article class="panel pricing-card{{ $plan['featured'] ? ' is-featured' : '' }}">
                        <div class="pricing-card-head"><span class="eyebrow">{{ $plan['label'] }}</span>@if($plan['featured'])<span class="tag">{{ __('landing.pricing.best_value') }}</span>@endif</div>
                        <div class="package-price"><div><strong>Legatus Chat</strong><small>{{ __('landing.pricing.chat_description') }}</small></div><div class="price-value"><b>{{ $plan['chat'] }}</b>@if($plan['chat_saving'])<span>{{ $plan['chat_saving'] }}</span>@endif</div><a class="btn ghost" href="{{ $planRoute($plan['period'], 'chat') }}">{{ __('landing.pricing.choose_chat') }}</a></div>
                        <div class="package-price package-price-social"><div><strong>Legatus Chat + Social</strong><small>{{ __('landing.pricing.social_description') }}</small></div><div class="price-value"><b>{{ $plan['social'] }}</b>@if($plan['social_saving'])<span>{{ $plan['social_saving'] }}</span>@endif</div><a class="btn{{ $plan['featured'] ? ' lime' : '' }}" href="{{ $planRoute($plan['period'], 'chat_social') }}">{{ __('landing.pricing.choose_social') }}</a></div>
                        @if($plan['featured'])<p class="store-included">{{ __('landing.pricing.store_included') }}</p>@endif
                    </article>
                @endforeach
            </div>
        </section>

        <section class="steps-section" id="how-it-works">
            <div class="section-heading centered-heading"><span class="eyebrow">{{ __('landing.steps.label') }}</span><h2>{{ __('landing.steps.title') }}</h2></div>
            <div class="steps-grid"><article class="panel"><span>01</span><h3>{{ __('landing.steps.one_title') }}</h3><p>{{ __('landing.steps.one_body') }}</p></article><article class="panel"><span>02</span><h3>{{ __('landing.steps.two_title') }}</h3><p>{{ __('landing.steps.two_body') }}</p></article><article class="panel"><span>03</span><h3>{{ __('landing.steps.three_title') }}</h3><p>{{ __('landing.steps.three_body') }}</p></article></div>
        </section>

        <section class="assistant-section" id="ask-legatus">
            <div class="assistant-section-copy">
                <span class="eyebrow">{{ __('landing.assistant.label') }}</span>
                <h2>{{ __('landing.assistant.title') }}</h2>
                <p>{{ __('landing.assistant.body') }}</p>
                @if($supportAgent)
                    <button class="btn lime" type="button" data-legatus-chat-open>{{ __('landing.assistant.cta') }}</button>
                @else
                    <a class="btn lime" href="mailto:{{ config('legatus.privacy_email') }}">{{ __('landing.assistant.fallback') }}</a>
                @endif
            </div>
            <div class="assistant-conversation" aria-hidden="true">
                <span>{{ __('landing.assistant.example_one') }}</span>
                <span>{{ __('landing.assistant.example_two') }}</span>
                <span>{{ __('landing.assistant.example_three') }}</span>
                <b>Legatus <i>●</i></b>
            </div>
        </section>

        <section class="final-cta" id="start">
            <h2>{{ __('landing.final.title') }}</h2><p>{{ __('landing.final.body') }}</p><p>{{ __('landing.final.rest') }}</p><strong>{{ __('landing.final.statement') }}</strong>
            <a class="btn lime" href="{{ $primaryRoute }}">{{ __('landing.cta.final') }}</a><small>{{ __('landing.final.annual_note') }}</small>
        </section>
    </main>

    <footer id="contact" class="panel landing-footer"><div><strong>{{ __('landing.footer.support') }}</strong><p><a href="mailto:{{ config('legatus.privacy_email') }}">{{ config('legatus.privacy_email') }}</a></p></div><div><a href="{{ route('terms') }}">{{ __('landing.footer.terms') }}</a><a href="{{ route('privacy') }}">{{ __('landing.footer.privacy') }}</a><a href="{{ route('refund-policy') }}">{{ __('landing.footer.refunds') }}</a></div></footer>
</div>

@if($supportWidgetUrl)
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
document.querySelectorAll('[data-legatus-chat-open]').forEach(button => button.addEventListener('click', () => {
    if (window.LegatusWidget && typeof window.LegatusWidget.open === 'function') {
        window.LegatusWidget.open();
        return;
    }

    window.LegatusWidgetRequestedOpen = true;
}));
</script>
<script src="{{ $supportWidgetUrl }}" async></script>
@endif

@push('head')
<link rel="preload" href="{{ asset('fonts/bpg_boxo-boxo.ttf') }}" as="font" type="font/ttf" crossorigin>
<link rel="preload" href="{{ asset('fonts/archyedt-bold-webfont.ttf') }}" as="font" type="font/ttf" crossorigin>
<link rel="preload" href="{{ asset('fonts/dachi-the-lynx.otf') }}" as="font" type="font/otf" crossorigin>
<style>
@font-face{font-family:'Legatus Boxo';src:url('{{ asset('fonts/bpg_boxo-boxo.ttf') }}') format('truetype');font-style:normal;font-weight:400;font-display:block}@font-face{font-family:'Legatus Archy';src:url('{{ asset('fonts/archyedt-bold-webfont.ttf') }}') format('truetype');font-style:normal;font-weight:700;font-display:block}@font-face{font-family:'Legatus Dachi';src:url('{{ asset('fonts/dachi-the-lynx.otf') }}') format('opentype');font-style:normal;font-weight:700;font-display:block}
html[lang="ka"] body,html[lang="ka"] body *{font-family:'Legatus Boxo','Noto Sans Georgian',sans-serif!important}html[lang="ka"] body h1,html[lang="ka"] body h1 *,html[lang="ka"] body h2,html[lang="ka"] body h2 *{font-family:'Legatus Dachi','Noto Sans Georgian',sans-serif!important}html[lang="ka"] body h3,html[lang="ka"] body h3 *,html[lang="ka"] body .brand,html[lang="ka"] body .brand *{font-family:'Legatus Archy','Noto Sans Georgian',sans-serif!important}
.landing-wrap{--landing-space:clamp(70px,9vw,118px)}.landing-nav{position:relative;z-index:5;gap:28px}.landing-nav-primary,.landing-nav-actions{display:flex;align-items:center}.landing-nav-primary{gap:28px;margin-left:auto;color:#52615c;font-size:14px}.landing-nav-actions{gap:12px;padding-left:20px;border-left:1px solid var(--line)}.landing-account-link{color:#52615c;font-size:14px}.landing-hero{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(320px,.75fr);align-items:center;gap:clamp(32px,6vw,86px);min-height:650px;padding:80px 0 62px}.landing-hero-copy{max-width:790px}.landing-hero h1{max-width:780px;margin:22px 0;font-size:clamp(46px,5.3vw,78px);line-height:1.02;letter-spacing:-3px}.landing-hero p{max-width:730px;color:var(--muted);font-size:17px;line-height:1.75}.landing-hero .landing-lead{color:var(--ink);font-size:clamp(20px,2vw,27px);line-height:1.48}.landing-hero-visual{position:relative;display:grid;place-items:center;aspect-ratio:1;max-width:440px;margin:auto}.assistant-core{position:relative;z-index:3;display:grid;place-items:center;width:150px;height:150px;border-radius:42px;background:var(--green);box-shadow:0 28px 70px rgba(14,51,40,.28);transform:rotate(-8deg)}.assistant-core span{color:var(--lime);font:800 68px Manrope;transform:rotate(8deg)}.assistant-orbit{position:absolute;border:1px solid rgba(28,83,65,.18);border-radius:50%}.orbit-one{inset:16%}.orbit-two{inset:2%}.assistant-pulse{position:absolute;z-index:2;width:18px;height:18px;border-radius:50%;background:var(--lime);box-shadow:0 0 0 8px rgba(190,255,74,.16)}.pulse-one{top:17%;right:22%}.pulse-two{bottom:20%;left:13%;background:var(--green)}.pulse-three{right:3%;bottom:37%;width:12px;height:12px}
.annual-banner{display:flex;align-items:center;justify-content:space-between;gap:28px;padding:25px 30px;border:1px solid rgba(190,255,74,.5);border-radius:20px;background:var(--green);color:white}.annual-banner h2{margin:0 0 7px;font-size:24px}.annual-banner p{margin:0;color:#d3e2dc;line-height:1.55}.annual-banner a{flex:0 0 auto;color:var(--lime);font-weight:800}.story-section{padding:var(--landing-space) 0;border-bottom:1px solid var(--line)}.section-heading{display:grid;grid-template-columns:auto minmax(0,1fr);align-items:start;gap:20px;max-width:960px;margin-bottom:42px}.section-number{display:grid;place-items:center;width:44px;height:44px;border-radius:14px;background:var(--lime);font-weight:900}.section-heading h2,.annual-offer h2,.final-cta h2{margin:0;font-size:clamp(34px,4vw,54px);line-height:1.12;letter-spacing:-1.4px}.story-grid{display:grid;grid-template-columns:minmax(280px,.8fr) minmax(0,1.2fr);gap:clamp(34px,7vw,100px);align-items:center}.story-copy,.split-copy{color:var(--muted);font-size:17px;line-height:1.75}.story-copy p:first-child,.split-copy p:first-child{margin-top:0}.quote-stack{display:grid;gap:13px}.quote-stack span{display:block;width:max-content;max-width:100%;padding:15px 19px;border:1px solid var(--line);border-radius:16px 16px 16px 4px;background:white;font-size:18px;font-weight:800;box-shadow:0 12px 30px rgba(14,51,40,.06)}.quote-stack span:nth-child(2){margin-left:11%}.quote-stack span:nth-child(3){margin-left:22%}.section-emphasis,.statement{display:block;color:var(--green);font-size:clamp(20px,2.4vw,28px);line-height:1.5}.split-copy{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:40px}.statement{margin-top:32px}.feature-list{display:grid;gap:12px;margin:20px 0 0;padding:0;list-style:none}.feature-list li{position:relative;padding-left:28px;line-height:1.6}.feature-list li::before{content:'✓';position:absolute;left:0;top:0;color:var(--green);font-weight:900}.schedule-visual{display:grid;gap:12px;padding:25px;border:1px solid var(--line);border-radius:22px;background:#f2f6ef}.schedule-visual div{display:grid;grid-template-columns:74px 1fr 12px;align-items:center;gap:16px;padding:16px;border-radius:14px;background:white}.schedule-visual b{color:var(--green)}.schedule-visual span{font-weight:800}.schedule-visual i{width:10px;height:10px;border-radius:50%;background:var(--lime);box-shadow:0 0 0 5px rgba(190,255,74,.2)}
.annual-offer{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(270px,.8fr);gap:clamp(36px,7vw,100px);align-items:center;margin:var(--landing-space) 0 0;padding:clamp(34px,6vw,74px);border-radius:30px;background:var(--green);color:white;overflow:hidden}.annual-offer h2{margin:12px 0 22px}.annual-offer p{color:#d3e2dc;font-size:16px;line-height:1.7}.annual-highlight{padding:18px 20px;border-left:4px solid var(--lime);background:rgba(255,255,255,.07);color:white!important;font-weight:800}.annual-offer h3{margin-top:30px}.annual-list{grid-template-columns:repeat(2,minmax(0,1fr));color:#e5efeb}.annual-list li::before{color:var(--lime)}.annual-cta{margin-top:18px}.annual-card{position:relative;display:grid;place-items:center;align-content:center;min-height:360px;border:1px solid rgba(255,255,255,.2);border-radius:26px;background:linear-gradient(145deg,rgba(255,255,255,.13),rgba(255,255,255,.04));text-align:center}.annual-card span{color:var(--lime);font:800 clamp(90px,12vw,156px)/.85 Manrope}.annual-card strong{margin-top:14px;text-transform:uppercase;letter-spacing:3px}.annual-card i{width:60%;height:1px;margin:30px 0;background:rgba(255,255,255,.25)}.annual-card b{font-size:20px}.pricing-section{padding:var(--landing-space) 0}.pricing-section .centered-heading p{max-width:660px;margin:14px auto 0;color:var(--muted);line-height:1.65}.pricing-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}.pricing-card{display:flex;flex-direction:column;gap:15px;padding:24px}.pricing-card.is-featured{border:2px solid var(--green);box-shadow:0 20px 45px rgba(14,51,40,.1)}.pricing-card-head{display:flex;align-items:center;justify-content:space-between;gap:12px}.package-price{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:7px 14px;padding:17px;border:1px solid var(--line);border-radius:14px;background:#f8faf8}.package-price>div:first-child{min-width:0}.package-price strong,.package-price small{display:block}.package-price small{margin-top:4px;color:var(--muted);font-size:11px;line-height:1.4}.package-price .btn{grid-column:1/-1;width:100%;margin-top:7px}.package-price-social{background:#eef5ea}.price-value{text-align:right}.price-value b{display:block;font:800 25px Manrope}.price-value span{display:block;color:#327145;font-size:10px;font-weight:800}.store-included{margin:0;padding:10px 12px;border-radius:10px;background:var(--lime);color:var(--ink);font-size:12px;font-weight:800;text-align:center}.steps-section{padding:30px 0 var(--landing-space)}.centered-heading{display:block;max-width:780px;margin:0 auto 40px;text-align:center}.centered-heading h2{margin-top:12px}.steps-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}.steps-grid article{padding:28px}.steps-grid article>span{display:grid;place-items:center;width:38px;height:38px;border-radius:12px;background:var(--lime);font-weight:900}.steps-grid h3{margin:20px 0 10px}.steps-grid p{margin:0;color:var(--muted);line-height:1.65}.final-cta{display:flex;flex-direction:column;align-items:center;padding:clamp(54px,8vw,94px) clamp(22px,6vw,80px);border-radius:28px;background:#edf3eb;text-align:center}.final-cta h2{max-width:900px}.final-cta p{max-width:760px;margin:22px 0 0;color:var(--muted);font-size:17px;line-height:1.7}.final-cta strong{max-width:760px;margin-top:28px;color:var(--green);font-size:24px}.final-cta .btn{margin-top:30px}.final-cta small{margin-top:16px;color:var(--muted);font-weight:700}.landing-footer{display:flex;justify-content:space-between;gap:24px;margin:38px 0 40px;padding:28px;flex-wrap:wrap}.landing-footer p{margin:7px 0 0;color:var(--muted)}.landing-footer>div:last-child{display:flex;gap:18px;flex-wrap:wrap}
.cta-short{display:none}@media(max-width:980px){.landing-nav-primary{display:none}.pricing-grid{grid-template-columns:1fr}.landing-hero{grid-template-columns:1fr;min-height:0}.landing-hero-visual{width:min(75vw,360px)}.story-grid,.annual-offer{grid-template-columns:1fr}.annual-card{min-height:270px}.annual-list{grid-template-columns:1fr}}@media(max-width:700px){.landing-wrap{padding-inline:16px}.landing-nav{height:auto;min-height:68px;gap:10px}.landing-nav-actions{min-width:0;margin-left:auto;padding-left:0;border-left:0;gap:8px}.landing-account-link{display:none}.landing-nav-actions .btn{padding:10px 12px;font-size:12px;white-space:nowrap}.landing-hero{padding:44px 0 48px;gap:18px}.landing-hero h1{font-size:clamp(38px,11vw,52px);letter-spacing:-1.8px}.landing-hero-visual{width:min(64vw,270px)}.assistant-core{width:104px;height:104px;border-radius:30px}.assistant-core span{font-size:48px}.annual-banner{align-items:flex-start;flex-direction:column;padding:22px}.story-section{padding:65px 0}.section-heading{grid-template-columns:1fr;gap:14px;margin-bottom:28px}.section-heading h2,.annual-offer h2,.final-cta h2{font-size:clamp(31px,9vw,40px)}.story-grid{gap:30px}.quote-stack span:nth-child(2),.quote-stack span:nth-child(3){margin-left:0}.split-copy{grid-template-columns:1fr;gap:8px}.schedule-visual{padding:14px}.schedule-visual div{grid-template-columns:66px 1fr 10px;padding:13px}.annual-offer{padding:34px 22px;border-radius:22px}.annual-card{min-height:220px}.package-price{grid-template-columns:1fr}.price-value{text-align:left}.steps-grid{grid-template-columns:1fr}.final-cta{border-radius:22px}.landing-footer{align-items:flex-start;flex-direction:column}}@media(max-width:520px){.landing-nav .brand-name,.landing-nav-actions>.ui-locale,.landing-nav-cta .cta-full{display:none}.landing-nav-actions{position:absolute;right:0;top:50%;transform:translateY(-50%)}.landing-nav-cta .cta-short{display:inline}}
</style>
<style>
html[lang="ka"] .landing-wrap h1,html[lang="ka"] .landing-wrap h2{letter-spacing:0!important}html[lang="ka"] .landing-hero h1{font-size:clamp(40px,4vw,58px);line-height:1.1}html[lang="ka"] .section-heading h2,html[lang="ka"] .annual-offer h2,html[lang="ka"] .final-cta h2{font-size:clamp(30px,3.2vw,44px);line-height:1.16}.installation-callout{display:flex;align-items:center;justify-content:space-between;gap:28px;margin-top:42px;padding:24px 26px;border:1px solid #cddbd3;border-radius:20px;background:#edf4ef}.installation-callout>div{display:flex;align-items:center;gap:18px;max-width:760px}.installation-code{display:grid;flex:0 0 auto;place-items:center;width:52px;height:52px;border-radius:15px;background:var(--green);color:var(--lime);font:800 16px Manrope}.installation-callout h3{margin:0 0 6px;font-size:19px}.installation-callout p{margin:0;color:var(--muted);line-height:1.55}.installation-callout ul{display:flex;justify-content:flex-end;gap:7px;margin:0;padding:0;list-style:none;flex-wrap:wrap}.installation-callout li{padding:7px 10px;border:1px solid #cfddd4;border-radius:99px;background:white;color:var(--green);font-size:11px;font-weight:800}@media(max-width:700px){html[lang="ka"] .landing-hero h1{font-size:clamp(34px,9vw,42px);line-height:1.12}html[lang="ka"] .section-heading h2,html[lang="ka"] .annual-offer h2,html[lang="ka"] .final-cta h2{font-size:clamp(28px,7.5vw,34px);line-height:1.18}.installation-callout{align-items:flex-start;flex-direction:column}.installation-callout>div{align-items:flex-start}.installation-callout ul{justify-content:flex-start}}@media(max-width:520px){.landing-nav{display:grid;grid-template-columns:auto auto;width:100%;max-width:100%;justify-content:space-between}.landing-nav-actions{position:static;margin:0;transform:none;justify-self:end}.installation-callout{padding:20px}.installation-code{width:44px;height:44px}}
</style>
<style>
.landing-hero-visual{isolation:isolate;min-height:500px;max-width:520px;width:100%;overflow:visible}.landing-hero-visual::before{content:'';position:absolute;z-index:-1;inset:4% -12% 0;border-radius:50%;background:radial-gradient(circle at 58% 42%,rgba(190,255,74,.22),rgba(221,238,229,.35) 38%,transparent 72%);filter:blur(4px)}.showcase-post,.showcase-workflow,.showcase-inbox{position:absolute;border:1px solid #dce5df;background:rgba(255,255,255,.96);box-shadow:0 22px 60px rgba(14,51,40,.13);backdrop-filter:blur(10px)}.showcase-post{z-index:2;top:16px;right:0;width:72%;overflow:hidden;border-radius:24px;transform:rotate(3deg)}.showcase-post header,.showcase-post footer,.showcase-workflow header,.showcase-inbox header{display:flex;align-items:center}.showcase-post header{gap:10px;padding:15px}.showcase-post header div{min-width:0;flex:1}.showcase-post header b,.showcase-post header small{display:block}.showcase-post header small{margin-top:2px;color:var(--muted);font-size:10px}.showcase-post header i{display:grid;place-items:center;width:23px;height:23px;border-radius:50%;background:#e8f5df;color:#3f7844;font-size:11px;font-style:normal}.showcase-avatar{display:grid;place-items:center;width:38px;height:38px;border-radius:12px;background:var(--green);color:var(--lime);font:800 18px Manrope}.showcase-product-art{position:relative;display:grid;place-items:center;height:230px;overflow:hidden;background:linear-gradient(145deg,#142f27 0 54%,#dfff84 54% 100%)}.showcase-product-art::before,.showcase-product-art::after{content:'';position:absolute;border:1px solid rgba(255,255,255,.28);border-radius:50%}.showcase-product-art::before{width:210px;height:210px}.showcase-product-art::after{width:150px;height:150px}.showcase-product{position:relative;z-index:2;display:grid;place-items:center;width:104px;height:128px;border:1px solid rgba(255,255,255,.28);border-radius:18px;background:linear-gradient(145deg,#285e4d,#15372d);box-shadow:18px 22px 35px rgba(4,22,16,.32);color:var(--lime);font:800 54px Manrope;transform:rotate(-7deg)}.showcase-spark{position:absolute;z-index:3;color:white;font-size:22px}.spark-one{top:28px;left:32px}.spark-two{right:28px;bottom:24px;color:var(--green)}.showcase-post footer{gap:7px;padding:12px 14px}.showcase-post footer span{padding:6px 9px;border-radius:99px;background:#edf5ed;color:#35624f;font-size:9px;font-weight:800}.showcase-post footer b{margin-left:auto;color:var(--green);font-size:10px}.showcase-workflow{z-index:3;left:-8px;bottom:24px;width:62%;padding:16px;border-radius:20px;transform:rotate(-2deg)}.showcase-workflow header{gap:8px;margin-bottom:12px}.showcase-workflow header>span{display:grid;place-items:center;width:30px;height:30px;border-radius:10px;background:#eff8d9}.showcase-workflow header b{flex:1;font-size:12px}.showcase-workflow header i{padding:4px 7px;border-radius:99px;background:#e4f7e5;color:#27814b;font-size:8px;font-style:normal;font-weight:900}.showcase-workflow>div{display:flex;align-items:center;gap:9px;margin-top:7px;padding:9px;border-radius:10px;background:#f6f8f6}.showcase-workflow em{display:grid;place-items:center;width:23px;height:23px;border:1px solid #d9e4dc;border-radius:7px;background:white;color:var(--green);font-size:9px;font-style:normal;font-weight:900}.showcase-workflow div span{font-size:9px;font-weight:800}.showcase-workflow div small{display:block;margin-bottom:2px;color:#93a09b;font-size:7px;letter-spacing:.7px}.showcase-inbox{z-index:4;right:-30px;bottom:-10px;width:48%;padding:14px;border-radius:20px;transform:rotate(2deg)}.showcase-inbox header{justify-content:space-between;margin-bottom:9px}.showcase-inbox header b{font-size:12px}.showcase-inbox header span{padding:4px 7px;border-radius:99px;background:#fff0ef;color:#c84f49;font-size:8px;font-weight:900;text-transform:uppercase}.showcase-inbox p{display:grid;grid-template-columns:27px minmax(0,1fr) auto;align-items:center;gap:7px;margin:7px 0;padding:8px;border-radius:10px;background:#f6f8f6}.showcase-inbox p i{display:grid;place-items:center;width:27px;height:27px;border-radius:8px;background:white;color:var(--green);font-size:8px;font-style:normal;font-weight:900}.showcase-inbox p span{overflow:hidden;font-size:9px;font-weight:800;text-overflow:ellipsis;white-space:nowrap}.showcase-inbox p small{color:#96a29d;font-size:7px}.showcase-inbox footer{margin-top:9px;color:#59776b;font-size:8px;font-weight:800}.knowledge-layout,.image-story-layout{display:grid;grid-template-columns:minmax(0,1fr) minmax(360px,.78fr);align-items:center;gap:clamp(36px,7vw,90px)}.knowledge-layout .split-copy,.image-story-layout .split-copy{grid-template-columns:1fr;gap:8px}.knowledge-visual{position:relative;min-height:330px;border:1px solid #dfe9e2;border-radius:28px;background:radial-gradient(circle at center,#e6f7d6 0 18%,#f2f7f1 18% 48%,#fbfcfa 48%);overflow:hidden}.knowledge-visual::before,.knowledge-visual::after{content:'';position:absolute;inset:18%;border:1px dashed #b9cec0;border-radius:50%}.knowledge-visual::after{inset:35%}.knowledge-core{position:absolute;z-index:2;left:50%;top:50%;display:grid;place-items:center;width:128px;height:128px;padding:15px;border-radius:32px;background:var(--green);color:white;text-align:center;transform:translate(-50%,-50%) rotate(-4deg);box-shadow:0 18px 38px rgba(14,51,40,.24)}.knowledge-core span{color:var(--lime);font:800 32px Manrope}.knowledge-core b{font-size:11px}.knowledge-core small{color:#bcd0c9;font-size:8px}.knowledge-node{position:absolute;z-index:3;display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid #dae5dd;border-radius:12px;background:white;box-shadow:0 10px 24px rgba(14,51,40,.08);font-size:10px;font-weight:800}.knowledge-node b{color:#3d8b51}.node-products{left:7%;top:14%}.node-prices{right:8%;top:19%}.node-stock{left:9%;bottom:15%}.node-tone{right:6%;bottom:12%}.image-editor-visual{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:14px;padding:24px;border:1px solid #dde8df;border-radius:26px;background:#f1f6ef}.image-stage{position:relative;display:grid;place-items:center;min-height:260px;overflow:hidden;border-radius:20px}.image-stage small{position:absolute;z-index:3;left:14px;top:13px;padding:5px 7px;border-radius:99px;background:rgba(255,255,255,.88);color:#456156;font-size:7px;font-weight:900;letter-spacing:.7px}.image-stage-before{background:#e5e9e4}.image-stage-after{background:linear-gradient(145deg,#173b30,#75a98f 56%,#dfff84)}.visual-product{position:relative;z-index:2;display:grid;place-items:center;width:82px;height:112px;border-radius:15px;background:var(--green);box-shadow:12px 17px 30px rgba(14,51,40,.24);color:var(--lime);font:800 40px Manrope}.image-stage-before .visual-product{background:#d8ded9;color:#7f9189;box-shadow:none}.visual-orbit{position:absolute;width:180px;height:180px;border:1px solid rgba(255,255,255,.34);border-radius:50%}.image-stage-after b{position:absolute;right:13px;bottom:12px;padding:6px 8px;border-radius:9px;background:var(--lime);color:var(--ink);font:900 9px Manrope}.image-transform{display:grid;place-items:center;width:34px;height:34px;border-radius:50%;background:var(--green);color:var(--lime);box-shadow:0 8px 20px rgba(14,51,40,.18)}
@media(max-width:1100px){.landing-hero{grid-template-columns:minmax(0,1fr) minmax(330px,.8fr)}.showcase-inbox{right:-6px}.knowledge-layout,.image-story-layout{grid-template-columns:1fr minmax(320px,.7fr)}}@media(max-width:980px){.landing-hero-visual{width:min(92vw,560px);min-height:510px}.knowledge-layout,.image-story-layout{grid-template-columns:1fr}.knowledge-visual,.image-editor-visual{width:min(100%,620px);margin:auto}.knowledge-layout .split-copy,.image-story-layout .split-copy{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:700px){.landing-hero-visual{width:100%;min-height:430px}.showcase-post{right:2%;width:76%}.showcase-product-art{height:180px}.showcase-workflow{left:0;bottom:20px;width:64%;padding:12px}.showcase-inbox{right:0;bottom:-6px;width:50%;padding:11px}.knowledge-layout .split-copy,.image-story-layout .split-copy{grid-template-columns:1fr}.knowledge-visual{min-height:290px}.image-editor-visual{gap:8px;padding:14px}.image-stage{min-height:210px}.visual-product{width:64px;height:90px;font-size:32px}.image-transform{width:28px;height:28px}}@media(max-width:430px){.landing-hero-visual{min-height:390px}.showcase-post{width:82%}.showcase-product-art{height:155px}.showcase-workflow{width:67%}.showcase-inbox{width:54%}.showcase-inbox p:nth-of-type(2){display:none}.knowledge-node{font-size:9px}.node-products{left:3%}.node-prices{right:3%}.node-stock{left:4%}.node-tone{right:2%}.image-editor-visual{grid-template-columns:1fr}.image-transform{position:relative;z-index:4;margin:-18px auto;transform:rotate(90deg)}.image-stage{min-height:190px}}
</style>
<style>
.assistant-section{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(320px,.9fr);align-items:center;gap:clamp(36px,7vw,96px);margin:0 0 var(--landing-space);padding:clamp(34px,6vw,70px);border-radius:30px;background:var(--green);color:white;overflow:hidden}.assistant-section-copy h2{margin:12px 0 18px;font-size:clamp(34px,4vw,54px);line-height:1.12;letter-spacing:-1.4px}.assistant-section-copy p{max-width:680px;margin:0 0 24px;color:#d3e2dc;font-size:17px;line-height:1.7}.assistant-section .eyebrow{color:var(--lime)}.assistant-section .btn{border:0;cursor:pointer}.assistant-conversation{position:relative;display:grid;gap:12px;padding:28px;border:1px solid rgba(255,255,255,.18);border-radius:24px;background:rgba(255,255,255,.08)}.assistant-conversation span{display:block;width:max-content;max-width:92%;padding:13px 16px;border-radius:15px 15px 15px 4px;background:white;color:var(--ink);font-size:13px;font-weight:800;line-height:1.45}.assistant-conversation span:nth-child(2){margin-left:8%}.assistant-conversation span:nth-child(3){margin-left:16%}.assistant-conversation b{display:flex;align-items:center;gap:8px;margin-top:8px;color:var(--lime);font:800 18px Manrope}.assistant-conversation i{color:#70d285;font-size:10px;font-style:normal}@media(max-width:900px){.assistant-section{grid-template-columns:1fr}.assistant-conversation{max-width:620px}}@media(max-width:520px){.assistant-section{padding:28px 22px}.assistant-section-copy h2{font-size:clamp(28px,7.5vw,34px)}.assistant-conversation{padding:18px}.assistant-conversation span:nth-child(2),.assistant-conversation span:nth-child(3){margin-left:0}}
</style>
@endpush
@endsection
