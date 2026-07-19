@extends('layouts.app')
@section('title', 'Legatus · Mission Control')
@section('body')
<div class="dash-shell">
    <aside class="side">
        <a class="brand" href="{{ route('landing') }}"><span class="mark">L</span> legatus</a>
        <div class="menu">
            <a class="active" href="{{ route('dashboard') }}">◫ &nbsp; Overview</a>
            <a href="{{ route('inbox.index') }}">◌ &nbsp; Inbox @if($metrics['needs_human'])<span class="pill">{{ $metrics['needs_human'] }}</span>@endif</a>
            <a href="{{ route('knowledge.index') }}">◇ &nbsp; Knowledge</a>
            <a href="{{ route('dashboard') }}#products">▦ &nbsp; Products <span class="pill">{{ $agent->products_count }}</span></a>
            <a href="{{ route('channels.index') }}">⌁ &nbsp; Channels</a>
            <a href="{{ route('analytics.index') }}">↗ &nbsp; Analytics</a>
            <a href="{{ route('settings.index') }}">⚙ &nbsp; Settings</a>
        </div>
        <div class="side-bottom"><b style="color:white">Knowledge readiness</b><br>{{ $metrics['knowledge_readiness'] }}% verified<div class="progress" style="margin-top:10px"><i style="width:{{ $metrics['knowledge_readiness'] }}%"></i></div></div>
    </aside>
    <main class="main">
        <div class="topline"><div><span class="eyebrow">Mission control</span><h1>გამარჯობა 👋</h1><p style="color:var(--muted);margin:4px 0">რეალური შედეგები {{ $agent->business_name }}-ის ყველა საუბრის მიხედვით.</p></div><div style="display:flex;gap:9px"><a class="btn ghost" href="{{ route('chat.show',$agent) }}">Preview agent</a><a class="btn" href="{{ route('onboarding') }}">Configure Legatus</a></div></div>

        @if(session('success'))<div class="panel" style="margin:18px 0;color:#267244;padding:14px">✓ {{ session('success') }}</div>@endif
        @foreach(session('warnings',[]) as $warning)<div class="panel" style="margin:10px 0;color:#9b5b12;background:#fff8e8;padding:14px">! {{ $warning }}</div>@endforeach

        <div class="grid">
            <div class="stat"><small>AI conversations</small><b>{{ number_format($metrics['conversations']) }}</b><span class="channel">Stored conversations</span></div>
            <div class="stat"><small>Automation rate</small><b>{{ $metrics['automation_rate'] }}%</b><span class="up">Without human escalation</span></div>
            <div class="stat"><small>Qualified leads</small><b>{{ number_format($metrics['qualified_leads']) }}</b><span class="up">Consent-backed contacts</span></div>
            <div class="stat"><small>Value influenced</small><b>{{ number_format($metrics['revenue_influenced'],2) }} ₾</b><span class="channel">Offers & reservations</span></div>
        </div>

        <div class="content-grid">
            <section class="panel">
                <div style="display:flex;justify-content:space-between"><h3>Live conversations</h3><a class="channel" href="{{ route('inbox.index') }}">View inbox →</a></div>
                @forelse($conversations as $conversation)
                    <a class="conversation" href="{{ route('inbox.index',['conversation'=>$conversation->id]) }}">
                        <span class="avatar" style="width:38px;height:38px">{{ mb_substr($conversation->customer_name ?? 'V',0,1) }}</span>
                        <div class="copy"><strong>{{ $conversation->customer_name ?? 'Visitor' }}</strong> <span class="channel">· {{ ucfirst($conversation->channel) }}</span> @if(str_starts_with($conversation->visitor_id, 'demo-'))<span class="tag" style="padding:3px 6px">Simulated demo</span>@endif<p>{{ $conversation->messages->last()?->content }}</p></div>
                        <div style="text-align:right"><span class="pill">{{ $conversation->status === 'human' ? 'Needs human' : ($conversation->status === 'closed' ? 'Closed' : 'AI handling') }}</span>@if($conversation->outcome)<div class="channel" style="margin-top:5px">{{ str_replace('_',' ',$conversation->outcome) }}</div>@endif</div>
                    </a>
                @empty<div style="padding:35px;color:var(--muted)">საუბრები ჯერ არ არის — გახსენით Preview agent.</div>@endforelse
            </section>
            <aside>
                <div class="agent-card"><div class="person"><span class="avatar" style="background:var(--lime);color:var(--green)">L</span><div><strong>{{ $agent->name }}</strong><small style="color:#bcd0c9">AI Sales Employee</small></div></div><p>{{ $agent->business_name }}-ის პროდუქტებს, პოლიტიკას და ბრენდის ტონს იყენებს გადამოწმებული პასუხებისთვის.</p><div style="display:flex;justify-content:space-between;font-size:12px"><span>Knowledge readiness</span><b>{{ $metrics['knowledge_readiness'] }}%</b></div><div class="progress" style="margin-top:9px"><i style="width:{{ $metrics['knowledge_readiness'] }}%"></i></div></div>
                <div class="panel" style="margin-top:18px"><h3>Channel status</h3><p>● Website demo & widget <span class="up">Available</span></p><p>◉ Messenger <span class="channel">Not connected</span></p><p>◎ Instagram <span class="channel">Not connected</span></p><p style="color:var(--muted);font-size:12px">Meta channels require a public HTTPS webhook and approved Meta credentials; they are not part of this local demo.</p></div>
            </aside>
        </div>
        <section class="panel" id="products" style="margin-top:18px;scroll-margin-top:20px">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:16px"><div><span class="eyebrow">Verified catalog</span><h3 style="margin:6px 0 0">Products Legatus can sell</h3></div><span class="tag">{{ number_format($agent->products_count) }} active products</span></div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px;margin-top:16px">
                @forelse($products as $product)
                    <article style="border:1px solid var(--line);border-radius:13px;padding:14px;background:#fbfcfa">
                        <div style="display:flex;justify-content:space-between;gap:8px"><strong>{{ $product->name }}</strong><span class="pill">{{ number_format($product->price, 2) }} ₾</span></div>
                        <p class="channel" style="margin:7px 0">{{ $product->sku ?: 'No SKU' }} · {{ $product->category ?: 'Uncategorized' }}</p>
                        <span class="{{ $product->stock > 0 ? 'up' : 'channel' }}">{{ $product->stock > 0 ? $product->stock.' in stock' : 'Out of stock' }}</span>
                    </article>
                @empty
                    <div style="padding:24px;color:var(--muted)">No active products yet. Import a CSV catalog from Knowledge.</div>
                @endforelse
            </div>
            @if($agent->products_count > $products->count())<p class="channel" style="margin:14px 0 0">Showing {{ $products->count() }} of {{ $agent->products_count }} active products. Import and synchronization status lives in Knowledge.</p>@endif
        </section>
    </main>
</div>
@endsection
