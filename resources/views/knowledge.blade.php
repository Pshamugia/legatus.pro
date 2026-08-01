@extends('layouts.app') @section('title','Knowledge · Legatus') @section('body')
<style nonce="{{ request()->attributes->get('csp_nonce') }}">
#connected-knowledge .knowledge-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;flex-wrap:wrap}
#connected-knowledge .knowledge-heading .channel{line-height:1.5;text-align:right}
#connected-knowledge .knowledge-source-row{display:grid;grid-template-columns:40px minmax(0,1fr) auto;align-items:start;gap:13px}
#connected-knowledge .knowledge-source-copy{min-width:0}
#connected-knowledge .knowledge-source-copy>p{max-width:none;white-space:normal;overflow:visible;text-overflow:clip;line-height:1.55}
#connected-knowledge .knowledge-source-copy>p:first-of-type{color:#596a64}
#connected-knowledge .knowledge-source-actions{display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-wrap:wrap;max-width:330px}
#connected-knowledge .knowledge-source-actions .channel{white-space:nowrap;margin-right:4px}
#connected-knowledge .knowledge-source-actions form{margin:0}
#connected-knowledge .knowledge-source-copy .progress{width:min(100%,360px)!important;margin-top:9px}
@media(max-width:1050px){
    #connected-knowledge .knowledge-source-row{grid-template-columns:40px minmax(0,1fr)}
    #connected-knowledge .knowledge-source-actions{grid-column:2;justify-content:flex-start;max-width:none}
}
@media(max-width:600px){
    #connected-knowledge .knowledge-source-row{grid-template-columns:1fr}
    #connected-knowledge .knowledge-source-row>.avatar{display:none}
    #connected-knowledge .knowledge-source-actions{grid-column:1}
    #connected-knowledge .knowledge-heading .channel{text-align:left}
}
</style>
<div class="dash-shell">@include('partials.workspace-navigation', ['active' => 'knowledge'])
<main class="main"><div class="topline"><div><span class="eyebrow">Business brain</span><h1>Knowledge sources</h1><p style="color:var(--muted);margin:4px 0">ასწავლეთ Legatus-ს პროდუქტები, პოლიტიკა და ბრენდის ცოდნა.</p></div><a class="btn ghost" href="{{ $agent ? route('chat.show',$agent) : route('dashboard') }}">Test knowledge ↗</a></div>
@if(session('success'))<div class="panel" style="margin-top:20px;border-color:#a9d6b4;color:#267244">✓ {{ session('success') }}</div>@endif @if(session('error'))<div class="panel" style="margin-top:20px;border-color:#e6afa9;color:#a43b32">{{ session('error') }}</div>@endif
<section class="panel" style="margin-top:24px">
    <h3>Website catalog structure</h3>
    <p class="channel">Connect the complete product catalog once, then map each business-specific category by its own name and URL.</p>
    <form id="website-structure-form" method="post" action="{{ route('knowledge.store') }}">
        @csrf
        <input type="hidden" name="mode" value="website_structure">
        <label>1. Site catalog URL <span style="color:var(--muted);font-weight:400">(all products)</span></label>
        <input type="url" name="catalog_url" required value="{{ $catalogSource?->url }}" placeholder="https://store.example/products">
        <label>2. Categories</label>
        <div id="category-fields"></div>
        <button type="button" class="btn ghost" id="add-category" style="margin-top:10px">＋ Add category</button>
        <label>3. Sitemap URL <span style="color:var(--muted);font-weight:400">(optional)</span></label>
        <input type="url" name="sitemap_url" value="{{ $sitemapSource?->url }}" placeholder="https://store.example/sitemap.xml">
        <button class="btn lime" style="margin-top:22px">Save and synchronize →</button>
        <span id="website-structure-status" class="channel" style="display:block;margin-top:12px" aria-live="polite"></span>
    </form>
