@extends('layouts.app')

@section('title', 'Channels · Legatus')

@section('body')
@php($connectedMetaCount = $metaChannels->where('connected', true)->count())
<div class="dash-shell channels-page">
    <aside class="side">
        <a class="brand" href="{{ route('landing') }}"><span class="mark">L</span> Legatus</a>
        <div class="menu">
            <a href="{{ route('dashboard') }}">◫ &nbsp; Overview</a>
            <a href="{{ route('inbox.index') }}">◌ &nbsp; Inbox</a>
            <a href="{{ route('knowledge.index') }}">◇ &nbsp; Knowledge</a>
            <a href="{{ route('dashboard') }}#products">▦ &nbsp; Products</a>
            <a class="active" href="{{ route('channels.index') }}">⌁ &nbsp; Channels</a>
            <a href="{{ route('analytics.index') }}">↗ &nbsp; Analytics</a>
            <a href="{{ route('settings.index') }}">⚙ &nbsp; Settings</a>
        </div>
        <div class="side-bottom">
            <b style="color:white">ერთი AI თანამშრომელი</b><br>
            საიტი, Messenger და Instagram ერთ inbox-ში.
        </div>
    </aside>

    <main class="main channels-main">
        <div class="topline channels-heading">
            <div>
                <span class="eyebrow">Simple channel setup</span>
                <h1>გაუშვით Legatus 3 ნაბიჯში</h1>
                <p>პროგრამირება არ გჭირდებათ — დააკოპირეთ ერთი script და დააკავშირეთ თქვენი სოციალური ანგარიშები.</p>
            </div>
            <a class="btn ghost" href="{{ route('widget.frame', $agent) }}" target="_blank" rel="noopener">ჩატის ნახვა ↗</a>
        </div>

        @if(session('success') || session('channel_success') || session('status'))
            <div class="channel-alert channel-alert--success" role="status">
                <span>✓</span>
                <div><b>მზადაა</b><p>{{ session('success') ?? session('channel_success') ?? session('status') }}</p></div>
            </div>
        @endif
        @if(session('error') || session('channel_error'))
            <div class="channel-alert channel-alert--error" role="alert">
                <span>!</span>
                <div><b>დაკავშირება ვერ დასრულდა</b><p>{{ session('error') ?? session('channel_error') }}</p></div>
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
                    <span class="status-badge status-badge--ready">მზადაა დასაყენებლად</span>
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
.channels-main{max-width:1240px;width:100%;margin:0 auto}.channels-heading p,.section-heading p{color:var(--muted);line-height:1.65;margin:8px 0 0;max-width:720px}.channels-heading{align-items:flex-start}.channel-alert{display:flex;gap:12px;align-items:flex-start;border-radius:15px;padding:14px 17px;margin-top:20px;border:1px solid}.channel-alert>span{width:24px;height:24px;border-radius:50%;display:grid;place-items:center;font-weight:800}.channel-alert b{font-size:13px}.channel-alert p{margin:2px 0 0;font-size:13px}.channel-alert--success{background:#eef9e9;border-color:#d6ebcb;color:#285f42}.channel-alert--success>span{background:#d7efca}.channel-alert--error{background:#fff4ee;border-color:#f0d8cc;color:#8a462f}.channel-alert--error>span{background:#f6ded2}.setup-progress{list-style:none;padding:0;margin:28px 0;display:grid;grid-template-columns:repeat(3,1fr);gap:1px;background:var(--line);border:1px solid var(--line);border-radius:17px;overflow:hidden}.setup-progress li{background:#fff;padding:16px 18px;display:flex;gap:11px;align-items:center}.setup-progress li>span,.step-number{width:32px;height:32px;flex:0 0 32px;border-radius:50%;background:#eef2ef;color:#53635d;display:grid;place-items:center;font:700 13px Manrope}.setup-progress li.is-ready>span{background:#e6f6cf;color:#3e6d30}.setup-progress b{display:block;font-size:13px}.setup-progress small{display:block;color:var(--muted);margin-top:2px}.setup-section{display:grid;grid-template-columns:42px minmax(0,1fr);gap:14px;margin:18px 0}.step-number{background:var(--green);color:var(--lime);margin-top:22px}.setup-content{background:white;border:1px solid var(--line);border-radius:20px;padding:24px}.section-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:20px}.section-heading h2{font-size:21px;margin:5px 0 0}.status-badge{white-space:nowrap;display:inline-flex;align-items:center;border:1px solid var(--line);border-radius:99px;padding:7px 10px;color:var(--muted);font-size:11px;font-weight:700;background:#fafbf9}.status-badge--connected{border-color:#d4e9c8;background:#ecf8e5;color:#3b704c}.status-badge--ready{border-color:#dce8a8;background:#f6ffd8;color:#51641f}.knowledge-summary{display:flex;align-items:center;gap:34px;padding:19px;margin-top:20px;background:#f7f9f6;border-radius:15px}.knowledge-summary div{min-width:115px}.knowledge-summary strong{display:block;font:700 22px Manrope}.knowledge-summary span{display:block;color:var(--muted);font-size:11px;margin-top:2px}.knowledge-summary .btn{margin-left:auto}.snippet-box{display:flex;align-items:center;gap:14px;background:#122c24;color:#d9ff72;padding:13px 13px 13px 17px;border-radius:15px;margin-top:20px}.snippet-box code{display:block;flex:1;min-width:0;white-space:nowrap;overflow:auto;font-size:12px;padding:5px 0}.snippet-box .btn{white-space:nowrap}.copy-feedback{min-height:16px;margin:7px 0 0;color:#3a745c;font-size:11px}.trusted-domains{display:flex;align-items:center;gap:7px;flex-wrap:wrap;color:var(--muted);font-size:10px;margin:-2px 0 12px}.trusted-domains code{border:1px solid var(--line);background:#f6f8f5;color:#365448;border-radius:99px;padding:4px 8px}.install-benefits{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:13px}.install-benefits div{padding:15px;border:1px solid #edf0ed;border-radius:14px}.install-benefits span{display:block;color:#799087;font-size:10px;margin-bottom:10px}.install-benefits b{display:block;font-size:12px}.install-benefits small{display:block;color:var(--muted);line-height:1.5;margin-top:4px}.social-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-top:22px}.social-card{border:1px solid var(--line);border-radius:18px;padding:19px;display:flex;flex-direction:column;min-height:300px}.social-card__top{display:flex;align-items:center;gap:12px}.channel-logo{width:46px;height:46px;border-radius:14px;display:grid;place-items:center;font:800 21px Manrope}.channel-logo--facebook{background:#e9f0ff;color:#2863bd}.channel-logo--instagram{background:linear-gradient(145deg,#fff0d8,#f4e3f3);color:#9d3d75}.social-card__identity h3{margin:0 0 4px;font-size:15px}.connection-status{display:inline-flex;align-items:center;gap:6px;color:var(--muted);font-size:10px;font-weight:600}.connection-status i{width:7px;height:7px;border-radius:50%;background:#a4aea9}.connection-status--connected{color:#367052}.connection-status--connected i{background:#55a979;box-shadow:0 0 0 3px #e6f4eb}.connection-status--pending i{background:#c5942d}.connection-status--error{color:#a05037}.connection-status--error i{background:#d36e4d}.social-card>p{color:var(--muted);font-size:12px;line-height:1.55;margin:18px 0}.connected-account{background:#f4f8f5;border-radius:13px;padding:13px;margin-top:auto}.connected-account span,.connected-account small{display:block;color:var(--muted);font-size:10px}.connected-account b{display:block;font-size:13px;margin:4px 0}.channel-actions{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:12px}.channel-actions .btn{padding:10px 13px}.link-button{border:0;background:transparent;color:#8a4a39;font:600 11px 'DM Sans';cursor:pointer;padding:8px}.connection-error{display:flex;flex-direction:column;gap:3px;background:#fff4ee;color:#8a462f;padding:11px 12px;border-radius:11px;font-size:10px;margin:0 0 10px}.connect-button{width:100%;margin-top:auto;border:0;border-radius:12px;padding:12px 14px;display:flex;align-items:center;justify-content:center;gap:9px;font:700 12px 'DM Sans';background:#e9edeb;color:#53615c;cursor:pointer}.connect-button--facebook{background:#2469ce;color:white}.connect-button--instagram{background:linear-gradient(100deg,#8055b8,#cd4d74,#ef8740);color:white}.connect-button:disabled{cursor:not-allowed;opacity:.72}.connector-note{display:block;color:var(--muted);text-align:center;margin-top:7px;font-size:9px}.meta-reassurance{display:flex;align-items:center;gap:12px;margin-top:16px;padding:13px 15px;border-radius:13px;background:#f7f9f6}.meta-reassurance p{margin:0;color:var(--muted);font-size:11px;line-height:1.5}.meta-reassurance b{color:var(--ink)}
@media(max-width:900px){.setup-progress{grid-template-columns:1fr}.section-heading{flex-direction:column}.social-grid,.install-benefits{grid-template-columns:1fr}.knowledge-summary{align-items:flex-start;flex-wrap:wrap}.knowledge-summary .btn{margin-left:0}.channels-heading{gap:18px}.channels-heading .btn{white-space:nowrap}}
@media(max-width:600px){.channels-heading{display:block}.channels-heading .btn{margin-top:16px}.setup-section{grid-template-columns:1fr}.step-number{margin:0}.setup-content{padding:18px}.snippet-box{display:block}.snippet-box .btn{width:100%;margin-top:12px}.knowledge-summary{gap:18px}.social-card{min-height:280px}}
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
