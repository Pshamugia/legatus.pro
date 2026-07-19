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
            <h1>Every conversation can become <em>a sale.</em></h1>
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
</div>
@endsection
