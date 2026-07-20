@php
    $navigationVariant = $variant ?? 'sidebar';
    $navigationActive = $active ?? '';
    $navigationUser = auth()->user();
    $navigationWorkspaces = $navigationUser
        ? $navigationUser->organizations()->orderBy('organizations.name')->get()
        : collect();
    $navigationAgent = $agent ?? null;
    $navigationOrganization = $organization ?? null;
    $navigationWorkspaceId = (int) (session('legatus_organization_id')
        ?: ($navigationOrganization?->id ?? $navigationAgent?->organization_id ?? 0));
    $navigationWorkspace = $navigationWorkspaces->firstWhere('id', $navigationWorkspaceId)
        ?? $navigationWorkspaces->first();
    $navigationCanManage = in_array($navigationWorkspace?->pivot?->role, ['owner', 'admin'], true);
    $navigationBusinessName = trim((string) ($navigationAgent?->business_name
        ?: $navigationWorkspace?->name
        ?: 'Your business'));
    $navigationInboxCount = $needsHumanCount ?? ($metrics['needs_human'] ?? null);
    $navigationProductCount = $navigationAgent?->products_count;
    $workspaceIndexUrl = Illuminate\Support\Facades\Route::has('workspaces.index')
        ? route('workspaces.index')
        : route('settings.index');
    $navigationAddBusinessUrl = $addBusinessUrl ?? $workspaceIndexUrl;
@endphp

