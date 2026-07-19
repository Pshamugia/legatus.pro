@extends('layouts.app')
@section('title', 'Settings · Legatus')
@section('body')
@php($delivery = $agent->settings['delivery_policy'] ?? [])
<div class="dash-shell">
    <aside class="side">
        <a class="brand" href="/"><span class="mark">L</span> Legatus</a>
        <div class="menu">
            <a href="{{ route('dashboard') }}">◫ &nbsp; Overview</a>
            <a href="{{ route('inbox.index') }}">◌ &nbsp; Inbox</a>
            <a href="{{ route('knowledge.index') }}">◇ &nbsp; Knowledge</a>
            <a href="{{ route('dashboard') }}#products">▦ &nbsp; Products</a>
            <a href="{{ route('channels.index') }}">⌁ &nbsp; Channels</a>
            <a href="{{ route('analytics.index') }}">↗ &nbsp; Analytics</a>
            <a class="active" href="{{ route('settings.index') }}">⚙ &nbsp; Settings</a>
        </div>
    </aside>
    <main class="main">
        <div class="topline">
            <div><span class="eyebrow">{{ $organization->name }} · {{ ucfirst($role) }}</span><h1>Workspace settings</h1></div>
            <form method="post" action="{{ route('logout') }}">@csrf<button class="btn ghost">Sign out</button></form>
        </div>
        @if(session('success'))<div class="panel" style="margin:18px 0;color:#267244">✓ {{ session('success') }}</div>@endif
        @if($errors->any())<div class="panel" style="margin:18px 0;color:#a33">{{ $errors->first() }}</div>@endif
        <div class="content-grid" style="margin-top:22px">
            <form class="panel" method="post" action="{{ route('settings.update') }}">
                @csrf @method('PUT')
                <h3>AI employee behavior</h3>
                <label>Employee name</label><input name="agent_name" value="{{ old('agent_name', $agent->name) }}" required>
                <label>Brand tone</label><input name="tone" value="{{ old('tone', $agent->tone) }}" required>
                <label>Human handoff threshold</label><input name="handoff_threshold" type="number" step="0.01" min="0" max="1" value="{{ old('handoff_threshold', $agent->settings['handoff_threshold'] ?? .72) }}">
                <label>Maximum autonomous discount (%)</label><input name="discount_limit" type="number" min="0" max="100" value="{{ old('discount_limit', $agent->settings['discount_limit'] ?? 0) }}">
                <label>Business hours</label><textarea name="business_hours" rows="2">{{ old('business_hours', $agent->settings['business_hours'] ?? '') }}</textarea>

                <h3 style="margin-top:28px">Verified delivery policy</h3>
                <p style="color:var(--muted);font-size:13px">Delivery promises are calculated from these server-owned rules, never from a model guess.</p>
                <label>Timezone</label><input name="delivery_timezone" value="{{ old('delivery_timezone', $delivery['timezone'] ?? 'Asia/Tbilisi') }}" required>
                <label>Local cities (comma-separated)</label><input name="delivery_local_cities" value="{{ old('delivery_local_cities', implode(', ', $delivery['local_cities'] ?? ['თბილისი', 'Tbilisi'])) }}" required>
                <label>Daily cutoff</label><input name="delivery_cutoff" type="time" value="{{ old('delivery_cutoff', $delivery['cutoff'] ?? '18:00') }}" required>
                <label>Local business days</label><input name="delivery_local_days" type="number" min="1" max="10" value="{{ old('delivery_local_days', $delivery['local_business_days'] ?? 1) }}" required>
                <label>Regional minimum business days</label><input name="delivery_regional_min_days" type="number" min="1" max="30" value="{{ old('delivery_regional_min_days', $delivery['regional_min_business_days'] ?? 1) }}" required>
                <label>Regional maximum business days</label><input name="delivery_regional_max_days" type="number" min="1" max="30" value="{{ old('delivery_regional_max_days', $delivery['regional_max_business_days'] ?? 3) }}" required>
                <button class="btn lime" style="margin-top:18px">Save verified controls</button>
            </form>

            <section class="panel">
                <h3>Team & permissions</h3>
                @foreach($members as $member)
                    <div class="conversation">
                        <span class="avatar" style="width:36px;height:36px">{{ mb_strtoupper(mb_substr($member->name, 0, 1)) }}</span>
                        <div class="copy"><b>{{ $member->name }}</b><p>{{ $member->email }}</p></div>
                        <span class="pill">{{ $member->pivot->role }}</span>
                        @if($role === 'owner' && ! $member->is(auth()->user()))
                            <form method="post" action="{{ route('team.remove', $member) }}">@csrf @method('DELETE')<button class="btn ghost" style="padding:7px">×</button></form>
                        @endif
                    </div>
                @endforeach
                @if(in_array($role, ['owner', 'admin']))
                    <form method="post" action="{{ route('team.add') }}" style="border-top:1px solid var(--line);margin-top:15px">
                        @csrf
                        <label>Add existing user by email</label><input type="email" name="email" required placeholder="teammate@example.com">
                        <label>Role</label><select name="role" style="width:100%;padding:13px;border:1px solid var(--line);border-radius:12px"><option value="agent">Agent</option><option value="admin">Admin</option><option value="viewer">Viewer</option></select>
                        <button class="btn" style="margin-top:14px">Add member</button>
                    </form>
                @endif
            </section>
        </div>
    </main>
</div>
@endsection
