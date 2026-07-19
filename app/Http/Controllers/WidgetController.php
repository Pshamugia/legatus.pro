<?php

namespace App\Http\Controllers;

use App\Models\Agent;

class WidgetController extends Controller
{
    public function script(Agent $agent)
    {
        abort_unless($agent->is_active, 404);
        $frame = route('widget.frame', $agent);
        $js = <<<JS
(function(){
 if(window.LegatusWidgetLoaded)return;window.LegatusWidgetLoaded=true;
 var root=document.createElement('div');root.id='legatus-widget-root';root.innerHTML='<button aria-label="Open Legatus shopping assistant" id="legatus-launcher"><span>L</span><b>Ask Legatus</b></button><iframe title="Legatus AI sales assistant" id="legatus-frame" src="{$frame}" allow="clipboard-write"></iframe>';
 var css=document.createElement('style');css.textContent='#legatus-widget-root{position:fixed;right:22px;bottom:22px;z-index:2147483000;font-family:Arial,sans-serif}#legatus-launcher{border:0;background:#163f33;color:white;border-radius:999px;padding:10px 17px 10px 10px;display:flex;align-items:center;gap:9px;box-shadow:0 12px 35px #142c2460;cursor:pointer}#legatus-launcher span{display:grid;place-items:center;width:34px;height:34px;border-radius:50%;background:#d9ff72;color:#173c31;font-weight:800}#legatus-frame{display:none;position:absolute;right:0;bottom:64px;width:min(390px,calc(100vw - 28px));height:min(680px,calc(100vh - 105px));border:0;border-radius:22px;background:white;box-shadow:0 24px 80px #142c244d}#legatus-widget-root.open #legatus-frame{display:block}@media(max-width:480px){#legatus-widget-root{right:14px;bottom:14px}#legatus-frame{position:fixed;inset:12px;width:calc(100vw - 24px);height:calc(100vh - 88px)}}';
 document.head.appendChild(css);document.body.appendChild(root);document.getElementById('legatus-launcher').onclick=function(){root.classList.toggle('open')};
 window.addEventListener('message',function(e){if(e.data==='legatus:close'||e.data==='nia:close')root.classList.remove('open')});
})();
JS;

        return response($js)->header('Content-Type', 'application/javascript; charset=UTF-8')->header('Cache-Control', 'public, max-age=300');
    }

    public function frame(Agent $agent)
    {
        abort_unless($agent->is_active, 404);

        return view('widget', compact('agent'));
    }
}
