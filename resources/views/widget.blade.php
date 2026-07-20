<!doctype html>
<html lang="ka">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $agent->name }} · Legatus</title>
    <style>
        *{box-sizing:border-box}body{margin:0;font-family:Arial,sans-serif;color:#17352c;background:#f6f8f4}.shell{height:100vh;display:flex;flex-direction:column}.head{background:#173f33;color:white;padding:15px;display:flex;align-items:center;gap:10px}.avatar{width:40px;height:40px;background:#d9ff72;color:#173f33;border-radius:13px;display:grid;place-items:center;font-weight:800}.head div{flex:1}.head b,.head small{display:block}.head small{color:#b9d1c9;margin-top:2px}.close{border:0;background:#ffffff15;color:white;width:32px;height:32px;border-radius:10px;cursor:pointer}.messages{flex:1;overflow:auto;padding:17px}.bubble{max-width:86%;padding:11px 13px;border-radius:15px;margin:8px 0;font-size:13px;line-height:1.5;background:white;border:1px solid #e4e9e5}.bubble.user{margin-left:auto;background:#214d3e;color:white;border:0}.bubble.human{border-left:3px solid #5b8c77}.operator{display:block;color:#3c735e;font-size:10px;font-weight:700;margin-bottom:5px}.products{display:flex;gap:7px;overflow:auto;margin-top:8px}.product{display:block;min-width:135px;padding:9px;background:#eff4ed;border-radius:10px;color:inherit;text-decoration:none}.product[href]:hover{outline:2px solid #d1e3d5}.product b,.product small{display:block}.product small{margin-top:4px;color:#557166}.trace{margin-top:10px;padding-top:9px;border-top:1px solid #e5ebe6;color:#557166;font-size:10px}.trace-row{display:flex;align-items:flex-start;gap:5px;margin-top:5px}.trace-label{font-weight:700;white-space:nowrap}.chips{display:flex;flex-wrap:wrap;gap:4px}.chip{background:#eff5ef;border-radius:99px;padding:2px 6px}.suggest{display:flex;gap:6px;padding:8px 14px;overflow:auto}.suggest button{white-space:nowrap;border:1px solid #dce4de;background:white;border-radius:99px;padding:7px 9px;font-size:10px;cursor:pointer}.composer{display:flex;gap:7px;padding:12px;background:white;border-top:1px solid #e2e8e3}.composer input{min-width:0;flex:1;border:1px solid #dce4de;border-radius:12px;padding:11px}.composer button{border:0;background:#d9ff72;border-radius:12px;padding:0 14px;font-weight:700;cursor:pointer}.composer button:disabled{cursor:wait;opacity:.65}
    </style>
</head>
<body>
<div class="shell">
    <header class="head">
        <span class="avatar">{{ mb_strtoupper(mb_substr($agent->name, 0, 1)) }}</span>
        <div>
            <b>{{ $agent->name }} · {{ $agent->business_name }}</b>
            <small>● Online · AI shopping assistant · Powered by Legatus</small>
        </div>
        <button class="close" id="close-widget" type="button" aria-label="ჩატის დახურვა">×</button>
    </header>
    <main class="messages" id="messages" aria-live="polite">
        <div class="bubble">გამარჯობა! 👋 რას ეძებთ? გირჩევთ პროდუქტს თქვენი გემოვნების, ბიუჯეტისა და საჭიროების მიხედვით.</div>
    </main>
    <div class="suggest">
        <button type="button" data-q="30 ლარამდე საჩუქარს ვეძებ">🎁 საჩუქარი 30₾-მდე</button>
        <button type="button" data-q="მირჩიე იდუმალი თანამედროვე რომანი">✨ პერსონალური რჩევა</button>
        <button type="button" data-q="ხვალ მიწოდება შეიძლება?">🚚 მიწოდება</button>
    </div>
    <form class="composer" id="form">
        <input id="input" required autocomplete="off" placeholder="მომწერეთ..." aria-label="შეტყობინება">
        <button id="send" type="submit" aria-label="გაგზავნა">↑</button>
    </form>
</div>
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
    document.querySelector('#close-widget').addEventListener('click', () => parent.postMessage('legatus:close', '*'));
    const messageUrl = @json(route('chat.message', $agent));
    const historyUrl = @json(route('chat.history', $agent));
    const storageKey = 'legatus_widget_visitor_token_{{ $agent->slug }}';
    const requestDeadlineMs = 55000;
    let visitorToken = readToken();
    let cursor = 0;
    let sending = false;
    let polling = false;
    const seen = new Set();
    const box = document.querySelector('#messages');
    const input = document.querySelector('#input');
    const sendButton = document.querySelector('#send');

    function readToken() {
        try { return localStorage.getItem(storageKey); } catch { return null; }
    }

    function saveToken(value) {
        visitorToken = value;
        try { localStorage.setItem(storageKey, value); } catch {}
    }

    function clearToken() {
        visitorToken = null;
        cursor = 0;
        seen.clear();
        try { localStorage.removeItem(storageKey); } catch {}
    }

    function requestId() {
        if (crypto.randomUUID) return crypto.randomUUID();
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, character => {
            const random = Math.random() * 16 | 0;
            return (character === 'x' ? random : (random & 3 | 8)).toString(16);
        });
    }

    function appendChips(container, values) {
        const chips = document.createElement('span');
        chips.className = 'chips';
        values.forEach(value => {
            const chip = document.createElement('span');
            chip.className = 'chip';
            chip.textContent = String(value);
            chips.append(chip);
        });
        container.append(chips);
    }

    function addTrace(bubble, meta) {
        const sources = (meta.sources || []).map(source => {
            const label = source.label || source.name || source.title || source.type;
            const detail = source.reference ? ` · ${source.reference}` : (source.updated_at ? ' · recently synced' : '');
            return label ? label + detail : null;
        }).filter(Boolean);
        const tools = (meta.tools || []).map(tool => String(tool).replaceAll('_', ' '));
        const hasConfidence = Number.isFinite(Number(meta.confidence));

        if (!sources.length && !tools.length && !hasConfidence && !meta.reason) return;

        const trace = document.createElement('div');
        trace.className = 'trace';

        if (sources.length) {
            const row = document.createElement('div');
            row.className = 'trace-row';
            const label = document.createElement('span');
            label.className = 'trace-label';
            label.textContent = 'Grounded in:';
            row.append(label);
            appendChips(row, sources);
            trace.append(row);
        }

        if (hasConfidence) {
            const row = document.createElement('div');
            row.className = 'trace-row';
            row.textContent = `Confidence: ${Math.round(Math.max(0, Math.min(1, Number(meta.confidence))) * 100)}%`;
            trace.append(row);
        }

        if (tools.length) {
            const row = document.createElement('div');
            row.className = 'trace-row';
            const label = document.createElement('span');
            label.className = 'trace-label';
            label.textContent = 'Actions:';
            row.append(label);
            appendChips(row, tools);
            trace.append(row);
        }

        if (meta.reason) {
            const row = document.createElement('div');
            row.className = 'trace-row';
            row.textContent = `Escalation: ${meta.reason}`;
            trace.append(row);
        }

        bubble.append(trace);
    }

    function add(text, role, products = [], meta = {}) {
        const bubble = document.createElement('div');
        bubble.className = `bubble ${role}`;
        if (role === 'human') {
            const operator = document.createElement('span');
            operator.className = 'operator';
            operator.textContent = 'Human operator';
            bubble.append(operator);
        }
        const copy = document.createElement('span');
        copy.textContent = text;
        bubble.append(copy);

        if (products.length) {
            const row = document.createElement('div');
            row.className = 'products';
            products.forEach(product => {
                let productUrl = null;
                try {
                    const candidate = new URL(product.url);
                    if (candidate.protocol === 'https:' || candidate.protocol === 'http:') productUrl = candidate.href;
                } catch (error) {}
                const card = document.createElement(productUrl ? 'a' : 'div');
                const name = document.createElement('b');
                const detail = document.createElement('small');
                card.className = 'product';
                if (productUrl) {
                    card.href = productUrl;
                    card.target = '_blank';
                    card.rel = 'noopener noreferrer';
                }
                name.textContent = product.name || 'Product';
                detail.textContent = `${product.price ?? '—'} ₾ · ${product.stock ? 'მარაგშია' : 'ამოიწურა'}`;
                card.append(name, detail);
                row.append(card);
            });
            bubble.append(row);
        }

        if (role === 'ai') addTrace(bubble, meta);
        box.append(bubble);
        box.scrollTop = box.scrollHeight;
    }

    function renderMessage(message) {
        if (!message.id || seen.has(message.id)) return;
        seen.add(message.id);
        const role = message.role === 'customer' ? 'user' : message.role === 'human' ? 'human' : 'ai';
        add(message.text, role, message.products || [], {
            confidence: message.confidence,
            sources: message.sources || [],
            tools: message.tools_used || [],
            reason: message.escalation_reason || null,
        });
    }

    async function pollHistory() {
        if (!visitorToken || polling || sending) return;
        polling = true;
        try {
            const url = new URL(historyUrl, window.location.href);
            url.searchParams.set('after', String(cursor));
            const response = await fetch(url, {headers: {'Accept': 'application/json', 'X-Legatus-Visitor-Token': visitorToken}, cache: 'no-store'});
            if (response.status === 401) {
                clearToken();
                return;
            }
            if (!response.ok) return;
            const data = await response.json();
            (data.messages || []).forEach(renderMessage);
            cursor = Math.max(cursor, Number(data.cursor) || 0);
        } finally {
            polling = false;
        }
    }

    async function postMessage(payload, signal) {
        for (let attempt = 0; attempt < 2; attempt++) {
            try {
                const response = await fetch(messageUrl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                    body: JSON.stringify(payload),
                    signal,
                });
                if (response.status === 401 && payload.visitor_token && attempt === 0) {
                    clearToken();
                    payload.visitor_token = null;
                    continue;
                }
                return response;
            } catch (error) {
                if (error.name === 'AbortError' || attempt === 1) throw error;
                await new Promise(resolve => setTimeout(resolve, 450));
            }
        }
    }

    async function responseData(response) {
        const type = response.headers.get('content-type') || '';
        if (!type.includes('application/json')) throw new Error(`Unexpected server response (${response.status})`);

        return response.json();
    }

    async function send(text) {
        const message = text.trim();
        if (!message || sending) return;

        sending = true;
        add(message, 'user');
        input.value = '';
        input.disabled = true;
        sendButton.disabled = true;
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), requestDeadlineMs);

        try {
            const response = await postMessage({
                message,
                visitor_token: visitorToken,
                request_id: requestId(),
                channel: 'widget',
            }, controller.signal);

            const data = await responseData(response);

            if (!response.ok) throw new Error(data.message || `Request failed: ${response.status}`);

            saveToken(data.visitor_token);
            if (data.customer_message_id) seen.add(data.customer_message_id);
            if (data.message_id) seen.add(data.message_id);
            add(data.text, 'ai', data.products || [], {
                confidence: data.confidence,
                sources: data.sources || [],
                tools: data.tools_used || [],
                reason: data.escalation_reason || null,
            });
        } catch (error) {
            add(error.name === 'AbortError' ? 'პასუხმა დროის ლიმიტს გადააჭარბა. გთხოვთ ხელახლა სცადოთ.' : 'კავშირი შეფერხდა. გთხოვთ ხელახლა სცადოთ.', 'ai');
        } finally {
            clearTimeout(timer);
            sending = false;
            input.disabled = false;
            sendButton.disabled = false;
            input.focus();
            pollHistory();
        }
    }

    document.querySelector('#form').addEventListener('submit', event => {
        event.preventDefault();
        send(input.value);
    });
    document.querySelectorAll('[data-q]').forEach(button => {
        button.addEventListener('click', () => send(button.dataset.q));
    });
    pollHistory();
    setInterval(pollHistory, 2500);
</script>
</body>
</html>
