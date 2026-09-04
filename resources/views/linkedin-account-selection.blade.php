@extends('layouts.app')
@section('title', 'Choose LinkedIn Page · Legatus')
@section('body')
<main class="main" style="max-width:760px;margin:40px auto">
    <section class="panel" style="padding:28px">
        <span class="eyebrow">LinkedIn</span><h1>Choose a company page</h1>
        <p>Select the LinkedIn Page where scheduled product posts should be published.</p>
        <form method="post" action="{{ route('channels.linkedin.select', $selectionToken) }}">@csrf
            @foreach($accounts as $account)
                <label style="display:flex;gap:10px;padding:14px;border:1px solid var(--line);border-radius:12px;margin:10px 0">
                    <input type="radio" name="organization_id" value="{{ $account['id'] }}" required> <strong>{{ $account['name'] }}</strong>
                </label>
            @endforeach
            <button class="btn" type="submit">Connect selected page</button>
        </form>
    </section>
</main>
@endsection
