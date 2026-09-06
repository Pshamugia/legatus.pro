@extends('layouts.app')
@section('title','Inbox · Legatus')
@section('body')
<style nonce="{{ request()->attributes->get('csp_nonce') }}">
.inbox-mobile-back{display:none}.inbox-thread{min-width:0}.inbox-thread>div{gap:12px}.inbox-thread strong,.inbox-thread p{overflow:hidden;text-overflow:ellipsis}.inbox-thread .channel{flex:0 0 auto;white-space:nowrap}.inbox-context{min-width:0;overflow:auto}.inbox-conversation-identity{min-width:0}.inbox-conversation-identity .channel{overflow-wrap:anywhere}.inbox-conversation-actions{align-items:center;flex-wrap:wrap;justify-content:flex-end}.inbox-conversation-actions form{margin:0}.operator-reply-form input{min-width:0}
@media(max-width:1050px) and (min-width:851px){.inbox-layout{grid-template-columns:290px minmax(0,1fr)!important}.inbox-context{display:none}}
@media(max-width:850px){
    .inbox-main{padding:18px 14px 0!important;overflow:hidden}.inbox-topline{display:block}.inbox-topline h1{font-size:clamp(27px,8vw,34px)}.inbox-filters{max-width:100%;overflow-x:auto;padding:2px 0 5px;scrollbar-width:none}.inbox-filters::-webkit-scrollbar{display:none}.inbox-filters .tag{flex:0 0 auto}
    .inbox-layout{display:block!important;height:auto!important;min-height:0!important;margin-top:14px!important;border-radius:16px!important;background:white!important}.inbox-list{max-height:none;overflow:visible!important}.inbox-workspace,.inbox-context{display:none!important}.inbox-layout.has-mobile-selection .inbox-list{display:none}.inbox-layout.has-mobile-selection .inbox-workspace{display:flex!important;height:calc(100dvh - 148px);min-height:480px}.inbox-layout.has-mobile-selection .inbox-context{display:block!important;border-top:1px solid var(--line)}
    .inbox-mobile-back{display:block;flex:0 0 auto;padding:11px 16px;border-bottom:1px solid var(--line);background:white;color:var(--green);font-size:12px;font-weight:800}.inbox-conversation-header{align-items:flex-start!important;flex-direction:column;gap:12px;padding:13px 16px!important}.inbox-conversation-actions{width:100%;justify-content:flex-start!important}.inbox-conversation-actions .btn{padding:9px 12px}.inbox-conversation-actions #conversation-state{max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.inbox-workspace #operator-messages{padding:14px!important}.inbox-workspace .bubble{max-width:92%}.operator-composer{padding:10px!important}.operator-reply-form{align-items:stretch}.operator-reply-form .btn{flex:0 0 auto;padding-inline:14px}
}
@media(max-width:390px){.operator-reply-form{flex-direction:column}.operator-reply-form .btn{width:100%}.inbox-layout.has-mobile-selection .inbox-workspace{height:auto;min-height:calc(100dvh - 148px)}}
</style>
<div class="dash-shell">
@include('partials.workspace-navigation', ['active' => 'inbox'])
<main class="main inbox-main" style="padding-bottom:0"><div class="topline inbox-topline"><div><span class="eyebrow">Omnichannel workspace</span><h1>Conversation inbox</h1></div><div class="inbox-filters" style="display:flex;gap:8px"><a class="tag" href="{{ route('inbox.index') }}">All</a><a class="tag" href="{{ route('inbox.index',['status'=>'human']) }}">Needs human</a><a class="tag" href="{{ route('inbox.index',['status'=>'ai']) }}">AI handling</a></div></div>
@if(session('success'))<div class="panel" style="margin:14px 0;color:#267244;padding:12px">✓ {{ session('success') }}</div>@endif
@if(session('error'))<div class="panel" style="margin:14px 0;color:#9a3d25;background:#fff2ed;padding:12px">{{ session('error') }}</div>@endif
<div class="inbox-layout{{ request()->filled('conversation') ? ' has-mobile-selection' : '' }}" style="display:grid;grid-template-columns:340px minmax(0,1fr) 300px;grid-template-rows:minmax(0,1fr);gap:1px;background:var(--line);border:1px solid var(--line);border-radius:18px 18px 0 0;overflow:hidden;height:calc(100vh - 150px);min-height:600px;margin-top:20px">
<section class="inbox-list" style="background:white;padding:12px;overflow:auto">@forelse($conversations as $conversation)<a class="inbox-thread" href="{{ route('inbox.index',['conversation'=>$conversation->id,'status'=>$status]) }}" style="display:block;padding:14px;border-radius:13px;background:{{ $selected?->id===$conversation->id?'#f0f5ee':'white' }};margin-bottom:4px"><div style="display:flex;justify-content:space-between"><strong>{{ $conversation->customer_name ?? 'Visitor' }}</strong><span class="channel">{{ $conversation->last_message_at?->diffForHumans() }}</span></div><p style="font-size:12px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ $conversation->messages->last()?->content }}</p><span class="pill">{{ $conversation->status==='human'?'Needs human':($conversation->status==='ai'?'Legatus handling':'Closed') }}</span> <span class="channel">· {{ ucfirst($conversation->channel) }}</span> @if(str_starts_with($conversation->visitor_id, 'demo-'))<span class="tag" style="padding:3px 6px">Simulated demo</span>@endif</a>@empty<div style="padding:30px;text-align:center;color:var(--muted)">No customer conversations in this view.</div>@endforelse</section>
<section class="inbox-workspace" id="conversation-workspace" data-status="{{ $selected?->status }}" data-assigned="{{ $selected?->assigned_to }}" style="min-width:0;min-height:0;overflow:hidden;background:#f7f8f5;display:flex;flex-direction:column">@if($selected)<a class="inbox-mobile-back" href="{{ route('inbox.index',['status'=>$status]) }}">← All conversations</a><header class="inbox-conversation-header" style="flex:0 0 auto;background:white;padding:16px 20px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center"><div class="inbox-conversation-identity"><strong>{{ $selected->customer_name ?? 'Visitor' }}</strong><div class="channel">{{ ucfirst($selected->channel) }} · {{ $selected->intent ?? 'Discovering intent' }} @if(str_starts_with($selected->visitor_id, 'demo-'))· <span class="tag" style="padding:3px 6px">Simulated demo</span>@endif</div></div><div class="inbox-conversation-actions" style="display:flex;gap:7px">@if($selected->status!=='human')<form method="post" action="{{ route('inbox.take-over',$selected) }}">@csrf<button class="btn">Pause AI & take over</button></form>@else<span class="tag" id="conversation-state"><span class="dot"></span>AI paused · {{ $selected->assigned_to ?? 'Waiting for operator' }}</span>@endif<form method="post" action="{{ route('inbox.close',$selected) }}">@csrf<button class="btn ghost">Close</button></form></div></header>
<div id="operator-messages" style="flex:1;min-height:0;overflow:auto;padding:24px"><div id="operator-message-list">@foreach($selected->messages as $message)@php($deliveryStatus = $message->channelMessage?->direction === 'outbound' ? $message->channelMessage->status : null)<div class="bubble {{ $message->role==='customer'?'user':'ai' }}" data-message-id="{{ $message->id }}" @if($message->role==='human') style="border-left:3px solid var(--lime)" @endif><small style="display:block;color:var(--muted);margin-bottom:4px">{{ $message->role==='human'?'Human operator':ucfirst($message->role) }} @if($message->confidence)· {{ round($message->confidence*100) }}% confidence @endif</small>{{ $message->content }}@if($message->role==='assistant' && count($message->metadata['sources'] ?? []))<div style="display:flex;gap:5px;flex-wrap:wrap;margin-top:8px">@foreach($message->metadata['sources'] as $source)<span class="tag" style="padding:4px 7px">{{ $source['label'] ?? 'Verified source' }}</span>@endforeach</div>@endif @if($deliveryStatus)<small class="delivery-state" data-delivery-status="{{ $deliveryStatus }}" style="display:block;color:var(--muted);margin-top:7px">{{ ['queued'=>'Queued','sending'=>'Sending','retrying'=>'Retry scheduled','sent'=>'Sent','delivered'=>'Delivered','read'=>'Read','failed'=>'Not delivered','delivery_unknown'=>'Delivery uncertain'][$deliveryStatus] ?? ucfirst(str_replace('_',' ',$deliveryStatus)) }}</small>@if($deliveryStatus==='failed' || $deliveryStatus==='delivery_unknown')<small class="delivery-warning" style="display:block;color:#9a5a12;margin-top:4px">{{ $deliveryStatus==='failed' ? 'Not delivered. Verify the channel connection before replying again.' : 'Delivery is uncertain. Check the native Meta inbox before resending.' }}</small>@endif @endif</div>@endforeach</div></div>
<footer class="operator-composer" id="operator-composer" style="flex:0 0 auto;background:white;padding:14px;border-top:1px solid var(--line)">@if($selected->status==='human')<form class="operator-reply-form" method="post" action="{{ route('inbox.reply',$selected) }}" style="display:flex;gap:8px">@csrf<input id="operator-reply" name="message" required placeholder="Reply as a human operator..."><button class="btn">Send ↑</button></form><form method="post" action="{{ route('inbox.release',$selected) }}" style="text-align:center;margin-top:9px">@csrf<button style="border:0;background:none;color:var(--green);cursor:pointer">↻ Resume AI</button></form>@else<div style="text-align:center;color:var(--muted);font-size:13px">Legatus is handling this conversation. Pause AI to reply yourself.</div>@endif</footer>
@else<div style="display:grid;place-items:center;height:100%;color:var(--muted)">Select a conversation</div>@endif</section>
<aside class="inbox-context" style="background:white;padding:20px">@if($selected)<span class="eyebrow">Handoff brief</span><h3 style="margin-bottom:8px">What you need to know</h3><p style="font-size:13px;color:var(--muted);line-height:1.6">{{ $selected->handoff_summary ?? 'Legatus is still handling this conversation. A concise brief appears here when human judgment is needed.' }}</p>@if($selected->handoff_reason)<div class="panel" style="padding:12px;background:#fff7e8;font-size:12px"><b>Escalation reason</b><br>{{ $selected->handoff_reason }}</div>@endif
@if($selected->suggested_reply)<div class="panel" style="padding:12px;background:#f2f5ff;font-size:12px;margin-top:10px"><b>Suggested reply</b><p id="suggested-copy" style="line-height:1.5">{{ $selected->suggested_reply }}</p>@if($selected->status==='human')<button class="btn ghost" id="use-suggested-reply" type="button" style="padding:7px 10px">Use reply</button>@endif</div>@endif
<h3 style="margin-top:25px">Customer context</h3>@if($selected->lead)<p class="channel">Consented contact · retained until {{ $selected->lead->retention_until?->toDateString() }}</p><b>{{ $selected->lead->name ?? 'Lead' }}</b><div style="font-size:12px;color:var(--muted)">{{ $selected->lead->email ?? $selected->lead->phone ?? 'No contact field' }}</div>@endif<p class="channel">Intent</p><b>{{ $selected->intent ?? 'Unknown' }}</b><p class="channel">Outcome</p><b>{{ $selected->outcome ? str_replace('_',' ',$selected->outcome) : 'In progress' }}</b>@if($selected->outcome_value>0)<span class="channel"> · {{ number_format($selected->outcome_value,2) }} ₾</span>@endif<p class="channel">Priority</p><b>{{ ucfirst($selected->priority) }}</b><p class="channel">Messages</p><b>{{ $selected->messages->count() }}</b><h3 style="margin-top:25px">AI trace</h3>@foreach(($selected->messages->where('role','assistant')->last()?->metadata['tools_used']??[]) as $tool)<span class="tag" style="margin:3px">{{ $tool }}</span>@endforeach
@endif</aside></div></main></div>
@if($selected)
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
    document.querySelector('#use-suggested-reply')?.addEventListener('click', () => {
        const reply = document.querySelector('#operator-reply');
        reply.value = document.querySelector('#suggested-copy').textContent;
        reply.focus();
    });
    const messagePane = document.querySelector('#operator-messages');
    const messageList = document.querySelector('#operator-message-list');
    const workspace = document.querySelector('#conversation-workspace');
    const pollUrl = @json(route('inbox.poll', $selected));
    const deliveryLabels = {queued: 'Queued', sending: 'Sending', retrying: 'Retry scheduled', sent: 'Sent', delivered: 'Delivered', read: 'Read', failed: 'Not delivered', delivery_unknown: 'Delivery uncertain'};

    const updateProducts = (bubble, message) => {
        bubble.querySelector('.linked-products')?.remove();
        if (message.role !== 'assistant' || !Array.isArray(message.products) || !message.products.length) return;
        const products = document.createElement('div');
        products.className = 'linked-products';
        products.style.cssText = 'display:grid;gap:6px;margin-top:10px';
        message.products.forEach((product) => {
            const card = document.createElement('div');
            card.style.cssText = 'border:1px solid var(--line);border-radius:10px;padding:8px 10px;background:white';
            const name = document.createElement('strong');
            name.style.display = 'block';
            name.textContent = product.name || 'Product';
            card.append(name);
            if (Number.isFinite(Number(product.price))) {
                const price = document.createElement('span');
                price.style.cssText = 'font-size:12px;color:var(--muted)';
                price.textContent = `${Number(product.price).toFixed(2)} ₾`;
                card.append(price);
            }
            try {
                const url = new URL(product.url);
                if (url.protocol === 'https:' || url.protocol === 'http:') {
                    const link = document.createElement('a');
                    link.href = url.href;
                    link.target = '_blank';
                    link.rel = 'noopener noreferrer';
                    link.textContent = 'Open product ↗';
                    link.style.cssText = 'display:inline-block;margin-left:8px;font-size:12px;color:var(--green);font-weight:700';
                    card.append(link);
                }
            } catch (_) {}
            products.append(card);
        });
        bubble.append(products);
    };

    const updateDelivery = (bubble, message) => {
        let state = bubble.querySelector('.delivery-state');
        let warning = bubble.querySelector('.delivery-warning');
        if (!message.delivery_status) {
            state?.remove();
            warning?.remove();
            return;
        }
        if (!state) {
            state = document.createElement('small');
            state.className = 'delivery-state';
            state.style.cssText = 'display:block;color:var(--muted);margin-top:7px';
            bubble.append(state);
        }
        state.dataset.deliveryStatus = message.delivery_status;
        state.textContent = deliveryLabels[message.delivery_status] || message.delivery_status.replaceAll('_', ' ');
        if (message.delivery_warning) {
            if (!warning) {
                warning = document.createElement('small');
                warning.className = 'delivery-warning';
                warning.style.cssText = 'display:block;color:#9a5a12;margin-top:4px';
                bubble.append(warning);
            }
            warning.textContent = message.delivery_warning;
        } else {
            warning?.remove();
        }
    };

    const addMessage = (message) => {
        const existing = messageList.querySelector(`[data-message-id="${message.id}"]`);
        if (existing) {
            updateProducts(existing, message);
            updateDelivery(existing, message);
            return false;
        }
        const bubble = document.createElement('div');
        bubble.className = `bubble ${message.role === 'customer' ? 'user' : 'ai'}`;
        bubble.dataset.messageId = message.id;
        if (message.role === 'human') bubble.style.borderLeft = '3px solid var(--lime)';

        const byline = document.createElement('small');
        byline.style.cssText = 'display:block;color:var(--muted);margin-bottom:4px';
        const role = message.role === 'human' ? 'Human operator' : message.role.charAt(0).toUpperCase() + message.role.slice(1);
        byline.textContent = role + (message.confidence ? ` · ${Math.round(message.confidence * 100)}% confidence` : '');
        bubble.append(byline, document.createTextNode(message.content));
        updateProducts(bubble, message);

        if (message.role === 'assistant' && Array.isArray(message.sources) && message.sources.length) {
            const sources = document.createElement('div');
            sources.style.cssText = 'display:flex;gap:5px;flex-wrap:wrap;margin-top:8px';
            message.sources.forEach((source) => {
                const tag = document.createElement('span');
                tag.className = 'tag';
                tag.style.padding = '4px 7px';
                tag.textContent = source.label || 'Verified source';
                sources.append(tag);
            });
            bubble.append(sources);
        }

        updateDelivery(bubble, message);
        messageList.append(bubble);
        return true;
    };

    const pollConversation = async () => {
        if (document.hidden) return;
        try {
            const response = await fetch(pollUrl, {headers: {'Accept': 'application/json'}});
            if (!response.ok) return;
            const data = await response.json();
            if (workspace.dataset.status !== data.status || workspace.dataset.assigned !== (data.assigned_to || '')) {
                window.location.reload();
                return;
            }
            let added = false;
            data.messages.forEach((message) => { added = addMessage(message) || added; });
            if (added) messagePane.scrollTop = messagePane.scrollHeight;
            document.title = (data.status === 'human' ? '● ' : '') + 'Inbox · Legatus';
        } catch (_) {
            // A transient polling failure must never interrupt the operator's reply.
        }
    };

    messagePane.scrollTop = messagePane.scrollHeight;
    pollConversation();
    setInterval(pollConversation, 5000);
</script>
@endif
@endsection
