<!doctype html>
<html lang="ka">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Super Admin · Legatus</title>
    <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{--ink:#13231e;--green:#153c30;--muted:#697872;--line:#e1e8e3;--lime:#d9ff72;--bg:#f4f7f3}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font-family:'DM Sans',sans-serif}.shell{max-width:1600px;margin:auto;padding:28px}.top{display:flex;align-items:center;gap:18px;margin-bottom:26px}.brand{display:flex;align-items:center;gap:10px;font:800 19px Manrope}.mark{display:grid;place-items:center;width:38px;height:38px;border-radius:12px;background:var(--green);color:var(--lime)}.top h1{margin:0;font:800 28px Manrope}.top p{margin:3px 0;color:var(--muted);font-size:12px}.actions{margin-left:auto;display:flex;gap:9px}.btn{display:inline-flex;align-items:center;border:1px solid var(--line);border-radius:11px;background:#fff;padding:10px 13px;color:var(--ink);font-weight:700;text-decoration:none;cursor:pointer}.metrics{display:grid;grid-template-columns:repeat(6,1fr);gap:13px;margin-bottom:18px}.metric,.panel{background:#fff;border:1px solid var(--line);border-radius:17px}.metric{padding:18px}.metric span{display:block;color:var(--muted);font-size:11px}.metric strong{display:block;margin-top:8px;font:800 27px Manrope}.notice{padding:12px 16px;margin-bottom:14px;border-radius:12px;background:#e8f7df;color:#367048;font-weight:700}.panel{overflow:hidden}.toolbar{display:flex;align-items:center;gap:14px;padding:17px;border-bottom:1px solid var(--line)}.toolbar h2{margin:0;font:700 17px Manrope}.env{padding:5px 9px;border-radius:99px;background:#edf4ef;color:#46675b;font-size:10px;font-weight:800;text-transform:uppercase}.search{margin-left:auto;display:flex;gap:7px}.search input{width:270px;border:1px solid var(--line);border-radius:10px;padding:10px 12px;font:inherit}.search button,.grant-form button{border:0;border-radius:9px;background:var(--green);color:white;padding:9px 12px;font-weight:700;cursor:pointer}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:1450px}th,td{padding:14px 15px;border-bottom:1px solid #edf1ee;text-align:left;vertical-align:top}th{background:#fafcf9;color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.05em}td{font-size:12px}.business strong,.owner strong{display:block;font-size:13px}.business small,.owner small,.subtext{display:block;margin-top:4px;color:var(--muted);font-size:10px}.badge{display:inline-flex;align-items:center;padding:6px 9px;border-radius:99px;font-size:10px;font-weight:800}.active,.complimentary{background:#e8f7df;color:#367048}.trialing{background:#eef4ff;color:#40638d}.attention{background:#fff0e9;color:#994c34}.none{background:#edf0ee;color:#69756f}.package{font-weight:800}.grant-form{display:grid;grid-template-columns:120px 165px auto;gap:6px}.grant-form select,.grant-form input{min-width:0;border:1px solid var(--line);border-radius:8px;padding:8px;font:inherit}.revoke{margin-top:7px;border:0;background:transparent;color:#a14b35;font-size:10px;font-weight:800;cursor:pointer;padding:0}.pagination{padding:15px}.empty{padding:55px;text-align:center;color:var(--muted)}@media(max-width:900px){.metrics{grid-template-columns:repeat(2,1fr)}.shell{padding:16px}.top{align-items:flex-start;flex-wrap:wrap}.actions{margin-left:0}.search{margin-left:0;width:100%}.search input{width:100%}}
    </style>
</head>
<body><main class="shell">
    <header class="top"><div class="brand"><span class="mark">L</span>Legatus</div><div><h1>Super Admin</h1><p>ბიზნესები, პაკეტები და გადახდის მდგომარეობა</p></div><div class="actions"><a class="btn" href="{{ route('dashboard') }}">Workspace →</a><form method="post" action="{{ route('logout') }}">@csrf<button class="btn">Log out</button></form></div></header>
    @if(session('status'))<div class="notice">{{ session('status') }}</div>@endif
    <section class="metrics">
        <div class="metric"><span>სულ ბიზნესი</span><strong>{{ number_format($metrics['businesses']) }}</strong></div>
        <div class="metric"><span>ამ თვეში ახალი</span><strong>{{ number_format($metrics['new_this_month']) }}</strong></div>
        <div class="metric"><span>აქტიური</span><strong>{{ number_format($metrics['active']) }}</strong></div>
        <div class="metric"><span>საცდელ პერიოდში</span><strong>{{ number_format($metrics['trialing']) }}</strong></div>
        <div class="metric"><span>უფასო წვდომა</span><strong>{{ number_format($metrics['complimentary']) }}</strong></div>
        <div class="metric"><span>საჭიროებს ყურადღებას</span><strong>{{ number_format($metrics['attention']) }}</strong></div>
    </section>
    <section class="panel"><div class="toolbar"><h2>რეგისტრირებული ბიზნესები</h2><span class="env">{{ $environment }}</span><form class="search" method="get"><input name="search" value="{{ $search }}" placeholder="ბიზნესი, მფლობელი ან ელფოსტა"><button>ძებნა</button></form></div>
        <div class="table-wrap"><table><thead><tr><th>ბიზნესი</th><th>მფლობელი</th><th>რეგისტრაცია</th><th>პაკეტი</th><th>სტატუსი</th><th>უფასო წვდომა</th><th>საცდელი პერიოდი</th><th>გადახდის ვადა</th><th>Paddle</th></tr></thead><tbody>
        @forelse($organizations as $organization)
            @php
                $owner = $organization->users->first(fn($user) => $user->pivot->role === 'owner') ?? $organization->users->first();
                $subscription = $organization->paddleSubscriptions->first();
                $grant = $organization->billingAccessGrants->first();
                $priceId = $subscription?->paddle_price_id;
                $package = !$subscription ? 'Not selected' : match($priceId) {
                    config('paddle.prices.monthly') => '$30 / month', config('paddle.prices.six_months') => '$162 / 6 months',
                    config('paddle.prices.yearly') => '$288 / year', default => 'Unknown price',
                };
                $expired = $subscription && (!in_array($subscription->status, ['active','trialing'], true)
                    || ($subscription->status === 'active' && $subscription->current_period_ends_at?->isPast())
                    || ($subscription->status === 'trialing' && $subscription->trial_ends_at?->isPast()));
                $badge = !$subscription ? 'none' : ($expired ? 'attention' : $subscription->status);
                $statusLabel = !$subscription ? 'No subscription' : ($expired ? 'Expired' : ucfirst(str_replace('_',' ',$subscription->status)));
            @endphp
            <tr>
                <td class="business"><strong>{{ $organization->name }}</strong><small>{{ $organization->agents->first()?->business_name ?: $organization->slug }}</small></td>
                <td class="owner"><strong>{{ $owner?->name ?? '—' }}</strong><small>{{ $owner?->email ?? '—' }}</small></td>
                <td>{{ $organization->created_at?->timezone('Asia/Tbilisi')->format('d M Y, H:i') }}</td>
                <td><span class="package">{{ $package }}</span></td>
                <td><span class="badge {{ $badge }}">{{ $statusLabel }}</span>@if($subscription?->scheduled_change_action)<span class="subtext">Scheduled: {{ $subscription->scheduled_change_action }}</span>@endif</td>
                <td>
                    @if($grant)
                        <span class="badge complimentary">Complimentary</span><span class="subtext">{{ $grant->expires_at?->timezone('Asia/Tbilisi')->format('d M Y, H:i') ?? 'Lifetime' }}</span>
                        @if($grant->reason)<span class="subtext">{{ $grant->reason }}</span>@endif
                        <form method="post" action="{{ route('super-admin.access.revoke', $organization) }}">@csrf @method('delete')<button class="revoke">Revoke free access</button></form>
                    @else
                        <form class="grant-form" method="post" action="{{ route('super-admin.access.grant', $organization) }}">@csrf
                            <select name="duration" aria-label="Free access duration"><option value="1_month">1 month</option><option value="3_months">3 months</option><option value="6_months">6 months</option><option value="12_months">1 year</option><option value="lifetime">Lifetime</option></select>
                            <input name="reason" maxlength="500" placeholder="Reason (optional)"><button>Grant</button>
                        </form>
                    @endif
                </td>
                <td>{{ $subscription?->trial_ends_at?->timezone('Asia/Tbilisi')->format('d M Y, H:i') ?? '—' }}</td>
                <td>{{ $subscription?->current_period_ends_at?->timezone('Asia/Tbilisi')->format('d M Y, H:i') ?? '—' }}@if($subscription?->current_period_ends_at?->isPast())<span class="subtext" style="color:#a14b35">ვადა გასულია</span>@endif</td>
                <td><span class="subtext">{{ $subscription?->paddle_subscription_id ?? '—' }}</span></td>
            </tr>
        @empty<tr><td colspan="9" class="empty">ბიზნესი ვერ მოიძებნა.</td></tr>@endforelse
        </tbody></table></div><div class="pagination">{{ $organizations->links() }}</div>
    </section>
</main></body></html>
