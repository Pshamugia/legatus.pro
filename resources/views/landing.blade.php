@extends('layouts.app')

@section('title', 'Legatus — Every conversation can become a sale')

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
            <a href="#product">პროდუქტი</a>
            <a href="#trust">ნდობა</a>
            <a href="#pricing">Pricing</a>
            <a href="#contact">Contact</a>
            @auth
                <a href="{{ route('dashboard') }}">Live dashboard</a>
            @else
                <a href="{{ route('login') }}">Sign in</a>
            @endauth
            <a class="btn" href="{{ $primaryRoute }}">{{ $primaryLabel }}</a>
        </div>
    </nav>

    <main class="hero" id="product">
        <section>
            <span class="tag"><span class="dot"></span> AI sales employee · online 24/7</span>
            <h1>You imagine. We  <em>execute</em></h1>
            <p>საქართველოში მცირე ონლაინ ბიზნესები Instagram-სა და Messenger-ში გაყიდვებს ხშირად ხელით მართავენ. როცა მფლობელს სძინავს ან დაკავებულია, პასუხი გვიანდება და გაყიდვა იკარგება. Legatus თითოეულ ბიზნესს აძლევს ციფრულ ელჩს, რომელიც მომხმარებელს უპასუხოდ არ ტოვებს — და ზუსტად იცის, როდის უნდა დაუძახოს ადამიანს.</p>
            <div class="actions">
                <a class="btn lime" href="{{ $primaryRoute }}">{{ $primaryLabel }}</a>
                @if($demoAgent && $primaryRoute !== route('chat.show', $demoAgent))
                    <a class="btn ghost" href="{{ route('chat.show', $demoAgent) }}">სცადე live demo ↗</a>
                @endif
            </div>
            <div class="proof">
                <span><b>URL + CSV</b><br>სწრაფი ონბორდინგი</span>
                <span><b>24/7</b><br>მომხმარებელთან</span>
                <span><b>KA · EN</b><br>ორენოვანი demo</span>
            </div>
        </section>

        <section class="demo-card" aria-label="Illustrative product demonstration">
            <div class="demo-head">
                <div class="person">
                    <span class="avatar">L</span>
                    <div><strong>Legatus · Chapter &amp; Co.</strong><small>● Online now</small></div>
                </div>
                <span class="tag">Illustrative demo · seeded catalog</span>
            </div>
            <div class="chatbody">
                <div class="bubble user">ვეძებ „ოსტატი და მარგარიტას“ მსგავს თანამედროვე წიგნს, 30 ლარამდე.</div>
                <div class="bubble ai">
                    თქვენთვის <b>Piranesi</b> საუკეთესო შესაბამისობაა — იდუმალი ატმოსფერო და რეალობის საზღვრებთან თამაში აქვს, 27.50 ₾ ღირს და მარაგში 7 ცალია.
                    <div class="product-row">
                        <div class="product-mini"><b>Piranesi</b><span>27.50 ₾ · 7 მარაგში</span></div>
                        <div class="product-mini"><b>Before the Coffee Gets Cold</b><span>26.90 ₾ · 9 მარაგში</span></div>
                    </div>
                    <div style="display:flex;gap:5px;flex-wrap:wrap;margin-top:10px">
                        <span class="tag">Seeded catalog snapshot</span>
                        <span class="tag">Confidence · 94%</span>
                        <span class="tag">check_stock</span>
                    </div>
                </div>
                <div class="bubble user">კორპორაციული საჩუქრებისთვის 10 ცალზე 18% ფასდაკლებას თუ გამიკეთებთ?</div>
                <div class="bubble ai">მარაგში 14 ცალია, მაგრამ ეს ფასდაკლება ჩემს 10%-იან ლიმიტს აღემატება. მენეჯერს გადავცემ რაოდენობასა და შეთავაზების სრულ კონტექსტს.</div>
            </div>
        </section>
    </main>

    <section class="metrics" id="trust">
        <div class="metric"><b>Grounded</b><span>ფასი და მარაგი მხოლოდ გადამოწმებული ბიზნესის მონაცემებიდან</span></div>
        <div class="metric"><b>Observable</b><span>წყარო, confidence, tools, latency და escalation reason თითოეულ flow-ში</span></div>
        <div class="metric"><b>Human-led</b><span>პოლიტიკის გამონაკლისები და დაბალი confidence ადამიანთან გადადის</span></div>
    </section>

    <section id="pricing" style="padding:70px 0">
        <div style="text-align:center;max-width:680px;margin:0 auto 30px">
            <span class="eyebrow">Simple pricing</span>
            <h2 style="font-size:38px;margin:10px 0">One product, three billing options</h2>
            <p style="color:var(--muted)">Every plan includes a 2-day free trial, full admin access, and AI sales employee setup.</p>
        </div>
        <div class="billing-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:18px">
            <article class="panel" style="padding:28px"><span class="eyebrow">Monthly</span><h3 style="font-size:38px;margin:16px 0 4px">$30</h3><p style="color:var(--muted)">billed every month</p></article>
            <article class="panel" style="padding:28px"><span class="eyebrow">6 months</span><h3 style="font-size:38px;margin:16px 0 4px">$162</h3><p style="color:var(--muted)">billed every 6 months · save $18</p></article>
            <article class="panel" style="padding:28px;border:2px solid var(--green)"><span class="eyebrow">Annual</span><h3 style="font-size:38px;margin:16px 0 4px">$288</h3><p style="color:var(--muted)">billed every year · save $72</p></article>
        </div>
    </section>

    <footer id="contact" class="panel" style="margin:0 0 40px;padding:28px;display:flex;justify-content:space-between;gap:24px;flex-wrap:wrap">
        <div><strong>Questions or support?</strong><p style="color:var(--muted);margin:7px 0 0"><a href="mailto:{{ config('legatus.privacy_email') }}">{{ config('legatus.privacy_email') }}</a></p></div>
        <div style="display:flex;gap:18px;flex-wrap:wrap"><a href="{{ route('terms') }}">Terms of Service</a><a href="{{ route('privacy') }}">Privacy Policy</a><a href="{{ route('refund-policy') }}">Refund Policy</a></div>
    </footer>
</div>
<style>@media(max-width:800px){.billing-grid{grid-template-columns:1fr!important}}</style>
@endsection
