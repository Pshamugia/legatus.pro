@extends('layouts.app')

@section('title', 'Channels · Legatus')

@section('body')
@php($connectedMetaCount = $metaChannels->where('connected', true)->count())
<div class="dash-shell channels-page">
    @include('partials.workspace-navigation', ['active' => 'channels'])

    <main class="main channels-main">
        <div class="topline channels-heading">
            <div>
                <span class="eyebrow">Simple channel setup</span>
                <h1>Launch Legatus in 3 steps</h1>
                <p>No coding required — copy one script and connect your social accounts.</p>
            </div>
            @if($widgetEnabled)
                <a class="btn ghost" href="{{ route('widget.frame', $agent) }}" target="_blank" rel="noopener">Preview website chat ↗</a>
            @else
                <a class="btn ghost" href="#website-install">Website chat is OFF</a>
            @endif
        </div>

        @if(session('success') || session('channel_success') || session('commerce_success') || session('status'))
            <div class="channel-alert channel-alert--success" role="status">
                <span>✓</span>
                <div><b>Ready</b><p>{{ session('success') ?? session('channel_success') ?? session('commerce_success') ?? session('status') }}</p></div>
            </div>
        @endif
        @foreach((array) session('warnings', []) as $warning)
            <div class="channel-alert channel-alert--error" role="alert">
                <span>!</span>
                <div><b>Source needs attention</b><p>{{ $warning }}</p></div>
            </div>
        @endforeach
        @foreach($failedKnowledgeSources as $failedSource)
            <div class="channel-alert channel-alert--error" role="alert">
                <span>!</span>
                <div><b>{{ $failedSource->name }} · Source is not usable</b><p>{{ $failedSource->error ?: 'No verified products were imported from this source.' }}</p></div>
            </div>
        @endforeach
        @if(session('error') || session('channel_error') || session('commerce_error'))
            <div class="channel-alert channel-alert--error" role="alert">
                <span>!</span>
                <div><b>Connection could not be completed</b><p>{{ session('error') ?? session('channel_error') ?? session('commerce_error') }}</p></div>
            </div>
        @endif

        <ol class="setup-progress" aria-label="Three setup steps">
            <li class="{{ $productCount > 0 ? 'is-ready' : '' }}">
                <span>1</span>
                <div><b>Teach</b><small>Catalog and policies</small></div>
            </li>
            <li>
                <span>2</span>
                <div><b>Add to website</b><small>One script</small></div>
            </li>
            <li class="{{ $connectedMetaCount > 0 ? 'is-ready' : '' }}">
                <span>3</span>
                <div><b>Connect Meta</b><small>Facebook and Instagram</small></div>
            </li>
        </ol>

        <section class="setup-section" aria-labelledby="knowledge-step-title">
            <div class="step-number">1</div>
            <div class="setup-content">
                <div class="section-heading">
                    <div>
                        <span class="eyebrow">Knowledge</span>
                        <h2 id="knowledge-step-title">Give Legatus your business knowledge</h2>
                        <p>Upload a catalog or provide your website. Answers will be grounded only in your prices, inventory and policies.</p>
                    </div>
                    @if($productCount > 0)
                        <span class="status-badge status-badge--connected">✓ Ready</span>
                    @else
                        <span class="status-badge">Not ready · 0 products</span>
                    @endif
                </div>
                <div class="knowledge-summary">
                    <div><strong>{{ number_format($productCount) }}</strong><span>Active products</span></div>
                    <div><strong>{{ number_format($knowledgeSourceCount) }}</strong><span>Knowledge sources</span></div>
                    <a class="btn ghost" href="{{ route('knowledge.index') }}">Manage knowledge →</a>
                </div>

                <div class="commerce-setup" id="commerce-connection">
                    <div class="commerce-setup__heading">
                        <div>
                            <span class="eyebrow">{{ in_array($catalogConnectionState, ['live', 'attention'], true) ? 'Live product catalog' : 'Product catalog' }}</span>
                            @if($catalogConnectionState === 'available')
                                <h3>Your catalog is already available</h3>
                                <p>Legatus is using your {{ number_format($productCount) }} active {{ \Illuminate\Support\Str::plural('product', $productCount) }}. A separate Live API connection is only needed for direct, real-time price and inventory synchronization.</p>
                            @else
                                <h3>Connect your online store</h3>
                                <p>Legatus securely checks prices, inventory and catalog data directly from your store. The connection is saved only after successful verification.</p>
                            @endif
                        </div>
                        <span data-catalog-status="{{ $catalogConnectionState }}" class="status-badge {{ in_array($catalogConnectionState, ['live', 'available'], true) ? 'status-badge--connected' : '' }}">
                            @if($catalogConnectionState === 'live')
                                ✓ Live API connected
                            @elseif($catalogConnectionState === 'attention')
                                Needs attention
                            @elseif($catalogConnectionState === 'available')
                                ✓ Catalog available
                            @else
                                Catalog not added
                            @endif
                        </span>
                    </div>

                    @if($commerceConnection)
                        <div class="commerce-status" data-commerce-status="{{ $commerceConnection->status }}">
                            <div>
                                <span>Source</span>
                                <b>{{ $commerceConnection->name ?: 'Connected store' }}</b>
                                <small>{{ parse_url($commerceConnection->base_url, PHP_URL_HOST) }}</small>
                            </div>
                            <div>
                                <span>Active products</span>
                                <b>{{ number_format($commerceProductCount) }}</b>
                                <small>From the live catalog</small>
                            </div>
                            <div>
                                <span>Last synchronization</span>
                                <b>{{ $commerceConnection->last_sync_at?->diffForHumans() ?? 'Not completed yet' }}</b>
                                <small>{{ $commerceConnection->status === 'error' ? 'The last attempt encountered a problem' : 'Updates every hour' }}</small>
                            </div>
                        </div>

                        @if($canManageChannels)
                            <div class="commerce-actions">
                                <form method="POST" action="{{ route('channels.commerce.sync') }}">
                                    @csrf
                                    <button class="btn lime" type="submit">Sync now</button>
                                </form>
                                <details>
                                    <summary>Change connection</summary>
                                    <p>New data is verified first; a failed attempt will not replace the existing connection.</p>
                                </details>
                            </div>
                        @endif
                    @endif

                    @if($canManageChannels)
                        @unless($commerceConnection)
                            <div class="catalog-onboarding-choice">
                                @if($catalogConnectionState === 'available')
                                    <div><b>Active catalog</b><p>View or update products and their sources from the Knowledge page.</p></div>
                                    <a class="btn ghost" href="{{ route('knowledge.index') }}">Manage catalog →</a>
                                @else
                                    <div><b>Add a catalog without code</b><p>Provide your public website or product catalog URL.</p></div>
                                    <a class="btn ghost" href="{{ route('onboarding') }}#catalog-url">Add catalog →</a>
                                @endif
                            </div>
                        @endunless
                        <details class="developer-connector" @if($errors->commerce->any()) open @endif>
                            <summary><span>Advanced</span> Custom store / developer integration</summary>
                            @unless($commerceConnection?->status === 'active')
                                <div class="connector-guide">
                                    <b>This section is only for a custom store's technical integration</b>
                                    <p>Connector Key ID and Shared secret are not OpenAI or Facebook credentials — they are created by the store connector. For a Bukinistebi/Laravel connector, run this once in the server terminal:</p>
                                    <code>php artisan legatus:connector-setup --write</code>
                                    <p>Regular business users do not need to complete these fields. Protect the Shared secret like a password.</p>
                                </div>
                            @endunless
                            <form class="commerce-form" method="POST" action="{{ route('channels.commerce.connect') }}" autocomplete="off">
                            @csrf
                            <div class="field commerce-form__wide">
                                <label for="commerce-name">Source name <small>(optional)</small></label>
                                <input id="commerce-name" name="name" type="text" maxlength="120" placeholder="e.g. Bukinistebi live catalog">
                                @error('name', 'commerce')<small class="field-error">{{ $message }}</small>@enderror
                            </div>
                            <div class="field commerce-form__wide">
                                <label for="commerce-base-url">Store HTTPS address</label>
                                <input id="commerce-base-url" name="base_url" type="url" maxlength="500" required placeholder="https://your-store.example" inputmode="url">
                                <small>Enter only the main address, without a path.</small>
                                @error('base_url', 'commerce')<small class="field-error">{{ $message }}</small>@enderror
                            </div>
                            <div class="field">
                                <label for="commerce-key-id">Connector Key ID</label>
                                <input id="commerce-key-id" name="key_id" type="text" maxlength="120" required autocomplete="off" spellcheck="false">
                                @error('key_id', 'commerce')<small class="field-error">{{ $message }}</small>@enderror
                            </div>
                            <div class="field">
                                <label for="commerce-secret">Shared secret</label>
                                <input id="commerce-secret" name="secret" type="password" minlength="32" maxlength="512" required autocomplete="new-password" spellcheck="false">
                                <small>At least 32 characters. Legatus encrypts it and never displays it again.</small>
                                @error('secret', 'commerce')<small class="field-error">{{ $message }}</small>@enderror
                            </div>
                            <div class="commerce-form__footer">
                                <span>🔒 Data is saved only after the connection and catalog are successfully verified.</span>
                                <button class="btn dark" type="submit">{{ $commerceConnection ? 'Verify and change connection' : 'Verify and connect' }}</button>
                            </div>
                            </form>
                        </details>

                        @if($commerceConnection)
                            <div class="commerce-disconnect">
                                <details>
                                    <summary>Disconnect store</summary>
                                    <p>Products imported from this source will be disabled in chat immediately.</p>
                                    <form method="POST" action="{{ route('channels.commerce.disconnect') }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="link-button" type="submit">Disconnect store</button>
                                    </form>
                                </details>
                            </div>
                        @endif
                    @else
                        <p class="commerce-readonly">Only a business owner or admin can change the connection.</p>
                    @endif
                </div>
            </div>
        </section>

        <section class="setup-section" id="website-install" aria-labelledby="website-step-title">
            <div class="step-number">2</div>
            <div class="setup-content">
                <div class="section-heading">
                    <div>
                        <span class="eyebrow">Website widget</span>
                        <h2 id="website-step-title">Add one script to your website</h2>
                        <p>Add the code once before <code>&lt;/body&gt;</code>. Chat appears automatically on every page, and updates never require reinstalling the script.</p>
                    </div>
                    <span class="status-badge {{ $widgetEnabled ? 'status-badge--connected' : 'status-badge--off' }}">
                        {{ $widgetEnabled ? '● Website chat ON' : '○ Website chat OFF' }}
                    </span>
                </div>

                <div class="widget-master-control widget-master-control--{{ $widgetEnabled ? 'on' : 'off' }}" data-widget-status="{{ $widgetEnabled ? 'enabled' : 'disabled' }}">
                    <div>
                        <b>{{ $widgetEnabled ? 'Website chat is visible to customers' : 'Website chat is hidden and cannot answer customers' }}</b>
                        <small>
                            {{ $widgetEnabled
                                ? 'Turn it off instantly whenever the catalog or assistant needs attention.'
                                : 'Your existing script can remain on the website. Turn this back on when you are ready.' }}
                        </small>
                    </div>
                    @if($canManageChannels)
                        <form method="POST" action="{{ route('channels.widget.update') }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="enabled" value="{{ $widgetEnabled ? '0' : '1' }}">
                            <button
                                class="widget-power widget-power--{{ $widgetEnabled ? 'on' : 'off' }}"
                                type="submit"
                                role="switch"
                                aria-checked="{{ $widgetEnabled ? 'true' : 'false' }}"
                                aria-label="{{ $widgetEnabled ? 'Turn website chat off' : 'Turn website chat on' }}"
                            >
                                <span aria-hidden="true"></span>
                                <b>{{ $widgetEnabled ? 'Turn website chat OFF' : 'Turn website chat ON' }}</b>
                            </button>
                        </form>
                    @else
                        <span class="widget-readonly">Only an owner or admin can change this.</span>
                    @endif
                </div>

                <div class="snippet-box">
                    <code id="widget-snippet">{{ $snippet }}</code>
                    <button class="btn lime" id="copy-snippet" type="button" data-default-label="Copy script">Copy script</button>
                </div>
                <p class="copy-feedback" id="copy-feedback" aria-live="polite"></p>
                @if($widgetDomains->isNotEmpty())
                    <div class="trusted-domains">
                        <span>🔒 Securely allowed domain</span>
                        @foreach($widgetDomains as $domain)
                            <code>{{ $domain }}</code>
                        @endforeach
                    </div>
                @endif

                <div class="install-benefits">
                    <div><span>01</span><b>Conflict-free</b><small>The widget is isolated from your website design.</small></div>
                    <div><span>02</span><b>Mobile-ready</b><small>Chat automatically adapts to smaller screens.</small></div>
                    <div><span>03</span><b>One inbox</b><small>Website conversations are stored in the Legatus Inbox.</small></div>
                </div>
            </div>
        </section>

        <section class="setup-section" aria-labelledby="social-step-title">
            <div class="step-number">3</div>
            <div class="setup-content">
                <div class="section-heading">
                    <div>
                        <span class="eyebrow">Facebook + Instagram</span>
                        <h2 id="social-step-title">Connect social channels</h2>
                        <p>Sign in with Meta, choose a Page and approve access. Legatus handles the technical configuration for you.</p>
                    </div>
                    <span class="status-badge {{ $connectedMetaCount === 2 ? 'status-badge--connected' : '' }}">
                        {{ $connectedMetaCount }}/2 connected
                    </span>
                </div>

                @if($connectedMetaCount < 2 && $metaConnectUrl)
                    <div class="meta-quick-connect">
                        <div>
                            <span class="eyebrow">Recommended</span>
                            <b>Connect Facebook and Instagram together</b>
                            <p>Sign in securely with Meta, choose a Page you manage, and Legatus will also connect its linked Instagram Professional account.</p>
                        </div>
                        <a class="connect-button connect-button--meta" href="{{ $metaConnectUrl }}">
                            <span>∞</span>
                            Connect with Meta
                        </a>
                    </div>
                @endif

                <div class="social-grid">
                    @foreach($metaChannels as $channel)
                        <article class="social-card social-card--{{ $channel['provider'] }}" data-channel="{{ $channel['provider'] }}" data-status="{{ $channel['status'] }}">
                            <div class="social-card__top">
                                <div class="channel-logo channel-logo--{{ $channel['provider'] }}">{{ $channel['icon'] }}</div>
                                <div class="social-card__identity">
                                    <h3>{{ $channel['name'] }}</h3>
                                    <span class="connection-status connection-status--{{ $channel['status'] }}">
                                        <i></i>
                                        @switch($channel['status'])
                                            @case('connected') Connected @break
                                            @case('pending') Connection in progress @break
                                            @case('error') Connection needs attention @break
                                            @default Not connected
                                        @endswitch
                                    </span>
                                </div>
                            </div>

                            <p>{{ $channel['description'] }}</p>

                            @if($channel['connected'])
                                <div class="connected-account">
                                    <span>Connected account</span>
                                    <b>{{ $channel['account_name'] ?: 'Meta business account' }}</b>
                                    <small>
                                        @if($channel['last_webhook_at'])
                                            Last activity {{ $channel['last_webhook_at']->diffForHumans() }}
                                        @else
                                            Ready to receive the first message
                                        @endif
                                    </small>
                                </div>
                                <div class="channel-actions">
                                    <a class="btn ghost" href="{{ route('inbox.index') }}">Open Inbox</a>
                                    @if($channel['disconnect_url'])
                                        <form action="{{ $channel['disconnect_url'] }}" method="POST">
                                            @csrf
                                            @method('DELETE')
                                            <button class="link-button" type="submit">Disconnect</button>
                                        </form>
                                    @endif
                                </div>
                            @else
                                @if($channel['error'])
                                    <div class="connection-error" role="alert"><b>Connection problem</b><span>{{ $channel['error'] }}</span></div>
                                @endif

                                @if($channel['connect_url'])
                                    <a class="connect-button connect-button--{{ $channel['provider'] }}" href="{{ $channel['connect_url'] }}">
                                        <span>{{ $channel['icon'] }}</span>
                                        {{ $channel['status'] === 'error' ? 'Reconnect' : 'Connect '.$channel['short_name'] }}
                                    </a>
                                @else
                                    <button class="connect-button" type="button" disabled>Connector setup required</button>
                                    <small class="connector-note">The Meta connector is not configured in this environment yet.</small>
                                @endif
                            @endif
                        </article>
                    @endforeach
                </div>

                <div class="meta-reassurance">
                    <span>🔒</span>
                    <p><b>Secure connection</b> — authorization happens on Meta's official page. Legatus never sees your password.</p>
                </div>
            </div>
        </section>
    </main>