</section>
<div class="content-grid" style="margin-top:24px"><section class="panel"><h3>Upload a catalog or policy</h3><form method="post" enctype="multipart/form-data" action="{{ route('knowledge.store') }}">@csrf<label>Source type</label><div style="display:flex;gap:9px"><label class="tag"><input style="width:auto" type="radio" name="type" value="csv" checked> CSV catalog</label><label class="tag"><input style="width:auto" type="radio" name="type" value="pdf"> PDF / policy</label></div><label>Display name <span style="color:var(--muted);font-weight:400">(optional)</span></label><input name="name" placeholder="Summer catalog or Delivery policy"><div id="file-field"><label>Choose CSV or TXT · max 10 MB</label><input type="file" name="file" accept=".csv,.txt"></div><button class="btn lime" style="margin-top:22px;width:100%">Teach Legatus →</button></form></section>
<aside class="agent-card"><span class="tag" style="background:#ffffff12;border-color:#ffffff20;color:white">How it works</span><h2 style="font-size:24px">From raw data to trusted answers.</h2><p>1. Content is fetched and validated.<br><br>2. Products are normalized and deduplicated.<br><br>3. Policies are split into searchable chunks.<br><br>4. Legatus cites the exact source used.</p><div style="padding-top:15px;border-top:1px solid #ffffff20;font-size:12px;color:#bcd0c9">Private network URLs are blocked. Catalog text is treated as data, never as AI instructions.</div></aside></div>
<section class="panel" id="connected-knowledge" style="margin-top:18px">
    <div class="knowledge-heading">
        <h3>Connected knowledge</h3>
        <span class="channel" id="knowledge-live-summary">Live catalog search enabled · synchronization runs in the background</span>
    </div>
    @forelse($sources as $source)
        @php($fixture = ! $source->isRefreshable())
        <div class="conversation knowledge-source-row" data-source-row="{{ $source->id }}">
            <span class="avatar" style="width:40px;height:40px;background:{{ $source->status==='ready'?'#e8f7d8':'#f3eee1' }};color:var(--ink)">{{ strtoupper(substr($source->type,0,1)) }}</span>
            <div class="copy knowledge-source-copy">
                <strong data-source-name>{{ $source->name }}</strong>
                @if($fixture)<span class="tag">Demo fixture snapshot</span>@else<span class="pill" data-source-status>{{ $source->status }}</span>@endif
                @if($source->type === 'url')
                    <p><b>{{ $source->status === 'processing' ? 'Synchronizing in the background' : 'Public-site knowledge ready' }}</b> · <span data-source-items>{{ number_format($source->items_found) }}</span> products indexed</p>
                @else
                    <p>{{ $source->items_found }} indexed in last sync · {{ $source->items_created }} created · {{ $source->items_updated }} updated</p>
                @endif
                @if($source->type === 'url')
                    <p class="channel" style="margin-top:4px">{{ $source->status === 'processing' ? 'Legatus is following sitemaps, catalog pages, product details and business-policy pages in the background.' : 'Products, descriptions, prices, sale data and public business policies are refreshed from this website.' }}</p>
                @endif
                <p class="channel" style="margin-top:4px">
                    @if($fixture)
                        Lexical search available · static fixture has no semantic index
                    @elseif($source->status === 'ready')
                        Semantic and lexical search active
                    @elseif($source->status === 'processing')
                        Search is available now · semantic enrichment continues in the background
                    @else
                        Lexical search available
                    @endif
                </p>
                <div class="progress" style="background:#edf1ed;width:260px"><i data-source-progress style="width:{{ $source->progress }}%"></i></div>
                <p class="channel" data-source-error style="margin-top:4px;color:#a43b32">{{ $source->error }}</p>
            </div>
            <div class="knowledge-source-actions">
                <span class="channel">{{ $fixture ? 'Static fixture · no source payload' : ($source->last_synced_at?->diffForHumans() ?? 'Not synced') }}</span>
                @if($source->isRefreshable())
                    <form class="async-source-action" data-action="sync" method="post" action="{{ route('knowledge.sync',$source) }}">@csrf<button class="btn ghost" style="padding:8px 11px">↻ Sync</button></form>
                @else
                    <span class="tag">Not refreshable</span>
                @endif
                <form class="async-source-action" data-action="remove" method="post" action="{{ route('knowledge.destroy',$source) }}">@csrf @method('DELETE')<button class="btn ghost" style="padding:8px 11px;color:#a43b32">Remove</button></form>
            </div>
        </div>
    @empty
        <div style="text-align:center;padding:45px;color:var(--muted)"><div style="font-size:34px">◇</div><b>No knowledge sources yet</b><p>Add a URL, CSV catalog, or PDF policy above.</p></div>
    @endforelse
