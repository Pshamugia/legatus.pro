@extends('layouts.app')
@section('title', 'Create workspace · Legatus')
@section('body')
@include('partials.auth-navigation')
<div class="chatpage" style="min-height:calc(100vh - 78px)">
    <div class="form-card" style="width:min(520px,100%)">
        <a class="brand" href="{{ route('landing') }}"><span class="mark">L</span> Legatus</a>
        <h1 style="margin-top:28px">Create your sales team.</h1>
        <form method="post" action="{{ route('register.store') }}">
            @csrf
            <input type="hidden" name="billing_period" value="{{ old('billing_period', $billingPeriod ?? 'monthly') }}">
            <input type="hidden" name="billing_package" value="{{ old('billing_package', $billingPackage ?? 'chat') }}">
            <label>Your name</label>
            <input name="name" required value="{{ old('name') }}">
            <label>Work email</label>
            <input type="email" name="email" required value="{{ old('email') }}">
            <label>Business name</label>
            <input name="business_name" required value="{{ old('business_name') }}">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                <div><label>Password</label><input type="password" name="password" required></div>
                <div><label>Confirm</label><input type="password" name="password_confirmation" required></div>
            </div>
            @if($errors->any())<p style="color:#a43b32">{{ $errors->first() }}</p>@endif
            <button class="btn lime" style="width:100%;margin-top:22px">Create workspace →</button>
        </form>
    </div>
</div>
@endsection
