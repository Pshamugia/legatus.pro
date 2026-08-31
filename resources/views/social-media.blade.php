@extends('layouts.app')
@section('title', 'Social media scheduler · Legatus')
@section('body')
@php
    $platforms = [
        'facebook' => ['label' => 'Facebook', 'account' => $connections->get('facebook')?->external_account_name ?: $agent->business_name, 'limit' => 6000],
        'instagram' => ['label' => 'Instagram', 'account' => $connections->get('instagram')?->external_account_name ?: $agent->business_name, 'limit' => 2200],
    ];
    $tokens = [
        '{product_title}' => 'Product title',
        '{product_description}' => 'Description',
        '{price}' => 'Price',
        '{category}' => 'Category',
        '{delivery}' => 'Delivery',
        '{business_name}' => 'Business name',
        '{product_url}' => 'Product URL',
    ];
@endphp
<div class="dash-shell">
    @include('partials.workspace-navigation', ['active' => 'social-media'])
    <main class="main social-main">
        <div class="topline">
            <div>
                <span class="eyebrow">AUTOMATED PUBLISHING</span>
                <h1>Social media scheduler</h1>
                <p>Design each channel separately, then publish verified public-site products automatically.</p>
            </div>
        </div>

        @if(session('social_success'))
            <div class="social-alert success">{{ session('social_success') }}</div>
        @endif
        @if($errors->any())
            <div class="social-alert error"><strong>Please check the highlighted settings:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        <div class="social-alert notice">
            <strong>Meta publishing access</strong><br>
            Connected app-role accounts can be tested now. Publishing for other businesses becomes public after Meta approves the requested permissions.
        </div>

        <section class="panel template-panel" aria-labelledby="template-heading">
            <div class="section-heading">
                <div><span class="step">01</span><div><h2 id="template-heading">Design post templates</h2><p>Facebook and Instagram keep independent text, emojis and delivery details.</p></div></div>
                <span class="status-pill">Saved per business</span>
            </div>

            <form method="post" action="{{ route('social-media.templates.update') }}" id="template-form">
                @csrf
                @method('PUT')
                <div class="template-actions-row">
                    <div class="platform-tabs" role="tablist" aria-label="Post template channel">
                        @foreach($platforms as $provider => $platform)
                            <button class="platform-tab {{ $loop->first ? 'active' : '' }}" type="button" role="tab" id="{{ $provider }}-tab" aria-controls="{{ $provider }}-panel" aria-selected="{{ $loop->first ? 'true' : 'false' }}" tabindex="{{ $loop->first ? '0' : '-1' }}" data-platform-tab="{{ $provider }}">
                                <span class="platform-mark {{ $provider }}">{{ $provider === 'facebook' ? 'f' : '◎' }}</span>
                                {{ $platform['label'] }}
                            </button>
                        @endforeach
                    </div>
                    <button class="copy-template" type="button" id="copy-facebook-template" @disabled(!$canManage)>Copy Facebook text to Instagram</button>
                </div>

                @foreach($platforms as $provider => $platform)
                    @php($configuration = $templates[$provider])
                    <div class="template-workspace" id="{{ $provider }}-panel" role="tabpanel" aria-labelledby="{{ $provider }}-tab" data-platform-panel="{{ $provider }}" @if(!$loop->first) hidden @endif>
                        <div class="template-editor">
                            <label for="{{ $provider }}-body"><strong>{{ $platform['label'] }} post text</strong><small>Edit every word and emoji. Product facts are inserted from the verified public catalog.</small></label>
                            <textarea id="{{ $provider }}-body" name="templates[{{ $provider }}][body_template]" rows="12" maxlength="{{ $provider === 'instagram' ? 1800 : 5000 }}" data-template-body="{{ $provider }}" @disabled(!$canManage)>{{ old("templates.{$provider}.body_template", $configuration['body_template']) }}</textarea>
                            <div class="token-tools" aria-label="Insert verified product field">
                                <span>Insert field:</span>
                                @foreach($tokens as $token => $label)
                                    <button type="button" data-insert-token="{{ $token }}" data-token-provider="{{ $provider }}" @disabled(!$canManage)>{{ $label }}</button>
                                @endforeach
                            </div>

                            <fieldset class="image-style-picker">
                                <legend>Product image design</legend>
                                <p class="field-help">Choose how product photos will be prepared for new {{ $platform['label'] }} schedules.</p>
                                <div class="image-style-grid">
                                    @foreach(['original' => 'Catalog design', 'raw' => 'Plain photo', 'framed' => 'Classic frame', 'editorial' => 'Editorial', 'dark' => 'Dark', 'brand' => 'Legatus green'] as $style => $label)
                                        <label class="image-style-option">
                                            <input type="radio" name="templates[{{ $provider }}][image_style]" value="{{ $style }}" data-image-style="{{ $provider }}" @checked(old("templates.{$provider}.image_style", $configuration['image_style']) === $style) @disabled(!$canManage)>
                                            <span class="style-swatch {{ $style }}">
                                                @if($previewProduct['image'])
                                                    <img src="{{ $previewProduct['style_images'][$style] ?: $previewProduct['image'] }}" alt="{{ $label }} preview">
                                                @else
                                                    <i></i>
                                                @endif
                                            </span><strong>{{ $label }}</strong>
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>

                            <div class="delivery-editor">
                                <input type="hidden" name="templates[{{ $provider }}][delivery_enabled]" value="0">
                                <label class="switch-row" for="{{ $provider }}-delivery-enabled">
                                    <span><strong>Add delivery details</strong><small>Turn this off to remove the whole delivery line from this channel.</small></span>
                                    <input id="{{ $provider }}-delivery-enabled" type="checkbox" name="templates[{{ $provider }}][delivery_enabled]" value="1" data-delivery-enabled="{{ $provider }}" role="switch" @checked((bool) old("templates.{$provider}.delivery_enabled", $configuration['delivery_enabled'])) @disabled(!$canManage)>
                                </label>
                                <label for="{{ $provider }}-delivery"><strong>Delivery wording</strong><small>This is business-authored text. Change the emoji in the template, add details here, or switch it off.</small></label>
                                <textarea id="{{ $provider }}-delivery" name="templates[{{ $provider }}][delivery_text]" rows="4" maxlength="600" data-delivery-text="{{ $provider }}" @disabled(!$canManage)>{{ old("templates.{$provider}.delivery_text", $configuration['delivery_text']) }}</textarea>
                            </div>
                        </div>

                        <aside class="template-preview" aria-label="{{ $platform['label'] }} post preview">
                            <div class="preview-label"><span>LIVE PREVIEW</span><span data-character-count="{{ $provider }}" aria-live="polite">0 / {{ $platform['limit'] }}</span></div>
                            <article class="social-preview {{ $provider }}">
                                <header>
                                    <span class="preview-avatar">{{ mb_strtoupper(mb_substr($previewProduct['business_name'], 0, 1)) }}</span>
                                    <div><strong>{{ $platform['account'] }}</strong><small>Sponsored by your schedule · 🌐</small></div>
                                </header>
                                @if($provider === 'instagram')
                                    <div class="preview-image square image-style-{{ $configuration['image_style'] }}" data-preview-image="{{ $provider }}">
                                        @if($previewProduct['image'])<img src="{{ $previewProduct['style_images'][$configuration['image_style']] ?: $previewProduct['image'] }}" alt="Preview of {{ $previewProduct['title'] }}">@else<span>Public product image</span>@endif
                                    </div>
                                    <p class="preview-copy" data-preview-copy="{{ $provider }}"></p>
                                    <small class="instagram-note">Instagram caption URLs appear as text and may not be clickable.</small>
                                @else
                                    <p class="preview-copy" data-preview-copy="{{ $provider }}"></p>
                                    <div class="preview-image wide image-style-{{ $configuration['image_style'] }}" data-preview-image="{{ $provider }}">
                                        @if($previewProduct['image'])<img src="{{ $previewProduct['style_images'][$configuration['image_style']] ?: $previewProduct['image'] }}" alt="Preview of {{ $previewProduct['title'] }}">@else<span>Link preview image</span>@endif
                                    </div>
                                    <a class="facebook-link-card" href="{{ $previewProduct['url'] }}" target="_blank" rel="noopener">
                                        <small data-preview-domain></small><strong>{{ $previewProduct['title'] }}</strong><span>View product</span>
                                    </a>
                                @endif
                            </article>
                        </aside>
                    </div>
                @endforeach

                <div class="template-save-row">
                    <p>Saving affects new schedules only. Already prepared posts keep their original template snapshot.</p>
                    <button class="btn lime" type="submit" @disabled(!$canManage)>Save both templates →</button>
                </div>
            </form>
        </section>

        <section class="social-layout">
            <form class="panel schedule-form" method="post" action="{{ route('social-media.store') }}">
                @csrf
                <div class="section-heading"><div><span class="step">02</span><div><h2>Create a schedule</h2><p>Every generated post uses the saved template for its channel.</p></div></div><span class="status-pill">Public catalog only</span></div>

                <div class="form-grid two">
                    <label>From date<input type="date" name="starts_on" min="{{ now()->toDateString() }}" value="{{ old('starts_on', now()->toDateString()) }}" required @disabled(!$canManage)></label>
                    <label>To date<input type="date" name="ends_on" min="{{ now()->toDateString() }}" value="{{ old('ends_on', now()->addMonth()->toDateString()) }}" required @disabled(!$canManage)></label>
                </div>
                <div class="form-grid two">
                    <label>Posts per day<input type="number" name="posts_per_day" min="1" max="24" value="{{ old('posts_per_day', 3) }}" required @disabled(!$canManage)><small>1–24 posts, distributed evenly from 09:00 to 21:00.</small></label>
                    <label>Business timezone<select name="timezone" @disabled(!$canManage)><option value="Asia/Tbilisi" @selected(old('timezone', 'Asia/Tbilisi') === 'Asia/Tbilisi')>Asia/Tbilisi</option><option value="UTC" @selected(old('timezone') === 'UTC')>UTC</option></select></label>
                </div>

                <fieldset class="timing-fieldset"><legend>Daily posting times</legend><p class="field-help">Use automatic spacing or choose the exact local time for every daily post.</p>
                    <div class="timing-mode-grid">
                        <label class="timing-mode"><input type="radio" name="timing_mode" value="auto" data-timing-mode @checked(old('timing_mode', 'auto') === 'auto') @disabled(!$canManage)><span><strong>Automatically distribute</strong><small>Legatus spaces posts between 09:00 and 21:00.</small></span></label>
                        <label class="timing-mode"><input type="radio" name="timing_mode" value="custom" data-timing-mode @checked(old('timing_mode') === 'custom') @disabled(!$canManage)><span><strong>Choose exact times</strong><small>Repeat the same local posting times on every selected day.</small></span></label>
                    </div>
                    <div class="posting-times" data-posting-times @if(old('timing_mode', 'auto') !== 'custom') hidden @endif></div>
                </fieldset>

                <fieldset><legend>Publish to</legend><p class="field-help">Choose Facebook, Instagram, or both connected channels.</p><div class="choice-grid">
                    @foreach(['facebook' => 'Facebook Page', 'instagram' => 'Instagram'] as $provider => $label)
                        @php($connection = $connections->get($provider))
                        <label class="choice-card @if(!$connection?->isActive()) disabled @endif">
                            <input type="checkbox" name="providers[]" value="{{ $provider }}" @checked(in_array($provider, old('providers', []), true)) @disabled(!$canManage || !$connection?->isActive())>
                            <span class="platform-mark {{ $provider }}">{{ $provider === 'facebook' ? 'f' : '◎' }}</span>
                            <span><strong>{{ $label }}</strong><small>{{ $connection?->isActive() ? ($connection->external_account_name ?: 'Connected') : 'Connect this channel first' }}</small></span>
                        </label>
                    @endforeach
                </div></fieldset>

                <fieldset><legend>Website languages</legend><p class="field-help">Leave every language unchecked to use all synchronized languages. Select one or several languages to publish only their localized products and text.</p><div class="category-grid">
                    @forelse($languages as $language)<label class="category-choice"><input type="checkbox" name="languages[]" value="{{ $language }}" @checked(in_array($language, old('languages', []), true)) @disabled(!$canManage)><span>{{ $language }}</span></label>@empty<p>Add and synchronize language URLs in Knowledge first.</p>@endforelse
                </div></fieldset>

                <fieldset><legend>Categories</legend><p class="field-help">Leave every category unchecked to rotate products from the whole public catalog.</p><div class="category-grid">
                    @forelse($categories as $category)<label class="category-choice"><input type="checkbox" name="categories[]" value="{{ $category }}" @checked(in_array($category, old('categories', []), true)) @disabled(!$canManage)><span>{{ $category }}</span></label>@empty<p>No public catalog categories are indexed yet.</p>@endforelse
                </div></fieldset>

                <button class="btn lime" type="submit" @disabled(!$canManage)>Create automatic schedule →</button>
            </form>

            <aside class="panel connection-panel">
                <h3>Publishing readiness</h3>
                @foreach(['facebook' => 'Facebook', 'instagram' => 'Instagram'] as $provider => $label)
                    @php($connection = $connections->get($provider))
                    <div class="readiness"><span class="readiness-dot {{ $connection?->isActive() ? 'ready' : '' }}"></span><div><strong>{{ $label }}</strong><small>{{ $connection?->isActive() ? 'Connected · publishing access requested' : 'Not connected' }}</small></div></div>
                @endforeach
                <a class="btn ghost" href="{{ route('channels.index') }}">Manage channels</a>
            </aside>
        </section>

        <section class="panel schedule-list">
            <div class="section-heading"><div><span class="step">03</span><h2>Schedules</h2></div></div>
            @forelse($schedules as $schedule)
                <article class="schedule-row">
                    <div><strong>{{ $schedule->starts_on->format('d M Y') }} — {{ $schedule->ends_on->format('d M Y') }}</strong><p>{{ $schedule->posts_per_day }} posts/day · {{ implode(', ', $schedule->providers) }} · {{ $schedule->languages ? implode(', ', $schedule->languages) : 'All languages' }} · {{ $schedule->categories ? implode(', ', $schedule->categories) : 'All categories' }}</p>@if($schedule->posting_times)<small>{{ implode(', ', $schedule->posting_times) }} · {{ $schedule->timezone }}</small>@else<small>Automatically distributed · {{ $schedule->timezone }}</small>@endif<small>{{ $schedule->published_posts_count }}/{{ $schedule->posts_count }} published @if($schedule->failed_posts_count) · {{ $schedule->failed_posts_count }} failed @endif</small></div>
                    <span class="status-pill {{ $schedule->status }}">{{ ucfirst($schedule->status) }}</span>
                    @if($canManage)<form method="post" action="{{ route('social-media.pause', $schedule) }}">@csrf @method('PATCH')<button class="text-button" type="submit">{{ $schedule->status === 'active' ? 'Pause' : 'Resume' }}</button></form><form method="post" action="{{ route('social-media.destroy', $schedule) }}">@csrf @method('DELETE')<button class="text-button danger" type="submit">Remove</button></form>@endif
                </article>
            @empty<p class="empty-state">No schedules yet.</p>@endforelse
        </section>

        <section class="panel schedule-list">
            <div class="section-heading"><div><span class="step">04</span><h2>Upcoming posts</h2></div></div>
            @forelse($upcoming as $post)
                @php($localScheduledFor = \Carbon\CarbonImmutable::createFromFormat('Y-m-d H:i:s', (string) $post->getRawOriginal('scheduled_for'), 'UTC')->setTimezone($post->schedule?->timezone ?: 'UTC'))
                <article class="upcoming-row">
                    @if($post->image_url)<img src="{{ $post->image_url }}" alt="">@endif
                    <div><strong>{{ $post->title }}</strong><p>{{ ucfirst($post->provider) }}@if($post->language) · {{ $post->language }}@endif · {{ $localScheduledFor->format('d M Y, H:i') }} {{ $post->schedule?->timezone ?: 'UTC' }}</p><a href="{{ $post->product_url }}" target="_blank" rel="noopener">Open public product ↗</a><details><summary>View prepared post text</summary><p class="prepared-caption">{{ $post->caption }}</p></details></div>
                    <span class="status-pill">{{ ucfirst($post->status) }}</span>
                </article>
            @empty<p class="empty-state">Scheduled posts will appear here.</p>@endforelse
        </section>
    </main>
