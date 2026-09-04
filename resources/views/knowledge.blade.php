@extends('layouts.app') @section('title','Knowledge · Legatus') @section('body')
<style nonce="{{ request()->attributes->get('csp_nonce') }}">
.knowledge-main{max-width:1180px;width:100%;margin:0 auto}.knowledge-section{margin-top:22px!important;padding:0!important;overflow:hidden}.knowledge-section__head{padding:22px 24px;border-bottom:1px solid var(--line)}.knowledge-section__head h3{margin:4px 0 6px;font-size:21px}.knowledge-section__head p{margin:0;color:var(--muted);font-size:12px}.knowledge-section__body{padding:24px}.knowledge-group{margin-top:22px;padding:18px;border:1px solid var(--line);border-radius:15px;background:#f8faf7}.knowledge-group:first-child{margin-top:0}.knowledge-group__title{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;margin-bottom:12px}.knowledge-group__title label{margin:0;font-size:14px}.knowledge-group__title p{margin:4px 0 0;color:var(--muted);font-size:11px}.source-controls{display:flex;align-items:center;gap:7px;flex-wrap:wrap}.source-controls .pill{font-size:9px}.source-controls button{padding:7px 9px!important;font-size:10px}.source-controls .remove-source{color:#a43b32}.structured-row{padding:12px;border:1px solid var(--line);border-radius:12px;background:#fff}.structured-row+.structured-row{margin-top:9px}.structured-row__fields{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.5fr);gap:9px}.structured-row__fields label{margin-top:0}.structured-field-with-controls{position:relative}.structured-field-with-controls.has-source-controls>input{padding-right:180px}.structured-field-with-controls.has-remove-control>input{padding-right:52px}.structured-field-with-controls>.source-controls,.structured-field-with-controls>.remove-category,.structured-field-with-controls>.remove-language{position:absolute;right:9px;top:50%;transform:translateY(-50%)}.structured-field-with-controls>.source-controls{flex-wrap:nowrap}.structured-field-with-controls>.remove-category,.structured-field-with-controls>.remove-language{width:34px;height:34px;padding:0!important}.policy-grid>div{padding:18px;border:1px solid var(--line);border-radius:15px;background:#f8faf7}.knowledge-action-forms{display:none}@media(max-width:700px){.knowledge-group__title{align-items:flex-start;flex-direction:column}.structured-row__fields{grid-template-columns:1fr}.source-controls{justify-content:flex-start}.knowledge-section__body{padding:16px}.policy-grid>div{padding:14px}}@media(max-width:480px){.structured-field-with-controls.has-source-controls>input{padding-right:154px}.structured-field-with-controls>.source-controls{gap:3px}.structured-field-with-controls>.source-controls button{padding:6px!important}.structured-field-with-controls>.source-controls .pill{font-size:8px}}
</style>
<div class="dash-shell">@include('partials.workspace-navigation', ['active' => 'knowledge'])
<main class="main knowledge-main"><div class="topline"><div><span class="eyebrow">Business brain</span><h1>Knowledge sources</h1><p style="color:var(--muted);margin:4px 0">Teach Legatus about your products, policies, and brand.</p></div><a class="btn ghost" href="{{ $agent ? route('chat.show',$agent) : route('onboarding') }}">Test knowledge ↗</a></div>
@if(session('success'))<div class="panel" style="margin-top:20px;border-color:#a9d6b4;color:#267244">✓ {{ session('success') }}</div>@endif @if(session('error'))<div class="panel" style="margin-top:20px;border-color:#e6afa9;color:#a43b32">{{ session('error') }}</div>@endif
<section class="panel knowledge-section">
    <div class="knowledge-section__head"><span class="eyebrow">Catalog</span><h3>Website catalog structure</h3><p>Connect the complete product catalog once, then map each business-specific category by its own name and URL.</p></div>
    <div class="knowledge-section__body">
    <form id="website-structure-form" method="post" action="{{ route('knowledge.store') }}">
        @csrf
        <input type="hidden" name="mode" value="website_structure">
        <div class="knowledge-group" @if($catalogSource) data-source-row="{{ $catalogSource->id }}" @endif>
            <div class="knowledge-group__title"><div><label>1. Site catalog URL</label><p>All products</p></div>@include('partials.knowledge-source-controls', ['source' => $catalogSource])</div>
            <input type="url" name="catalog_url" required value="{{ $catalogSource?->url }}" placeholder="https://store.example/products">
        </div>
        <div class="knowledge-group">
            <div class="knowledge-group__title"><div><label>2. Site search URL</label><p>Optional direct search endpoint</p></div></div>
            <input type="url" name="search_url" value="{{ old('search_url', data_get($agent->settings, 'catalog_search_url')) }}" placeholder="https://store.example/search">
        </div>
        <div class="knowledge-group"><div class="knowledge-group__title"><div><label>3. Website languages</label><p>Add one public catalog URL for every website language.</p></div><button type="button" class="btn ghost" id="add-language">＋ Add language</button></div><div id="language-fields"></div></div>
        <div class="knowledge-group"><div class="knowledge-group__title"><div><label>4. Categories</label><p>Give every category its public name and URL.</p></div><button type="button" class="btn ghost" id="add-category">＋ Add category</button></div><div id="category-fields"></div></div>
        <div class="knowledge-group" @if($sitemapSource) data-source-row="{{ $sitemapSource->id }}" @endif>
            <div class="knowledge-group__title"><div><label>5. Sitemap URL</label><p>Optional</p></div>@include('partials.knowledge-source-controls', ['source' => $sitemapSource])</div>
            <input type="url" name="sitemap_url" value="{{ $sitemapSource?->url }}" placeholder="https://store.example/sitemap.xml">
        </div>
        <button class="btn lime" style="margin-top:22px">Save structure →</button>
        <span id="website-structure-status" class="channel" style="display:block;margin-top:12px" aria-live="polite"></span>
    </form>
    </div>
</section>
<section class="panel knowledge-section">
    <div class="knowledge-section__head"><span class="eyebrow">Business knowledge</span><h3>Custom business information</h3><p>Add any facts Legatus should use in answers, such as delivery rules, working hours, addresses, returns, payments, or services.</p></div>
    <div class="knowledge-section__body">
    <form method="post" action="{{ route('knowledge.store') }}">
        @csrf
        <input type="hidden" name="mode" value="business_knowledge">
        <div class="knowledge-group__title"><div><label>Knowledge items</label><p>Use direct text or connect a public page.</p></div><button type="button" class="btn ghost" id="add-business-knowledge">＋ Add information</button></div>
        <div id="business-knowledge-fields"></div>
        <button class="btn lime" style="margin-top:22px">Save knowledge →</button>
    </form>
    </div>
</section>
<style>.business-knowledge-row{padding:18px;border:1px solid var(--line);border-radius:15px;background:#f8faf7}.business-knowledge-row+.business-knowledge-row{margin-top:12px}.business-knowledge-fields{display:grid;grid-template-columns:minmax(0,1fr) 180px;gap:12px}.business-knowledge-row textarea,.business-knowledge-row select{width:100%;box-sizing:border-box;border:1px solid var(--line);border-radius:12px;padding:12px;background:#fff;font:inherit}.business-knowledge-row textarea{resize:vertical}@media(max-width:700px){.business-knowledge-fields{grid-template-columns:1fr}}</style>
<div class="knowledge-action-forms" aria-hidden="true">
    @foreach($sources as $source)
        @if($source->isRefreshable())<form id="sync-source-{{ $source->id }}" class="async-source-action" data-source-id="{{ $source->id }}" data-action="sync" method="post" action="{{ route('knowledge.sync',$source) }}">@csrf</form>@endif
        <form id="remove-source-{{ $source->id }}" class="async-source-action" data-source-id="{{ $source->id }}" data-action="remove" method="post" action="{{ route('knowledge.destroy',$source) }}">@csrf @method('DELETE')</form>
    @endforeach
</div></main></div>
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
const categoryFields=document.querySelector('#category-fields'),addCategory=document.querySelector('#add-category'),languageFields=document.querySelector('#language-fields'),addLanguage=document.querySelector('#add-language'),structureForm=document.querySelector('#website-structure-form'),structureStatus=document.querySelector('#website-structure-status');
const knowledgeStatusUrl=@json(route('knowledge.status'));
const existingCategories=@json($categorySources);
const existingLanguages=@json($languageSources);
const existingBusinessKnowledge=@json($businessSources);
let trackedSourceIds=[];
let categoryIndex=0;
let languageIndex=0;
let businessKnowledgeIndex=0;
function appendCategory(category={}){
    const row=document.createElement('div');
    row.className='structured-row';
    if(category.id)row.dataset.sourceRow=category.id;
    const savedActions=category.id?`<div class="source-controls"><span class="pill" data-source-status>${category.status||'ready'}</span>${category.refreshable?`<button type="submit" class="btn ghost" form="sync-source-${category.id}">↻ Sync</button>`:''}<button type="submit" class="btn ghost remove-source" form="remove-source-${category.id}">Remove</button></div>`:`<button type="button" class="btn ghost remove-category" aria-label="Remove category">×</button>`;
    row.innerHTML=`<div class="structured-row__fields"><div><label>Category name</label><input required name="categories[${categoryIndex}][name]" placeholder="e.g. Thriller"></div><div><label>Category URL</label><div class="structured-field-with-controls ${category.id?'has-source-controls':'has-remove-control'}"><input required type="url" name="categories[${categoryIndex}][url]" placeholder="https://store.example/category/thriller">${savedActions}</div></div></div>`;
    row.querySelector('.remove-category')?.addEventListener('click',()=>row.remove());
    row.querySelector('input[name$="[name]"]').value=category.name||'';
    row.querySelector('input[name$="[url]"]').value=category.url||'';
    categoryFields.appendChild(row);
    categoryIndex++;
}
addCategory.addEventListener('click',()=>appendCategory());
if(existingCategories.length)existingCategories.forEach(appendCategory);else appendCategory();
function appendLanguage(language={}){
    const row=document.createElement('div');
    row.className='structured-row';
    if(language.id)row.dataset.sourceRow=language.id;
    const savedActions=language.id?`<div class="source-controls"><span class="pill" data-source-status>${language.status||'ready'}</span>${language.refreshable?`<button type="submit" class="btn ghost" form="sync-source-${language.id}">↻ Sync</button>`:''}<button type="submit" class="btn ghost remove-source" form="remove-source-${language.id}">Remove</button></div>`:`<button type="button" class="btn ghost remove-language" aria-label="Remove language">×</button>`;
    row.innerHTML=`<div class="structured-row__fields"><div><label>Language name</label><input required name="languages[${languageIndex}][name]" placeholder="e.g. Georgian"></div><div><label>Language catalog URL</label><div class="structured-field-with-controls ${language.id?'has-source-controls':'has-remove-control'}"><input required type="url" name="languages[${languageIndex}][url]" placeholder="https://store.example/?lang=ka">${savedActions}</div></div></div>`;
    row.querySelector('.remove-language')?.addEventListener('click',()=>row.remove());
    row.querySelector('input[name$="[name]"]').value=language.name||'';
    row.querySelector('input[name$="[url]"]').value=language.url||'';
    languageFields.appendChild(row);
    languageIndex++;
}
addLanguage.addEventListener('click',()=>appendLanguage());
if(existingLanguages.length)existingLanguages.forEach(appendLanguage);else appendLanguage();
const businessKnowledgeFields=document.querySelector('#business-knowledge-fields'),addBusinessKnowledge=document.querySelector('#add-business-knowledge');
function appendBusinessKnowledge(item={}){
    const row=document.createElement('div');
    row.className='business-knowledge-row';
    if(item.id)row.dataset.sourceRow=item.id;
    const index=businessKnowledgeIndex++;
    const controls=item.id?`<div class="source-controls"><span class="pill" data-source-status>${item.status||'ready'}</span>${item.refreshable?`<button type="submit" class="btn ghost" form="sync-source-${item.id}">↻ Sync</button>`:''}<button type="submit" class="btn ghost remove-source" form="remove-source-${item.id}">Remove</button></div>`:`<button type="button" class="btn ghost remove-business-knowledge">Remove</button>`;
    row.innerHTML=`<div class="knowledge-group__title"><span class="eyebrow">Business information</span>${controls}</div><input type="hidden" name="business_knowledge[${index}][id]" value="${item.id||''}"><div class="business-knowledge-fields"><div><label>Title</label><input required maxlength="150" name="business_knowledge[${index}][title]" placeholder="e.g. Working hours"></div><div><label>Source type</label><select name="business_knowledge[${index}][type]"><option value="text">Text</option><option value="url">Public URL</option></select></div></div><div data-business-text><label>Information</label><textarea rows="6" maxlength="20000" name="business_knowledge[${index}][text]" placeholder="Write the authoritative information Legatus should use."></textarea></div><div data-business-url hidden><label>Public URL</label><input type="url" maxlength="2000" name="business_knowledge[${index}][url]" placeholder="https://store.example/information"></div>`;
    const title=row.querySelector('[name$="[title]"]'),type=row.querySelector('[name$="[type]"]'),textInput=row.querySelector('textarea'),urlInput=row.querySelector('[name$="[url]"]');
    title.value=item.name||'';type.value=item.type||'text';textInput.value=item.text||'';urlInput.value=item.url||'';
    const toggle=()=>{const isText=type.value==='text';row.querySelector('[data-business-text]').hidden=!isText;row.querySelector('[data-business-url]').hidden=isText;textInput.required=isText;urlInput.required=!isText;};
    type.addEventListener('change',toggle);toggle();
    row.querySelector('.remove-business-knowledge')?.addEventListener('click',()=>row.remove());
    businessKnowledgeFields.appendChild(row);
}
addBusinessKnowledge.addEventListener('click',()=>appendBusinessKnowledge());
if(existingBusinessKnowledge.length)existingBusinessKnowledge.forEach(appendBusinessKnowledge);else appendBusinessKnowledge();
structureForm.addEventListener('submit',async event=>{
    event.preventDefault();
    const button=structureForm.querySelector('button[type=submit],button:not([type])');
    button.disabled=true;
    structureStatus.textContent='Saving structure…';
    try{
        const response=await fetch(structureForm.action,{method:'POST',headers:{Accept:'application/json'},body:new FormData(structureForm)});
        const payload=await response.json();
        if(!response.ok)throw new Error(payload.message||'Could not save the website structure.');
        trackedSourceIds=payload.source_ids||[];
        structureStatus.textContent=payload.message;
    }catch(error){structureStatus.textContent=error.message;}finally{button.disabled=false;}
});
document.querySelectorAll('.async-source-action').forEach(form=>form.addEventListener('submit',async event=>{
    event.preventDefault();
    const button=document.querySelector(`[form="${form.id}"]`),row=document.querySelector(`[data-source-row="${form.dataset.sourceId}"]`),action=form.dataset.action;
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
</script>
@endsection
