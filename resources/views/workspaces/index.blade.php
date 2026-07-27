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
                    <div style="display:flex;align-items:center;gap:8px">
                    @if($workspace->is($activeWorkspace))
                        <span class="pill">Active</span>
                    @else
                        <form method="post" action="{{ route('workspaces.switch', $workspace) }}">
                            @csrf
                            <button class="btn ghost" type="submit" style="padding:9px 12px">Switch</button>
                        </form>
                    @endif
                    @if($workspace->pivot->role === 'owner' && $workspaces->count() > 1)
                        <details style="position:relative">
                            <summary class="btn ghost" style="padding:9px 12px;color:#a33;cursor:pointer;list-style:none">Delete</summary>
                            <form class="panel" method="post" action="{{ route('workspaces.destroy', $workspace) }}" style="position:absolute;z-index:20;right:0;top:44px;width:min(330px,80vw);padding:16px;box-shadow:0 18px 50px #10291f30">
                                @csrf
                                @method('delete')
                                <strong>Permanently delete {{ $workspace->name }}?</strong>
                                <p style="margin:8px 0 12px;color:var(--muted);font-size:12px;line-height:1.45">Conversations, products, knowledge and channel connections will be removed. This cannot be undone. Type the exact business name to confirm.</p>
                                <input name="confirmation" required autocomplete="off" placeholder="{{ $workspace->name }}" aria-label="Type the exact business name">
                                <button class="btn" type="submit" style="margin-top:10px;width:100%;background:#a33;color:white">Delete permanently</button>
                            </form>
                        </details>
                    @elseif($workspace->pivot->role === 'owner')
                        <span title="Create another business before deleting this one" style="color:var(--muted);font-size:11px">Only business</span>
                    @endif
                    </div>
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
