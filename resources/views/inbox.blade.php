@extends('layouts.app')
@section('title','Inbox · Legatus')
@section('body')
<div class="dash-shell">
<aside class="side"><a class="brand" href="{{ route('landing') }}"><span class="mark">L</span> legatus</a><div class="menu"><a href="{{ route('dashboard') }}">◫ &nbsp; Overview</a><a class="active" href="{{ route('inbox.index') }}">◌ &nbsp; Inbox @if($needsHumanCount)<span class="pill">{{ $needsHumanCount }}</span>@endif</a><a href="{{ route('knowledge.index') }}">◇ &nbsp; Knowledge</a><a href="{{ route('dashboard') }}#products">▦ &nbsp; Products</a><a href="{{ route('channels.index') }}">⌁ &nbsp; Channels</a><a href="{{ route('analytics.index') }}">↗ &nbsp; Analytics</a><a href="{{ route('settings.index') }}">⚙ &nbsp; Settings</a></div><div class="side-bottom"><b style="color:white">Human in control</b><br>Low confidence, policy exceptions and approval limits are escalated automatically.</div></aside>
<main class="main" style="padding-bottom:0"><div class="topline"><div><span class="eyebrow">Omnichannel workspace</span><h1>Conversation inbox</h1></div><div style="display:flex;gap:8px"><a class="tag" href="{{ route('inbox.index') }}">All</a><a class="tag" href="{{ route('inbox.index',['status'=>'human']) }}">Needs human</a><a class="tag" href="{{ route('inbox.index',['status'=>'ai']) }}">AI handling</a></div></div>
@if(session('success'))<div class="panel" style="margin:14px 0;color:#267244;padding:12px">✓ {{ session('success') }}</div>@endif
@if(session('error'))<div class="panel" style="margin:14px 0;color:#9a3d25;background:#fff2ed;padding:12px">{{ session('error') }}</div>@endif
<div style="display:grid;grid-template-columns:340px 1fr 300px;gap:1px;background:var(--line);border:1px solid var(--line);border-radius:18px 18px 0 0;overflow:hidden;min-height:calc(100vh - 115px);margin-top:20px">
<section style="background:white;padding:12px;overflow:auto">@forelse($conversations as $conversation)<a href="{{ route('inbox.index',['conversation'=>$conversation->id,'status'=>$status]) }}" style="display:block;padding:14px;border-radius:13px;background:{{ $selected?->id===$conversation->id?'#f0f5ee':'white' }};margin-bottom:4px"><div style="display:flex;justify-content:space-between"><strong>{{ $conversation->customer_name ?? 'Visitor' }}</strong><span class="channel">{{ $conversation->last_message_at?->diffForHumans() }}</span></div><p style="font-size:12px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ $conversation->messages->last()?->content }}</p><span class="pill">{{ $conversation->status==='human'?'Needs human':($conversation->status==='ai'?'Legatus handling':'Closed') }}</span> <span class="channel">· {{ ucfirst($conversation->channel) }}</span> @if(str_starts_with($conversation->visitor_id, 'demo-'))<span class="tag" style="padding:3px 6px">Simulated demo</span>@endif</a>@empty<div style="padding:30px;text-align:center;color:var(--muted)">No customer conversations in this view.</div>@endforelse</section>
<section id="conversation-workspace" data-status="{{ $selected?->status }}" data-assigned="{{ $selected?->assigned_to }}" style="background:#f7f8f5;display:flex;flex-direction:column">@if($selected)<header style="background:white;padding:16px 20px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center"><div><strong>{{ $selected->customer_name ?? 'Visitor' }}</strong><div class="channel">{{ ucfirst($selected->channel) }} · {{ $selected->intent ?? 'Discovering intent' }} @if(str_starts_with($selected->visitor_id, 'demo-'))· <span class="tag" style="padding:3px 6px">Simulated demo</span>@endif</div></div><div style="display:flex;gap:7px">@if($selected->status!=='human')<form method="post" action="{{ route('inbox.take-over',$selected) }}">@csrf<button class="btn">Take over</button></form>@else<span class="tag" id="conversation-state"><span class="dot"></span>{{ $selected->assigned_to ?? 'Waiting for operator' }}</span>@endif<form method="post" action="{{ route('inbox.close',$selected) }}">@csrf<button class="btn ghost">Close</button></form></div></header>
<div id="operator-messages" style="flex:1;overflow:auto;padding:24px">@foreach($selected->messages as $message)@php($deliveryStatus = $message->channelMessage?->direction === 'outbound' ? $message->channelMessage->status : null)<div class="bubble {{ $message->role==='customer'?'user':'ai' }}" data-message-id="{{ $message->id }}" @if($message->role==='human') style="border-left:3px solid var(--lime)" @endif><small style="display:block;color:var(--muted);margin-bottom:4px">{{ $message->role==='human'?'Human operator':ucfirst($message->role) }} @if($message->confidence)· {{ round($message->confidence*100) }}% confidence @endif</small>{{ $message->content }}@if($message->role==='assistant' && count($message->metadata['sources'] ?? []))<div style="display:flex;gap:5px;flex-wrap:wrap;margin-top:8px">@foreach($message->metadata['sources'] as $source)<span class="tag" style="padding:4px 7px">{{ $source['label'] ?? 'Verified source' }}</span>@endforeach</div>@endif @if($deliveryStatus)<small class="delivery-state" data-delivery-status="{{ $deliveryStatus }}" style="display:block;color:var(--muted);margin-top:7px">{{ ['queued'=>'Queued','sending'=>'Sending','retrying'=>'Retry scheduled','sent'=>'Sent','delivered'=>'Delivered','read'=>'Read','failed'=>'Not delivered','delivery_unknown'=>'Delivery uncertain'][$deliveryStatus] ?? ucfirst(str_replace('_',' ',$deliveryStatus)) }}</small>@if($deliveryStatus==='failed' || $deliveryStatus==='delivery_unknown')<small class="delivery-warning" style="display:block;color:#9a5a12;margin-top:4px">{{ $deliveryStatus==='failed' ? 'Not delivered. Verify the channel connection before replying again.' : 'Delivery is uncertain. Check the native Meta inbox before resending.' }}</small>@endif @endif</div>@endforeach</div>
<footer style="background:white;padding:14px;border-top:1px solid var(--line)">@if($selected->status==='human')<form method="post" action="{{ route('inbox.reply',$selected) }}" style="display:flex;gap:8px">@csrf<input id="operator-reply" name="message" required placeholder="Reply as a human operator..."><button class="btn">Send ↑</button></form><form method="post" action="{{ route('inbox.release',$selected) }}" style="text-align:center;margin-top:9px">@csrf<button style="border:0;background:none;color:var(--green);cursor:pointer">↻ Return conversation to Legatus</button></form>@else<div style="text-align:center;color:var(--muted);font-size:13px">Legatus is handling this conversation. Take over to reply.</div>@endif</footer>
@else<div style="display:grid;place-items:center;height:100%;color:var(--muted)">Select a conversation</div>@endif</section>
<aside style="background:white;padding:20px">@if($selected)<span class="eyebrow">Handoff brief</span><h3 style="margin-bottom:8px">What you need to know</h3><p style="font-size:13px;color:var(--muted);line-height:1.6">{{ $selected->handoff_summary ?? 'Legatus is still handling this conversation. A concise brief appears here when human judgment is needed.' }}</p>@if($selected->handoff_reason)<div class="panel" style="padding:12px;background:#fff7e8;font-size:12px"><b>Escalation reason</b><br>{{ $selected->handoff_reason }}</div>@endif
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
    const workspace = document.querySelector('#conversation-workspace');
    const pollUrl = @json(route('inbox.poll', $selected));
    const deliveryLabels = {queued: 'Queued', sending: 'Sending', retrying: 'Retry scheduled', sent: 'Sent', delivered: 'Delivered', read: 'Read', failed: 'Not delivered', delivery_unknown: 'Delivery uncertain'};

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
        const existing = messagePane.querySelector(`[data-message-id="${message.id}"]`);
        if (existing) {
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
        messagePane.append(bubble);
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
    setInterval(pollConversation, 5000);
</script>
@endif
@endsection
