@extends('layouts.app')
@section('title', 'Businesses · Legatus')
@section('body')
<div class="wrap" style="padding-top:32px;padding-bottom:56px">
    @include('partials.workspace-navigation', ['active' => 'workspaces', 'variant' => 'topbar', 'organization' => $activeWorkspace, 'addBusinessUrl' => '#new-business'])

    <div class="topline">
        <div><span class="eyebrow">Business workspaces</span><h1>თქვენი ბიზნესები · Your businesses</h1><p style="color:var(--muted)">თითოეულ ბიზნესს აქვს საკუთარი AI თანამშრომელი, ცოდნა, არხები და საუბრები.</p></div>
    </div>

    @if(session('status'))<div class="panel" style="margin:18px 0;color:#267244">✓ {{ session('status') }}</div>@endif
    @if($errors->any())<div class="panel" style="margin:18px 0;color:#a33">{{ $errors->first() }}</div>@endif

    <div class="content-grid" style="margin-top:24px">
        <section class="panel">
            <h3>Business list</h3>
            @foreach($workspaces as $workspace)
                @php($agent = $workspace->agents->first())
                <div class="conversation" data-workspace-id="{{ $workspace->id }}">
                    <span class="avatar">{{ mb_strtoupper(mb_substr($workspace->name, 0, 1)) }}</span>
                    <div class="copy">
                        <b>{{ $workspace->name }}</b>
                        <p>{{ $agent?->assistantDisplayName() ?? 'AI Assistant' }} · {{ ucfirst($workspace->pivot->role) }}</p>
                    </div>
                    @if($workspace->is($activeWorkspace))
                        <span class="pill">Active</span>
                    @else
                        <form method="post" action="{{ route('workspaces.switch', $workspace) }}">
                            @csrf
                            <button class="btn ghost" type="submit" style="padding:9px 12px">Switch</button>
                        </form>
                    @endif
                </div>
            @endforeach
        </section>

        <form class="panel" id="new-business" method="post" action="{{ route('workspaces.store') }}">
            @csrf
            <h3>ახალი ბიზნესის დამატება</h3>
            <p style="color:var(--muted);font-size:13px;line-height:1.55">Create an isolated workspace, then complete the same guided onboarding as your first business.</p>
            <label for="new-business-name">Business name</label>
            <input id="new-business-name" name="business_name" value="{{ old('business_name') }}" maxlength="120" required placeholder="e.g. My second store">
            <button class="btn lime" type="submit" style="margin-top:18px;width:100%">Create business →</button>
        </form>
    </div>
</div>
@endsection
