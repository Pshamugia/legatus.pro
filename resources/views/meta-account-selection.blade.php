@extends('layouts.app')
@section('title','Choose a Meta account · Legatus')
@section('body')
<main style="min-height:100vh;display:grid;place-items:center;padding:32px;background:var(--cream)">
    <section class="panel" style="width:min(680px,100%);padding:30px">
        <span class="eyebrow">One final step</span>
        <h1 style="margin:8px 0 10px">Choose your {{ $provider === 'facebook' ? 'Facebook Page' : 'Instagram account' }}</h1>
        <p style="color:var(--muted);line-height:1.6;margin-bottom:22px">
            Legatus will connect only the account you select. Other Pages and accounts stay untouched.
        </p>

        <form method="post" action="{{ route('channels.meta.select', ['provider' => $provider, 'selection' => $selectionToken]) }}">
            @csrf
            <div style="display:grid;gap:10px">
                @foreach($accounts as $index => $account)
                    <label style="display:flex;gap:12px;align-items:center;border:1px solid var(--line);border-radius:14px;padding:15px;cursor:pointer;background:white">
                        <input type="radio" name="candidate_id" value="{{ $account['candidate_id'] }}" @checked($index === 0) required>
                        <span><strong>{{ $account['name'] }}</strong><br><small style="color:var(--muted)">{{ $account['description'] }}</small></span>
                    </label>
                @endforeach
            </div>
            @error('candidate_id')<p style="color:#a33">{{ $message }}</p>@enderror
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:22px;gap:12px">
                <a class="btn ghost" href="{{ route('channels.index') }}">Cancel</a>
                <button class="btn" type="submit">Connect selected account →</button>
            </div>
        </form>
        <p style="font-size:12px;color:var(--muted);margin:18px 0 0">This secure selection expires {{ $expiresAt->diffForHumans() }}.</p>
    </section>
</main>
@endsection
