@extends('layouts.app') @section('title','Knowledge · Legatus') @section('body')
<div class="dash-shell">@include('partials.workspace-navigation', ['active' => 'knowledge'])
<main class="main"><div class="topline"><div><span class="eyebrow">Business brain</span><h1>Knowledge sources</h1><p style="color:var(--muted);margin:4px 0">ასწავლეთ Legatus-ს პროდუქტები, პოლიტიკა და ბრენდის ცოდნა.</p></div><a class="btn ghost" href="{{ $agent ? route('chat.show',$agent) : route('dashboard') }}">Test knowledge ↗</a></div>
@if(session('success'))<div class="panel" style="margin-top:20px;border-color:#a9d6b4;color:#267244">✓ {{ session('success') }}</div>@endif @if(session('error'))<div class="panel" style="margin-top:20px;border-color:#e6afa9;color:#a43b32">{{ session('error') }}</div>@endif
<div class="content-grid" style="margin-top:24px"><section class="panel"><h3>Add a source</h3><form method="post" enctype="multipart/form-data" action="{{ route('knowledge.store') }}">@csrf<label>Source type</label><div style="display:flex;gap:9px"><label class="tag"><input style="width:auto" type="radio" name="type" value="url" checked> Website URL</label><label class="tag"><input style="width:auto" type="radio" name="type" value="csv"> CSV catalog</label><label class="tag"><input style="width:auto" type="radio" name="type" value="pdf"> PDF / policy</label></div><label>Display name <span style="color:var(--muted);font-weight:400">(optional)</span></label><input name="name" placeholder="Summer catalog or Delivery policy"><div id="url-field"><label>Public website URL</label><input type="url" name="url" placeholder="https://store.example/products"></div><div id="file-field" style="display:none"><label>Choose CSV or PDF · max 10 MB</label><input type="file" name="file" accept=".csv,.txt,.pdf"></div><button class="btn lime" style="margin-top:22px;width:100%">Teach Legatus →</button></form></section>
<aside class="agent-card"><span class="tag" style="background:#ffffff12;border-color:#ffffff20;color:white">How it works</span><h2 style="font-size:24px">From raw data to trusted answers.</h2><p>1. Content is fetched and validated.<br><br>2. Products are normalized and deduplicated.<br><br>3. Policies are split into searchable chunks.<br><br>4. Legatus cites the exact source used.</p><div style="padding-top:15px;border-top:1px solid #ffffff20;font-size:12px;color:#bcd0c9">Private network URLs are blocked. Catalog text is treated as data, never as AI instructions.</div></aside></div>
<section class="panel" id="connected-knowledge" style="margin-top:18px">
    <div style="display:flex;justify-content:space-between;align-items:center">
        <h3>Connected knowledge</h3>
        <span class="channel">{{ number_format($agent->products()->where('is_active', true)->count()) }} active products · {{ $sources->sum('chunks_count') }} searchable chunks · {{ $sources->sum('embedded_chunks_count') }} embedded</span>
    </div>
    @forelse($sources as $source)
        @php($fixture = ! $source->isRefreshable())
        <div class="conversation" style="align-items:center">
            <span class="avatar" style="width:40px;height:40px;background:{{ $source->status==='ready'?'#e8f7d8':'#f3eee1' }};color:var(--ink)">{{ strtoupper(substr($source->type,0,1)) }}</span>
            <div class="copy">
                <strong>{{ $source->name }}</strong>
                @if($fixture)<span class="tag">Demo fixture snapshot</span>@else<span class="pill">{{ $source->status }}</span>@endif
                <p>{{ $source->items_found }} found · {{ $source->items_created }} created · {{ $source->items_updated }} updated · {{ $source->chunks_count }} chunks</p>
                <p class="channel" style="margin-top:4px">
                    @if($source->chunks_count > 0 && $source->embedded_chunks_count === $source->chunks_count)
                        Semantic embeddings ready · {{ $source->embedded_chunks_count }}/{{ $source->chunks_count }}
                    @elseif($source->embedded_chunks_count > 0)
                        Partial embeddings · {{ $source->embedded_chunks_count }}/{{ $source->chunks_count }} · lexical fallback active
                    @else
                        Lexical search only · no embeddings stored
                    @endif
                </p>
                <div class="progress" style="background:#edf1ed;width:260px"><i style="width:{{ $source->progress }}%"></i></div>
            </div>
            <span class="channel">{{ $fixture ? 'Static fixture · no source payload' : ($source->last_synced_at?->diffForHumans() ?? 'Not synced') }}</span>
            @if($source->isRefreshable())
                <form method="post" action="{{ route('knowledge.sync',$source) }}">@csrf<button class="btn ghost" style="padding:8px 11px">↻ Sync</button></form>
            @else
                <span class="tag">Not refreshable</span>
            @endif
            <form method="post" action="{{ route('knowledge.destroy',$source) }}">@csrf @method('DELETE')<button class="btn ghost" style="padding:8px 11px;color:#a43b32">Remove</button></form>
        </div>
    @empty
        <div style="text-align:center;padding:45px;color:var(--muted)"><div style="font-size:34px">◇</div><b>No knowledge sources yet</b><p>Add a URL, CSV catalog, or PDF policy above.</p></div>
    @endforelse
</section></main></div>
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
const radios=document.querySelectorAll('input[name=type]'),url=document.querySelector('#url-field'),file=document.querySelector('#file-field'),upload=file.querySelector('input[type=file]'),uploadLabel=file.querySelector('label');
radios.forEach(r=>r.addEventListener('change',()=>{
    if(!r.checked)return;
    url.style.display=r.value==='url'?'block':'none';
    file.style.display=r.value==='url'?'none':'block';
    if(r.value==='pdf'){
        upload.accept='.pdf';
        uploadLabel.textContent='Choose PDF · max 10 MB';
    }else if(r.value==='csv'){
        upload.accept='.csv,.txt';
        uploadLabel.textContent='Choose CSV or TXT · max 10 MB';
    }
}));
</script>
@endsection
