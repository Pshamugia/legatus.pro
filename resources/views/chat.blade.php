@extends('layouts.app')
@php
    $assistantName = $agent->assistantDisplayName();
    $assistantIntroduction = $agent->hasCustomAssistantName()
        ? "მე ვარ {$assistantName} — {$agent->business_name}-ის AI ასისტენტი."
        : "მე ვარ {$agent->business_name}-ის AI ასისტენტი.";
    $widgetTheme = $agent->widgetTheme();
@endphp
@section('title', $assistantName.' · '.$agent->business_name)
@section('body')
<style nonce="{{ request()->attributes->get('csp_nonce') }}">
    .chatwindow{--chat-primary:{{ $widgetTheme['primary'] }};--chat-accent:{{ $widgetTheme['accent'] }};--chat-primary-foreground:{{ $widgetTheme['primary_foreground'] }};--chat-accent-foreground:{{ $widgetTheme['accent_foreground'] }}}
    .chatwindow .demo-head{background:var(--chat-primary);color:var(--chat-primary-foreground)}
    .chatwindow .demo-head .avatar{background:var(--chat-accent);color:var(--chat-accent-foreground)}
    .chatwindow .demo-head .person small{color:var(--chat-primary-foreground);opacity:.76}
    .chatwindow .demo-head .tag{border-color:currentColor;background:transparent;color:var(--chat-primary-foreground)}
    .chatwindow .bubble.user{background:var(--chat-primary);color:var(--chat-primary-foreground)}
    .chatwindow .composer .btn{background:var(--chat-accent);color:var(--chat-accent-foreground)}
</style>
<div class="chatpage"><div class="chatwindow">
    <div class="demo-head"><div class="person"><span class="avatar">{{ mb_strtoupper(mb_substr($assistantName, 0, 1)) }}</span><div><strong>{{ $assistantName }} · {{ $agent->business_name }}</strong><small>● Online · AI sales & shopping ambassador · Powered by Legatus</small></div></div><div style="display:flex;gap:7px"><button type="button" class="tag" id="new-conversation" style="cursor:pointer">New conversation ↻</button><span class="tag">Instagram-style demo</span>@auth<a class="tag" href="{{ route('dashboard') }}">Dashboard ↗</a>@endauth</div></div>
    <div id="messages"><div class="bubble ai">გამარჯობა! 👋 {{ $assistantIntroduction }} დაგეხმარებით არჩევანში, ფასისა და მარაგის გადამოწმებაში, მიწოდებასა და საბითუმო მოთხოვნაში. რას ეძებთ?</div></div>
    <div class="suggestions"><button data-q="ვეძებ ოსტატი და მარგარიტას მსგავს თანამედროვე და იდუმალ წიგნს, 30 ლარამდე">✨ Personal shopper</button><button data-q="Piranesi რა ღირს და 2 ცალი მარაგშია?">💸 Price + stock</button><button data-q="თბილისში ხვალ ჩამომივა?">🚚 Delivery</button><button data-q="Convenience Store Woman-ის 10 ცალი მინდა და 18% ფასდაკლებას ვითხოვ">📦 Wholesale offer</button><button data-q="ოპერატორთან დამაკავშირე">🙋 Human handoff</button></div>
    <form class="composer" id="chat-form"><input id="message" autocomplete="off" maxlength="1500" placeholder="დაწერეთ შეტყობინება..."><button class="btn" type="submit">გაგზავნა ↑</button></form>
