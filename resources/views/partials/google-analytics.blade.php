@production
    <div
        id="analytics-consent-banner"
        class="analytics-consent"
        role="dialog"
        aria-modal="false"
        aria-labelledby="analytics-consent-title"
        hidden
    >
        <div class="analytics-consent__copy">
            <strong id="analytics-consent-title">{{ __('landing.cookies.title') }}</strong>
            <p>
                {{ __('landing.cookies.description') }}
                <a href="{{ route('privacy') }}">{{ __('landing.cookies.learn_more') }}</a>
            </p>
        </div>
        <div class="analytics-consent__actions">
            <button type="button" class="analytics-consent__button analytics-consent__button--reject" data-analytics-consent="denied">
                {{ __('landing.cookies.reject') }}
            </button>
            <button type="button" class="analytics-consent__button analytics-consent__button--accept" data-analytics-consent="granted">
                {{ __('landing.cookies.accept') }}
            </button>
        </div>
    </div>

    <button
        type="button"
        id="analytics-consent-settings"
        class="analytics-consent-settings"
        aria-controls="analytics-consent-banner"
        hidden
    >
        {{ __('landing.cookies.settings') }}
    </button>

    <style nonce="{{ request()->attributes->get('csp_nonce') }}">
        .analytics-consent{position:fixed;z-index:2147483000;left:50%;bottom:18px;width:min(920px,calc(100% - 28px));transform:translateX(-50%);display:flex;align-items:center;justify-content:space-between;gap:24px;padding:20px 22px;border:1px solid #d9e4dc;border-radius:18px;background:#fff;color:#13231e;box-shadow:0 20px 65px rgba(19,35,30,.22);font-family:'DM Sans',sans-serif}.analytics-consent[hidden],.analytics-consent-settings[hidden]{display:none!important}.analytics-consent__copy{max-width:640px}.analytics-consent__copy strong{display:block;margin-bottom:5px;font:800 17px Manrope,sans-serif}.analytics-consent__copy p{margin:0;color:#5f6f69;font-size:13px;line-height:1.55}.analytics-consent__copy a{color:#215d49;text-decoration:underline;text-underline-offset:3px}.analytics-consent__actions{display:flex;flex:0 0 auto;gap:9px}.analytics-consent__button,.analytics-consent-settings{border:0;cursor:pointer;font:700 13px 'DM Sans',sans-serif}.analytics-consent__button{min-height:42px;padding:10px 16px;border-radius:11px}.analytics-consent__button--reject{border:1px solid #d9e4dc;background:#fff;color:#193a30}.analytics-consent__button--accept{background:#d9ff72;color:#13231e}.analytics-consent__button:focus-visible,.analytics-consent-settings:focus-visible{outline:3px solid #70a48f;outline-offset:2px}.analytics-consent-settings{position:fixed;z-index:2147482999;left:14px;bottom:14px;padding:8px 11px;border:1px solid #d9e4dc;border-radius:999px;background:#fff;color:#40554e;box-shadow:0 8px 24px rgba(19,35,30,.12);font-size:11px}@media(max-width:700px){.analytics-consent{align-items:stretch;flex-direction:column;gap:16px;padding:18px;bottom:10px}.analytics-consent__actions{width:100%}.analytics-consent__button{flex:1}.analytics-consent-settings{left:10px;bottom:10px}}
    </style>

    <script nonce="{{ request()->attributes->get('csp_nonce') }}">
        (() => {
            const measurementId = 'G-VH5ZFXN6PW';
            const storageKey = 'legatus_analytics_consent';
            const granted = 'granted';
            const denied = 'denied';
            let analyticsLoaded = false;

            window.dataLayer = window.dataLayer || [];
            window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };

            window.gtag('consent', 'default', {
                ad_storage: denied,
                ad_user_data: denied,
                ad_personalization: denied,
                analytics_storage: denied,
            });

            const loadAnalytics = () => {
                if (analyticsLoaded) return;

                analyticsLoaded = true;
                const script = document.createElement('script');
                script.async = true;
                script.src = `https://www.googletagmanager.com/gtag/js?id=${measurementId}`;
                document.head.appendChild(script);
                window.gtag('js', new Date());
                window.gtag('config', measurementId);
            };

            const clearAnalyticsCookies = () => {
                const hostname = window.location.hostname;
                const rootDomain = hostname.split('.').slice(-2).join('.');

                document.cookie.split(';').forEach((cookie) => {
                    const name = cookie.split('=')[0].trim();
                    if (!name.startsWith('_ga')) return;

                    document.cookie = `${name}=; Max-Age=0; path=/; SameSite=Lax`;
                    if (rootDomain.includes('.')) {
                        document.cookie = `${name}=; Max-Age=0; path=/; domain=.${rootDomain}; SameSite=Lax`;
                    }
                });
            };

            const readChoice = () => {
                try {
                    const choice = window.localStorage.getItem(storageKey);
                    return choice === granted || choice === denied ? choice : null;
                } catch (error) {
                    return null;
                }
            };

            const saveChoice = (choice) => {
                try {
                    window.localStorage.setItem(storageKey, choice);
                } catch (error) {
                    // Consent still applies to this page when storage is unavailable.
                }
            };

            const applyChoice = (choice, persist = true) => {
                window.gtag('consent', 'update', {
                    ad_storage: denied,
                    ad_user_data: denied,
                    ad_personalization: denied,
                    analytics_storage: choice,
                });

                if (persist) saveChoice(choice);
                if (choice === granted) loadAnalytics();
                if (choice === denied) clearAnalyticsCookies();
            };

            const initialChoice = readChoice();
            if (initialChoice) applyChoice(initialChoice, false);

            document.addEventListener('DOMContentLoaded', () => {
                const banner = document.getElementById('analytics-consent-banner');
                const settings = document.getElementById('analytics-consent-settings');
                if (!banner || !settings) return;

                const showBanner = () => {
                    banner.hidden = false;
                    settings.hidden = true;
                };

                const hideBanner = () => {
                    banner.hidden = true;
                    settings.hidden = false;
                };

                banner.querySelectorAll('[data-analytics-consent]').forEach((button) => {
                    button.addEventListener('click', () => {
                        applyChoice(button.dataset.analyticsConsent);
                        hideBanner();
                    });
                });

                settings.addEventListener('click', showBanner);
                initialChoice ? hideBanner() : showBanner();
            });
        })();
    </script>
@endproduction
