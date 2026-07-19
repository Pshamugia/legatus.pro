@extends('layouts.app')

@section('title', 'Sign in · Legatus')

@section('body')
<div class="chatpage">
    <div class="form-card" style="width:min(440px,100%)">
        <a class="brand" href="/"><span class="mark">L</span> Legatus</a>
        <h1 style="margin-top:32px">Welcome back.</h1>
        <p style="color:var(--muted)">Sign in to your AI sales team.</p>

        <form method="post" action="{{ route('login.store') }}">
            @csrf
            <label>Email</label>
            <input
                type="email"
                name="email"
                value="{{ old('email', config('legatus.demo_login_enabled') ? 'demo@legatus.ai' : '') }}"
                autocomplete="email"
                required
            >
            <label>Password</label>
            <input type="password" name="password" autocomplete="current-password" required>
            <label style="font-weight:400"><input type="checkbox" name="remember" style="width:auto"> Remember me</label>
            @error('email')<p style="color:#a43b32">{{ $message }}</p>@enderror
            <button class="btn lime" style="width:100%;margin-top:15px">Sign in →</button>
        </form>

        @if(config('legatus.registration_enabled'))
            <p style="text-align:center;font-size:13px;color:var(--muted);margin-top:22px">
                New to Legatus? <a href="{{ route('register') }}" style="color:var(--green);font-weight:700">Create a workspace</a>
            </p>
        @else
            <p style="text-align:center;font-size:13px;color:var(--muted);margin-top:22px">Workspace access is currently invite-only.</p>
        @endif
    </div>
</div>
@endsection
