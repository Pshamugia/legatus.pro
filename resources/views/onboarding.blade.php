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
    $authoritativeCommerceConnection = $agent->commerceConnection?->status === 'active'
        ? $agent->commerceConnection
        : null;
    $initialBusiness = old('business_name', $agent->business_name);
    $initialAssistant = old('agent_name', $agent->assistantDisplayName());
    $widgetTheme = $agent->widgetTheme();
    $widgetThemePresets = \App\Support\WidgetTheme::presets();
    $widgetThemePreviewPalettes = collect($widgetThemePresets)
        ->map(fn (array $palette): array => ['primary' => $palette['primary'], 'accent' => $palette['accent']])
        ->all();
    $selectedWidgetThemeInput = old('widget_theme_preset', $widgetTheme['preset']);
    $selectedWidgetTheme = is_string($selectedWidgetThemeInput) && in_array($selectedWidgetThemeInput, \App\Support\WidgetTheme::allowedPresets(), true)
        ? $selectedWidgetThemeInput
        : $widgetTheme['preset'];
    $widgetThemePrimaryOld = old('widget_theme_primary', $widgetTheme['primary']);
    $widgetThemeAccentOld = old('widget_theme_accent', $widgetTheme['accent']);
    $widgetThemePrimaryInput = is_string($widgetThemePrimaryOld) ? $widgetThemePrimaryOld : $widgetTheme['primary'];
    $widgetThemeAccentInput = is_string($widgetThemeAccentOld) ? $widgetThemeAccentOld : $widgetTheme['accent'];
    $previewWidgetTheme = \App\Support\WidgetTheme::resolve([
        'preset' => $selectedWidgetTheme,
        'primary' => $widgetThemePrimaryInput,
        'accent' => $widgetThemeAccentInput,
    ]);