@once
    <style nonce="{{ request()->attributes->get('csp_nonce') }}">
        .app-side{display:flex;flex-direction:column;box-sizing:border-box;overflow:visible}.app-side .brand{margin:0 8px 18px}.app-side .menu{margin-top:16px}.app-side .menu a{display:flex;align-items:center;gap:10px}.app-side .menu a.active{box-shadow:inset 3px 0 0 var(--lime)}.app-nav-glyph{display:grid;place-items:center;width:22px;height:22px;border-radius:7px;background:#ffffff0d;color:#bcd0c9;font-size:11px;font-weight:800;letter-spacing:.02em}.app-side .menu a.active .app-nav-glyph{background:var(--lime);color:var(--ink)}.app-nav-count{margin-left:auto;min-width:21px;padding:2px 6px;border-radius:999px;background:#ffffff14;color:#dbe8e2;text-align:center;font-size:10px;font-weight:800}.workspace-switcher{position:relative}.workspace-switcher>summary{display:flex;align-items:center;gap:10px;min-width:0;padding:10px;border:1px solid #ffffff1c;border-radius:14px;background:#ffffff0b;color:white;cursor:pointer;list-style:none;transition:border-color .18s ease,background .18s ease}.workspace-switcher>summary::-webkit-details-marker{display:none}.workspace-switcher>summary:hover,.workspace-switcher[open]>summary{border-color:#ffffff3a;background:#ffffff12}.workspace-avatar{display:grid;place-items:center;flex:0 0 34px;height:34px;border-radius:11px;background:var(--lime);color:var(--ink);font-weight:900}.workspace-summary-copy{display:flex;min-width:0;flex:1;flex-direction:column;line-height:1.2}.workspace-summary-copy small{margin-bottom:3px;color:#a9c0b8;font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase}.workspace-summary-copy strong{overflow:hidden;color:white;font-size:13px;text-overflow:ellipsis;white-space:nowrap}.workspace-chevron{color:#a9c0b8;font-size:16px;transition:transform .18s ease}.workspace-switcher[open] .workspace-chevron{transform:rotate(180deg)}.workspace-menu{position:absolute;z-index:120;top:calc(100% + 8px);left:0;width:280px;box-sizing:border-box;padding:8px;border:1px solid var(--line);border-radius:15px;background:white;box-shadow:0 20px 50px #10291f2e;color:var(--ink)}.workspace-menu-label{display:block;padding:7px 9px;color:var(--muted);font-size:10px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}.workspace-menu form{margin:0}.workspace-option{display:flex;width:100%;box-sizing:border-box;align-items:center;gap:10px;padding:9px;border:0;border-radius:10px;background:transparent;color:var(--ink);font:inherit;text-align:left;cursor:pointer}.workspace-option:hover{background:#f2f5f1}.workspace-option.is-current{background:#eff6e9;cursor:default}.workspace-option-mark{display:grid;place-items:center;flex:0 0 28px;height:28px;border-radius:9px;background:#e7eee9;color:var(--green);font-size:11px;font-weight:900}.workspace-option.is-current .workspace-option-mark{background:var(--lime);color:var(--ink)}.workspace-option>span:last-child{display:flex;min-width:0;flex-direction:column}.workspace-option strong{overflow:hidden;font-size:12px;text-overflow:ellipsis;white-space:nowrap}.workspace-option small{margin-top:2px;color:var(--muted);font-size:10px}.workspace-empty{display:block;padding:10px;color:var(--muted);font-size:12px}.app-side-footer{margin-top:auto;padding:18px 4px 0;border-top:1px solid #ffffff1c}.app-side-account{display:flex;align-items:center;gap:9px;margin-top:12px}.app-side-user{display:flex;min-width:0;flex:1;flex-direction:column}.app-side-user strong,.app-side-user small{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.app-side-user strong{color:white;font-size:12px}.app-side-user small{color:#9fb7ae;font-size:10px}.app-add-business,.app-logout{display:flex;align-items:center;justify-content:center;box-sizing:border-box;border-radius:10px;font-size:12px;font-weight:800;text-decoration:none}.app-add-business{width:100%;padding:9px 10px;border:1px dashed #ffffff38;color:#dce9e3}.app-add-business:hover{border-color:var(--lime);color:var(--lime)}.app-logout-form{margin:0}.app-logout{padding:8px 10px;border:1px solid #ffffff24;background:transparent;color:#dce9e3;cursor:pointer}.app-logout:hover{border-color:#ffffff55;background:#ffffff0c}.app-side-footer>.app-logout-form{margin-top:10px}.app-side-footer>.app-logout-form .app-logout{width:100%}.app-mobile-nav{display:none}.app-workspace-topbar{display:flex;align-items:center;gap:14px;padding:20px 0;border-bottom:1px solid var(--line)}.app-workspace-topbar>.brand{margin-right:auto}.app-workspace-topbar .workspace-switcher{width:min(280px,35vw)}.app-workspace-topbar .workspace-switcher>summary{border-color:var(--line);background:white;color:var(--ink)}.app-workspace-topbar .workspace-summary-copy small{color:var(--muted)}.app-workspace-topbar .workspace-summary-copy strong{color:var(--ink)}.app-workspace-topbar .workspace-menu{right:0;left:auto}.app-workspace-topbar .app-add-business{width:auto;padding:10px 13px;border-style:solid;border-color:var(--line);color:var(--green)}.app-workspace-topbar .app-add-business:hover{border-color:var(--green);color:var(--green)}.app-workspace-topbar .app-logout{border-color:var(--line);color:var(--green)}.app-workspace-topbar .app-topbar-link{padding:10px 4px;color:var(--green);font-size:12px;font-weight:800}.app-mobile-controls{display:flex;align-items:center;gap:8px}.app-mobile-links{display:flex;gap:5px;overflow-x:auto;padding-top:9px;scrollbar-width:none}.app-mobile-links::-webkit-scrollbar{display:none}.app-mobile-links a{flex:0 0 auto;padding:7px 9px;border-radius:9px;color:var(--muted);font-size:11px;font-weight:750}.app-mobile-links a.active{background:#e8f1df;color:var(--green)}
        @media(max-width:850px){.side.app-side{display:none}.app-mobile-nav{position:sticky;z-index:100;top:0;display:block;padding:10px 14px;border-bottom:1px solid var(--line);background:#ffffffee;box-shadow:0 7px 22px #18352b0d;backdrop-filter:blur(12px)}.app-mobile-controls>.brand{margin-right:auto;font-size:16px}.app-mobile-controls>.brand .mark{width:32px;height:32px}.app-mobile-nav .workspace-switcher{width:min(310px,44vw)}.app-mobile-nav .workspace-switcher>summary{padding:6px 8px;border-color:var(--line);background:white;color:var(--ink)}.app-mobile-nav .workspace-avatar{flex-basis:30px;height:30px}.app-mobile-nav .workspace-summary-copy small{display:none}.app-mobile-nav .workspace-summary-copy strong{color:var(--ink);font-size:12px}.app-mobile-nav .workspace-menu{right:0;left:auto}.app-mobile-nav .app-add-business{width:auto;min-width:34px;padding:8px;border-style:solid;border-color:var(--line);color:var(--green)}.app-mobile-nav .app-logout{min-width:34px;padding:8px;border-color:var(--line);color:var(--green)}.app-workspace-topbar{align-items:stretch;flex-wrap:wrap;padding:13px 0}.app-workspace-topbar>.brand{display:flex;align-items:center}.app-workspace-topbar .workspace-switcher{order:3;width:100%}.app-workspace-topbar .workspace-menu{right:auto;left:0;width:min(100%,310px)}.app-workspace-topbar .app-topbar-link{margin-left:auto}.app-workspace-topbar .app-add-business{width:auto}.app-workspace-topbar .app-logout{height:38px}}
        @media(max-width:560px){.app-mobile-controls>.brand{font-size:0}.app-mobile-nav .workspace-switcher{width:auto;min-width:0;flex:1}.app-mobile-nav .workspace-menu{right:-82px;width:min(88vw,310px)}.app-mobile-nav .workspace-avatar{display:none}.app-mobile-add-label{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}.app-mobile-logout-label{font-size:10px}.app-workspace-topbar>.brand{font-size:17px}.app-workspace-topbar .app-topbar-link{display:none}}
    </style>
@endonce

@if($navigationVariant === 'topbar')
    <header class="app-workspace-topbar">
        <a class="brand" href="{{ route('landing') }}"><span class="mark">L</span> legatus</a>
        <a class="app-topbar-link" href="{{ route('dashboard') }}">Dashboard</a>
        @if($navigationCanManage)<a class="app-topbar-link" href="{{ route('onboarding') }}">Business setup</a>@endif
        @include('partials.workspace-switcher')
        <a class="app-add-business" href="{{ $navigationAddBusinessUrl }}">+ Add business</a>
        <form class="app-logout-form" method="post" action="{{ route('logout') }}">
            @csrf
            <button class="app-logout" type="submit">Sign out</button>
        </form>
    </header>
@else
    <aside class="side app-side">
        <a class="brand" href="{{ route('landing') }}"><span class="mark">L</span> legatus</a>
        @include('partials.workspace-switcher')

        <nav class="menu app-primary-nav" aria-label="Workspace navigation">
            <a @class(['active' => $navigationActive === 'dashboard']) href="{{ route('dashboard') }}"><span class="app-nav-glyph">OV</span> Overview</a>
            @if($navigationCanManage)<a @class(['active' => $navigationActive === 'onboarding']) href="{{ route('onboarding') }}"><span class="app-nav-glyph">SU</span> Business setup</a>@endif
            <a @class(['active' => $navigationActive === 'inbox']) href="{{ route('inbox.index') }}"><span class="app-nav-glyph">IN</span> Inbox @if($navigationInboxCount)<span class="app-nav-count">{{ $navigationInboxCount }}</span>@endif</a>
            <a @class(['active' => $navigationActive === 'knowledge']) href="{{ route('knowledge.index') }}"><span class="app-nav-glyph">KN</span> Knowledge</a>
            <a @class(['active' => $navigationActive === 'products']) href="{{ route('dashboard') }}#products"><span class="app-nav-glyph">PR</span> Products @if($navigationProductCount)<span class="app-nav-count">{{ $navigationProductCount }}</span>@endif</a>
            <a @class(['active' => $navigationActive === 'channels']) href="{{ route('channels.index') }}"><span class="app-nav-glyph">CH</span> Channels</a>
            <a @class(['active' => $navigationActive === 'analytics']) href="{{ route('analytics.index') }}"><span class="app-nav-glyph">AN</span> Analytics</a>
            <a @class(['active' => $navigationActive === 'settings']) href="{{ route('settings.index') }}"><span class="app-nav-glyph">ST</span> Settings</a>
        </nav>

        <div class="app-side-footer">
            <a class="app-add-business" href="{{ $navigationAddBusinessUrl }}">+ Add business</a>
            <div class="app-side-account">
                <span class="workspace-option-mark" aria-hidden="true">{{ mb_strtoupper(mb_substr($navigationUser?->name ?? 'U', 0, 1)) }}</span>
                <span class="app-side-user"><strong>{{ $navigationUser?->name }}</strong><small>{{ $navigationUser?->email }}</small></span>
            </div>
            <form class="app-logout-form" method="post" action="{{ route('logout') }}">
                @csrf
                <button class="app-logout" type="submit">Sign out</button>
            </form>
        </div>
    </aside>

    <header class="app-mobile-nav">
        <div class="app-mobile-controls">
            <a class="brand" href="{{ route('landing') }}"><span class="mark">L</span> legatus</a>
            @include('partials.workspace-switcher')
            <a class="app-add-business" href="{{ $navigationAddBusinessUrl }}" title="Add business"><span aria-hidden="true">+</span><span class="app-mobile-add-label"> Add business</span></a>
            <form class="app-logout-form" method="post" action="{{ route('logout') }}">
                @csrf
                <button class="app-logout" type="submit" title="Sign out"><span aria-hidden="true">&#8594;</span><span class="app-mobile-logout-label"> Sign out</span></button>
            </form>
        </div>
        <nav class="app-mobile-links" aria-label="Workspace navigation">
            <a @class(['active' => $navigationActive === 'dashboard']) href="{{ route('dashboard') }}">Overview</a>
            @if($navigationCanManage)<a @class(['active' => $navigationActive === 'onboarding']) href="{{ route('onboarding') }}">Setup</a>@endif
            <a @class(['active' => $navigationActive === 'inbox']) href="{{ route('inbox.index') }}">Inbox @if($navigationInboxCount)({{ $navigationInboxCount }})@endif</a>
            <a @class(['active' => $navigationActive === 'knowledge']) href="{{ route('knowledge.index') }}">Knowledge</a>
            <a @class(['active' => $navigationActive === 'products']) href="{{ route('dashboard') }}#products">Products</a>
            <a @class(['active' => $navigationActive === 'channels']) href="{{ route('channels.index') }}">Channels</a>
            <a @class(['active' => $navigationActive === 'analytics']) href="{{ route('analytics.index') }}">Analytics</a>
            <a @class(['active' => $navigationActive === 'settings']) href="{{ route('settings.index') }}">Settings</a>
        </nav>
    </header>
@endif
