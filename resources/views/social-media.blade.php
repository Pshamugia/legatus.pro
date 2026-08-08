@extends('layouts.app')
@section('title', 'Social media scheduler · Legatus')
@section('body')
<div class="dash-shell">
    @include('partials.workspace-navigation', ['active' => 'social-media'])
    <main class="main social-main">
        <div class="topline"><div><span class="eyebrow">AUTOMATED PUBLISHING</span><h1>Social media scheduler</h1><p>Publish verified public-site products to Facebook and Instagram automatically.</p></div></div>

        @if(session('social_success'))<div class="social-alert success">{{ session('social_success') }}</div>@endif
        @if($errors->any())<div class="social-alert error"><strong>Please check the schedule:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

        <div class="social-alert notice"><strong>Meta permission required</strong><br>Automatic publishing becomes available after Meta approves <code>pages_manage_posts</code> and <code>instagram_content_publish</code>, and the business reconnects its channels.</div>

        <section class="social-layout">
            <form class="panel schedule-form" method="post" action="{{ route('social-media.store') }}">
                @csrf
                <div class="section-heading"><div><span class="step">01</span><h2>Create a schedule</h2></div><span class="status-pill">Public catalog only</span></div>

                <div class="form-grid two">
                    <label>From date<input type="date" name="starts_on" min="{{ now()->toDateString() }}" value="{{ old('starts_on', now()->toDateString()) }}" required @disabled(!$canManage)></label>
                    <label>To date<input type="date" name="ends_on" min="{{ now()->toDateString() }}" value="{{ old('ends_on', now()->addMonth()->toDateString()) }}" required @disabled(!$canManage)></label>
                </div>
                <div class="form-grid two">
                    <label>Posts per day<input type="number" name="posts_per_day" min="1" max="24" value="{{ old('posts_per_day', 3) }}" required @disabled(!$canManage)><small>Posts are distributed evenly from 09:00 to 21:00.</small></label>
                    <label>Business timezone<select name="timezone" @disabled(!$canManage)><option value="Asia/Tbilisi" @selected(old('timezone', 'Asia/Tbilisi') === 'Asia/Tbilisi')>Asia/Tbilisi</option><option value="UTC" @selected(old('timezone') === 'UTC')>UTC</option></select></label>
                </div>

                <fieldset><legend>Publish to</legend><div class="choice-grid">
                    @foreach(['facebook' => 'Facebook Page', 'instagram' => 'Instagram'] as $provider => $label)
                        @php($connection = $connections->get($provider))
                        <label class="choice-card @if(!$connection?->isActive()) disabled @endif"><input type="checkbox" name="providers[]" value="{{ $provider }}" @checked(in_array($provider, old('providers', []), true)) @disabled(!$canManage || !$connection?->isActive())><span><strong>{{ $label }}</strong><small>{{ $connection?->isActive() ? ($connection->external_account_name ?: 'Connected') : 'Connect this channel first' }}</small></span></label>
                    @endforeach
                </div></fieldset>

                <fieldset><legend>Categories</legend><p class="field-help">Leave every category unchecked to rotate products from the whole public catalog.</p><div class="category-grid">
                    @forelse($categories as $category)<label class="category-choice"><input type="checkbox" name="categories[]" value="{{ $category }}" @checked(in_array($category, old('categories', []), true)) @disabled(!$canManage)><span>{{ $category }}</span></label>@empty<p>No public catalog categories are indexed yet.</p>@endforelse
                </div></fieldset>

                <div class="post-format"><strong>Post format</strong><div class="preview-card"><b>Product title</b><p>Product description from the public website…</p><span>შესაძენად გადადით საიტზე:<br>https://business.example/product</span></div></div>
                <button class="btn lime" type="submit" @disabled(!$canManage)>Create automatic schedule →</button>
            </form>

            <aside class="panel connection-panel"><h3>Publishing readiness</h3>@foreach(['facebook' => 'Facebook', 'instagram' => 'Instagram'] as $provider => $label)@php($connection = $connections->get($provider))<div class="readiness"><span class="readiness-dot {{ $connection?->isActive() ? 'ready' : '' }}"></span><div><strong>{{ $label }}</strong><small>{{ $connection?->isActive() ? 'Connected · publishing permission pending Meta approval' : 'Not connected' }}</small></div></div>@endforeach<a class="btn ghost" href="{{ route('channels.index') }}">Manage channels</a></aside>
        </section>

        <section class="panel schedule-list"><div class="section-heading"><div><span class="step">02</span><h2>Schedules</h2></div></div>
            @forelse($schedules as $schedule)<article class="schedule-row"><div><strong>{{ $schedule->starts_on->format('d M Y') }} — {{ $schedule->ends_on->format('d M Y') }}</strong><p>{{ $schedule->posts_per_day }} posts/day · {{ implode(', ', $schedule->providers) }} · {{ $schedule->categories ? implode(', ', $schedule->categories) : 'All categories' }}</p><small>{{ $schedule->published_posts_count }}/{{ $schedule->posts_count }} published @if($schedule->failed_posts_count) · {{ $schedule->failed_posts_count }} failed @endif</small></div><span class="status-pill {{ $schedule->status }}">{{ ucfirst($schedule->status) }}</span>@if($canManage)<form method="post" action="{{ route('social-media.pause', $schedule) }}">@csrf @method('PATCH')<button class="text-button" type="submit">{{ $schedule->status === 'active' ? 'Pause' : 'Resume' }}</button></form><form method="post" action="{{ route('social-media.destroy', $schedule) }}">@csrf @method('DELETE')<button class="text-button danger" type="submit">Remove</button></form>@endif</article>@empty<p class="empty-state">No schedules yet.</p>@endforelse
        </section>

        <section class="panel schedule-list"><div class="section-heading"><div><span class="step">03</span><h2>Upcoming posts</h2></div></div>
            @forelse($upcoming as $post)<article class="upcoming-row">@if($post->image_url)<img src="{{ $post->image_url }}" alt="">@endif<div><strong>{{ $post->title }}</strong><p>{{ ucfirst($post->provider) }} · {{ $post->scheduled_for->timezone('Asia/Tbilisi')->format('d M Y, H:i') }}</p><a href="{{ $post->product_url }}" target="_blank" rel="noopener">Open public product ↗</a></div><span class="status-pill">{{ ucfirst($post->status) }}</span></article>@empty<p class="empty-state">Scheduled posts will appear here.</p>@endforelse
        </section>
    </main>
