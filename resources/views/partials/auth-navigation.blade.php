<header class="wrap">
    <nav class="nav" aria-label="Public navigation">
        <a class="brand" href="{{ route('landing') }}"><span class="mark">L</span> Legatus</a>
        <span class="navlinks">
            <a href="{{ route('landing') }}#product">Product</a>
            <a href="{{ route('landing') }}#how-it-works">How it works</a>
            @if(Illuminate\Support\Facades\Route::currentRouteNamed('login'))
                @if(config('legatus.registration_enabled'))<a class="btn lime" href="{{ route('register') }}">Create workspace</a>@endif
            @else
                <a class="btn" href="{{ route('login') }}">Sign in</a>
            @endif
        </span>
    </nav>
</header>
