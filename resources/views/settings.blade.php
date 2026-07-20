@extends('layouts.app')
@section('title', 'Settings · Legatus')
@section('body')
@php
    $delivery = $agent->settings['delivery_policy'] ?? [];
    $widgetTheme = $agent->widgetTheme();
    $widgetThemePresets = \App\Support\WidgetTheme::presets();
    $widgetThemePreviewPalettes = collect($widgetThemePresets)
        ->map(fn (array $palette): array => ['primary' => $palette['primary'], 'accent' => $palette['accent']])
        ->all();
    $selectedWidgetTheme = old('widget_theme_preset', $widgetTheme['preset']);
    $widgetThemePrimaryInput = old('widget_theme_primary', $widgetTheme['primary']);
    $widgetThemeAccentInput = old('widget_theme_accent', $widgetTheme['accent']);
    $previewWidgetTheme = \App\Support\WidgetTheme::resolve([
        'preset' => $selectedWidgetTheme,
        'primary' => $widgetThemePrimaryInput,
        'accent' => $widgetThemeAccentInput,
    ]);
    $canManageSettings = in_array($role, ['owner', 'admin'], true);
@endphp
<div class="dash-shell">
    @include('partials.workspace-navigation', ['active' => 'settings'])
    <main class="main">
        <div class="topline">
            <div><span class="eyebrow">{{ $organization->name }} · {{ ucfirst($role) }}</span><h1>Workspace settings</h1></div>
        </div>
        @if(session('success'))<div class="panel" style="margin:18px 0;color:#267244">✓ {{ session('success') }}</div>@endif
        @if($errors->any())<div class="panel" style="margin:18px 0;color:#a33">{{ $errors->first() }}</div>@endif
        <div class="content-grid" style="margin-top:22px">
            <form class="panel" method="post" action="{{ route('settings.update') }}">
                @csrf @method('PUT')
                <h3>Business & AI identity</h3>
                <p style="color:var(--muted);font-size:13px;line-height:1.55">Keep the business brand separate from the AI employee’s personal display name.</p>
                <label for="business-name">ბიზნესის სახელი · Business name</label>
                <input id="business-name" name="business_name" value="{{ old('business_name', $agent->business_name ?: $organization->name) }}" maxlength="120" required aria-describedby="business-name-help" {{ ! in_array($role, ['owner', 'admin']) ? 'disabled' : '' }}>
                <small id="business-name-help" class="identity-help">აკონტროლებს საჯარო ბრენდს და ღილაკს “Ask [Business]” — მაგალითად, Ask Bukinistebi.ge.</small>
                <label for="agent-name">AI ასისტენტის სახელი · Assistant display name</label>
                <input id="agent-name" name="agent_name" value="{{ old('agent_name', $agent->assistantDisplayName()) }}" maxlength="80" required aria-describedby="agent-name-help" {{ ! in_array($role, ['owner', 'admin']) ? 'disabled' : '' }}>
                <small id="agent-name-help" class="identity-help">აკონტროლებს AI თანამშრომლის chat identity-ს — მაგალითად, Nia. ეს არ ცვლის ბიზნესის ბრენდს.</small>

                <section class="theme-section" aria-labelledby="widget-theme-title">
                    <h3 id="widget-theme-title">Widget branding</h3>
                    <p>Choose a professional palette or enter your own brand colors. Legatus automatically selects readable text colors.</p>

                    <fieldset class="theme-picker">
                        <legend>Color palette</legend>
                        @foreach($widgetThemePresets as $presetKey => $palette)
                            <label class="theme-card" style="--theme-card-primary:{{ $palette['primary'] }};--theme-card-accent:{{ $palette['accent'] }}">
                                <input type="radio" name="widget_theme_preset" value="{{ $presetKey }}" data-primary="{{ $palette['primary'] }}" data-accent="{{ $palette['accent'] }}" @checked($selectedWidgetTheme === $presetKey) @disabled(! $canManageSettings)>
                                <span class="theme-swatches" aria-hidden="true"><i></i><i></i></span>
                                <span><b>{{ $palette['label'] }}</b><small>{{ $palette['description'] }}</small></span>
                            </label>
                        @endforeach
                        <label class="theme-card" id="widget-theme-custom-card" style="--theme-card-primary:{{ $previewWidgetTheme['primary'] }};--theme-card-accent:{{ $previewWidgetTheme['accent'] }}">
                            <input type="radio" name="widget_theme_preset" value="custom" @checked($selectedWidgetTheme === 'custom') @disabled(! $canManageSettings)>
                            <span class="theme-swatches" aria-hidden="true"><i></i><i></i></span>
                            <span><b>Custom</b><small>Use your exact brand colors</small></span>
                        </label>
                    </fieldset>

                    <div id="widget-theme-custom" @if($selectedWidgetTheme !== 'custom') hidden @endif>
                        <div class="theme-custom-grid">
                            <div>
                                <label for="widget-theme-primary">Primary color</label>
                                <div class="theme-color-control">
                                    <input id="widget-theme-primary" type="text" name="widget_theme_primary" value="{{ $widgetThemePrimaryInput }}" maxlength="7" inputmode="text" pattern="#[0-9A-Fa-f]{6}" autocomplete="off" spellcheck="false" @disabled(! $canManageSettings)>
                                    <input id="widget-theme-primary-picker" type="color" value="{{ $previewWidgetTheme['primary'] }}" aria-label="Choose primary color" @disabled(! $canManageSettings)>
                                </div>
                            </div>
                            <div>
                                <label for="widget-theme-accent">Accent color</label>
                                <div class="theme-color-control">
                                    <input id="widget-theme-accent" type="text" name="widget_theme_accent" value="{{ $widgetThemeAccentInput }}" maxlength="7" inputmode="text" pattern="#[0-9A-Fa-f]{6}" autocomplete="off" spellcheck="false" @disabled(! $canManageSettings)>
                                    <input id="widget-theme-accent-picker" type="color" value="{{ $previewWidgetTheme['accent'] }}" aria-label="Choose accent color" @disabled(! $canManageSettings)>
                                </div>
                            </div>
                        </div>
                        <small class="identity-help">Use the exact format #RRGGBB. Primary and accent need enough contrast to remain clear.</small>
                    </div>

                    <div class="theme-preview" id="widget-theme-preview" style="--preview-primary:{{ $previewWidgetTheme['primary'] }};--preview-accent:{{ $previewWidgetTheme['accent'] }};--preview-primary-foreground:{{ $previewWidgetTheme['primary_foreground'] }};--preview-accent-foreground:{{ $previewWidgetTheme['accent_foreground'] }}">
                        <div class="theme-preview-launcher"><span id="theme-preview-business-initial">{{ mb_strtoupper(mb_substr(old('business_name', $agent->business_name ?: $organization->name), 0, 1)) }}</span><b>Ask <span id="theme-preview-business">{{ old('business_name', $agent->business_name ?: $organization->name) }}</span></b></div>
                        <div class="theme-preview-frame">
                            <div class="theme-preview-head"><span id="theme-preview-assistant-initial">{{ mb_strtoupper(mb_substr(old('agent_name', $agent->assistantDisplayName()), 0, 1)) }}</span><div><b><span id="theme-preview-assistant">{{ old('agent_name', $agent->assistantDisplayName()) }}</span> · <span id="theme-preview-header-business">{{ old('business_name', $agent->business_name ?: $organization->name) }}</span></b><small>● Online · Powered by Legatus</small></div></div>
                            <div class="theme-preview-body"><p>გამარჯობა! როგორ დაგეხმაროთ?</p><p>მაჩვენე ყველაზე პოპულარული პროდუქტები</p></div>
                            <button type="button">გაგზავნა ↑</button>
                        </div>
                    </div>
                    <small class="theme-status" id="widget-theme-status" aria-live="polite"></small>
                </section>

                <h3 style="margin-top:28px">AI employee behavior</h3>
                <label>Brand tone</label><input name="tone" value="{{ old('tone', $agent->tone) }}" required>
                <label>Human handoff threshold</label><input name="handoff_threshold" type="number" step="0.01" min="0" max="1" value="{{ old('handoff_threshold', $agent->settings['handoff_threshold'] ?? .72) }}">
                <label>Maximum autonomous discount (%)</label><input name="discount_limit" type="number" min="0" max="100" value="{{ old('discount_limit', $agent->settings['discount_limit'] ?? 0) }}">
                <label>Business hours</label><textarea name="business_hours" rows="2">{{ old('business_hours', $agent->settings['business_hours'] ?? '') }}</textarea>

                <h3 style="margin-top:28px">Verified delivery policy</h3>
                <p style="color:var(--muted);font-size:13px">Delivery promises are calculated from these server-owned rules, never from a model guess.</p>
                <label>Timezone</label><input name="delivery_timezone" value="{{ old('delivery_timezone', $delivery['timezone'] ?? 'Asia/Tbilisi') }}" required>
                <label>Local cities (comma-separated)</label><input name="delivery_local_cities" value="{{ old('delivery_local_cities', implode(', ', $delivery['local_cities'] ?? ['თბილისი', 'Tbilisi'])) }}" required>
                <label>Daily cutoff</label><input name="delivery_cutoff" type="time" value="{{ old('delivery_cutoff', $delivery['cutoff'] ?? '18:00') }}" required>
                <label>Local business days</label><input name="delivery_local_days" type="number" min="1" max="10" value="{{ old('delivery_local_days', $delivery['local_business_days'] ?? 1) }}" required>
                <label>Regional minimum business days</label><input name="delivery_regional_min_days" type="number" min="1" max="30" value="{{ old('delivery_regional_min_days', $delivery['regional_min_business_days'] ?? 1) }}" required>
                <label>Regional maximum business days</label><input name="delivery_regional_max_days" type="number" min="1" max="30" value="{{ old('delivery_regional_max_days', $delivery['regional_max_business_days'] ?? 3) }}" required>
                @if(in_array($role, ['owner', 'admin']))
                    <button class="btn lime" style="margin-top:18px">Save business & AI settings</button>
                @else
                    <p style="color:var(--muted);font-size:12px;margin-top:18px">Only an owner or admin can change workspace settings.</p>
                @endif
            </form>

            <section class="panel">
                <h3>Team & permissions</h3>
                @foreach($members as $member)
                    <div class="conversation">
                        <span class="avatar" style="width:36px;height:36px">{{ mb_strtoupper(mb_substr($member->name, 0, 1)) }}</span>
                        <div class="copy"><b>{{ $member->name }}</b><p>{{ $member->email }}</p></div>
                        <span class="pill">{{ $member->pivot->role }}</span>
                        @if($role === 'owner' && ! $member->is(auth()->user()))
                            <form method="post" action="{{ route('team.remove', $member) }}">@csrf @method('DELETE')<button class="btn ghost" style="padding:7px">×</button></form>
                        @endif
                    </div>
                @endforeach
                @if(in_array($role, ['owner', 'admin']))
                    <form method="post" action="{{ route('team.add') }}" style="border-top:1px solid var(--line);margin-top:15px">
                        @csrf
                        <label>Add existing user by email</label><input type="email" name="email" required placeholder="teammate@example.com">
                        <label>Role</label><select name="role" style="width:100%;padding:13px;border:1px solid var(--line);border-radius:12px"><option value="agent">Agent</option><option value="admin">Admin</option><option value="viewer">Viewer</option></select>
                        <button class="btn" style="margin-top:14px">Add member</button>
                    </form>
                @endif
            </section>
        </div>
    </main>
