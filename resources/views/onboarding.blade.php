@extends('layouts.app')
@php
    $existingWebsite = data_get($agent->settings, 'website', '');
    $savedUrlSources = $agent->knowledgeSources
        ->filter(fn ($source) => $source->type === 'url' && filled($source->url))
        ->unique('url')
        ->values();
    $legacyCatalogSource = $savedUrlSources->first(fn ($source) => str_contains(mb_strtolower((string) $source->name), 'catalog') || str_contains(mb_strtolower((string) $source->name), 'product'));
    $existingCatalogUrl = data_get($agent->settings, 'catalog_url', '') ?: ($legacyCatalogSource?->url ?? '');
    $isConfigured = filled($existingWebsite) || filled($existingCatalogUrl) || filled($agent->description) || $agent->knowledgeSources->isNotEmpty() || $agent->commerceConnection;
    $initialBusiness = old('business_name', $agent->business_name);
    $initialAssistant = old('agent_name', $agent->assistantDisplayName());
@endphp
@section('title', ($isConfigured ? 'Update business setup' : 'Create your AI employee').' · Legatus')
@section('body')
<div class="wrap">
    @include('partials.workspace-navigation', ['active' => 'onboarding', 'variant' => 'topbar'])

    <main class="onboard">
        <div class="setup-intro">
            <span class="tag">3 clear steps · about 3 minutes</span>
            <h1>{{ $isConfigured ? 'Update your business setup.' : 'Create your digital ambassador.' }}</h1>
            <p>Your saved data stays in place. Change only what you need; existing catalog and knowledge sources are not removed when no new file is selected.</p>
        </div>

        @if(session('status'))<div class="panel setup-notice success">✓ {{ session('status') }}</div>@endif
        @if(session('success'))<div class="panel setup-notice success">✓ {{ session('success') }}</div>@endif
        @if($errors->any())<div class="panel setup-notice error">{{ $errors->first() }}</div>@endif

        <form class="form-card setup-form" id="onboarding-form" method="post" enctype="multipart/form-data" action="{{ route('onboarding.store') }}">
            @csrf

            <section class="setup-step">
                <div class="setup-step-head"><span>1</span><div><h2>Business and AI identity</h2><p>These are separate: the launcher uses the business name; the conversation uses the AI employee’s name.</p></div></div>
                <div class="setup-grid">
                    <div><label for="business-name">Business name</label><input id="business-name" name="business_name" value="{{ $initialBusiness }}" required maxlength="120" placeholder="e.g. Bukinistebi.ge"></div>
                    <div><label for="agent-name">AI employee name</label><input id="agent-name" name="agent_name" value="{{ $initialAssistant }}" required maxlength="80" placeholder="e.g. ანა"></div>
                </div>
                <div class="identity-preview" aria-live="polite">
                    <span>Website button</span><strong id="launcher-preview">Ask {{ $initialBusiness }}</strong>
                    <span>Chat identity</span><strong id="assistant-preview">{{ $initialAssistant }} · {{ $initialBusiness }}</strong>
                </div>
            </section>

            <section class="setup-step">
                <div class="setup-step-head"><span>2</span><div><h2>Store website</h2><p>Legatus uses this public address to learn verified business information and to allow your embedded widget on that site.</p></div></div>
                <label for="website">Public website</label>
                <input id="website" name="website" value="{{ old('website', $existingWebsite) }}" type="url" maxlength="2000" placeholder="https://yourstore.ge">
                @if($existingWebsite)
                    <p class="saved-value">✓ Saved website: <a href="{{ $existingWebsite }}" target="_blank" rel="noopener noreferrer">{{ $existingWebsite }}</a></p>
                @endif
            </section>

            <section class="setup-step">
                <div class="setup-step-head"><span>3</span><div><h2>Catalog and business knowledge</h2><p>Provide a complete catalog URL, a CSV/PDF file, or both. Browsers cannot prefill a file input, so a blank chooser does not mean your previous upload disappeared.</p></div></div>
                <label for="catalog-url">Full product catalog URL <span class="optional">Optional</span></label>
                <input id="catalog-url" name="catalog_url" value="{{ old('catalog_url', $existingCatalogUrl) }}" type="url" maxlength="2000" placeholder="https://yourstore.ge/products">
                <p class="channel">Use the public page or feed that lists the complete catalog. This is separate from the main website URL above.</p>
                @if($existingCatalogUrl)
                    <p class="saved-value">✓ Saved catalog URL: <a href="{{ $existingCatalogUrl }}" target="_blank" rel="noopener noreferrer">{{ $existingCatalogUrl }}</a></p>
                @endif

                @if($savedUrlSources->where('url', '!=', $existingCatalogUrl)->isNotEmpty())
                    <div class="saved-url-options">
                        <b>Previously connected URL{{ $savedUrlSources->where('url', '!=', $existingCatalogUrl)->count() === 1 ? '' : 's' }}</b>
                        <p>If one of these is your complete catalog, select it instead of typing the address again.</p>
                        @foreach($savedUrlSources->where('url', '!=', $existingCatalogUrl) as $savedUrlSource)
                            <button type="button" class="saved-url-choice" data-catalog-url="{{ $savedUrlSource->url }}"><span>{{ $savedUrlSource->url }}</span><strong>Use as catalog URL</strong></button>
                        @endforeach
                    </div>
                @endif

                <label for="catalog">Add another catalog or policy file <span class="optional">Optional</span></label>
                <input id="catalog" name="catalog" type="file" accept=".csv,.txt,.pdf">
                <p class="channel">CSV columns: name, sku, category, description, price, stock, image · or PDF policy · maximum 10 MB</p>

                <label for="description">What do you sell and what makes the business different?</label>
                <textarea id="description" name="description" rows="4" maxlength="1000" placeholder="Describe the products, customers and brand voice...">{{ old('description', $agent->description) }}</textarea>

                @if($agent->knowledgeSources->isNotEmpty() || $agent->commerceConnection)
                    <div class="connected-data">
                        <div class="connected-title"><div><b>Connected data</b><small>Your existing sources remain active.</small></div><div><a href="{{ route('knowledge.index') }}">Manage knowledge ↗</a><a href="{{ route('channels.index') }}">Manage channels ↗</a></div></div>
                        @if($agent->commerceConnection)
                            <div class="source-row"><span class="source-icon">↻</span><div><b>{{ $agent->commerceConnection->name }}</b><small>{{ parse_url($agent->commerceConnection->base_url, PHP_URL_HOST) }} · live commerce catalog</small></div><span class="source-status {{ $agent->commerceConnection->status === 'active' ? 'ready' : '' }}">{{ $agent->commerceConnection->status }}</span></div>
                        @endif
                        @foreach($agent->knowledgeSources as $source)
                            <div class="source-row"><span class="source-icon">{{ $source->type === 'url' ? '↗' : '▤' }}</span><div><b>{{ $source->name }}</b><small>{{ $source->url ?: strtoupper($source->type).' source' }}</small></div><span class="source-status {{ $source->status === 'ready' ? 'ready' : '' }}">{{ $source->status }}</span></div>
                        @endforeach
                    </div>
                @else
                    <div class="empty-data"><b>No source connected yet.</b><span>Add a website or file above; you can connect a live store from Channels after saving.</span></div>
                @endif
            </section>

            <div class="setup-submit"><span>🔒 Public pages are treated as untrusted data, never as AI instructions.</span><button class="btn lime" id="create-button" type="submit">{{ $isConfigured ? 'Save setup changes' : 'Create AI employee' }} →</button></div>
        </form>

        <div class="form-card" id="learning-state" hidden style="text-align:center;margin-top:18px"><div class="avatar" style="margin:auto">L</div><h3>Legatus is updating verified knowledge…</h3><p style="color:var(--muted)">We are validating the source, normalizing products and preparing searchable business knowledge.</p><div class="progress" style="background:#edf1ed"><i style="width:82%"></i></div></div>
    </main>