</div>

<style nonce="{{ request()->attributes->get('csp_nonce') }}">
.social-main{max-width:1400px}.topline p,.section-heading p{margin:7px 0 0;color:var(--muted)}.social-alert{margin:18px 0;padding:15px 18px;border-radius:14px;font-size:13px;line-height:1.55}.social-alert.success{background:#eaf7df;color:#315f20}.social-alert.error{background:#fff0ed;color:#923827}.social-alert.notice{background:#fff8df;border:1px solid #eedc96;color:#675414}.social-alert ul{margin:8px 0 0;padding-left:20px}.section-heading,.section-heading>div{display:flex;align-items:center;gap:10px}.section-heading{justify-content:space-between;margin-bottom:22px}.section-heading h2{margin:0;font-size:20px}.section-heading p{font-size:12px}.step{display:grid;place-items:center;width:31px;height:31px;border-radius:10px;background:var(--lime);font-size:11px;font-weight:900}.status-pill{display:inline-flex;padding:6px 9px;border-radius:999px;background:#edf1ee;color:#596760;font-size:10px;font-weight:800}.status-pill.active{background:#e8f7dc;color:#3e702b}.status-pill.paused{background:#fff1d8;color:#8a621d}.template-panel{padding:28px;margin-top:24px}.template-actions-row{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px}.platform-tabs{display:flex;gap:7px;padding:5px;border:1px solid var(--line);border-radius:14px;background:#f6f8f5}.platform-tab,.copy-template,.token-tools button{min-height:40px;border:0;border-radius:10px;background:transparent;color:var(--ink);font:inherit;font-size:12px;font-weight:800;cursor:pointer}.platform-tab{display:flex;align-items:center;gap:8px;padding:8px 14px}.platform-tab.active{background:#fff;box-shadow:0 4px 14px rgba(18,47,38,.09)}.platform-mark{display:inline-grid;place-items:center;width:26px;height:26px;border-radius:8px;font-weight:900}.platform-mark.facebook{background:#e7efff;color:#1769e0}.platform-mark.instagram{background:linear-gradient(135deg,#8b4dca,#f47b3e);color:#fff}.copy-template{padding:8px 13px;border:1px solid var(--line);background:#fff}.template-workspace{display:grid;grid-template-columns:minmax(0,1fr) minmax(340px,430px);gap:20px}.template-workspace[hidden]{display:none!important}.template-editor>label,.delivery-editor>label:not(.switch-row){display:block;margin-bottom:8px}.template-editor label small{display:block;margin-top:5px;color:var(--muted);font-size:11px;line-height:1.5}.template-editor textarea{width:100%;box-sizing:border-box;padding:14px;border:1px solid var(--line);border-radius:13px;background:#fff;color:var(--ink);font:inherit;font-size:13px;line-height:1.6;resize:vertical}.token-tools{display:flex;align-items:center;flex-wrap:wrap;gap:6px;margin:10px 0 20px}.token-tools span{margin-right:2px;color:var(--muted);font-size:11px}.token-tools button{min-height:34px;padding:6px 9px;border:1px solid var(--line);background:#f7faf6;font-size:10px}.delivery-editor{padding:16px;border:1px solid var(--line);border-radius:14px;background:#f8faf7}.switch-row{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:15px}.switch-row span{min-width:0}.switch-row input{width:44px;height:24px;accent-color:var(--green)}.template-preview{position:sticky;top:24px;align-self:start}.preview-label{display:flex;justify-content:space-between;margin-bottom:8px;color:var(--muted);font-size:10px;font-weight:800;letter-spacing:.08em}.social-preview{overflow:hidden;border:1px solid var(--line);border-radius:16px;background:#fff;box-shadow:0 13px 30px rgba(20,48,39,.08)}.social-preview header{display:flex;align-items:center;gap:10px;padding:13px}.preview-avatar{display:grid;place-items:center;width:38px;height:38px;border-radius:50%;background:var(--green);color:white;font-weight:900}.social-preview header div{display:flex;flex-direction:column;min-width:0}.social-preview header strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.social-preview header small{margin-top:3px;color:var(--muted);font-size:9px}.preview-copy{margin:0;padding:4px 14px 14px;white-space:pre-wrap;overflow-wrap:anywhere;font-size:12px;line-height:1.55}.preview-image{display:grid;place-items:center;overflow:hidden;background:#edf2ec;color:var(--muted);font-size:11px}.preview-image img{width:100%;height:100%;object-fit:cover}.preview-image.square{aspect-ratio:1/1}.preview-image.wide{aspect-ratio:1.6/1}.facebook-link-card{display:flex;flex-direction:column;padding:12px;background:#f1f3f5;color:var(--ink);text-decoration:none}.facebook-link-card small{color:var(--muted);text-transform:uppercase}.facebook-link-card strong{margin:3px 0}.facebook-link-card span{font-size:10px;color:var(--muted)}.instagram-note{display:block;padding:10px 14px;border-top:1px solid var(--line);color:var(--muted);font-size:9px}.template-save-row{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-top:22px;padding-top:18px;border-top:1px solid var(--line)}.template-save-row p{margin:0;color:var(--muted);font-size:11px}.social-layout{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:18px;margin:24px 0}.schedule-form{padding:28px}.form-grid{display:grid;gap:14px}.form-grid.two{grid-template-columns:1fr 1fr}.schedule-form label{margin:0}.schedule-form input,.schedule-form select{width:100%;box-sizing:border-box;margin-top:7px;padding:12px;border:1px solid var(--line);border-radius:11px;background:white}.schedule-form label small,.field-help{display:block;margin-top:6px;color:var(--muted);font-size:11px}.schedule-form fieldset{margin:24px 0;padding:0;border:0}.schedule-form legend{margin-bottom:10px;font-size:13px;font-weight:800}.choice-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.choice-card{display:flex;align-items:center;gap:10px;padding:13px;border:1px solid var(--line);border-radius:12px;cursor:pointer}.choice-card input,.category-choice input{width:auto;margin:0}.choice-card>span:last-child{display:flex;flex-direction:column;min-width:0}.choice-card small{margin:3px 0 0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.choice-card.disabled{opacity:.55}.category-grid{display:flex;flex-wrap:wrap;gap:7px;max-height:220px;overflow:auto}.category-choice{display:flex;align-items:center;gap:6px;padding:7px 10px;border:1px solid var(--line);border-radius:999px;font-size:11px;cursor:pointer}.connection-panel{align-self:start;position:sticky;top:24px}.readiness{display:flex;gap:10px;padding:13px 0;border-top:1px solid var(--line)}.readiness-dot{flex:0 0 9px;height:9px;margin-top:5px;border-radius:50%;background:#c9cfcb}.readiness-dot.ready{background:#54b877;box-shadow:0 0 0 4px #e2f5e7}.readiness div{display:flex;flex-direction:column}.readiness small{margin-top:4px;color:var(--muted);font-size:10px;line-height:1.4}.connection-panel .btn{width:100%;margin-top:13px}.schedule-list{margin:18px 0}.schedule-row,.upcoming-row{display:flex;align-items:center;gap:12px;padding:14px 0;border-top:1px solid var(--line)}.schedule-row>div:first-child,.upcoming-row>div{min-width:0;flex:1}.schedule-row p,.upcoming-row p{margin:4px 0;color:var(--muted);font-size:12px}.schedule-row small{color:var(--muted)}.text-button{border:0;background:none;color:var(--green);font-weight:800;cursor:pointer}.text-button.danger{color:#a74a3a}.upcoming-row img{width:54px;height:54px;border-radius:10px;object-fit:cover}.upcoming-row a{color:var(--green);font-size:11px;font-weight:800}.upcoming-row details{margin-top:7px}.upcoming-row summary{cursor:pointer;color:var(--muted);font-size:10px}.prepared-caption{white-space:pre-wrap;padding:10px;border-radius:10px;background:#f5f7f4;color:var(--ink)!important}.empty-state{color:var(--muted)}
.timing-mode-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.timing-mode{display:flex;align-items:flex-start;gap:10px;padding:13px;border:1px solid var(--line);border-radius:12px;cursor:pointer}.timing-mode input{width:auto;margin:3px 0 0}.timing-mode span{display:flex;min-width:0;flex-direction:column}.timing-mode small{margin-top:3px;color:var(--muted);font-size:10px;line-height:1.4}.posting-times{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-top:14px;padding:14px;border:1px solid var(--line);border-radius:13px;background:#f8faf7}.posting-times[hidden]{display:none!important}.posting-time{margin:0}.posting-time input{margin-top:6px}.schedule-row small{display:block;margin-top:3px}
.image-style-picker{margin:0 0 20px;padding:0;border:0}.image-style-picker legend{font-size:13px;font-weight:800}.image-style-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px;margin-top:11px}.image-style-option{cursor:pointer}.image-style-option input{position:absolute;opacity:0;pointer-events:none}.image-style-option strong{display:block;margin-top:5px;font-size:9px;text-align:center}.style-swatch{display:grid;place-items:center;aspect-ratio:1;border:2px solid transparent;border-radius:10px;background:#edf2ec;overflow:hidden}.style-swatch i{display:block;width:55%;height:70%;background:#fff;border:1px solid #ccd4ce}.style-swatch img{width:100%;height:100%;object-fit:cover}.image-style-option input:checked+.style-swatch{border-color:var(--green);box-shadow:0 0 0 3px rgba(30,91,69,.12)}.style-swatch.raw img{object-fit:cover}.style-swatch.raw i{width:72%;height:82%;background:#ddd}.style-swatch.framed{padding:11%;background:#f6f2e8;box-sizing:border-box}.style-swatch.framed img{object-fit:contain;background:#fff;box-shadow:0 0 0 4px #fff,0 0 0 6px #1f4a3c}.style-swatch.framed i{box-shadow:0 0 0 7px #fff,0 0 0 9px #1f4a3c}.style-swatch.editorial{padding:10%;background:#eef1eb;box-sizing:border-box}.style-swatch.editorial img{object-fit:contain;background:#fff;box-shadow:7px 7px 0 #beff49}.style-swatch.editorial i{box-shadow:8px 8px 0 #beff49}.style-swatch.dark{padding:11%;background:#131d1a;box-sizing:border-box}.style-swatch.dark img{object-fit:contain;background:#fff;box-shadow:0 0 0 5px #222f2a,0 0 0 7px #beff49}.style-swatch.dark i{box-shadow:0 0 0 6px #222f2a}.style-swatch.brand{padding:11%;background:#def5e5;box-sizing:border-box}.style-swatch.brand img{object-fit:contain;background:#fff;box-shadow:0 0 0 5px #fff,0 0 0 7px #185d46}.style-swatch.brand i{box-shadow:0 0 0 7px #185d46}.preview-image:not(.image-style-original):not(.image-style-raw){padding:8%;box-sizing:border-box}.preview-image:not(.image-style-original):not(.image-style-raw) img{object-fit:contain;background:#fff}.preview-image.image-style-framed{background:#f6f2e8}.preview-image.image-style-framed img{box-shadow:0 0 0 12px #fff,0 0 0 15px #1f4a3c}.preview-image.image-style-editorial{background:#eef1eb}.preview-image.image-style-editorial img{box-shadow:16px 16px 0 #beff49}.preview-image.image-style-dark{background:#131d1a}.preview-image.image-style-dark img{box-shadow:0 0 0 12px #222f2a,0 0 0 15px #beff49}.preview-image.image-style-brand{background:#def5e5}.preview-image.image-style-brand img{box-shadow:0 0 0 12px #fff,0 0 0 15px #185d46}
@media(max-width:1100px){.template-workspace,.social-layout{grid-template-columns:1fr}.template-preview,.connection-panel{position:static}.template-preview{max-width:520px}}
@media(max-width:650px){.template-panel,.schedule-form{padding:19px}.template-actions-row,.template-save-row{align-items:stretch;flex-direction:column}.platform-tabs{overflow-x:auto}.copy-template,.template-save-row .btn{width:100%}.form-grid.two,.choice-grid,.timing-mode-grid{grid-template-columns:1fr}.image-style-grid{grid-template-columns:repeat(3,1fr)}.schedule-row{align-items:flex-start;flex-wrap:wrap}.section-heading{align-items:flex-start}.status-pill{white-space:nowrap}}
</style>

<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(() => {
    const product = {{ \Illuminate\Support\Js::from($previewProduct) }};
    const limits = {facebook: 6000, instagram: 2200};
    const tabs = [...document.querySelectorAll('[data-platform-tab]')];
    const panels = [...document.querySelectorAll('[data-platform-panel]')];
    const postsPerDay = document.querySelector('[name="posts_per_day"]');
    const timingModes = [...document.querySelectorAll('[data-timing-mode]')];
    const postingTimes = document.querySelector('[data-posting-times]');
    const initialPostingTimes = {{ \Illuminate\Support\Js::from(old('posting_times', [])) }};

    function defaultPostingTime(index, count) {
        const start = 9 * 60;
        const end = 21 * 60;
        const minutes = count === 1 ? 12 * 60 : Math.round(start + ((end - start) * index / (count - 1)));
        return `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
    }

    function renderPostingTimes(preferredValues = null) {
        if (!postsPerDay || !postingTimes) return;
        const custom = timingModes.find((mode) => mode.checked)?.value === 'custom';
        const count = Math.max(1, Math.min(24, Number.parseInt(postsPerDay.value, 10) || 1));
        const existing = preferredValues ?? [...postingTimes.querySelectorAll('input')].map((input) => input.value);
        postingTimes.hidden = !custom;
        postingTimes.replaceChildren();

        if (!custom) return;
        for (let index = 0; index < count; index += 1) {
            const label = document.createElement('label');
            label.className = 'posting-time';
            label.textContent = `Post ${index + 1}`;
            const input = document.createElement('input');
            input.type = 'time';
            input.name = 'posting_times[]';
            input.value = existing[index] || defaultPostingTime(index, count);
            input.required = true;
            input.disabled = !{{ $canManage ? 'true' : 'false' }};
            label.append(input);
            postingTimes.append(label);
        }
    }

    timingModes.forEach((mode) => mode.addEventListener('change', () => renderPostingTimes()));
    postsPerDay?.addEventListener('change', () => renderPostingTimes());
    renderPostingTimes(initialPostingTimes);

    function selectPlatform(provider, focus = false) {
        tabs.forEach((tab) => {
            const active = tab.dataset.platformTab === provider;
            tab.classList.toggle('active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
            tab.tabIndex = active ? 0 : -1;
            if (active && focus) tab.focus();
        });
        panels.forEach((panel) => { panel.hidden = panel.dataset.platformPanel !== provider; });
    }

    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => selectPlatform(tab.dataset.platformTab));
        tab.addEventListener('keydown', (event) => {
            if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
            event.preventDefault();
            const direction = event.key === 'ArrowRight' ? 1 : -1;
            const next = (index + direction + tabs.length) % tabs.length;
            selectPlatform(tabs[next].dataset.platformTab, true);
        });
    });

    function render(provider) {
        const body = document.querySelector(`[data-template-body="${provider}"]`);
        const deliveryToggle = document.querySelector(`[data-delivery-enabled="${provider}"]`);
        const deliveryField = document.querySelector(`[data-delivery-text="${provider}"]`);
        const output = document.querySelector(`[data-preview-copy="${provider}"]`);
        if (!body || !output) return;

        const delivery = deliveryToggle?.checked ? (deliveryField?.value || '').trim() : '';
        let template = body.value.replace(/\r\n?/g, '\n');
        if (!delivery) {
            template = template.split('\n').filter((line) => !line.includes('{delivery}')).join('\n');
        }
        const values = {
            business_name: product.business_name,
            product_title: product.title,
            product_description: product.description,
            price: product.price,
            category: product.category,
            delivery,
            product_url: product.url,
        };
        let caption = template.replace(/\{([a-z_]+)\}/gi, (match, token) => Object.prototype.hasOwnProperty.call(values, token) ? values[token] : match);
        caption = caption.replace(/[ \t]+\n/g, '\n').replace(/\n{3,}/g, '\n\n').trim();
        output.textContent = caption;
        const counter = document.querySelector(`[data-character-count="${provider}"]`);
        if (counter) {
            const count = Array.from(caption).length;
            counter.textContent = `${count} / ${limits[provider]}`;
            counter.style.color = count > limits[provider] ? '#a33d2c' : '';
        }
        if (deliveryField && {{ $canManage ? 'true' : 'false' }}) {
            deliveryField.readOnly = !deliveryToggle.checked;
            deliveryField.setAttribute('aria-disabled', deliveryToggle.checked ? 'false' : 'true');
        }
    }

    document.querySelectorAll('[data-template-body], [data-delivery-text]').forEach((field) => field.addEventListener('input', () => render(field.dataset.templateBody || field.dataset.deliveryText)));
    document.querySelectorAll('[data-delivery-enabled]').forEach((toggle) => toggle.addEventListener('change', () => render(toggle.dataset.deliveryEnabled)));
    document.querySelectorAll('[data-image-style]').forEach((input) => input.addEventListener('change', () => {
        const preview = document.querySelector(`[data-preview-image="${input.dataset.imageStyle}"]`);
        if (!preview || !input.checked) return;
        preview.className = preview.className.replace(/image-style-[a-z]+/g, '').trim();
        preview.classList.add(`image-style-${input.value}`);
        const image = preview.querySelector('img');
        if (image) image.src = product.style_images?.[input.value] || product.image;
    }));
    document.querySelectorAll('[data-insert-token]').forEach((button) => button.addEventListener('click', () => {
        const textarea = document.querySelector(`[data-template-body="${button.dataset.tokenProvider}"]`);
        if (!textarea) return;
        textarea.setRangeText(button.dataset.insertToken, textarea.selectionStart, textarea.selectionEnd, 'end');
        textarea.focus();
        render(button.dataset.tokenProvider);
    }));
    document.getElementById('copy-facebook-template')?.addEventListener('click', () => {
        const facebookBody = document.querySelector('[data-template-body="facebook"]');
        const instagramBody = document.querySelector('[data-template-body="instagram"]');
        const facebookDelivery = document.querySelector('[data-delivery-text="facebook"]');
        const instagramDelivery = document.querySelector('[data-delivery-text="instagram"]');
        const facebookToggle = document.querySelector('[data-delivery-enabled="facebook"]');
        const instagramToggle = document.querySelector('[data-delivery-enabled="instagram"]');
        if (!facebookBody || !instagramBody) return;
        instagramBody.value = facebookBody.value;
        instagramDelivery.value = facebookDelivery.value;
        instagramToggle.checked = facebookToggle.checked;
        render('instagram');
        selectPlatform('instagram');
    });

    const domain = document.querySelector('[data-preview-domain]');
    if (domain) {
        try { domain.textContent = new URL(product.url).hostname; } catch { domain.textContent = product.url; }
    }
    render('facebook');
    render('instagram');
})();
</script>
@endsection
