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
@endphp

<div class="wrap landing-wrap">
    <nav class="nav landing-nav">
        <a class="brand" href="{{ route('landing') }}"><span class="mark">L</span> Legatus</a>
        <div class="navlinks">
            <a href="#communication">{{ __('landing.nav.communication') }}</a>
            <a href="#social-media">{{ __('landing.nav.social') }}</a>
            <a href="#annual-offer">{{ __('landing.nav.annual') }}</a>
            <a href="#how-it-works">{{ __('landing.nav.how') }}</a>
            @auth
                <a href="{{ route('onboarding') }}">{{ __('landing.nav.setup') }}</a>
            @else
                <a href="{{ route('login') }}">{{ __('landing.nav.sign_in') }}</a>
            @endauth
            @include('partials.ui-locale-switcher')
            <a class="btn" href="{{ $primaryRoute }}">{{ __('landing.cta.start') }}</a>
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
                <div class="assistant-orbit orbit-one"></div><div class="assistant-orbit orbit-two"></div>
                <div class="assistant-core"><span>L</span></div>
                <div class="assistant-pulse pulse-one"></div><div class="assistant-pulse pulse-two"></div><div class="assistant-pulse pulse-three"></div>
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
        </section>

        <section class="story-section" id="business-knowledge">
            <div class="section-heading"><span class="section-number">02</span><h2>{{ __('landing.business.title') }}</h2></div>
            <div class="split-copy"><p>{{ __('landing.business.intro') }}</p><p>{{ __('landing.business.rules') }}</p></div>
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
            <div class="split-copy"><p>{{ __('landing.images.truth') }}</p><p>{{ __('landing.images.preparation') }}</p></div>
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

        <section class="steps-section" id="how-it-works">
            <div class="section-heading centered-heading"><span class="eyebrow">{{ __('landing.steps.label') }}</span><h2>{{ __('landing.steps.title') }}</h2></div>
            <div class="steps-grid"><article class="panel"><span>01</span><h3>{{ __('landing.steps.one_title') }}</h3><p>{{ __('landing.steps.one_body') }}</p></article><article class="panel"><span>02</span><h3>{{ __('landing.steps.two_title') }}</h3><p>{{ __('landing.steps.two_body') }}</p></article><article class="panel"><span>03</span><h3>{{ __('landing.steps.three_title') }}</h3><p>{{ __('landing.steps.three_body') }}</p></article></div>
        </section>

        <section class="final-cta" id="start">
            <h2>{{ __('landing.final.title') }}</h2><p>{{ __('landing.final.body') }}</p><p>{{ __('landing.final.rest') }}</p><strong>{{ __('landing.final.statement') }}</strong>
            <a class="btn lime" href="{{ $primaryRoute }}">{{ __('landing.cta.final') }}</a><small>{{ __('landing.final.annual_note') }}</small>
        </section>
    </main>

    <footer id="contact" class="panel landing-footer"><div><strong>{{ __('landing.footer.support') }}</strong><p><a href="mailto:{{ config('legatus.privacy_email') }}">{{ config('legatus.privacy_email') }}</a></p></div><div><a href="{{ route('terms') }}">{{ __('landing.footer.terms') }}</a><a href="{{ route('privacy') }}">{{ __('landing.footer.privacy') }}</a><a href="{{ route('refund-policy') }}">{{ __('landing.footer.refunds') }}</a></div></footer>
</div>