</div>
<style nonce="{{ request()->attributes->get('csp_nonce') }}">
.social-main{max-width:1400px}.topline p{margin:7px 0 0;color:var(--muted)}.social-alert{margin:18px 0;padding:15px 18px;border-radius:14px;font-size:13px;line-height:1.55}.social-alert.success{background:#eaf7df;color:#315f20}.social-alert.error{background:#fff0ed;color:#923827}.social-alert.notice{background:#fff8df;border:1px solid #eedc96;color:#675414}.social-alert ul{margin:8px 0 0;padding-left:20px}.social-layout{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:18px;margin:24px 0}.schedule-form{padding:28px}.section-heading,.section-heading>div{display:flex;align-items:center;gap:10px}.section-heading{justify-content:space-between;margin-bottom:22px}.section-heading h2{margin:0;font-size:20px}.step{display:grid;place-items:center;width:31px;height:31px;border-radius:10px;background:var(--lime);font-size:11px;font-weight:900}.status-pill{display:inline-flex;padding:6px 9px;border-radius:999px;background:#edf1ee;color:#596760;font-size:10px;font-weight:800}.status-pill.active{background:#e8f7dc;color:#3e702b}.status-pill.paused{background:#fff1d8;color:#8a621d}.form-grid{display:grid;gap:14px}.form-grid.two{grid-template-columns:1fr 1fr}.schedule-form label{margin:0}.schedule-form input,.schedule-form select{width:100%;margin-top:7px;padding:12px;border:1px solid var(--line);border-radius:11px;background:white}.schedule-form label small,.field-help{display:block;margin-top:6px;color:var(--muted);font-size:11px}.schedule-form fieldset{margin:24px 0;padding:0;border:0}.schedule-form legend{margin-bottom:10px;font-size:13px;font-weight:800}.choice-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.choice-card{display:flex;align-items:center;gap:10px;padding:13px;border:1px solid var(--line);border-radius:12px;cursor:pointer}.choice-card input,.category-choice input{width:auto;margin:0}.choice-card span{display:flex;flex-direction:column}.choice-card small{margin:3px 0 0}.choice-card.disabled{opacity:.55}.category-grid{display:flex;flex-wrap:wrap;gap:7px;max-height:220px;overflow:auto}.category-choice{display:flex;align-items:center;gap:6px;padding:7px 10px;border:1px solid var(--line);border-radius:999px;font-size:11px;cursor:pointer}.post-format{margin:24px 0}.preview-card{margin-top:9px;padding:15px;border-radius:13px;background:#f3f6f2}.preview-card p,.preview-card span{font-size:12px;color:var(--muted);line-height:1.5}.connection-panel{align-self:start;position:sticky;top:24px}.readiness{display:flex;gap:10px;padding:13px 0;border-top:1px solid var(--line)}.readiness-dot{flex:0 0 9px;height:9px;margin-top:5px;border-radius:50%;background:#c9cfcb}.readiness-dot.ready{background:#54b877;box-shadow:0 0 0 4px #e2f5e7}.readiness div{display:flex;flex-direction:column}.readiness small{margin-top:4px;color:var(--muted);font-size:10px;line-height:1.4}.connection-panel .btn{width:100%;margin-top:13px}.schedule-list{margin:18px 0}.schedule-row,.upcoming-row{display:flex;align-items:center;gap:12px;padding:14px 0;border-top:1px solid var(--line)}.schedule-row>div:first-child,.upcoming-row>div{min-width:0;flex:1}.schedule-row p,.upcoming-row p{margin:4px 0;color:var(--muted);font-size:12px}.schedule-row small{color:var(--muted)}.text-button{border:0;background:none;color:var(--green);font-weight:800;cursor:pointer}.text-button.danger{color:#a74a3a}.upcoming-row img{width:54px;height:54px;border-radius:10px;object-fit:cover}.upcoming-row a{color:var(--green);font-size:11px;font-weight:800}.empty-state{color:var(--muted)}@media(max-width:1000px){.social-layout{grid-template-columns:1fr}.connection-panel{position:static}}@media(max-width:650px){.form-grid.two,.choice-grid{grid-template-columns:1fr}.schedule-row{align-items:flex-start;flex-wrap:wrap}}
</style>
@endsection