</section></main></div>
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
const categoryFields=document.querySelector('#category-fields'),addCategory=document.querySelector('#add-category'),structureForm=document.querySelector('#website-structure-form'),structureStatus=document.querySelector('#website-structure-status');
const knowledgeStatusUrl=@json(route('knowledge.status'));
const existingCategories=@json($categorySources);
let trackedSourceIds=[];
let categoryIndex=0;
function appendCategory(category={}){
    const row=document.createElement('div');
    row.style.cssText='display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.5fr) auto;gap:9px;align-items:end;margin-top:9px';
    row.innerHTML=`<div><label>Category name</label><input required name="categories[${categoryIndex}][name]" placeholder="e.g. Thriller"></div><div><label>Category URL</label><input required type="url" name="categories[${categoryIndex}][url]" placeholder="https://store.example/category/thriller"></div><button type="button" class="btn ghost remove-category" aria-label="Remove category">×</button>`;
    row.querySelector('.remove-category').addEventListener('click',()=>row.remove());
    row.querySelector('input[name$="[name]"]').value=category.name||'';
    row.querySelector('input[name$="[url]"]').value=category.url||'';
    categoryFields.appendChild(row);
    categoryIndex++;
}
addCategory.addEventListener('click',()=>appendCategory());
if(existingCategories.length)existingCategories.forEach(appendCategory);else appendCategory();
structureForm.addEventListener('submit',async event=>{
    event.preventDefault();
    const button=structureForm.querySelector('button[type=submit],button:not([type])');
    button.disabled=true;
    structureStatus.textContent='Queuing synchronization…';
    try{
        const response=await fetch(structureForm.action,{method:'POST',headers:{Accept:'application/json'},body:new FormData(structureForm)});
        const payload=await response.json();
        if(!response.ok)throw new Error(payload.message||'Could not save the website structure.');
        trackedSourceIds=payload.source_ids||[];
        structureStatus.textContent=payload.message+' Live progress will appear here; you can keep working on this page.';
        scheduleKnowledgeStatus(300);
    }catch(error){structureStatus.textContent=error.message;}finally{button.disabled=false;}
});
document.querySelectorAll('.async-source-action').forEach(form=>form.addEventListener('submit',async event=>{
    event.preventDefault();
    const button=form.querySelector('button'),row=form.closest('[data-source-row]'),action=form.dataset.action;
    button.disabled=true;
    const original=button.textContent;
    button.textContent=action==='remove'?'Removing…':'Queued…';
    try{
        const response=await fetch(form.action,{method:'POST',headers:{Accept:'text/html'},body:new FormData(form)});
        if(!response.ok)throw new Error('The action could not be completed.');
        if(action==='remove')row.remove();
        else{
            const pill=row.querySelector('.pill');
            if(pill)pill.textContent='processing';
            button.textContent='Queued';
            trackedSourceIds=[Number(row.dataset.sourceRow)];
            scheduleKnowledgeStatus(300);
        }
    }catch(error){button.textContent=original;button.disabled=false;structureStatus.textContent=error.message;}
}));
let knowledgeStatusTimer=null,knowledgeStatusBusy=false;
function scheduleKnowledgeStatus(delay=3000){
    clearTimeout(knowledgeStatusTimer);
    knowledgeStatusTimer=setTimeout(refreshKnowledgeStatus,delay);
}
async function refreshKnowledgeStatus(){
    if(knowledgeStatusBusy)return;
    knowledgeStatusBusy=true;
    try{
        const response=await fetch(knowledgeStatusUrl,{headers:{Accept:'application/json'},cache:'no-store'});
        if(!response.ok)throw new Error('Live synchronization status is temporarily unavailable.');
        const payload=await response.json();
        payload.sources.forEach(source=>{
            const row=document.querySelector(`[data-source-row="${source.id}"]`);
            if(!row)return;
            const status=row.querySelector('[data-source-status]'),progress=row.querySelector('[data-source-progress]'),items=row.querySelector('[data-source-items]'),error=row.querySelector('[data-source-error]');
            if(status)status.textContent=source.status;
            if(progress)progress.style.width=`${source.progress}%`;
            if(items)items.textContent=new Intl.NumberFormat().format(source.items_found);
            if(error)error.textContent=source.error||'';
        });
        if(trackedSourceIds.length){
            const tracked=payload.sources.filter(source=>trackedSourceIds.includes(source.id));
            structureStatus.textContent=tracked.map(source=>`${source.name}: ${source.progress}% (${source.status})`).join(' · ');
            if(tracked.length&&tracked.every(source=>source.status!=='processing'))trackedSourceIds=[];
        }
        if(payload.processing>0)scheduleKnowledgeStatus();
    }catch(error){structureStatus.textContent=error.message;scheduleKnowledgeStatus(10000);}finally{knowledgeStatusBusy=false;}
}
if(document.querySelector('[data-source-status]'))scheduleKnowledgeStatus(1000);
const radios=document.querySelectorAll('input[name=type]'),file=document.querySelector('#file-field'),upload=file.querySelector('input[type=file]'),uploadLabel=file.querySelector('label');
radios.forEach(r=>r.addEventListener('change',()=>{
    if(!r.checked)return;
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