</div>

<style>
.channels-main{max-width:1240px;width:100%;margin:0 auto}.channels-heading p,.section-heading p{color:var(--muted);line-height:1.65;margin:8px 0 0;max-width:720px}.channels-heading{align-items:flex-start}.channel-alert{display:flex;gap:12px;align-items:flex-start;border-radius:15px;padding:14px 17px;margin-top:20px;border:1px solid}.channel-alert>span{width:24px;height:24px;border-radius:50%;display:grid;place-items:center;font-weight:800}.channel-alert b{font-size:13px}.channel-alert p{margin:2px 0 0;font-size:13px}.channel-alert--success{background:#eef9e9;border-color:#d6ebcb;color:#285f42}.channel-alert--success>span{background:#d7efca}.channel-alert--error{background:#fff4ee;border-color:#f0d8cc;color:#8a462f}.channel-alert--error>span{background:#f6ded2}.setup-progress{list-style:none;padding:0;margin:28px 0;display:grid;grid-template-columns:repeat(3,1fr);gap:1px;background:var(--line);border:1px solid var(--line);border-radius:17px;overflow:hidden}.setup-progress li{background:#fff;padding:16px 18px;display:flex;gap:11px;align-items:center}.setup-progress li>span,.step-number{width:32px;height:32px;flex:0 0 32px;border-radius:50%;background:#eef2ef;color:#53635d;display:grid;place-items:center;font:700 13px Manrope}.setup-progress li.is-ready>span{background:#e6f6cf;color:#3e6d30}.setup-progress b{display:block;font-size:13px}.setup-progress small{display:block;color:var(--muted);margin-top:2px}.setup-section{display:grid;grid-template-columns:42px minmax(0,1fr);gap:14px;margin:18px 0}.step-number{background:var(--green);color:var(--lime);margin-top:22px}.setup-content{background:white;border:1px solid var(--line);border-radius:20px;padding:24px}.section-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:20px}.section-heading h2{font-size:21px;margin:5px 0 0}.status-badge{white-space:nowrap;display:inline-flex;align-items:center;border:1px solid var(--line);border-radius:99px;padding:7px 10px;color:var(--muted);font-size:11px;font-weight:700;background:#fafbf9}.status-badge--connected{border-color:#d4e9c8;background:#ecf8e5;color:#3b704c}.status-badge--ready{border-color:#dce8a8;background:#f6ffd8;color:#51641f}.knowledge-summary{display:flex;align-items:center;gap:34px;padding:19px;margin-top:20px;background:#f7f9f6;border-radius:15px}.knowledge-summary div{min-width:115px}.knowledge-summary strong{display:block;font:700 22px Manrope}.knowledge-summary span{display:block;color:var(--muted);font-size:11px;margin-top:2px}.knowledge-summary .btn{margin-left:auto}.commerce-setup{border-top:1px solid var(--line);margin-top:24px;padding-top:24px}.commerce-setup__heading{display:flex;justify-content:space-between;align-items:flex-start;gap:18px}.commerce-setup__heading h3{font-size:17px;margin:5px 0}.commerce-setup__heading p{color:var(--muted);font-size:12px;line-height:1.55;max-width:720px;margin:7px 0}.commerce-status{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:18px}.commerce-status>div{background:#f4f8f5;border-radius:13px;padding:13px;min-width:0}.commerce-status span,.commerce-status small{display:block;color:var(--muted);font-size:10px}.commerce-status b{display:block;font-size:12px;margin:5px 0;overflow-wrap:anywhere}.commerce-actions{display:flex;align-items:center;gap:14px;margin-top:14px}.commerce-actions details{font-size:11px;color:var(--muted)}.commerce-actions summary,.commerce-disconnect summary{cursor:pointer;font-weight:700;color:#52665e}.commerce-actions details p,.commerce-disconnect p{font-size:10px;max-width:520px}.commerce-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px;margin-top:18px;padding:17px;border:1px solid var(--line);border-radius:15px;background:#fbfcfa}.commerce-form__wide,.commerce-form__footer{grid-column:1/-1}.commerce-form label{display:block;font-weight:700;font-size:11px;margin-bottom:6px}.commerce-form label small{font-weight:400;color:var(--muted)}.commerce-form input{width:100%;box-sizing:border-box;border:1px solid var(--line);border-radius:10px;background:#fff;padding:11px 12px;font:12px 'DM Sans';color:var(--ink)}.commerce-form input:focus{outline:2px solid #dff293;border-color:#a7c74b}.commerce-form .field>small{display:block;color:var(--muted);font-size:9px;line-height:1.45;margin-top:5px}.commerce-form .field-error{color:#a54935!important;font-weight:700}.commerce-form__footer{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-top:4px}.commerce-form__footer>span{color:var(--muted);font-size:10px;line-height:1.5;max-width:520px}.commerce-disconnect{border-top:1px dashed var(--line);padding-top:13px;margin-top:16px}.commerce-disconnect .link-button{padding-left:0}.commerce-readonly{background:#f4f6f4;color:var(--muted);padding:12px;border-radius:11px;font-size:11px}.snippet-box{display:flex;align-items:center;gap:14px;background:#122c24;color:#d9ff72;padding:13px 13px 13px 17px;border-radius:15px;margin-top:20px}.snippet-box code{display:block;flex:1;min-width:0;white-space:nowrap;overflow:auto;font-size:12px;padding:5px 0}.snippet-box .btn{white-space:nowrap}.copy-feedback{min-height:16px;margin:7px 0 0;color:#3a745c;font-size:11px}.trusted-domains{display:flex;align-items:center;gap:7px;flex-wrap:wrap;color:var(--muted);font-size:10px;margin:-2px 0 12px}.trusted-domains code{border:1px solid var(--line);background:#f6f8f5;color:#365448;border-radius:99px;padding:4px 8px}.install-benefits{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:13px}.install-benefits div{padding:15px;border:1px solid #edf0ed;border-radius:14px}.install-benefits span{display:block;color:#799087;font-size:10px;margin-bottom:10px}.install-benefits b{display:block;font-size:12px}.install-benefits small{display:block;color:var(--muted);line-height:1.5;margin-top:4px}.meta-quick-connect{display:flex;align-items:center;justify-content:space-between;gap:22px;margin-top:22px;padding:18px;border:1px solid #d8e7dc;border-radius:16px;background:#f4faf5}.meta-quick-connect b{display:block;font-size:14px;margin-top:5px}.meta-quick-connect p{color:var(--muted);font-size:11px;line-height:1.5;margin:5px 0 0;max-width:680px}.meta-quick-connect .connect-button{width:auto;min-width:210px;margin:0}.connect-button--meta{background:#182c27;color:#fff}.social-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-top:22px}.social-card{border:1px solid var(--line);border-radius:18px;padding:19px;display:flex;flex-direction:column;min-height:300px}.social-card__top{display:flex;align-items:center;gap:12px}.channel-logo{width:46px;height:46px;border-radius:14px;display:grid;place-items:center;font:800 21px Manrope}.channel-logo--facebook{background:#e9f0ff;color:#2863bd}.channel-logo--instagram{background:linear-gradient(145deg,#fff0d8,#f4e3f3);color:#9d3d75}.social-card__identity h3{margin:0 0 4px;font-size:15px}.connection-status{display:inline-flex;align-items:center;gap:6px;color:var(--muted);font-size:10px;font-weight:600}.connection-status i{width:7px;height:7px;border-radius:50%;background:#a4aea9}.connection-status--connected{color:#367052}.connection-status--connected i{background:#55a979;box-shadow:0 0 0 3px #e6f4eb}.connection-status--pending i{background:#c5942d}.connection-status--error{color:#a05037}.connection-status--error i{background:#d36e4d}.social-card>p{color:var(--muted);font-size:12px;line-height:1.55;margin:18px 0}.connected-account{background:#f4f8f5;border-radius:13px;padding:13px;margin-top:auto}.connected-account span,.connected-account small{display:block;color:var(--muted);font-size:10px}.connected-account b{display:block;font-size:13px;margin:4px 0}.channel-actions{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:12px}.channel-actions .btn{padding:10px 13px}.link-button{border:0;background:transparent;color:#8a4a39;font:600 11px 'DM Sans';cursor:pointer;padding:8px}.connection-error{display:flex;flex-direction:column;gap:3px;background:#fff4ee;color:#8a462f;padding:11px 12px;border-radius:11px;font-size:10px;margin:0 0 10px}.connect-button{width:100%;margin-top:auto;border:0;border-radius:12px;padding:12px 14px;display:flex;align-items:center;justify-content:center;gap:9px;font:700 12px 'DM Sans';background:#e9edeb;color:#53615c;cursor:pointer}.connect-button--facebook{background:#2469ce;color:white}.connect-button--instagram{background:linear-gradient(100deg,#8055b8,#cd4d74,#ef8740);color:white}.connect-button:disabled{cursor:not-allowed;opacity:.72}.connector-note{display:block;color:var(--muted);text-align:center;margin-top:7px;font-size:9px}.meta-reassurance{display:flex;align-items:center;gap:12px;margin-top:16px;padding:13px 15px;border-radius:13px;background:#f7f9f6}.meta-reassurance p{margin:0;color:var(--muted);font-size:11px;line-height:1.5}.meta-reassurance b{color:var(--ink)}
.status-badge--off{border-color:#f0d2c5;background:#fff4ee;color:#934a35}.widget-master-control{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-top:20px;padding:16px;border:1px solid #d6e8d9;border-radius:15px;background:#f0f9f1}.widget-master-control--off{border-color:#efd6cb;background:#fff6f2}.widget-master-control>div{min-width:0}.widget-master-control b,.widget-master-control small{display:block}.widget-master-control>div>b{font-size:13px}.widget-master-control small{color:var(--muted);font-size:10px;line-height:1.5;margin-top:4px}.widget-power{display:flex;align-items:center;gap:9px;border:0;border-radius:99px;padding:7px 12px 7px 7px;white-space:nowrap;cursor:pointer;font:700 11px 'DM Sans'}.widget-power>span{position:relative;width:38px;height:22px;border-radius:99px;background:#ffffffb8}.widget-power>span:after{content:'';position:absolute;top:3px;width:16px;height:16px;border-radius:50%;background:currentColor;transition:left .15s}.widget-power--on{background:#2f7352;color:white}.widget-power--on>span:after{left:19px}.widget-power--off{background:#8c4938;color:white}.widget-power--off>span:after{left:3px}.widget-readonly{color:var(--muted);font-size:10px}
.catalog-onboarding-choice{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-top:18px;padding:15px 17px;border:1px solid var(--line);border-radius:14px;background:#fbfcfa}.catalog-onboarding-choice b{font-size:12px}.catalog-onboarding-choice p{margin:4px 0 0;color:var(--muted);font-size:10px}.developer-connector{margin-top:12px;border:1px dashed #cfd9d3;border-radius:13px;padding:12px 15px}.developer-connector>summary{cursor:pointer;color:#52665e;font-size:11px;font-weight:700}.developer-connector>summary span{display:inline-flex;margin-right:7px;padding:3px 7px;border-radius:99px;background:#eef2ef;font-size:9px;text-transform:uppercase}.connector-guide{margin-top:14px;padding:15px 17px;border:1px solid #dbe8d4;border-radius:14px;background:#f5faef}.connector-guide b{font-size:12px}.connector-guide p{margin:6px 0;color:var(--muted);font-size:10px;line-height:1.5}.connector-guide code{display:block;padding:10px 12px;border-radius:9px;background:#163f33;color:#d9ff72;font-size:11px;overflow:auto}
@media(max-width:900px){.setup-progress{grid-template-columns:1fr}.section-heading,.commerce-setup__heading{flex-direction:column}.social-grid,.install-benefits,.commerce-status{grid-template-columns:1fr}.knowledge-summary{align-items:flex-start;flex-wrap:wrap}.knowledge-summary .btn{margin-left:0}.channels-heading{gap:18px}.channels-heading .btn{white-space:nowrap}}
@media(max-width:600px){.channels-heading{display:block}.channels-heading .btn{margin-top:16px}.setup-section{grid-template-columns:1fr}.step-number{margin:0}.setup-content{padding:18px}.widget-master-control{align-items:stretch;flex-direction:column}.widget-power{width:100%;justify-content:center}.snippet-box{display:block}.snippet-box .btn{width:100%;margin-top:12px}.knowledge-summary{gap:18px}.social-card{min-height:280px}.commerce-form{grid-template-columns:1fr}.commerce-form__wide,.commerce-form__footer{grid-column:1}.commerce-form__footer,.commerce-actions{align-items:stretch;flex-direction:column}.commerce-form__footer .btn{width:100%}}
</style>

<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(() => {
    const button = document.querySelector('#copy-snippet');
    const snippet = document.querySelector('#widget-snippet');
    const feedback = document.querySelector('#copy-feedback');
    if (!button || !snippet || !feedback) return;

    button.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(snippet.textContent.trim());
            button.textContent = 'Copied ✓';
            feedback.textContent = 'Script copied — paste it before your website\'s </body> tag.';
        } catch (error) {
            const selection = window.getSelection();
            const range = document.createRange();
            range.selectNodeContents(snippet);
            selection.removeAllRanges();
            selection.addRange(range);
            feedback.textContent = 'Code selected — press Ctrl+C or ⌘+C.';
        }

        window.setTimeout(() => {
            button.textContent = button.dataset.defaultLabel;
        }, 2400);
    });
})();
</script>
@endsection