</div></div>
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
const form=document.querySelector('#chat-form'),input=document.querySelector('#message'),messages=document.querySelector('#messages');
const messageUrl=@json(route('chat.message',$agent)),historyUrl=@json(route('chat.history',$agent)),feedbackBase=@json(url('/demo/'.$agent->slug.'/messages'));
const storageKey='legatus_visitor_token_{{ $agent->slug }}';
const requestDeadlineMs=55000;
if(new URLSearchParams(window.location.search).get('new')==='1'){try{localStorage.removeItem(storageKey)}catch{}window.history.replaceState({},'',window.location.pathname)}
let visitorToken=readToken(),cursor=0,sending=false,polling=false;
const seen=new Set();
function readToken(){try{return localStorage.getItem(storageKey)}catch{return null}}
function saveToken(value){visitorToken=value;try{localStorage.setItem(storageKey,value)}catch{}}
function clearToken(){visitorToken=null;cursor=0;seen.clear();try{localStorage.removeItem(storageKey)}catch{}}
function requestId(){return crypto.randomUUID?crypto.randomUUID():'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g,c=>{const r=Math.random()*16|0;return(c==='x'?r:(r&3|8)).toString(16)})}
function bubble(text,role,{products=[],sources=[],tools=[],confidence=null,reason=null,messageId=null,human=false}={}){
 const el=document.createElement('div');el.className='bubble '+role;if(human){const badge=document.createElement('small');badge.textContent='Human operator';badge.style.cssText='display:block;color:var(--chat-primary);font-weight:700;margin-bottom:5px';el.append(badge)}const copy=document.createElement('div');copy.textContent=text;el.append(copy);
 if(products.length){const row=document.createElement('div');row.className='product-row';products.forEach(product=>{let productUrl=null;try{const candidate=new URL(product.url);if(candidate.protocol==='https:'||candidate.protocol==='http:')productUrl=candidate.href}catch{}const card=document.createElement(productUrl?'a':'div');card.className='product-mini';if(productUrl){card.href=productUrl;card.target='_blank';card.rel='noopener noreferrer';card.style.color='inherit';card.style.textDecoration='none'}const name=document.createElement('b'),meta=document.createElement('span');name.textContent=product.name;meta.textContent=Number(product.price).toFixed(2)+' ₾ · '+(product.stock>0?product.stock+' მარაგში':'ამოიწურა');card.append(name,meta);row.append(card)});el.append(row)}
 if(role==='ai'&&(sources.length||tools.length||confidence!==null||reason)){const proof=document.createElement('div');proof.style.cssText='display:flex;flex-wrap:wrap;gap:6px;margin-top:11px;padding-top:9px;border-top:1px solid #e7ece8';sources.slice(0,4).forEach(source=>proof.append(chip('Grounded · '+(source.label||source.type||'Knowledge')+freshness(source),'#edf4ef','#426557')));if(confidence!==null)proof.append(chip('Confidence · '+Math.round(confidence*100)+'%','#eef4ff','#365c8a'));tools.slice(0,5).forEach(tool=>proof.append(chip('Action · '+tool,'#f1f0ff','#554c8a')));if(reason)proof.append(chip('Escalated · '+reason,'#fff3df','#8a5b19'));el.append(proof)}
 if(role==='ai'&&messageId){const rate=document.createElement('div');rate.style.cssText='display:flex;gap:6px;margin-top:9px';const label=document.createElement('small');label.textContent='Was this useful?';label.style.color='#76847f';rate.append(label);[['👍','helpful'],['👎','unhelpful']].forEach(([icon,value])=>{const button=document.createElement('button');button.type='button';button.textContent=icon;button.style.cssText='border:1px solid #dfe7e1;background:white;border-radius:8px;cursor:pointer';button.onclick=()=>feedback(messageId,value,rate);rate.append(button)});el.append(rate)}
 messages.append(el);messages.scrollTop=messages.scrollHeight;
}
function chip(text,bg,color){const element=document.createElement('span');element.style.cssText=`font-size:10px;padding:5px 8px;border-radius:99px;background:${bg};color:${color};max-width:100%`;element.textContent=text;return element}
function freshness(source){if(source.reference)return ' · '+source.reference;if(!source.updated_at)return '';const minutes=Math.max(0,Math.round((Date.now()-new Date(source.updated_at).getTime())/60000));return ' · updated '+(minutes<1?'just now':minutes+'m ago')}
async function feedback(messageId,value,row){if(!visitorToken)return;const response=await fetch(`${feedbackBase}/${encodeURIComponent(messageId)}/feedback`,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({feedback:value,visitor_token:visitorToken})});if(response.ok){row.textContent='Thanks · feedback saved ✓'}}
function renderMessage(message){if(!message.id||seen.has(message.id))return;seen.add(message.id);bubble(message.text,message.role==='customer'?'user':'ai',{products:message.products||[],sources:message.sources||[],tools:message.tools_used||[],confidence:message.confidence,reason:message.escalation_reason,messageId:message.role==='assistant'?message.id:null,human:message.role==='human'})}
async function pollHistory(){if(!visitorToken||polling||sending)return;polling=true;try{const url=new URL(historyUrl,window.location.href);url.searchParams.set('after',String(cursor));const response=await fetch(url,{headers:{'Accept':'application/json','X-Legatus-Visitor-Token':visitorToken},cache:'no-store'});if(response.status===401){clearToken();return}if(!response.ok)return;const data=await response.json();(data.messages||[]).forEach(renderMessage);cursor=Math.max(cursor,Number(data.cursor)||0)}finally{polling=false}}
async function postMessage(payload,signal){for(let attempt=0;attempt<2;attempt++){try{const response=await fetch(messageUrl,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(payload),signal});if(response.status===401&&payload.visitor_token&&attempt===0){clearToken();payload.visitor_token=null;continue}return response}catch(error){if(error.name==='AbortError'||attempt===1)throw error;await new Promise(resolve=>setTimeout(resolve,450))}}}
async function responseData(response){const type=response.headers.get('content-type')||'';if(!type.includes('application/json'))throw new Error(`Unexpected server response (${response.status})`);return response.json()}
async function send(text){const clean=text.trim();if(!clean||sending)return;sending=true;bubble(clean,'user');input.value='';input.disabled=true;const typing=document.createElement('div');typing.className='bubble ai';typing.textContent='ვამოწმებ გადამოწმებულ მონაცემებს…';messages.append(typing);messages.scrollTop=messages.scrollHeight;const id=requestId(),controller=new AbortController(),timer=setTimeout(()=>controller.abort(),requestDeadlineMs);try{const response=await postMessage({message:clean,visitor_token:visitorToken,request_id:id,channel:'web'},controller.signal);const data=await responseData(response);if(!response.ok)throw new Error(data.message||'Request failed');saveToken(data.visitor_token);if(data.customer_message_id)seen.add(data.customer_message_id);if(data.message_id)seen.add(data.message_id);typing.remove();bubble(data.text,'ai',{products:data.products||[],sources:data.sources||[],tools:data.tools_used||[],confidence:data.confidence,reason:data.escalation_reason,messageId:data.message_id})}catch(error){typing.remove();bubble(error.name==='AbortError'?'პასუხმა დროის ლიმიტს გადააჭარბა. გთხოვთ ხელახლა სცადოთ.':'კავშირი შეფერხდა. გთხოვთ ხელახლა სცადოთ.','ai')}finally{clearTimeout(timer);sending=false;input.disabled=false;input.focus();pollHistory()}}
form.addEventListener('submit',event=>{event.preventDefault();send(input.value)});document.querySelectorAll('[data-q]').forEach(button=>button.onclick=()=>send(button.dataset.q));
document.querySelector('#new-conversation').onclick=()=>{clearToken();window.location.reload()};
pollHistory();setInterval(pollHistory,2500);
</script>
@endsection
