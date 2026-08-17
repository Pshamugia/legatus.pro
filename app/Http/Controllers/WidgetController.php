<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Support\WidgetLocaleResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;

class WidgetController extends Controller
{
    public function script(Request $request, Agent $agent, WidgetLocaleResolver $locales)
    {
        abort_unless($agent->is_active, 404);

        if (! $agent->websiteWidgetEnabled()) {
            return $this->javascript("(function(){var root=document.getElementById('legatus-widget-root');if(root)root.remove();window.LegatusWidgetLoaded=false;})();\n");
        }

        $widgetLocale = $locales->resolve($request);
        $frame = route('widget.frame', ['agent' => $agent, 'lang' => $widgetLocale]);
        $frameJson = json_encode($frame, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $businessName = trim($agent->business_name);
        $assistantName = $agent->assistantDisplayName();
        $theme = $agent->widgetTheme();
        $configuredLauncherLabel = trim((string) data_get($agent->settings, 'widget_launcher_label', ''));
        $launcherLabel = $configuredLauncherLabel !== ''
            ? $configuredLauncherLabel
            : (string) Lang::get('widget.launcher_label', [], $widgetLocale);
        $launcherOpen = (string) Lang::get('widget.launcher_open', ['label' => $launcherLabel], $widgetLocale);
        $frameTitle = (string) Lang::get('widget.frame_title', [
            'assistant' => $assistantName,
            'business' => $businessName,
        ], $widgetLocale);
        $businessNameJson = json_encode($businessName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $assistantNameJson = json_encode($assistantName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $businessInitialJson = json_encode(mb_strtoupper(mb_substr($businessName, 0, 1)), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $launcherOpenJson = json_encode($launcherOpen, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $launcherLabelJson = json_encode($launcherLabel, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $frameTitleJson = json_encode($frameTitle, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $primaryJson = json_encode($theme['primary'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $accentJson = json_encode($theme['accent'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $primaryForegroundJson = json_encode($theme['primary_foreground'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $accentForegroundJson = json_encode($theme['accent_foreground'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $js = <<<JS
(function(){
 if(window.LegatusWidgetLoaded)return;window.LegatusWidgetLoaded=true;
 var frameUrl={$frameJson},frameOrigin=(new URL(frameUrl)).origin,businessName={$businessNameJson},assistantName={$assistantNameJson},businessInitial={$businessInitialJson},launcherOpen={$launcherOpenJson},launcherLabel={$launcherLabelJson},frameTitle={$frameTitleJson},primary={$primaryJson},accent={$accentJson},primaryForeground={$primaryForegroundJson},accentForeground={$accentForegroundJson};
 var root=document.createElement('div');root.id='legatus-widget-root';
 root.style.setProperty('--legatus-widget-primary',primary);root.style.setProperty('--legatus-widget-accent',accent);root.style.setProperty('--legatus-widget-primary-foreground',primaryForeground);root.style.setProperty('--legatus-widget-accent-foreground',accentForeground);
 var launcher=document.createElement('button');launcher.id='legatus-launcher';launcher.setAttribute('aria-label',launcherOpen);
 var mark=document.createElement('span');mark.textContent=businessInitial;
 var label=document.createElement('b');label.textContent=launcherLabel;
 launcher.append(mark,label);root.appendChild(launcher);
 var frame=document.createElement('iframe');frame.title=frameTitle;frame.id='legatus-frame';frame.src=frameUrl;frame.allow='clipboard-write';root.appendChild(frame);
 var css=document.createElement('style');css.textContent='#legatus-widget-root{position:fixed;right:22px;bottom:22px;z-index:2147483000;font-family:Arial,sans-serif}#legatus-launcher{border:0;background:var(--legatus-widget-primary);color:var(--legatus-widget-primary-foreground);border-radius:999px;padding:10px 17px 10px 10px;max-width:min(330px,calc(100vw - 44px));display:flex;align-items:center;gap:9px;box-shadow:0 12px 35px #142c2460;cursor:pointer}#legatus-launcher span{display:grid;place-items:center;flex:0 0 34px;width:34px;height:34px;border-radius:50%;background:var(--legatus-widget-accent);color:var(--legatus-widget-accent-foreground);font-weight:800}#legatus-launcher b{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}#legatus-frame{display:none;position:absolute;right:0;bottom:64px;width:min(390px,calc(100vw - 28px));height:min(680px,calc(100vh - 105px));border:0;border-radius:22px;background:white;box-shadow:0 24px 80px #142c244d}#legatus-widget-root.open #legatus-frame{display:block}@media(max-width:480px){#legatus-widget-root{right:14px;bottom:14px}#legatus-frame{position:fixed;inset:12px;width:calc(100vw - 24px);height:calc(100vh - 88px)}}';
 document.head.appendChild(css);document.body.appendChild(root);document.getElementById('legatus-launcher').onclick=function(){root.classList.toggle('open')};
 window.addEventListener('message',function(e){if(e.origin===frameOrigin&&(e.data==='legatus:close'||e.data==='nia:close'))root.classList.remove('open')});
})();
JS;

        return $this->javascript($js, $widgetLocale);
    }

    public function frame(Request $request, Agent $agent, WidgetLocaleResolver $locales)
    {
        abort_unless($agent->is_active && $agent->websiteWidgetEnabled(), 404);

        $widgetLocale = $locales->resolve($request);
        $widgetCopy = Lang::get('widget', [], $widgetLocale);

        return response()
            ->view('widget', compact('agent', 'widgetLocale', 'widgetCopy'))
            ->header('Content-Language', $widgetLocale)
            ->header('Cache-Control', 'no-store, private');
    }

    private function javascript(string $content, ?string $locale = null)
    {
        $response = response($content)
            ->header('Content-Type', 'application/javascript; charset=UTF-8')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');

        if ($locale !== null) {
            $response->headers->set('Content-Language', $locale);
        }

        return $response;
    }
}
