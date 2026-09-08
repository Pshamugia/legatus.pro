@php($connectedMetaCount = $metaChannels->where('connected', true)->count())
<section class="business-channels" id="channels" aria-labelledby="channels-title">
    <div class="business-channels__heading">
        <span class="eyebrow">Customer channels</span>
        <h2 id="channels-title">Where Legatus talks to customers</h2>
        <p>Manage each channel here. Products, policies and business information stay in Knowledge.</p>
    </div>

    @if(session('channel_success') || session('channel_error'))
        <div @class(['channel-message', 'channel-message--error' => session('channel_error')])>{{ session('channel_success') ?? session('channel_error') }}</div>
    @endif

    <article class="channel-block" id="commerce-connection">
        <div class="channel-block__head">
            <div><span class="channel-number">1</span><div><h3>Product catalog connection</h3><p>See whether Legatus has products to sell and, when needed, connect a live store.</p></div></div>
            <span data-catalog-status="{{ $catalogConnectionState }}" @class(['channel-status', 'is-connected' => in_array($catalogConnectionState, ['live', 'available'], true), 'is-off' => $catalogConnectionState === 'missing'])>
                @if($catalogConnectionState === 'live') ✓ Live API connected
                @elseif($catalogConnectionState === 'attention') Needs attention
                @elseif($catalogConnectionState === 'available') ✓ Catalog available
                @else Catalog not added
                @endif
            </span>
        </div>
        @if($catalogConnectionState === 'available')
            <div class="catalog-summary"><div><b>Your catalog is already available</b><small>Legatus is using your {{ number_format($productCount) }} active {{ \Illuminate\Support\Str::plural('product', $productCount) }}. Live API is optional for real-time stock and prices.</small></div><a class="btn ghost" href="{{ route('knowledge.index') }}">Manage in Knowledge</a></div>
        @elseif(!$commerceConnection)
            <div class="catalog-summary"><div><b>Add or manage the catalog in Knowledge</b><small>Public catalogs, categories, policies and uploaded files belong there.</small></div><a class="btn ghost" href="{{ route('knowledge.index') }}">Open Knowledge</a></div>
        @endif
        @if($commerceConnection)
            <div class="commerce-status" data-commerce-status="{{ $commerceConnection->status }}">
                <div><span>Source</span><b>{{ $commerceConnection->name ?: 'Connected store' }}</b><small>{{ parse_url($commerceConnection->base_url, PHP_URL_HOST) }}</small></div>
                <div><span>Active products</span><b>{{ number_format($commerceProductCount) }}</b><small>From the live catalog</small></div>
                <div><span>Last synchronization</span><b>{{ $commerceConnection->last_sync_at?->diffForHumans() ?? 'Not completed yet' }}</b><small>Updates every hour</small></div>
            </div>
            @if($canManageChannels)<div class="commerce-actions"><form method="POST" action="{{ route('channels.commerce.sync') }}">@csrf<button class="btn lime" type="submit">Sync now</button></form></div>@endif
        @endif
        @if($canManageChannels)
            <details class="developer-connector" @if($errors->commerce->any()) open @endif>
                <summary>Advanced: custom store integration</summary>
                <p>Only use this with a developer-provided Connector Key ID and Shared secret.</p>
                <form class="commerce-form" method="POST" action="{{ route('channels.commerce.connect') }}" autocomplete="off">@csrf
                    <label>Source name <small>(optional)</small><input name="name" maxlength="120" placeholder="e.g. Live catalog"></label>
                    <label>Store HTTPS address<input name="base_url" type="url" maxlength="500" required placeholder="https://your-store.example"></label>
                    <label>Connector Key ID<input name="key_id" maxlength="120" required autocomplete="off"></label>
                    <label>Shared secret<input name="secret" type="password" minlength="32" maxlength="512" required autocomplete="new-password"></label>
                    <button class="btn" type="submit">{{ $commerceConnection ? 'Verify and change connection' : 'Verify and connect' }}</button>
                </form>
            </details>
            @if($commerceConnection)<details class="commerce-disconnect"><summary>Disconnect store</summary><form method="POST" action="{{ route('channels.commerce.disconnect') }}">@csrf @method('DELETE')<button class="link-button" type="submit">Disconnect store</button></form></details>@endif
        @else
            <p class="commerce-readonly">Only a business owner or admin can change the connection.</p>
        @endif
    </article>

    <article class="channel-block" id="website-channel">
        <div class="channel-block__head">
            <div><span class="channel-number">2</span><div><h3>Website chat</h3><p>Legatus detects your platform, shows the right steps, and checks the installation.</p></div></div>
            <span @class(['channel-status', 'is-connected' => ($widgetInstallation['installed'] ?? false), 'is-off' => ! ($widgetInstallation['installed'] ?? false)])>{{ ($widgetInstallation['installed'] ?? false) ? '✓ Installed' : 'Not verified' }}</span>
        </div>
        <div class="widget-control">
            <div><b>{{ $widgetEnabled ? 'Visible to website visitors' : 'Hidden from website visitors' }}</b><small>The installed script can stay in place when chat is off.</small></div>
            @if($canManageChannels)
                <form method="POST" action="{{ route('channels.widget.update') }}">
                    @csrf @method('PATCH')
                    <input type="hidden" name="enabled" value="{{ $widgetEnabled ? '0' : '1' }}">
                    <button class="btn {{ $widgetEnabled ? 'ghost' : '' }}" type="submit">Turn {{ $widgetEnabled ? 'off' : 'on' }}</button>
                </form>
            @endif
        </div>

        <div class="widget-installer">
            <div class="widget-installer__head">
                <div>
                    <b>{{ $widgetWebsite !== '' ? parse_url($widgetWebsite, PHP_URL_HOST) : 'Website address required' }}</b>
                    <small>Detection is read-only. Legatus never changes a website automatically.</small>
                </div>
                @if($canManageChannels)
                    <form method="POST" action="{{ route('channels.widget.detect-platform') }}">@csrf
                        <button class="btn ghost" type="submit" @disabled($widgetWebsite === '')>Detect platform</button>
                    </form>
                @endif
            </div>

            @if($canManageChannels)
                <form class="platform-picker" method="POST" action="{{ route('channels.widget.platform') }}">
                    @csrf @method('PATCH')
                    <label for="widget-platform">Website platform</label>
                    <select id="widget-platform" name="platform">
                        @foreach($widgetPlatforms as $key => $platform)
                            <option value="{{ $key }}" @selected($widgetPlatform === $key)>{{ $platform['label'] }}</option>
                        @endforeach
                    </select>
                    <button class="btn ghost" type="submit">Show instructions</button>
                </form>
            @endif

            <div class="platform-guide">
                <span class="eyebrow">{{ $widgetPlatforms[$widgetPlatform]['label'] }}</span>
                <h4>Install the universal Legatus widget</h4>
                <p>{{ $widgetPlatforms[$widgetPlatform]['description'] }}</p>
                <ol>
                    @foreach($widgetPlatforms[$widgetPlatform]['steps'] as $step)
                        <li><span>{{ $loop->iteration }}</span>{{ $step }}</li>
                    @endforeach
                </ol>
                <div class="channel-snippet"><code id="widget-snippet">{{ $snippet }}</code><button class="btn lime" id="copy-snippet" type="button">Copy universal script</button></div>
                <div class="installer-actions">
                    <button class="btn ghost" id="copy-developer-instructions" type="button">Copy instructions for developer</button>
                    @if($canManageChannels)
                        <form method="POST" action="{{ route('channels.widget.verify') }}">@csrf
                            <button class="btn dark" type="submit" @disabled($widgetWebsite === '')>Check installation</button>
                        </form>
                    @endif
                </div>
                <p class="copy-feedback" id="copy-feedback" aria-live="polite"></p>
            </div>
        </div>
        @if($widgetDomains->isNotEmpty())<p class="allowed-domains">Allowed on: {{ $widgetDomains->join(', ') }}</p>@endif
        <p class="widget-security-note">🔒 The widget frame is allowed only on the saved business domain. Detecting a platform does not install or activate anything on that website.</p>
    </article>

    <article class="channel-block" id="meta-channels">
        <div class="channel-block__head">
            <div><span class="channel-number">3</span><div><h3>Facebook and Instagram</h3><p>Connect through Meta once, then manage both accounts below.</p></div></div>
            <span @class(['channel-status', 'is-connected' => $connectedMetaCount === 2])>{{ $connectedMetaCount }}/2 connected</span>
        </div>
        @if($metaConnectUrl)
            <div class="meta-account-control">
                <div>
                    <b>{{ $connectedMetaCount === 2 ? '✓ Meta connection is active' : 'Connect your Meta business account' }}</b>
                    <small>{{ $connectedMetaCount === 2 ? 'Both Facebook and Instagram are connected. You can reopen Meta authorization whenever accounts or permissions change.' : 'Use Meta’s official authorization to select the Facebook Page and linked Instagram account.' }}</small>
                </div>
                <a class="btn {{ $connectedMetaCount === 2 ? 'ghost' : '' }}" href="{{ $metaConnectUrl }}">{{ $connectedMetaCount === 2 ? 'Manage or reconnect Meta' : 'Connect Facebook and Instagram with Meta' }}</a>
            </div>
        @endif
        <div class="meta-channel-grid">
            @foreach($metaChannels as $channel)
                <div class="meta-channel-card" data-channel="{{ $channel['provider'] }}" data-status="{{ $channel['status'] }}">
                    <div class="meta-channel-card__title"><span>{{ $channel['icon'] }}</span><div><b>{{ $channel['name'] }}</b><small>{{ $channel['connected'] ? 'Connected' : ($channel['status'] === 'error' ? 'Needs attention' : 'Not connected') }}</small></div></div>
                    <p>{{ $channel['description'] }}</p>
                    @if($channel['connected'])
                        <div class="connected-account"><small>Connected account</small><b>{{ $channel['account_name'] ?: 'Meta business account' }}</b></div>
                        <div class="channel-actions"><a class="btn ghost" href="{{ route('inbox.index') }}">Open Inbox</a>@if($channel['disconnect_url'])<form action="{{ $channel['disconnect_url'] }}" method="POST">@csrf @method('DELETE')<button class="link-button" type="submit">Disconnect</button></form>@endif</div>
                    @else
                        @if($channel['error'])<p class="channel-error">{{ $channel['error'] }}</p>@endif
                        @if($channel['connect_url'])<a class="btn" href="{{ $channel['connect_url'] }}">{{ $channel['status'] === 'error' ? 'Reconnect' : 'Connect '.$channel['short_name'] }}</a>@endif
                    @endif
                </div>
            @endforeach
        </div>
        <p class="meta-security">Authorization happens on Meta’s official page. Legatus never sees your Facebook or Instagram password.</p>
    </article>

    <article class="channel-block" id="linkedin-channel">
        <div class="channel-block__head">
            <div><span class="channel-number">4</span><div><h3>LinkedIn company page</h3><p>Publish scheduled product posts to a LinkedIn Page managed by your business.</p></div></div>
            <span @class(['channel-status', 'is-connected' => $linkedinChannel['connected']])>{{ $linkedinChannel['connected'] ? '✓ Connected' : 'Not connected' }}</span>
        </div>
        <div class="meta-channel-grid" style="grid-template-columns:1fr">
            <div class="meta-channel-card" data-channel="linkedin" data-status="{{ $linkedinChannel['connected'] ? 'connected' : 'disconnected' }}">
                <div class="meta-channel-card__title"><span style="background:#e8f2fb;color:#0a66c2">in</span><div><b>LinkedIn</b><small>{{ $linkedinChannel['connected'] ? 'Ready for scheduled publishing' : 'Company Page connection required' }}</small></div></div>
                <p>Uses LinkedIn's official authorization and posts only to the Page you select.</p>
                @if($linkedinChannel['connected'])
                    <div class="connected-account"><small>Connected page</small><b>{{ $linkedinChannel['account_name'] }}</b></div>
                    @if($canManageChannels && $linkedinChannel['disconnect_url'])<div class="channel-actions"><span></span><form action="{{ $linkedinChannel['disconnect_url'] }}" method="POST">@csrf @method('DELETE')<button class="link-button" type="submit">Disconnect</button></form></div>@endif
                @else
                    @if($linkedinChannel['error'])<p class="channel-error">{{ $linkedinChannel['error'] }}</p>@endif
                    @if($canManageChannels && $linkedinChannel['connect_url'])<a class="btn" href="{{ $linkedinChannel['connect_url'] }}">Connect LinkedIn</a>@endif
                @endif
            </div>
        </div>
        <p class="meta-security">Authorization happens on LinkedIn's official page. Legatus never sees your LinkedIn password.</p>
    </article>

    <article class="channel-block" id="whatsapp-channel">
        <div class="channel-block__head">
            <div><span class="channel-number">5</span><div><h3>WhatsApp Business</h3><p>Let customers message your business number and receive grounded Legatus replies.</p></div></div>
            <span @class(['channel-status', 'is-connected' => $whatsappChannel['connected']])>{{ $whatsappChannel['connected'] ? '✓ Connected' : 'Not connected' }}</span>
        </div>
        <div class="meta-channel-grid" style="grid-template-columns:1fr">
            <div class="meta-channel-card" data-channel="whatsapp" data-status="{{ $whatsappChannel['connected'] ? 'connected' : 'disconnected' }}">
                <div class="meta-channel-card__title"><span style="background:#e8f8ed;color:#128c4a">W</span><div><b>WhatsApp</b><small>{{ $whatsappChannel['connected'] ? 'Ready for customer conversations' : 'Business number connection required' }}</small></div></div>
                <p>Incoming chats use the same Knowledge, safety rules, human handoff and Legatus Inbox as your other customer channels.</p>
                @if($whatsappChannel['connected'])
                    <div class="connected-account"><small>Connected business number</small><b>{{ $whatsappChannel['phone_number'] ?: $whatsappChannel['account_name'] }}</b></div>
                    <div class="channel-actions"><a class="btn ghost" href="{{ route('inbox.index') }}">Open Inbox</a>@if($canManageChannels && $whatsappChannel['disconnect_url'])<form action="{{ $whatsappChannel['disconnect_url'] }}" method="POST">@csrf @method('DELETE')<button class="link-button" type="submit">Disconnect</button></form>@endif</div>
                @else
                    @if($whatsappChannel['error'])<p class="channel-error">{{ $whatsappChannel['error'] }}</p>@endif
                    @if($canManageChannels && $whatsappChannel['connect_url'])<a class="btn" href="{{ $whatsappChannel['connect_url'] }}">Connect WhatsApp</a>@endif
                @endif
            </div>
        </div>
        <p class="meta-security">WhatsApp Status/Stories publishing is not shown because Meta does not provide a supported public Cloud API for it.</p>
    </article>
