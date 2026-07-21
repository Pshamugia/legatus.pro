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
                <h1>გაუშვით Legatus 3 ნაბიჯში</h1>
                <p>პროგრამირება არ გჭირდებათ — დააკოპირეთ ერთი script და დააკავშირეთ თქვენი სოციალური ანგარიშები.</p>
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
                <div><b>მზადაა</b><p>{{ session('success') ?? session('channel_success') ?? session('commerce_success') ?? session('status') }}</p></div>
            </div>
        @endif
        @foreach((array) session('warnings', []) as $warning)
            <div class="channel-alert channel-alert--error" role="alert">
                <span>!</span>
                <div><b>წყაროს შემოწმება სჭირდება · Source needs attention</b><p>{{ $warning }}</p></div>
            </div>
        @endforeach
        @if(session('error') || session('channel_error') || session('commerce_error'))
            <div class="channel-alert channel-alert--error" role="alert">
                <span>!</span>
                <div><b>დაკავშირება ვერ დასრულდა</b><p>{{ session('error') ?? session('channel_error') ?? session('commerce_error') }}</p></div>
            </div>
        @endif

        <ol class="setup-progress" aria-label="დაყენების სამი ნაბიჯი">
            <li class="{{ $productCount > 0 || $knowledgeSourceCount > 0 ? 'is-ready' : '' }}">
                <span>1</span>
                <div><b>ასწავლეთ</b><small>კატალოგი და წესები</small></div>
            </li>
            <li>
                <span>2</span>
                <div><b>დაამატეთ საიტზე</b><small>ერთი script</small></div>
            </li>
            <li class="{{ $connectedMetaCount > 0 ? 'is-ready' : '' }}">
                <span>3</span>
                <div><b>დააკავშირეთ Meta</b><small>Facebook და Instagram</small></div>
            </li>
        </ol>

        <section class="setup-section" aria-labelledby="knowledge-step-title">
            <div class="step-number">1</div>
            <div class="setup-content">
                <div class="section-heading">
                    <div>
                        <span class="eyebrow">Knowledge</span>
                        <h2 id="knowledge-step-title">მიეცით Legatus-ს თქვენი ბიზნესის ცოდნა</h2>
                        <p>ატვირთეთ კატალოგი ან მიუთითეთ საიტი. პასუხები დაეფუძნება მხოლოდ თქვენს ფასებს, მარაგსა და წესებს.</p>
                    </div>
                    @if($productCount > 0 || $knowledgeSourceCount > 0)
                        <span class="status-badge status-badge--connected">✓ მზადაა</span>
                    @else
                        <span class="status-badge">გასაკეთებელია</span>
                    @endif
                </div>
                <div class="knowledge-summary">
                    <div><strong>{{ number_format($productCount) }}</strong><span>აქტიური პროდუქტი</span></div>
                    <div><strong>{{ number_format($knowledgeSourceCount) }}</strong><span>ცოდნის წყარო</span></div>
                    <a class="btn ghost" href="{{ route('knowledge.index') }}">ცოდნის მართვა →</a>
                </div>

                <div class="commerce-setup" id="commerce-connection">
                    <div class="commerce-setup__heading">
                        <div>
                            <span class="eyebrow">Live product catalog</span>
                            <h3>დააკავშირეთ თქვენი ონლაინ მაღაზია</h3>
                            <p>Legatus უსაფრთხოდ ამოწმებს ფასს, მარაგსა და კატალოგს პირდაპირ თქვენს მაღაზიაში. კავშირი მხოლოდ წარმატებული შემოწმების შემდეგ შეინახება.</p>
                        </div>
                        <span class="status-badge {{ $commerceConnection?->status === 'active' ? 'status-badge--connected' : '' }}">
                            @if($commerceConnection?->status === 'active')
                                ✓ დაკავშირებულია
                            @elseif($commerceConnection)
                                ყურადღება სჭირდება
                            @else
                                არ არის დაკავშირებული
                            @endif
                        </span>
                    </div>

                    @if($commerceConnection)
                        <div class="commerce-status" data-commerce-status="{{ $commerceConnection->status }}">
                            <div>
                                <span>წყარო</span>
                                <b>{{ $commerceConnection->name ?: 'Connected store' }}</b>
                                <small>{{ parse_url($commerceConnection->base_url, PHP_URL_HOST) }}</small>
                            </div>
                            <div>
                                <span>აქტიური პროდუქტები</span>
                                <b>{{ number_format($commerceProductCount) }}</b>
                                <small>Live catalog-იდან</small>
                            </div>
                            <div>
                                <span>ბოლო სინქრონიზაცია</span>
                                <b>{{ $commerceConnection->last_sync_at?->diffForHumans() ?? 'ჯერ არ დასრულებულა' }}</b>
                                <small>{{ $commerceConnection->status === 'error' ? 'ბოლო ცდას პრობლემა ჰქონდა' : 'ყოველ საათში ახლდება' }}</small>
                            </div>
                        </div>

                        @if($canManageChannels)
                            <div class="commerce-actions">
                                <form method="POST" action="{{ route('channels.commerce.sync') }}">
                                    @csrf
                                    <button class="btn lime" type="submit">ახლავე სინქრონიზაცია</button>
                                </form>
                                <details>
                                    <summary>კავშირის შეცვლა</summary>
                                    <p>ახალი მონაცემები ჯერ შემოწმდება; წარუმატებელი ცდა არსებულ კავშირს არ შეცვლის.</p>
                                </details>
                            </div>
                        @endif
                    @endif

                    @if($canManageChannels)
                        <form class="commerce-form" method="POST" action="{{ route('channels.commerce.connect') }}" autocomplete="off">
                            @csrf
                            <div class="field commerce-form__wide">
                                <label for="commerce-name">წყაროს სახელი <small>(არასავალდებულო)</small></label>
                                <input id="commerce-name" name="name" type="text" maxlength="120" placeholder="მაგ. Bukinistebi live catalog">
                                @error('name', 'commerce')<small class="field-error">{{ $message }}</small>@enderror
                            </div>
                            <div class="field commerce-form__wide">
                                <label for="commerce-base-url">მაღაზიის HTTPS მისამართი</label>
                                <input id="commerce-base-url" name="base_url" type="url" maxlength="500" required placeholder="https://your-store.example" inputmode="url">
                                <small>ჩაწერეთ მხოლოდ მთავარი მისამართი — ბილიკის გარეშე.</small>
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
                                <small>მინიმუმ 32 სიმბოლო. Legatus მას შიფრავს და აღარასოდეს აჩვენებს.</small>
                                @error('secret', 'commerce')<small class="field-error">{{ $message }}</small>@enderror
                            </div>
                            <div class="commerce-form__footer">
                                <span>🔒 მონაცემები შეინახება მხოლოდ კავშირის და კატალოგის წარმატებული შემოწმების შემდეგ.</span>
                                <button class="btn dark" type="submit">{{ $commerceConnection ? 'შემოწმება და კავშირის შეცვლა' : 'შემოწმება და დაკავშირება' }}</button>
                            </div>
                        </form>

                        @if($commerceConnection)
                            <div class="commerce-disconnect">
                                <details>
                                    <summary>მაღაზიის გათიშვა</summary>
                                    <p>გათიშვისას ამ წყაროდან იმპორტირებული პროდუქტები ჩატში დაუყოვნებლივ გაითიშება.</p>
                                    <form method="POST" action="{{ route('channels.commerce.disconnect') }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="link-button" type="submit">გათიშეთ მაღაზია</button>
                                    </form>
                                </details>
                            </div>
                        @endif
                    @else
                        <p class="commerce-readonly">კავშირის შეცვლა მხოლოდ ბიზნესის owner-ს ან admin-ს შეუძლია.</p>
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
                        <h2 id="website-step-title">ჩასვით ერთი script თქვენს საიტზე</h2>
                        <p>ერთხელ ჩასვით კოდი <code>&lt;/body&gt;</code>-ს წინ. ჩატი ყველა გვერდზე ავტომატურად გამოჩნდება და განახლებები ხელახლა ჩასმას აღარ მოითხოვს.</p>
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
                        <span>🔒 უსაფრთხოდ დაშვებული დომენი</span>
                        @foreach($widgetDomains as $domain)
                            <code>{{ $domain }}</code>
                        @endforeach
                    </div>
                @endif

                <div class="install-benefits">
                    <div><span>01</span><b>კონფლიქტის გარეშე</b><small>Widget იზოლირებულია თქვენი საიტის დიზაინისგან.</small></div>
                    <div><span>02</span><b>მობილურზე მზადაა</b><small>ჩატი ავტომატურად ერგება პატარა ეკრანს.</small></div>
                    <div><span>03</span><b>ერთიანი inbox</b><small>ვებ-საუბრები Legatus-ის Inbox-ში ინახება.</small></div>
                </div>
            </div>
        </section>

        <section class="setup-section" aria-labelledby="social-step-title">
            <div class="step-number">3</div>
            <div class="setup-content">
                <div class="section-heading">
                    <div>
                        <span class="eyebrow">Facebook + Instagram</span>
                        <h2 id="social-step-title">დააკავშირეთ სოციალური არხები</h2>
                        <p>შედით Meta ანგარიშით, აირჩიეთ გვერდი და დაადასტურეთ წვდომა. ტექნიკურ პარამეტრებს Legatus თავად მართავს.</p>
                    </div>
                    <span class="status-badge {{ $connectedMetaCount === 2 ? 'status-badge--connected' : '' }}">
                        {{ $connectedMetaCount }}/2 დაკავშირებული
                    </span>
                </div>

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
                                            @case('connected') დაკავშირებულია @break
                                            @case('pending') დაკავშირება სრულდება @break
                                            @case('error') კავშირს ყურადღება სჭირდება @break
                                            @default არ არის დაკავშირებული
                                        @endswitch
                                    </span>
                                </div>
                            </div>

                            <p>{{ $channel['description'] }}</p>

                            @if($channel['connected'])
                                <div class="connected-account">
                                    <span>დაკავშირებული ანგარიში</span>
                                    <b>{{ $channel['account_name'] ?: 'Meta business account' }}</b>
                                    <small>
                                        @if($channel['last_webhook_at'])
                                            ბოლო აქტივობა {{ $channel['last_webhook_at']->diffForHumans() }}
                                        @else
                                            მზადაა პირველი შეტყობინების მისაღებად
                                        @endif
                                    </small>
                                </div>
                                <div class="channel-actions">
                                    <a class="btn ghost" href="{{ route('inbox.index') }}">Inbox-ის გახსნა</a>
                                    @if($channel['disconnect_url'])
                                        <form action="{{ $channel['disconnect_url'] }}" method="POST">
                                            @csrf
                                            @method('DELETE')
                                            <button class="link-button" type="submit">გათიშვა</button>
                                        </form>
                                    @endif
                                </div>
                            @else
                                @if($channel['error'])
                                    <div class="connection-error" role="alert"><b>დაკავშირების პრობლემა</b><span>{{ $channel['error'] }}</span></div>
                                @endif

                                @if($channel['connect_url'])
                                    <a class="connect-button connect-button--{{ $channel['provider'] }}" href="{{ $channel['connect_url'] }}">
                                        <span>{{ $channel['icon'] }}</span>
                                        {{ $channel['status'] === 'error' ? 'ხელახლა დაკავშირება' : 'Connect '.$channel['short_name'] }}
                                    </a>
                                @else
                                    <button class="connect-button" type="button" disabled>Connector setup required</button>
                                    <small class="connector-note">ამ გარემოში Meta connector ჯერ არ არის კონფიგურირებული.</small>
                                @endif
                            @endif
                        </article>
                    @endforeach
                </div>

                <div class="meta-reassurance">
                    <span>🔒</span>
                    <p><b>უსაფრთხო დაკავშირება</b> — ავტორიზაცია ხდება Meta-ს ოფიციალურ გვერდზე. Legatus თქვენს პაროლს არასდროს ხედავს.</p>
                </div>
            </div>
        </section>
    </main>