@endphp
@section('title', ($isConfigured ? 'Update business setup' : 'Create your AI employee').' · Legatus')
@section('body')
<div class="wrap">
    @include('partials.workspace-navigation', ['active' => 'onboarding', 'variant' => 'topbar'])

    <main class="onboard">
        <div class="setup-intro">
            <span class="tag">4 clear steps · about 4 minutes</span>
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
                <div class="setup-step-head"><span>2</span><div><h2 id="widget-theme-title">Widget appearance & brand colors</h2><p>Choose a ready-made palette or use your exact brand colors. This controls the website button and chat widget your customers see.</p></div></div>

                <fieldset class="theme-picker" aria-labelledby="widget-theme-title">
                    <legend>Color palette</legend>
                    @foreach($widgetThemePresets as $presetKey => $palette)
                        <label class="theme-card" style="--theme-card-primary:{{ $palette['primary'] }};--theme-card-accent:{{ $palette['accent'] }}">
                            <input type="radio" name="widget_theme_preset" value="{{ $presetKey }}" data-primary="{{ $palette['primary'] }}" data-accent="{{ $palette['accent'] }}" @checked($selectedWidgetTheme === $presetKey)>
                            <span class="theme-swatches" aria-hidden="true"><i></i><i></i></span>
                            <span><b>{{ $palette['label'] }}</b><small>{{ $palette['description'] }}</small></span>
                        </label>
                    @endforeach
                    <label class="theme-card" id="widget-theme-custom-card" style="--theme-card-primary:{{ $previewWidgetTheme['primary'] }};--theme-card-accent:{{ $previewWidgetTheme['accent'] }}">
                        <input type="radio" name="widget_theme_preset" value="custom" @checked($selectedWidgetTheme === 'custom')>
                        <span class="theme-swatches" aria-hidden="true"><i></i><i></i></span>
                        <span><b>Custom</b><small>Use your exact brand colors</small></span>
                    </label>
                </fieldset>

                <div id="widget-theme-custom" @if($selectedWidgetTheme !== 'custom') hidden @endif>
                    <div class="theme-custom-grid">
                        <div>
                            <label for="widget-theme-primary">Primary color</label>
                            <div class="theme-color-control">
                                <input id="widget-theme-primary" type="text" name="widget_theme_primary" value="{{ $widgetThemePrimaryInput }}" maxlength="7" inputmode="text" pattern="#[0-9A-Fa-f]{6}" autocomplete="off" spellcheck="false">
                                <input id="widget-theme-primary-picker" type="color" value="{{ $previewWidgetTheme['primary'] }}" aria-label="Choose primary color">
                            </div>
                        </div>
                        <div>
                            <label for="widget-theme-accent">Accent color</label>
                            <div class="theme-color-control">
                                <input id="widget-theme-accent" type="text" name="widget_theme_accent" value="{{ $widgetThemeAccentInput }}" maxlength="7" inputmode="text" pattern="#[0-9A-Fa-f]{6}" autocomplete="off" spellcheck="false">
                                <input id="widget-theme-accent-picker" type="color" value="{{ $previewWidgetTheme['accent'] }}" aria-label="Choose accent color">
                            </div>
                        </div>
                    </div>
                    <small class="theme-help">Use #RRGGBB. Primary and accent need enough contrast to stay clear and readable.</small>
                </div>

                <div class="theme-preview" id="widget-theme-preview" style="--preview-primary:{{ $previewWidgetTheme['primary'] }};--preview-accent:{{ $previewWidgetTheme['accent'] }};--preview-primary-foreground:{{ $previewWidgetTheme['primary_foreground'] }};--preview-accent-foreground:{{ $previewWidgetTheme['accent_foreground'] }}">
                    <div class="theme-preview-launcher"><span id="theme-preview-business-initial">{{ mb_strtoupper(mb_substr($initialBusiness, 0, 1)) }}</span><b>Ask <span id="theme-preview-business">{{ $initialBusiness }}</span></b></div>
                    <div class="theme-preview-frame">
                        <div class="theme-preview-head"><span id="theme-preview-assistant-initial">{{ mb_strtoupper(mb_substr($initialAssistant, 0, 1)) }}</span><div><b><span id="theme-preview-assistant">{{ $initialAssistant }}</span> · <span id="theme-preview-header-business">{{ $initialBusiness }}</span></b><small>● Online · Powered by Legatus</small></div></div>
                        <div class="theme-preview-body"><p>Hello! How can I help?</p><p>Show me your most popular products</p></div>
                        <button type="button">Send ↑</button>
                    </div>
                </div>
                <small class="theme-status" id="widget-theme-status" aria-live="polite"></small>
                <p class="theme-settings-link">You can change these colors any time under <a href="{{ route('settings.index') }}">Settings → Widget branding</a>.</p>
            </section>

            <section class="setup-step">
                <div class="setup-step-head"><span>3</span><div><h2>Store website</h2><p>Legatus uses this public address to learn verified business information and to allow your embedded widget on that site.</p></div></div>
                <label for="website">Public website</label>
                <input id="website" name="website" value="{{ old('website', $existingWebsite) }}" type="url" maxlength="2000" placeholder="https://yourstore.ge">
                @if($existingWebsite)
                    <p class="saved-value">✓ Saved website: <a href="{{ $existingWebsite }}" target="_blank" rel="noopener noreferrer">{{ $existingWebsite }}</a></p>
                @endif
            </section>

            <section class="setup-step">
                <div class="setup-step-head"><span>4</span><div><h2>Catalog and business knowledge</h2><p>@if($authoritativeCommerceConnection)Your verified live store connection supplies the authoritative product catalog. Add other sources only for supplementary business knowledge.@else Provide a complete catalog URL, a CSV/PDF file, or both. Browsers cannot prefill a file input, so a blank chooser does not mean your previous upload disappeared.@endif</p></div></div>

                @if($authoritativeCommerceConnection)
                    <div class="authoritative-catalog" data-authoritative-commerce-catalog>
                        <span aria-hidden="true">✓</span>
                        <div>
                            <b>{{ $authoritativeCommerceConnection->name ?: 'Connected store' }} is your live source of truth</b>
                            <p>Products, prices and stock come from the verified connector at {{ parse_url($authoritativeCommerceConnection->base_url, PHP_URL_HOST) }}. The URL below stays saved for reference and is not re-imported while this connection is active.</p>
                        </div>
                        <a href="{{ route('channels.index') }}#commerce-connection">Manage live catalog →</a>
                    </div>
                @endif

                <label for="catalog-url">Full product catalog URL <span class="optional">{{ $authoritativeCommerceConnection ? 'Saved reference' : 'Optional' }}</span></label>
                <input id="catalog-url" name="catalog_url" value="{{ old('catalog_url', $existingCatalogUrl) }}" type="url" maxlength="2000" placeholder="https://yourstore.ge/products">
                <p class="channel">@if($authoritativeCommerceConnection)This address remains available if you disconnect the live catalog later; it does not override verified connector data.@else Use the public page or feed that lists the complete catalog. This is separate from the main website URL above.@endif</p>
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
                            <div class="source-row"><span class="source-icon">↻</span><div><b>{{ $agent->commerceConnection->name ?: 'Connected store' }}</b><small>{{ parse_url($agent->commerceConnection->base_url, PHP_URL_HOST) }} · live commerce catalog{{ $authoritativeCommerceConnection ? ' · source of truth' : '' }}</small></div><span class="source-status {{ $agent->commerceConnection->status === 'active' ? 'ready' : '' }}">{{ $authoritativeCommerceConnection ? 'authoritative' : $agent->commerceConnection->status }}</span></div>
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
.authoritative-catalog{display:flex;align-items:flex-start;gap:11px;margin:20px 0 4px;padding:14px 15px;border:1px solid #cfe3c5;border-radius:14px;background:#f1f9eb}.authoritative-catalog>span{display:grid;place-items:center;flex:0 0 28px;width:28px;height:28px;border-radius:50%;background:#d9f1c8;color:#356342;font-weight:900}.authoritative-catalog>div{min-width:0;flex:1}.authoritative-catalog b{display:block;font-size:12px}.authoritative-catalog p{margin:4px 0 0;color:#587064;font-size:10px;line-height:1.5}.authoritative-catalog a{flex:0 0 auto;padding-top:5px;color:#376c58;font-size:10px;font-weight:800;white-space:nowrap}@media(max-width:700px){.authoritative-catalog{flex-wrap:wrap}.authoritative-catalog a{margin-left:39px}}
.theme-picker{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;border:0;padding:0;margin:22px 0 0}.theme-picker legend{font-size:13px;font-weight:700;margin-bottom:9px}.theme-card{position:relative;display:flex;align-items:center;gap:10px;margin:0;padding:11px;border:1px solid var(--line);border-radius:13px;cursor:pointer;background:#fff}.theme-card:has(input:checked){border-color:var(--theme-card-primary);box-shadow:0 0 0 2px color-mix(in srgb,var(--theme-card-primary) 18%,transparent)}.theme-card input{width:auto;margin:0;accent-color:var(--theme-card-primary)}.theme-card>span:last-child{min-width:0}.theme-card b,.theme-card small{display:block}.theme-card b{font-size:12px}.theme-card small{font-size:10px;color:var(--muted);margin-top:2px}.theme-swatches{display:flex;flex:0 0 auto}.theme-swatches i{width:22px;height:28px;background:var(--theme-card-primary);border-radius:8px 0 0 8px}.theme-swatches i+i{background:var(--theme-card-accent);border-radius:0 8px 8px 0}.theme-custom-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.theme-custom-grid label{margin-top:16px}.theme-custom-grid input{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;text-transform:uppercase}.theme-color-control{display:flex;gap:8px;align-items:stretch}.theme-color-control input[type="text"]{min-width:0}.theme-color-control input[type="color"]{flex:0 0 52px;width:52px;min-height:48px;padding:4px;cursor:pointer;background:white}.theme-help{display:block;color:var(--muted);font-size:11px;line-height:1.5;margin-top:7px}.theme-preview{display:grid;grid-template-columns:auto minmax(0,1fr);gap:14px;align-items:end;margin-top:18px;padding:16px;border:1px solid var(--line);border-radius:16px;background:#f5f7f4}.theme-preview-launcher{display:flex;align-items:center;gap:8px;max-width:220px;padding:8px 13px 8px 8px;border-radius:999px;background:var(--preview-primary);color:var(--preview-primary-foreground);box-shadow:0 10px 24px #142c2429;font-size:11px}.theme-preview-launcher>span{display:grid;place-items:center;width:28px;height:28px;border-radius:50%;background:var(--preview-accent);color:var(--preview-accent-foreground);font-weight:800}.theme-preview-launcher b{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.theme-preview-frame{min-width:0;overflow:hidden;border-radius:14px;background:white;box-shadow:0 10px 28px #142c241f}.theme-preview-head{display:flex;align-items:center;gap:8px;padding:10px;background:var(--preview-primary);color:var(--preview-primary-foreground)}.theme-preview-head>span{display:grid;place-items:center;flex:0 0 29px;width:29px;height:29px;border-radius:9px;background:var(--preview-accent);color:var(--preview-accent-foreground);font-weight:800;font-size:11px}.theme-preview-head div{min-width:0}.theme-preview-head b,.theme-preview-head small{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.theme-preview-head b{font-size:10px}.theme-preview-head small{font-size:8px;opacity:.72;margin-top:2px}.theme-preview-body{padding:9px;background:#f6f8f4}.theme-preview-body p{width:max-content;max-width:88%;padding:7px 8px;margin:4px 0;border-radius:9px;background:white;border:1px solid #e4e9e5;font-size:9px}.theme-preview-body p:last-child{margin-left:auto;background:var(--preview-primary);color:var(--preview-primary-foreground);border:0}.theme-preview-frame button{float:right;margin:0 8px 8px;border:0;border-radius:8px;padding:6px 9px;background:var(--preview-accent);color:var(--preview-accent-foreground);font-size:9px;font-weight:700}.theme-status{display:block;min-height:18px;margin-top:7px;color:var(--muted);font-size:10px}.theme-status[data-state="error"]{color:#a33}.theme-settings-link{margin:4px 0 0;color:var(--muted);font-size:11px}.theme-settings-link a{color:#376c58;font-weight:700;text-decoration:underline}@media(max-width:700px){.theme-picker,.theme-custom-grid,.theme-preview{grid-template-columns:1fr}.theme-preview-launcher{justify-self:end}}
</style>
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
const setupForm=document.querySelector('#onboarding-form');
const businessInput=document.querySelector('#business-name');
const assistantInput=document.querySelector('#agent-name');
const themePalettes=@json($widgetThemePreviewPalettes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
const themeRadios=[...document.querySelectorAll('input[name="widget_theme_preset"]')];
const themePrimaryInput=document.querySelector('#widget-theme-primary');
const themeAccentInput=document.querySelector('#widget-theme-accent');
const themePrimaryPicker=document.querySelector('#widget-theme-primary-picker');
const themeAccentPicker=document.querySelector('#widget-theme-accent-picker');
const themeCustomFields=document.querySelector('#widget-theme-custom');
const themeCustomCard=document.querySelector('#widget-theme-custom-card');
const themePreview=document.querySelector('#widget-theme-preview');
const themeStatus=document.querySelector('#widget-theme-status');
const themeHex=/^#[0-9A-Fa-f]{6}$/;
const themeLuminance=(color)=>{
    const channels=[1,3,5].map((index)=>parseInt(color.slice(index,index+2),16)/255).map((channel)=>channel<=.04045?channel/12.92:Math.pow((channel+.055)/1.055,2.4));
    return .2126*channels[0]+.7152*channels[1]+.0722*channels[2];
};
const themeContrast=(first,second)=>{
    const firstLuminance=themeLuminance(first);
    const secondLuminance=themeLuminance(second);
    return(Math.max(firstLuminance,secondLuminance)+.05)/(Math.min(firstLuminance,secondLuminance)+.05);
};
const themeForeground=(background)=>themeContrast(background,'#FFFFFF')>=themeContrast(background,'#000000')?'#FFFFFF':'#000000';
const selectedThemePreset=()=>themeRadios.find((radio)=>radio.checked)||themeRadios.find((radio)=>radio.value==='forest');
const applyThemePreview=(primary,accent)=>{
    const isCustom=selectedThemePreset()?.value==='custom';
    if(!themeHex.test(primary)||!themeHex.test(accent)){
        themeStatus.textContent='Enter both colors in #RRGGBB format.';
        themeStatus.dataset.state='error';
        themeAccentInput.setCustomValidity(isCustom?'Enter both colors in #RRGGBB format.':'');
        return;
    }
    primary=primary.toUpperCase();
    accent=accent.toUpperCase();
    themePrimaryPicker.value=primary;
    themeAccentPicker.value=accent;
    const pairContrast=themeContrast(primary,accent);
    themeAccentInput.setCustomValidity(isCustom&&pairContrast<3?'Choose colors with at least a 3:1 contrast ratio.':'');
    if(pairContrast<3){
        themeStatus.textContent='These colors are too similar. Increase contrast between primary and accent.';
        themeStatus.dataset.state='error';
        return;
    }
    themePreview.style.setProperty('--preview-primary',primary);
    themePreview.style.setProperty('--preview-accent',accent);
    themePreview.style.setProperty('--preview-primary-foreground',themeForeground(primary));
    themePreview.style.setProperty('--preview-accent-foreground',themeForeground(accent));
    themeCustomCard.style.setProperty('--theme-card-primary',primary);
    themeCustomCard.style.setProperty('--theme-card-accent',accent);
    themeStatus.textContent=`Accessible preview · color contrast ${pairContrast.toFixed(1)}:1`;
    themeStatus.dataset.state='ok';
};
const syncTheme=()=>{
    const selected=selectedThemePreset();
    if(!selected)return;
    const isCustom=selected.value==='custom';
    themeCustomFields.hidden=!isCustom;
    themePrimaryInput.readOnly=!isCustom;
    themeAccentInput.readOnly=!isCustom;
    themePrimaryInput.required=isCustom;
    themeAccentInput.required=isCustom;
    if(!isCustom&&themePalettes[selected.value]){
        themePrimaryInput.value=themePalettes[selected.value].primary;
        themeAccentInput.value=themePalettes[selected.value].accent;
    }
    applyThemePreview(themePrimaryInput.value,themeAccentInput.value);
};
const updateIdentityPreview=()=>{
    const business=businessInput.value.trim()||'Your business';
    const assistant=assistantInput.value.trim()||'AI Assistant';
    document.querySelector('#launcher-preview').textContent='Ask '+business;
    document.querySelector('#assistant-preview').textContent=assistant+' · '+business;
    document.querySelector('#theme-preview-business').textContent=business;
    document.querySelector('#theme-preview-header-business').textContent=business;
    document.querySelector('#theme-preview-assistant').textContent=assistant;
    document.querySelector('#theme-preview-business-initial').textContent=Array.from(business)[0].toLocaleUpperCase();
    document.querySelector('#theme-preview-assistant-initial').textContent=Array.from(assistant)[0].toLocaleUpperCase();
};
businessInput.addEventListener('input',updateIdentityPreview);
assistantInput.addEventListener('input',updateIdentityPreview);
themeRadios.forEach((radio)=>radio.addEventListener('change',syncTheme));
[themePrimaryInput,themeAccentInput].forEach((input)=>input.addEventListener('input',()=>applyThemePreview(themePrimaryInput.value,themeAccentInput.value)));
[[themePrimaryPicker,themePrimaryInput],[themeAccentPicker,themeAccentInput]].forEach(([picker,input])=>picker.addEventListener('input',()=>{
    input.value=picker.value.toUpperCase();
    applyThemePreview(themePrimaryInput.value,themeAccentInput.value);
}));
document.querySelectorAll('[data-catalog-url]').forEach((button)=>button.addEventListener('click',()=>{
    document.querySelector('#catalog-url').value=button.dataset.catalogUrl;
    document.querySelector('#catalog-url').focus();
}));
setupForm.addEventListener('submit',()=>{
    document.querySelector('#create-button').disabled=true;
    document.querySelector('#create-button').textContent='Saving…';
    document.querySelector('#learning-state').hidden=false;
});
syncTheme();
updateIdentityPreview();
</script>
@endsection
