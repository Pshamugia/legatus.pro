<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Support conversations · Legatus</title>
    <style nonce="{{ request()->attributes->get('csp_nonce') }}">
        :root{--ink:#13231e;--green:#153c30;--muted:#697872;--line:#e1e8e3;--lime:#d9ff72;--bg:#f4f7f3;--soft:#f8faf7}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.shell{max-width:1500px;margin:auto;padding:28px}.top{display:flex;align-items:center;gap:16px;margin-bottom:20px}.brand{display:flex;align-items:center;gap:10px;font-weight:800}.mark{display:grid;place-items:center;width:38px;height:38px;border-radius:12px;background:var(--green);color:var(--lime)}h1{margin:0;font-size:27px}.top p{margin:4px 0 0;color:var(--muted);font-size:13px}.actions{display:flex;gap:8px;margin-left:auto}.btn{display:inline-flex;align-items:center;border:1px solid var(--line);border-radius:11px;background:#fff;padding:10px 13px;color:var(--ink);font-weight:700;text-decoration:none}.metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:16px}.metric{padding:17px;background:#fff;border:1px solid var(--line);border-radius:15px}.metric span{display:block;color:var(--muted);font-size:12px}.metric strong{display:block;margin-top:6px;font-size:24px}.filters{display:flex;align-items:center;gap:8px;margin-bottom:14px}.filters a{padding:8px 11px;border:1px solid var(--line);border-radius:99px;background:#fff;color:var(--ink);font-size:12px;font-weight:700;text-decoration:none}.filters a.active{background:var(--green);border-color:var(--green);color:#fff}.search{display:flex;gap:7px;margin-left:auto}.search input{width:280px;border:1px solid var(--line);border-radius:10px;padding:9px 11px;font:inherit}.search button{border:0;border-radius:10px;background:var(--green);color:#fff;padding:9px 13px;font-weight:800}.workspace{display:grid;grid-template-columns:360px minmax(0,1fr);grid-template-rows:minmax(0,1fr);height:calc(100vh - 240px);min-height:650px;overflow:hidden;background:var(--line);border:1px solid var(--line);border-radius:18px}.list{min-height:0;padding:10px;background:#fff;overflow-y:auto}.item{display:block;padding:14px;border-radius:13px;color:var(--ink);text-decoration:none;margin-bottom:4px}.item:hover,.item.active{background:#eef4ef}.item-head{display:flex;justify-content:space-between;gap:12px}.item strong{font-size:13px}.item time,.meta{color:var(--muted);font-size:11px}.preview{margin:7px 0;color:#56655f;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.pill{display:inline-flex;padding:4px 7px;border-radius:99px;background:#edf2ee;color:#496258;font-size:10px;font-weight:800}.pill.human{background:#fff0e9;color:#994c34}.thread{display:flex;min-width:0;min-height:0;flex-direction:column;overflow:hidden;background:var(--soft)}.thread-header{flex:0 0 auto;padding:17px 20px;border-bottom:1px solid var(--line);background:#fff}.thread-header strong{display:block}.messages{flex:1;min-height:0;padding:22px;overflow-y:auto;overscroll-behavior:contain}.message{max-width:78%;margin:0 0 12px;padding:12px 14px;border:1px solid var(--line);border-radius:15px;background:#fff;white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.55;font-size:14px}.message.customer{margin-left:auto;background:var(--green);border-color:var(--green);color:#fff}.message.system{max-width:100%;background:#fff7dc;color:#725914}.message small{display:block;margin-bottom:5px;color:var(--muted);font-size:10px;font-weight:800;text-transform:uppercase}.message.customer small{color:#dbe9e2}.feedback{display:inline-block;margin-top:7px;padding:4px 7px;border-radius:8px;background:#eef4ef;color:#3e6554;font-size:10px}.empty{display:grid;place-items:center;min-height:300px;padding:30px;color:var(--muted);text-align:center}.pagination{padding:10px}.pagination nav>div:first-child{display:none}.pagination nav>div:last-child{display:flex;justify-content:space-between;align-items:center;gap:10px}.pagination svg{width:18px}.pagination a,.pagination span{font-size:11px;color:var(--ink)}
        @media(max-width:850px){.shell{padding:15px}.top{align-items:flex-start;flex-wrap:wrap}.actions{margin-left:0}.metrics{grid-template-columns:repeat(3,1fr)}.filters{align-items:stretch;flex-wrap:wrap}.search{width:100%;margin-left:0}.search input{min-width:0;flex:1}.workspace{display:block;height:auto;min-height:0}.list{max-height:420px}.thread{height:70vh;min-height:500px}.message{max-width:92%}}
    </style>
</head>
<body><main class="shell">
    <header class="top"><div class="brand"><span class="mark">L</span>Legatus</div><div><h1>Support conversations</h1><p>Messages sent to the Legatus website assistant</p></div><div class="actions"><a class="btn" href="{{ route('super-admin.index') }}">← Super Admin</a><a class="btn" href="{{ route('onboarding') }}">Workspace →</a></div></header>
    <section class="metrics"><div class="metric"><span>All conversations</span><strong>{{ number_format($metrics['total']) }}</strong></div><div class="metric"><span>Started today</span><strong>{{ number_format($metrics['today']) }}</strong></div><div class="metric"><span>Needs human</span><strong>{{ number_format($metrics['needs_human']) }}</strong></div></section>
    <div class="filters">
        @foreach(['' => 'All', 'ai' => 'AI handling', 'human' => 'Needs human', 'closed' => 'Closed'] as $value => $label)<a class="{{ $status === $value ? 'active' : '' }}" href="{{ route('super-admin.support-conversations', array_filter(['status' => $value, 'search' => $search])) }}">{{ $label }}</a>@endforeach
        <form class="search" method="get"><input type="hidden" name="status" value="{{ $status }}"><input name="search" value="{{ $search }}" placeholder="Search message text"><button>Search</button></form>
    </div>
    <section class="workspace">
        <aside class="list">
            @forelse($conversations ?? [] as $conversation)
                @php($latest = $conversation->messages->first())
                <a class="item {{ $selected?->id === $conversation->id ? 'active' : '' }}" href="{{ route('super-admin.support-conversations', array_filter(['conversation' => $conversation->id, 'status' => $status, 'search' => $search])) }}">
                    <div class="item-head"><strong>Conversation #{{ $conversation->id }}</strong><time>{{ ($conversation->last_message_at ?? $conversation->created_at)?->diffForHumans() }}</time></div>
                    <p class="preview">{{ $latest?->content ?? 'No messages yet' }}</p>
                    <span class="pill {{ $conversation->status }}">{{ $conversation->status === 'human' ? 'Needs human' : ($conversation->status === 'closed' ? 'Closed' : 'AI handling') }}</span>
                    <span class="meta"> · {{ ucfirst($conversation->channel) }} · {{ $conversation->messages_count }} messages</span>
                </a>
            @empty
                <div class="empty">{{ $agent ? 'No support conversations match this view.' : 'The Legatus support assistant has not been initialized yet.' }}</div>
            @endforelse
            @if($conversations)<div class="pagination">{{ $conversations->links() }}</div>@endif
        </aside>
        <article class="thread">
            @if($selected)
                <header class="thread-header"><strong>Conversation #{{ $selected->id }}</strong><span class="meta">{{ ucfirst($selected->channel) }} · {{ ucfirst($selected->status) }} · started {{ $selected->created_at?->timezone(config('app.timezone'))->format('d M Y, H:i') }}</span></header>
                <div class="messages" data-scroll-region="conversation-messages">
                    @forelse($selected->messages as $message)
                        <div class="message {{ $message->role }}"><small>{{ $message->role === 'customer' ? 'Visitor' : ($message->role === 'assistant' ? 'Legatus' : ucfirst($message->role)) }} · {{ $message->created_at?->timezone(config('app.timezone'))->format('d M, H:i') }}</small>{{ $message->content }}@if($message->feedback)<span class="feedback">{{ $message->feedback === 'helpful' ? '👍 Helpful' : '👎 Not helpful' }}</span>@endif</div>
                    @empty<div class="empty">This conversation has no messages.</div>@endforelse
                </div>
            @else<div class="empty">Select a conversation to read its full history.</div>@endif
        </article>
    </section>
</main></body></html>