</div>

<style>
.channels-main{max-width:1240px;width:100%;margin:0 auto}.channels-heading p,.section-heading p{color:var(--muted);line-height:1.65;margin:8px 0 0;max-width:720px}.channels-heading{align-items:flex-start}.channel-alert{display:flex;gap:12px;align-items:flex-start;border-radius:15px;padding:14px 17px;margin-top:20px;border:1px solid}.channel-alert>span{width:24px;height:24px;border-radius:50%;display:grid;place-items:center;font-weight:800}.channel-alert b{font-size:13px}.channel-alert p{margin:2px 0 0;font-size:13px}.channel-alert--success{background:#eef9e9;border-color:#d6ebcb;color:#285f42}.channel-alert--success>span{background:#d7efca}.channel-alert--error{background:#fff4ee;border-color:#f0d8cc;color:#8a462f}.channel-alert--error>span{background:#f6ded2}.setup-progress{list-style:none;padding:0;margin:28px 0;display:grid;grid-template-columns:repeat(3,1fr);gap:1px;background:var(--line);border:1px solid var(--line);border-radius:17px;overflow:hidden}.setup-progress li{background:#fff;padding:16px 18px;display:flex;gap:11px;align-items:center}.setup-progress li>span,.step-number{width:32px;height:32px;flex:0 0 32px;border-radius:50%;background:#eef2ef;color:#53635d;display:grid;place-items:center;font:700 13px Manrope}.setup-progress li.is-ready>span{background:#e6f6cf;color:#3e6d30}.setup-progress b{display:block;font-size:13px}.setup-progress small{display:block;color:var(--muted);margin-top:2px}.setup-section{display:grid;grid-template-columns:42px minmax(0,1fr);gap:14px;margin:18px 0}.step-number{background:var(--green);color:var(--lime);margin-top:22px}.setup-content{background:white;border:1px solid var(--line);border-radius:20px;padding:24px}.section-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:20px}.section-heading h2{font-size:21px;margin:5px 0 0}.status-badge{white-space:nowrap;display:inline-flex;align-items:center;border:1px solid var(--line);border-radius:99px;padding:7px 10px;color:var(--muted);font-size:11px;font-weight:700;background:#fafbf9}.status-badge--connected{border-color:#d4e9c8;background:#ecf8e5;color:#3b704c}.status-badge--ready{border-color:#dce8a8;background:#f6ffd8;color:#51641f}.knowledge-summary{display:flex;align-items:center;gap:34px;padding:19px;margin-top:20px;background:#f7f9f6;border-radius:15px}.knowledge-summary div{min-width:115px}.knowledge-summary strong{display:block;font:700 22px Manrope}.knowledge-summary span{display:block;color:var(--muted);font-size:11px;margin-top:2px}.knowledge-summary .btn{margin-left:auto}.commerce-setup{border-top:1px solid var(--line);margin-top:24px;padding-top:24px}.commerce-setup__heading{display:flex;justify-content:space-between;align-items:flex-start;gap:18px}.commerce-setup__heading h3{font-size:17px;margin:5px 0}.commerce-setup__heading p{color:var(--muted);font-size:12px;line-height:1.55;max-width:720px;margin:7px 0}.commerce-status{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:18px}.commerce-status>div{background:#f4f8f5;border-radius:13px;padding:13px;min-width:0}.commerce-status span,.commerce-status small{display:block;color:var(--muted);font-size:10px}.commerce-status b{display:block;font-size:12px;margin:5px 0;overflow-wrap:anywhere}.commerce-actions{display:flex;align-items:center;gap:14px;margin-top:14px}.commerce-actions details{font-size:11px;color:var(--muted)}.commerce-actions summary,.commerce-disconnect summary{cursor:pointer;font-weight:700;color:#52665e}.commerce-actions details p,.commerce-disconnect p{font-size:10px;max-width:520px}.commerce-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px;margin-top:18px;padding:17px;border:1px solid var(--line);border-radius:15px;background:#fbfcfa}.commerce-form__wide,.commerce-form__footer{grid-column:1/-1}.commerce-form label{display:block;font-weight:700;font-size:11px;margin-bottom:6px}.commerce-form label small{font-weight:400;color:var(--muted)}.commerce-form input{width:100%;box-sizing:border-box;border:1px solid var(--line);border-radius:10px;background:#fff;padding:11px 12px;font:12px 'DM Sans';color:var(--ink)}.commerce-form input:focus{outline:2px solid #dff293;border-color:#a7c74b}.commerce-form .field>small{display:block;color:var(--muted);font-size:9px;line-height:1.45;margin-top:5px}.commerce-form .field-error{color:#a54935!important;font-weight:700}.commerce-form__footer{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-top:4px}.commerce-form__footer>span{color:var(--muted);font-size:10px;line-height:1.5;max-width:520px}.commerce-disconnect{border-top:1px dashed var(--line);padding-top:13px;margin-top:16px}.commerce-disconnect .link-button{padding-left:0}.commerce-readonly{background:#f4f6f4;color:var(--muted);padding:12px;border-radius:11px;font-size:11px}.snippet-box{display:flex;align-items:center;gap:14px;background:#122c24;color:#d9ff72;padding:13px 13px 13px 17px;border-radius:15px;margin-top:20px}.snippet-box code{display:block;flex:1;min-width:0;white-space:nowrap;overflow:auto;font-size:12px;padding:5px 0}.snippet-box .btn{white-space:nowrap}.copy-feedback{min-height:16px;margin:7px 0 0;color:#3a745c;font-size:11px}.trusted-domains{display:flex;align-items:center;gap:7px;flex-wrap:wrap;color:var(--muted);font-size:10px;margin:-2px 0 12px}.trusted-domains code{border:1px solid var(--line);background:#f6f8f5;color:#365448;border-radius:99px;padding:4px 8px}.install-benefits{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:13px}.install-benefits div{padding:15px;border:1px solid #edf0ed;border-radius:14px}.install-benefits span{display:block;color:#799087;font-size:10px;margin-bottom:10px}.install-benefits b{display:block;font-size:12px}.install-benefits small{display:block;color:var(--muted);line-height:1.5;margin-top:4px}.social-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-top:22px}.social-card{border:1px solid var(--line);border-radius:18px;padding:19px;display:flex;flex-direction:column;min-height:300px}.social-card__top{display:flex;align-items:center;gap:12px}.channel-logo{width:46px;height:46px;border-radius:14px;display:grid;place-items:center;font:800 21px Manrope}.channel-logo--facebook{background:#e9f0ff;color:#2863bd}.channel-logo--instagram{background:linear-gradient(145deg,#fff0d8,#f4e3f3);color:#9d3d75}.social-card__identity h3{margin:0 0 4px;font-size:15px}.connection-status{display:inline-flex;align-items:center;gap:6px;color:var(--muted);font-size:10px;font-weight:600}.connection-status i{width:7px;height:7px;border-radius:50%;background:#a4aea9}.connection-status--connected{color:#367052}.connection-status--connected i{background:#55a979;box-shadow:0 0 0 3px #e6f4eb}.connection-status--pending i{background:#c5942d}.connection-status--error{color:#a05037}.connection-status--error i{background:#d36e4d}.social-card>p{color:var(--muted);font-size:12px;line-height:1.55;margin:18px 0}.connected-account{background:#f4f8f5;border-radius:13px;padding:13px;margin-top:auto}.connected-account span,.connected-account small{display:block;color:var(--muted);font-size:10px}.connected-account b{display:block;font-size:13px;margin:4px 0}.channel-actions{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:12px}.channel-actions .btn{padding:10px 13px}.link-button{border:0;background:transparent;color:#8a4a39;font:600 11px 'DM Sans';cursor:pointer;padding:8px}.connection-error{display:flex;flex-direction:column;gap:3px;background:#fff4ee;color:#8a462f;padding:11px 12px;border-radius:11px;font-size:10px;margin:0 0 10px}.connect-button{width:100%;margin-top:auto;border:0;border-radius:12px;padding:12px 14px;display:flex;align-items:center;justify-content:center;gap:9px;font:700 12px 'DM Sans';background:#e9edeb;color:#53615c;cursor:pointer}.connect-button--facebook{background:#2469ce;color:white}.connect-button--instagram{background:linear-gradient(100deg,#8055b8,#cd4d74,#ef8740);color:white}.connect-button:disabled{cursor:not-allowed;opacity:.72}.connector-note{display:block;color:var(--muted);text-align:center;margin-top:7px;font-size:9px}.meta-reassurance{display:flex;align-items:center;gap:12px;margin-top:16px;padding:13px 15px;border-radius:13px;background:#f7f9f6}.meta-reassurance p{margin:0;color:var(--muted);font-size:11px;line-height:1.5}.meta-reassurance b{color:var(--ink)}
.status-badge--off{border-color:#f0d2c5;background:#fff4ee;color:#934a35}.widget-master-control{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-top:20px;padding:16px;border:1px solid #d6e8d9;border-radius:15px;background:#f0f9f1}.widget-master-control--off{border-color:#efd6cb;background:#fff6f2}.widget-master-control>div{min-width:0}.widget-master-control b,.widget-master-control small{display:block}.widget-master-control>div>b{font-size:13px}.widget-master-control small{color:var(--muted);font-size:10px;line-height:1.5;margin-top:4px}.widget-power{display:flex;align-items:center;gap:9px;border:0;border-radius:99px;padding:7px 12px 7px 7px;white-space:nowrap;cursor:pointer;font:700 11px 'DM Sans'}.widget-power>span{position:relative;width:38px;height:22px;border-radius:99px;background:#ffffffb8}.widget-power>span:after{content:'';position:absolute;top:3px;width:16px;height:16px;border-radius:50%;background:currentColor;transition:left .15s}.widget-power--on{background:#2f7352;color:white}.widget-power--on>span:after{left:19px}.widget-power--off{background:#8c4938;color:white}.widget-power--off>span:after{left:3px}.widget-readonly{color:var(--muted);font-size:10px}
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
            feedback.textContent = 'Script დაკოპირებულია — ჩასვით თქვენი საიტის </body> ტეგამდე.';
        } catch (error) {
            const selection = window.getSelection();
            const range = document.createRange();
            range.selectNodeContents(snippet);
            selection.removeAllRanges();
            selection.addRange(range);
            feedback.textContent = 'კოდი მონიშნულია — დააჭირეთ Ctrl+C ან ⌘+C.';
        }

        window.setTimeout(() => {
            button.textContent = button.dataset.defaultLabel;
        }, 2400);
    });
})();
</script>
@endsection
