@extends('layouts.app')
@section('title','Create your Legatus')
@section('body')
<div class="wrap"><nav class="nav"><a class="brand" href="{{ route('landing') }}"><span class="mark">L</span> legatus</a><span class="tag"><span class="dot"></span> One guided setup</span></nav>
<main class="onboard">
    <div style="text-align:center;margin-bottom:28px"><span class="tag">About 3 minutes</span><h1 style="font-size:42px;letter-spacing:-2px;margin-bottom:10px">Create your digital ambassador.</h1><p style="color:var(--muted)">მიუთითეთ ბიზნესი, ვებსაიტი და კატალოგი. Legatus ავტომატურად შექმნის სანდო ცოდნის ბაზას.</p></div>
    <form class="form-card" id="onboarding-form" method="post" enctype="multipart/form-data" action="{{ route('onboarding.store') }}">@csrf
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px"><div><label>ბიზნესის სახელი</label><input name="business_name" value="{{ old('business_name') }}" required placeholder="მაგ. Chapter & Co."></div><div><label>საჯარო ვებსაიტი</label><input name="website" value="{{ old('website') }}" type="url" placeholder="https://yourstore.ge"></div></div>
        <label>პროდუქტების კატალოგი ან პოლიტიკა</label><input name="catalog" type="file" accept=".csv,.txt,.pdf"><p class="channel" style="margin-top:7px">CSV: name, sku, category, description, price, stock, image · ან PDF policy · მაქს. 10 MB</p>
        <label>რას ყიდით და რა გამოგარჩევთ?</label><textarea name="description" rows="4" placeholder="აღწერეთ პროდუქცია, მომხმარებლები და ბრენდის ხმა...">{{ old('description') }}</textarea>
        @if($errors->any())<div style="color:#a43b32;margin-top:14px">{{ $errors->first() }}</div>@endif
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:25px"><span style="font-size:12px;color:var(--muted)">🔒 საჯარო URL მოწმდება; კატალოგი განიხილება როგორც მონაცემი და არა AI ინსტრუქცია.</span><button class="btn lime" id="create-button">Create Legatus →</button></div>
    </form>
    <div class="form-card" id="learning-state" hidden style="text-align:center;margin-top:18px"><div class="avatar" style="margin:auto">L</div><h3>Legatus is learning your business…</h3><p style="color:var(--muted)">ვიღებთ კონტენტს, ვამოწმებთ უსაფრთხოებას, ვანორმალიზებთ პროდუქტებს და ვქმნით საძიებო ცოდნას.</p><div class="progress" style="background:#edf1ed"><i style="width:82%"></i></div></div>
</main></div>
<script nonce="{{ request()->attributes->get('csp_nonce') }}">document.querySelector('#onboarding-form').addEventListener('submit',()=>{document.querySelector('#create-button').disabled=true;document.querySelector('#create-button').textContent='Learning…';document.querySelector('#learning-state').hidden=false});</script>
@endsection