</div>

<style>
.setup-nav-actions,.setup-submit,.connected-title,.source-row{display:flex;align-items:center}.setup-nav-actions{gap:9px}.setup-nav-actions form{margin:0}.setup-intro{text-align:center;margin-bottom:28px}.setup-intro h1{font-size:42px;letter-spacing:-2px;margin:18px 0 10px}.setup-intro p{color:var(--muted);line-height:1.7;max-width:720px;margin:auto}.setup-form{padding:0;overflow:hidden}.setup-step{padding:30px 36px;border-bottom:1px solid var(--line)}.setup-step-head{display:flex;gap:14px;align-items:flex-start}.setup-step-head>span{display:grid;place-items:center;flex:0 0 32px;width:32px;height:32px;border-radius:10px;background:var(--green);color:var(--lime);font-weight:800}.setup-step-head h2{font:700 19px Manrope;margin:2px 0 5px}.setup-step-head p{color:var(--muted);font-size:13px;line-height:1.55;margin:0}.setup-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.identity-preview{display:grid;grid-template-columns:auto 1fr;gap:7px 14px;margin-top:18px;padding:14px 16px;border:1px solid #dce7df;background:#f6faf5;border-radius:13px}.identity-preview span{color:var(--muted);font-size:11px}.identity-preview strong{font-size:13px;overflow-wrap:anywhere}.saved-value{font-size:12px;color:#267244;margin:9px 0 0}.saved-value a{text-decoration:underline}.optional{font-size:10px;color:var(--muted);font-weight:500}.connected-data{border:1px solid var(--line);border-radius:14px;margin-top:22px;overflow:hidden}.connected-title{justify-content:space-between;gap:15px;padding:14px 16px;background:#f6f8f5}.connected-title b,.connected-title small{display:block}.connected-title small,.source-row small{color:var(--muted);font-size:11px;margin-top:3px}.connected-title>div:last-child{display:flex;gap:12px;font-size:11px;font-weight:700;color:#376c58}.source-row{gap:11px;padding:12px 15px;border-top:1px solid var(--line)}.source-row>div{min-width:0;flex:1}.source-row b,.source-row small{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.source-icon{display:grid;place-items:center;width:30px;height:30px;border-radius:9px;background:#edf4ed;color:#376c58}.source-status{font-size:10px;text-transform:capitalize;padding:5px 8px;border-radius:99px;background:#fff0d9;color:#8b5a15}.source-status.ready{background:#e9f8dc;color:#477426}.empty-data{display:flex;flex-direction:column;gap:4px;margin-top:18px;padding:14px 16px;border:1px dashed #ced9d1;border-radius:13px;color:var(--muted);font-size:12px}.empty-data b{color:var(--ink)}.setup-submit{justify-content:space-between;gap:20px;padding:22px 36px;background:#fafbf8}.setup-submit span{font-size:11px;color:var(--muted);max-width:430px}.setup-notice{margin:0 0 16px}.setup-notice.success{color:#267244}.setup-notice.error{color:#a33}@media(max-width:700px){.setup-nav-actions .tag{display:none}.setup-nav-actions .btn{padding:10px}.setup-intro h1{font-size:34px}.setup-step{padding:24px 20px}.setup-grid{grid-template-columns:1fr}.setup-submit{align-items:stretch;flex-direction:column;padding:20px}.connected-title{align-items:flex-start;flex-direction:column}}
.saved-url-options{margin:12px 0 20px;padding:14px;border:1px solid #dce7df;border-radius:13px;background:#f8fbf7}.saved-url-options>b{font-size:12px}.saved-url-options>p{margin:4px 0 10px;color:var(--muted);font-size:11px}.saved-url-choice{display:flex;width:100%;align-items:center;justify-content:space-between;gap:14px;margin-top:7px;padding:10px 12px;border:1px solid var(--line);border-radius:10px;background:#fff;color:var(--ink);cursor:pointer;text-align:left}.saved-url-choice span{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px}.saved-url-choice strong{flex:0 0 auto;color:var(--green);font-size:10px}@media(max-width:700px){.saved-url-choice{align-items:flex-start;flex-direction:column;gap:4px}}
</style>
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
const setupForm=document.querySelector('#onboarding-form');
const businessInput=document.querySelector('#business-name');
const assistantInput=document.querySelector('#agent-name');
const updateIdentityPreview=()=>{
    const business=businessInput.value.trim()||'Your business';
    const assistant=assistantInput.value.trim()||'AI Assistant';
    document.querySelector('#launcher-preview').textContent='Ask '+business;
    document.querySelector('#assistant-preview').textContent=assistant+' · '+business;
};
businessInput.addEventListener('input',updateIdentityPreview);
assistantInput.addEventListener('input',updateIdentityPreview);
document.querySelectorAll('[data-catalog-url]').forEach((button)=>button.addEventListener('click',()=>{
    document.querySelector('#catalog-url').value=button.dataset.catalogUrl;
    document.querySelector('#catalog-url').focus();
}));
setupForm.addEventListener('submit',()=>{
    document.querySelector('#create-button').disabled=true;
    document.querySelector('#create-button').textContent='Saving…';
    document.querySelector('#learning-state').hidden=false;
});
</script>
@endsection