</div>
<style nonce="{{ request()->attributes->get('csp_nonce') }}">
    .identity-help{display:block;color:var(--muted);font-size:11px;line-height:1.5;margin-top:6px}
    .theme-section{margin-top:28px;padding-top:26px;border-top:1px solid var(--line)}
    .theme-section>p{color:var(--muted);font-size:13px;line-height:1.55;margin:-8px 0 16px}
    .theme-picker{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;border:0;padding:0;margin:0}
    .theme-picker legend{font-size:13px;font-weight:600;margin-bottom:8px}
    .theme-card{position:relative;display:flex;align-items:center;gap:10px;margin:0;padding:11px;border:1px solid var(--line);border-radius:13px;cursor:pointer;background:#fff}
    .theme-card:has(input:checked){border-color:var(--theme-card-primary);box-shadow:0 0 0 2px color-mix(in srgb,var(--theme-card-primary) 18%,transparent)}
    .theme-card input{width:auto;margin:0;accent-color:var(--theme-card-primary)}
    .theme-card>span:last-child{min-width:0}
    .theme-card b,.theme-card small{display:block}
    .theme-card b{font-size:12px}
    .theme-card small{font-size:10px;color:var(--muted);margin-top:2px}
    .theme-swatches{display:flex;flex:0 0 auto}
    .theme-swatches i{width:22px;height:28px;background:var(--theme-card-primary);border-radius:8px 0 0 8px}
    .theme-swatches i+ i{background:var(--theme-card-accent);border-radius:0 8px 8px 0}
    .theme-custom-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    .theme-custom-grid label{margin-top:14px}
    .theme-custom-grid input{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;text-transform:uppercase}
    .theme-color-control{display:flex;gap:8px;align-items:stretch}
    .theme-color-control input[type="text"]{min-width:0}
    .theme-color-control input[type="color"]{flex:0 0 52px;width:52px;min-height:48px;padding:4px;cursor:pointer;background:white}
    .theme-preview{display:grid;grid-template-columns:auto minmax(0,1fr);gap:14px;align-items:end;margin-top:18px;padding:16px;border:1px solid var(--line);border-radius:16px;background:#f5f7f4}
    .theme-preview-launcher{display:flex;align-items:center;gap:8px;max-width:220px;padding:8px 13px 8px 8px;border-radius:999px;background:var(--preview-primary);color:var(--preview-primary-foreground);box-shadow:0 10px 24px #142c2429;font-size:11px}
    .theme-preview-launcher>span{display:grid;place-items:center;width:28px;height:28px;border-radius:50%;background:var(--preview-accent);color:var(--preview-accent-foreground);font-weight:800}
    .theme-preview-launcher b{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .theme-preview-frame{min-width:0;overflow:hidden;border-radius:14px;background:white;box-shadow:0 10px 28px #142c241f}
    .theme-preview-head{display:flex;align-items:center;gap:8px;padding:10px;background:var(--preview-primary);color:var(--preview-primary-foreground)}
    .theme-preview-head>span{display:grid;place-items:center;flex:0 0 29px;width:29px;height:29px;border-radius:9px;background:var(--preview-accent);color:var(--preview-accent-foreground);font-weight:800;font-size:11px}
    .theme-preview-head div{min-width:0}
    .theme-preview-head b,.theme-preview-head small{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .theme-preview-head b{font-size:10px}.theme-preview-head small{font-size:8px;opacity:.72;margin-top:2px}
    .theme-preview-body{padding:9px;background:#f6f8f4}
    .theme-preview-body p{width:max-content;max-width:88%;padding:7px 8px;margin:4px 0;border-radius:9px;background:white;border:1px solid #e4e9e5;font-size:9px}
    .theme-preview-body p:last-child{margin-left:auto;background:var(--preview-primary);color:var(--preview-primary-foreground);border:0}
    .theme-preview-frame button{float:right;margin:0 8px 8px;border:0;border-radius:8px;padding:6px 9px;background:var(--preview-accent);color:var(--preview-accent-foreground);font-size:9px;font-weight:700}
    .theme-status{display:block;min-height:18px;margin-top:7px;color:var(--muted);font-size:10px}
    .theme-status[data-state="error"]{color:#a33}
    @media(max-width:680px){.theme-picker,.theme-custom-grid{grid-template-columns:1fr}.theme-preview{grid-template-columns:1fr}.theme-preview-launcher{justify-self:end}}
</style>
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
{
    const palettes=@json($widgetThemePreviewPalettes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    const radios=[...document.querySelectorAll('input[name="widget_theme_preset"]')];
    const primaryInput=document.querySelector('#widget-theme-primary');
    const accentInput=document.querySelector('#widget-theme-accent');
    const primaryPicker=document.querySelector('#widget-theme-primary-picker');
    const accentPicker=document.querySelector('#widget-theme-accent-picker');
    const customFields=document.querySelector('#widget-theme-custom');
    const customCard=document.querySelector('#widget-theme-custom-card');
    const preview=document.querySelector('#widget-theme-preview');
    const status=document.querySelector('#widget-theme-status');
    const businessInput=document.querySelector('#business-name');
    const assistantInput=document.querySelector('#agent-name');
    const hex=/^#[0-9A-Fa-f]{6}$/;

    function luminance(color){
        const channels=[1,3,5].map(index=>parseInt(color.slice(index,index+2),16)/255).map(channel=>channel<=.04045?channel/12.92:Math.pow((channel+.055)/1.055,2.4));
        return .2126*channels[0]+.7152*channels[1]+.0722*channels[2];
    }
    function contrast(first,second){const a=luminance(first),b=luminance(second);return(Math.max(a,b)+.05)/(Math.min(a,b)+.05)}
    function foreground(background){return contrast(background,'#FFFFFF')>=contrast(background,'#000000')?'#FFFFFF':'#000000'}
    function selectedPreset(){return radios.find(radio=>radio.checked)||radios.find(radio=>radio.value==='forest')}
    function applyPreview(primary,accent){
        if(!hex.test(primary)||!hex.test(accent)){
            status.textContent='Enter both colors in #RRGGBB format.';
            status.dataset.state='error';
            accentInput.setCustomValidity(selectedPreset()?.value==='custom'?'Enter both colors in #RRGGBB format.':'');
            return;
        }
        primary=primary.toUpperCase();accent=accent.toUpperCase();
        primaryPicker.value=primary;
        accentPicker.value=accent;
        const pairContrast=contrast(primary,accent);
        const custom=selectedPreset()?.value==='custom';
        accentInput.setCustomValidity(custom&&pairContrast<3?'Choose colors with at least a 3:1 contrast ratio.':'');
        if(pairContrast<3){
            status.textContent='These colors are too similar. Increase contrast between primary and accent.';
            status.dataset.state='error';
            return;
        }
        preview.style.setProperty('--preview-primary',primary);
        preview.style.setProperty('--preview-accent',accent);
        preview.style.setProperty('--preview-primary-foreground',foreground(primary));
        preview.style.setProperty('--preview-accent-foreground',foreground(accent));
        customCard.style.setProperty('--theme-card-primary',primary);
        customCard.style.setProperty('--theme-card-accent',accent);
        status.textContent=`Accessible preview · color contrast ${pairContrast.toFixed(1)}:1`;
        status.dataset.state='ok';
    }
    function syncTheme(){
        const selected=selectedPreset();
        if(!selected)return;
        const custom=selected.value==='custom';
        customFields.hidden=!custom;
        primaryInput.readOnly=!custom;
        accentInput.readOnly=!custom;
        primaryInput.required=custom;
        accentInput.required=custom;
        if(!custom&&palettes[selected.value]){
            primaryInput.value=palettes[selected.value].primary;
            accentInput.value=palettes[selected.value].accent;
        }
        applyPreview(primaryInput.value,accentInput.value);
    }
    function syncIdentity(){
        const business=businessInput.value.trim()||'Your business';
        const assistant=assistantInput.value.trim()||'AI Assistant';
        document.querySelector('#theme-preview-business').textContent=business;
        document.querySelector('#theme-preview-header-business').textContent=business;
        document.querySelector('#theme-preview-assistant').textContent=assistant;
        document.querySelector('#theme-preview-business-initial').textContent=Array.from(business)[0].toLocaleUpperCase();
        document.querySelector('#theme-preview-assistant-initial').textContent=Array.from(assistant)[0].toLocaleUpperCase();
    }
    radios.forEach(radio=>radio.addEventListener('change',syncTheme));
    [primaryInput,accentInput].forEach(input=>input.addEventListener('input',()=>applyPreview(primaryInput.value,accentInput.value)));
    [[primaryPicker,primaryInput],[accentPicker,accentInput]].forEach(([picker,input])=>picker.addEventListener('input',()=>{input.value=picker.value.toUpperCase();applyPreview(primaryInput.value,accentInput.value)}));
    [businessInput,assistantInput].forEach(input=>input.addEventListener('input',syncIdentity));
    syncTheme();syncIdentity();
}
</script>
@endsection