</section>

<style nonce="{{ request()->attributes->get('csp_nonce') }}">
.business-channels{margin-top:34px}.business-channels__heading{margin-bottom:18px}.business-channels__heading h2{font-size:30px;margin:7px 0}.business-channels__heading p,.channel-block__head p{color:var(--muted);margin:0}.channel-block{margin-top:16px;padding:24px;border:1px solid var(--line);border-radius:20px;background:#fff}.channel-block__head,.channel-block__head>div,.widget-control,.channel-snippet,.meta-channel-card__title,.channel-actions,.meta-account-control{display:flex;align-items:center}.channel-block__head{justify-content:space-between;gap:18px}.channel-block__head>div{align-items:flex-start;gap:12px}.channel-block__head h3{margin:1px 0 5px;font-size:20px}.channel-block__head p{font-size:12px}.channel-number{display:grid;place-items:center;flex:0 0 32px;height:32px;border-radius:10px;background:var(--green);color:var(--lime);font-weight:800}.channel-status{padding:7px 10px;border:1px solid var(--line);border-radius:99px;color:var(--muted);font-size:11px;font-weight:800}.channel-status.is-connected{background:#eaf7df;color:#356342}.channel-status.is-off{background:#fff3ed;color:#904d39}.widget-control{justify-content:space-between;gap:18px;margin-top:20px;padding:16px;border-radius:14px;background:#f4f8f4}.widget-control b,.widget-control small{display:block}.widget-control small,.allowed-domains{color:var(--muted);font-size:10px;margin-top:4px}.channel-snippet{gap:12px;margin-top:14px;padding:12px;border-radius:13px;background:#122c24}.channel-snippet code{min-width:0;flex:1;overflow:auto;color:#d9ff72;white-space:nowrap}.copy-feedback{min-height:16px;margin:6px 0 0;color:#377157;font-size:11px}.meta-connect{display:block;margin-top:18px;padding:13px;border-radius:12px;background:var(--green);color:#fff;text-align:center;font-weight:800}.meta-account-control{justify-content:space-between;gap:18px;margin-top:18px;padding:14px 16px;border:1px solid #d8e7dc;border-radius:13px;background:#f4faf5}.meta-account-control b,.meta-account-control small{display:block}.meta-account-control small{margin-top:4px;color:var(--muted);font-size:10px;line-height:1.5}.meta-account-control .btn{flex:0 0 auto}.meta-channel-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:16px}.meta-channel-card{display:flex;flex-direction:column;padding:18px;border:1px solid var(--line);border-radius:16px}.meta-channel-card__title{gap:10px}.meta-channel-card__title>span{display:grid;place-items:center;width:38px;height:38px;border-radius:12px;background:#eef3ef;font-weight:900}.meta-channel-card__title b,.meta-channel-card__title small{display:block}.meta-channel-card__title small{color:var(--muted);font-size:10px;margin-top:3px}.meta-channel-card>p{color:var(--muted);font-size:11px;line-height:1.55}.meta-channel-card>.btn{margin-top:auto;text-align:center}.connected-account{margin-top:auto;padding:12px;border-radius:11px;background:#f4f8f4}.connected-account small,.connected-account b{display:block}.connected-account small{color:var(--muted)}.channel-actions{justify-content:space-between;gap:10px;margin-top:10px}.link-button{border:0;background:transparent;color:#914c38;cursor:pointer}.channel-error{padding:10px;border-radius:10px;background:#fff1eb;color:#904b38!important}.meta-security{margin:14px 0 0;color:var(--muted);font-size:11px}.channel-message{margin:12px 0;padding:12px 14px;border-radius:12px;background:#eaf7df;color:#356342}.channel-message--error{background:#fff1eb;color:#904b38}@media(max-width:720px){.channel-block__head,.widget-control,.channel-snippet,.meta-account-control{align-items:stretch;flex-direction:column}.meta-channel-grid{grid-template-columns:1fr}.channel-snippet .btn,.meta-account-control .btn{width:100%}}
.catalog-summary{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-top:18px;padding:16px;border-radius:14px;background:#f4f8f4}.catalog-summary b,.catalog-summary small,.commerce-status span,.commerce-status b,.commerce-status small{display:block}.catalog-summary small,.commerce-status span,.commerce-status small,.developer-connector>p{margin-top:4px;color:var(--muted);font-size:10px}.commerce-status{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:18px}.commerce-status>div{padding:13px;border-radius:13px;background:#f4f8f4}.commerce-actions{margin-top:14px}.developer-connector,.commerce-disconnect{margin-top:16px;padding-top:14px;border-top:1px dashed var(--line)}.developer-connector summary,.commerce-disconnect summary{cursor:pointer;font-weight:700}.commerce-form{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:14px;padding:16px;border-radius:14px;background:#f7f9f7}.commerce-form label{margin:0}.commerce-form input{margin-top:6px}.commerce-form .btn{grid-column:1/-1}.commerce-readonly{margin-top:16px;padding:12px;border-radius:11px;background:#f4f6f4;color:var(--muted);font-size:11px}@media(max-width:720px){.catalog-summary{align-items:stretch;flex-direction:column}.commerce-status,.commerce-form{grid-template-columns:1fr}.commerce-form .btn{grid-column:1}}
.widget-installer{margin-top:16px;padding:18px;border:1px solid #dce7df;border-radius:16px;background:#fbfdf9}.widget-installer__head,.platform-picker,.installer-actions{display:flex;align-items:center;gap:12px}.widget-installer__head{justify-content:space-between}.widget-installer__head b,.widget-installer__head small{display:block}.widget-installer__head small{margin-top:4px;color:var(--muted);font-size:10px}.platform-picker{margin-top:16px;padding:12px;border-radius:12px;background:#f1f6f1}.platform-picker label{font-size:11px;font-weight:800}.platform-picker select{min-width:240px;flex:1;margin:0;padding:10px 11px;border:1px solid var(--line);border-radius:10px;background:#fff}.platform-guide{margin-top:14px;padding:17px;border-radius:14px;background:#fff;border:1px solid #e7ece8}.platform-guide h4{margin:5px 0 4px;font-size:17px}.platform-guide>p{margin:0;color:var(--muted);font-size:11px}.platform-guide ol{list-style:none;padding:0;margin:15px 0}.platform-guide li{display:flex;align-items:flex-start;gap:9px;margin-top:8px;color:#40534b;font-size:12px;line-height:1.5}.platform-guide li span{display:grid;place-items:center;flex:0 0 23px;height:23px;border-radius:50%;background:#e9f5dd;color:#3e6b36;font-size:10px;font-weight:800}.installer-actions{justify-content:flex-end;flex-wrap:wrap;margin-top:11px}.installer-actions form{margin:0}.widget-security-note{margin:12px 0 0;color:#587064;font-size:10px;line-height:1.5}@media(max-width:720px){.widget-installer__head,.platform-picker,.installer-actions{align-items:stretch;flex-direction:column}.platform-picker select,.platform-picker .btn,.installer-actions .btn,.installer-actions form{width:100%}.installer-actions form .btn{width:100%}}
</style>
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(()=>{const snippet=document.querySelector('#widget-snippet');const feedback=document.querySelector('#copy-feedback');if(!snippet||!feedback)return;const developerWebsite=@json($widgetWebsite ?: 'the business website', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);const copy=async(text,success)=>{try{await navigator.clipboard.writeText(text);feedback.textContent=success}catch(error){feedback.textContent='Copying was blocked by the browser. Select the script and copy it manually.'}};const button=document.querySelector('#copy-snippet');if(button)button.addEventListener('click',()=>copy(snippet.textContent.trim(),'Universal script copied. Add it site-wide before </body>.'));const developer=document.querySelector('#copy-developer-instructions');if(developer)developer.addEventListener('click',()=>copy(`Please install the Legatus website chat on every page of ${developerWebsite}. Add this script immediately before the closing </body> tag:\n\n${snippet.textContent.trim()}\n\nAfter publishing, let the business owner run “Check installation” in Legatus.`, 'Developer instructions copied. You can paste them into an email or message.'))})();
</script>