@push('head')
<link rel="preload" href="{{ asset('fonts/bpg_boxo-boxo.ttf') }}" as="font" type="font/ttf" crossorigin>
<link rel="preload" href="{{ asset('fonts/archyedt-bold-webfont.ttf') }}" as="font" type="font/ttf" crossorigin>
<style>
@font-face{font-family:'Legatus Boxo';src:url('{{ asset('fonts/bpg_boxo-boxo.ttf') }}') format('truetype');font-style:normal;font-weight:400;font-display:block}@font-face{font-family:'Legatus Archy';src:url('{{ asset('fonts/archyedt-bold-webfont.ttf') }}') format('truetype');font-style:normal;font-weight:700;font-display:block}
html[lang="ka"] body,html[lang="ka"] body *{font-family:'Legatus Boxo','Noto Sans Georgian',sans-serif!important}html[lang="ka"] body h1,html[lang="ka"] body h1 *,html[lang="ka"] body h2,html[lang="ka"] body h2 *,html[lang="ka"] body h3,html[lang="ka"] body h3 *,html[lang="ka"] body .brand,html[lang="ka"] body .brand *{font-family:'Legatus Archy','Noto Sans Georgian',sans-serif!important}
.landing-wrap{--landing-space:clamp(70px,9vw,118px)}.landing-nav{position:relative;z-index:5}.landing-hero{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(320px,.75fr);align-items:center;gap:clamp(32px,6vw,86px);min-height:650px;padding:80px 0 62px}.landing-hero-copy{max-width:790px}.landing-hero h1{max-width:780px;margin:22px 0;font-size:clamp(46px,5.3vw,78px);line-height:1.02;letter-spacing:-3px}.landing-hero p{max-width:730px;color:var(--muted);font-size:17px;line-height:1.75}.landing-hero .landing-lead{color:var(--ink);font-size:clamp(20px,2vw,27px);line-height:1.48}.landing-hero-visual{position:relative;display:grid;place-items:center;aspect-ratio:1;max-width:440px;margin:auto}.assistant-core{position:relative;z-index:3;display:grid;place-items:center;width:150px;height:150px;border-radius:42px;background:var(--green);box-shadow:0 28px 70px rgba(14,51,40,.28);transform:rotate(-8deg)}.assistant-core span{color:var(--lime);font:800 68px Manrope;transform:rotate(8deg)}.assistant-orbit{position:absolute;border:1px solid rgba(28,83,65,.18);border-radius:50%}.orbit-one{inset:16%}.orbit-two{inset:2%}.assistant-pulse{position:absolute;z-index:2;width:18px;height:18px;border-radius:50%;background:var(--lime);box-shadow:0 0 0 8px rgba(190,255,74,.16)}.pulse-one{top:17%;right:22%}.pulse-two{bottom:20%;left:13%;background:var(--green)}.pulse-three{right:3%;bottom:37%;width:12px;height:12px}
.annual-banner{display:flex;align-items:center;justify-content:space-between;gap:28px;padding:25px 30px;border:1px solid rgba(190,255,74,.5);border-radius:20px;background:var(--green);color:white}.annual-banner h2{margin:0 0 7px;font-size:24px}.annual-banner p{margin:0;color:#d3e2dc;line-height:1.55}.annual-banner a{flex:0 0 auto;color:var(--lime);font-weight:800}.story-section{padding:var(--landing-space) 0;border-bottom:1px solid var(--line)}.section-heading{display:grid;grid-template-columns:auto minmax(0,1fr);align-items:start;gap:20px;max-width:960px;margin-bottom:42px}.section-number{display:grid;place-items:center;width:44px;height:44px;border-radius:14px;background:var(--lime);font-weight:900}.section-heading h2,.annual-offer h2,.final-cta h2{margin:0;font-size:clamp(34px,4vw,54px);line-height:1.12;letter-spacing:-1.4px}.story-grid{display:grid;grid-template-columns:minmax(280px,.8fr) minmax(0,1.2fr);gap:clamp(34px,7vw,100px);align-items:center}.story-copy,.split-copy{color:var(--muted);font-size:17px;line-height:1.75}.story-copy p:first-child,.split-copy p:first-child{margin-top:0}.quote-stack{display:grid;gap:13px}.quote-stack span{display:block;width:max-content;max-width:100%;padding:15px 19px;border:1px solid var(--line);border-radius:16px 16px 16px 4px;background:white;font-size:18px;font-weight:800;box-shadow:0 12px 30px rgba(14,51,40,.06)}.quote-stack span:nth-child(2){margin-left:11%}.quote-stack span:nth-child(3){margin-left:22%}.section-emphasis,.statement{display:block;color:var(--green);font-size:clamp(20px,2.4vw,28px);line-height:1.5}.split-copy{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:40px}.statement{margin-top:32px}.feature-list{display:grid;gap:12px;margin:20px 0 0;padding:0;list-style:none}.feature-list li{position:relative;padding-left:28px;line-height:1.6}.feature-list li::before{content:'✓';position:absolute;left:0;top:0;color:var(--green);font-weight:900}.schedule-visual{display:grid;gap:12px;padding:25px;border:1px solid var(--line);border-radius:22px;background:#f2f6ef}.schedule-visual div{display:grid;grid-template-columns:74px 1fr 12px;align-items:center;gap:16px;padding:16px;border-radius:14px;background:white}.schedule-visual b{color:var(--green)}.schedule-visual span{font-weight:800}.schedule-visual i{width:10px;height:10px;border-radius:50%;background:var(--lime);box-shadow:0 0 0 5px rgba(190,255,74,.2)}
.annual-offer{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(270px,.8fr);gap:clamp(36px,7vw,100px);align-items:center;margin:var(--landing-space) 0;padding:clamp(34px,6vw,74px);border-radius:30px;background:var(--green);color:white;overflow:hidden}.annual-offer h2{margin:12px 0 22px}.annual-offer p{color:#d3e2dc;font-size:16px;line-height:1.7}.annual-highlight{padding:18px 20px;border-left:4px solid var(--lime);background:rgba(255,255,255,.07);color:white!important;font-weight:800}.annual-offer h3{margin-top:30px}.annual-list{grid-template-columns:repeat(2,minmax(0,1fr));color:#e5efeb}.annual-list li::before{color:var(--lime)}.annual-cta{margin-top:18px}.annual-card{position:relative;display:grid;place-items:center;align-content:center;min-height:360px;border:1px solid rgba(255,255,255,.2);border-radius:26px;background:linear-gradient(145deg,rgba(255,255,255,.13),rgba(255,255,255,.04));text-align:center}.annual-card span{color:var(--lime);font:800 clamp(90px,12vw,156px)/.85 Manrope}.annual-card strong{margin-top:14px;text-transform:uppercase;letter-spacing:3px}.annual-card i{width:60%;height:1px;margin:30px 0;background:rgba(255,255,255,.25)}.annual-card b{font-size:20px}.steps-section{padding:30px 0 var(--landing-space)}.centered-heading{display:block;max-width:780px;margin:0 auto 40px;text-align:center}.centered-heading h2{margin-top:12px}.steps-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}.steps-grid article{padding:28px}.steps-grid article>span{display:grid;place-items:center;width:38px;height:38px;border-radius:12px;background:var(--lime);font-weight:900}.steps-grid h3{margin:20px 0 10px}.steps-grid p{margin:0;color:var(--muted);line-height:1.65}.final-cta{display:flex;flex-direction:column;align-items:center;padding:clamp(54px,8vw,94px) clamp(22px,6vw,80px);border-radius:28px;background:#edf3eb;text-align:center}.final-cta h2{max-width:900px}.final-cta p{max-width:760px;margin:22px 0 0;color:var(--muted);font-size:17px;line-height:1.7}.final-cta strong{max-width:760px;margin-top:28px;color:var(--green);font-size:24px}.final-cta .btn{margin-top:30px}.final-cta small{margin-top:16px;color:var(--muted);font-weight:700}.landing-footer{display:flex;justify-content:space-between;gap:24px;margin:38px 0 40px;padding:28px;flex-wrap:wrap}.landing-footer p{margin:7px 0 0;color:var(--muted)}.landing-footer>div:last-child{display:flex;gap:18px;flex-wrap:wrap}
@media(max-width:900px){.landing-hero{grid-template-columns:1fr;min-height:0}.landing-hero-visual{width:min(75vw,360px)}.story-grid,.annual-offer{grid-template-columns:1fr}.annual-card{min-height:270px}.annual-list{grid-template-columns:1fr}}@media(max-width:700px){.landing-wrap{padding-inline:16px}.landing-nav{height:auto;min-height:68px;gap:10px}.navlinks{gap:8px}.navlinks>a:not(.btn),.navlinks>.ui-locale{display:none}.navlinks .btn{padding:10px 12px;font-size:12px}.landing-hero{padding:44px 0 48px;gap:18px}.landing-hero h1{font-size:clamp(38px,11vw,52px);letter-spacing:-1.8px}.landing-hero-visual{width:min(64vw,270px)}.assistant-core{width:104px;height:104px;border-radius:30px}.assistant-core span{font-size:48px}.annual-banner{align-items:flex-start;flex-direction:column;padding:22px}.story-section{padding:65px 0}.section-heading{grid-template-columns:1fr;gap:14px;margin-bottom:28px}.section-heading h2,.annual-offer h2,.final-cta h2{font-size:clamp(31px,9vw,40px)}.story-grid{gap:30px}.quote-stack span:nth-child(2),.quote-stack span:nth-child(3){margin-left:0}.split-copy{grid-template-columns:1fr;gap:8px}.schedule-visual{padding:14px}.schedule-visual div{grid-template-columns:66px 1fr 10px;padding:13px}.annual-offer{padding:34px 22px;border-radius:22px}.annual-card{min-height:220px}.steps-grid{grid-template-columns:1fr}.final-cta{border-radius:22px}.landing-footer{align-items:flex-start;flex-direction:column}}
</style>
@endpush
@endsection
