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
            <div><span class="channel-number">2</span><div><h3>Website chat</h3><p>Install the widget once and control whether customers can use it.</p></div></div>
            <span @class(['channel-status', 'is-connected' => $widgetEnabled, 'is-off' => ! $widgetEnabled])>{{ $widgetEnabled ? 'On' : 'Off' }}</span>
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
        <div class="channel-snippet"><code id="widget-snippet">{{ $snippet }}</code><button class="btn lime" id="copy-snippet" type="button">Copy script</button></div>
        <p class="copy-feedback" id="copy-feedback" aria-live="polite"></p>
        @if($widgetDomains->isNotEmpty())<p class="allowed-domains">Allowed on: {{ $widgetDomains->join(', ') }}</p>@endif
    </article>

    <article class="channel-block" id="meta-channels">
        <div class="channel-block__head">
            <div><span class="channel-number">3</span><div><h3>Facebook and Instagram</h3><p>Connect through Meta once, then manage both accounts below.</p></div></div>
            <span @class(['channel-status', 'is-connected' => $connectedMetaCount === 2])>{{ $connectedMetaCount }}/2 connected</span>
        </div>
        @if($connectedMetaCount < 2 && $metaConnectUrl)
            <a class="meta-connect" href="{{ $metaConnectUrl }}">Connect Facebook and Instagram with Meta</a>
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
.business-channels{margin-top:34px}.business-channels__heading{margin-bottom:18px}.business-channels__heading h2{font-size:30px;margin:7px 0}.business-channels__heading p,.channel-block__head p{color:var(--muted);margin:0}.channel-block{margin-top:16px;padding:24px;border:1px solid var(--line);border-radius:20px;background:#fff}.channel-block__head,.channel-block__head>div,.widget-control,.channel-snippet,.meta-channel-card__title,.channel-actions{display:flex;align-items:center}.channel-block__head{justify-content:space-between;gap:18px}.channel-block__head>div{align-items:flex-start;gap:12px}.channel-block__head h3{margin:1px 0 5px;font-size:20px}.channel-block__head p{font-size:12px}.channel-number{display:grid;place-items:center;flex:0 0 32px;height:32px;border-radius:10px;background:var(--green);color:var(--lime);font-weight:800}.channel-status{padding:7px 10px;border:1px solid var(--line);border-radius:99px;color:var(--muted);font-size:11px;font-weight:800}.channel-status.is-connected{background:#eaf7df;color:#356342}.channel-status.is-off{background:#fff3ed;color:#904d39}.widget-control{justify-content:space-between;gap:18px;margin-top:20px;padding:16px;border-radius:14px;background:#f4f8f4}.widget-control b,.widget-control small{display:block}.widget-control small,.allowed-domains{color:var(--muted);font-size:10px;margin-top:4px}.channel-snippet{gap:12px;margin-top:14px;padding:12px;border-radius:13px;background:#122c24}.channel-snippet code{min-width:0;flex:1;overflow:auto;color:#d9ff72;white-space:nowrap}.copy-feedback{min-height:16px;margin:6px 0 0;color:#377157;font-size:11px}.meta-connect{display:block;margin-top:18px;padding:13px;border-radius:12px;background:var(--green);color:#fff;text-align:center;font-weight:800}.meta-channel-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:16px}.meta-channel-card{display:flex;flex-direction:column;padding:18px;border:1px solid var(--line);border-radius:16px}.meta-channel-card__title{gap:10px}.meta-channel-card__title>span{display:grid;place-items:center;width:38px;height:38px;border-radius:12px;background:#eef3ef;font-weight:900}.meta-channel-card__title b,.meta-channel-card__title small{display:block}.meta-channel-card__title small{color:var(--muted);font-size:10px;margin-top:3px}.meta-channel-card>p{color:var(--muted);font-size:11px;line-height:1.55}.meta-channel-card>.btn{margin-top:auto;text-align:center}.connected-account{margin-top:auto;padding:12px;border-radius:11px;background:#f4f8f4}.connected-account small,.connected-account b{display:block}.connected-account small{color:var(--muted)}.channel-actions{justify-content:space-between;gap:10px;margin-top:10px}.link-button{border:0;background:transparent;color:#914c38;cursor:pointer}.channel-error{padding:10px;border-radius:10px;background:#fff1eb;color:#904b38!important}.meta-security{margin:14px 0 0;color:var(--muted);font-size:11px}.channel-message{margin:12px 0;padding:12px 14px;border-radius:12px;background:#eaf7df;color:#356342}.channel-message--error{background:#fff1eb;color:#904b38}@media(max-width:720px){.channel-block__head,.widget-control,.channel-snippet{align-items:stretch;flex-direction:column}.meta-channel-grid{grid-template-columns:1fr}.channel-snippet .btn{width:100%}}
.catalog-summary{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-top:18px;padding:16px;border-radius:14px;background:#f4f8f4}.catalog-summary b,.catalog-summary small,.commerce-status span,.commerce-status b,.commerce-status small{display:block}.catalog-summary small,.commerce-status span,.commerce-status small,.developer-connector>p{margin-top:4px;color:var(--muted);font-size:10px}.commerce-status{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:18px}.commerce-status>div{padding:13px;border-radius:13px;background:#f4f8f4}.commerce-actions{margin-top:14px}.developer-connector,.commerce-disconnect{margin-top:16px;padding-top:14px;border-top:1px dashed var(--line)}.developer-connector summary,.commerce-disconnect summary{cursor:pointer;font-weight:700}.commerce-form{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:14px;padding:16px;border-radius:14px;background:#f7f9f7}.commerce-form label{margin:0}.commerce-form input{margin-top:6px}.commerce-form .btn{grid-column:1/-1}.commerce-readonly{margin-top:16px;padding:12px;border-radius:11px;background:#f4f6f4;color:var(--muted);font-size:11px}@media(max-width:720px){.catalog-summary{align-items:stretch;flex-direction:column}.commerce-status,.commerce-form{grid-template-columns:1fr}.commerce-form .btn{grid-column:1}}
</style>
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(()=>{const button=document.querySelector('#copy-snippet');if(!button)return;button.addEventListener('click',async()=>{const feedback=document.querySelector('#copy-feedback');try{await navigator.clipboard.writeText(document.querySelector('#widget-snippet').textContent.trim());button.textContent='Copied';feedback.textContent='Script copied. Paste it before </body> on your website.'}catch(error){feedback.textContent='Select and copy the script manually.'}})})();
</script>
