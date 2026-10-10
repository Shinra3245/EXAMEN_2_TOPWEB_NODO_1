(() => {
    const status = document.getElementById('realtime-status');
    const refresh = document.getElementById('realtime-refresh');
    if (!status || !refresh) return;
    let socket, heartbeat, renewal, reconnect, stopped = false, reference = 0;
    refresh.addEventListener('click', () => window.location.reload());
    const send = (topic, event, payload, joinRef) => {
        if (socket?.readyState === WebSocket.OPEN) {
            socket.send(JSON.stringify({topic, event, payload, ref: String(++reference), ...(joinRef ? {join_ref: joinRef} : {})}));
        }
    };
    const configuration = async () => {
        const response = await fetch(status.dataset.configUrl, {headers: {Accept: 'application/json'}, cache: 'no-store'});
        if (!response.ok) throw new Error('session');
        return response.json();
    };
    const connect = async () => {
        try {
            const config = await configuration();
            if (stopped) return;
            const topic = `realtime:${config.topic}`;
            const url = new URL('/realtime/v1/websocket', config.url);
            url.protocol = 'wss:';
            url.searchParams.set('apikey', config.publishable_key);
            url.searchParams.set('vsn', '1.0.0');
            socket = new WebSocket(url);
            const joinRef = String(reference + 1);
            socket.onopen = () => {
                send(topic, 'phx_join', {config: {private: true, broadcast: {ack: false, self: false}, presence: {enabled: false}, postgres_changes: []}, access_token: config.access_token}, joinRef);
                heartbeat = setInterval(() => send('phoenix', 'heartbeat', {}), 25000);
                renewal = setInterval(async () => {
                    try { const next = await configuration(); send(topic, 'access_token', {access_token: next.access_token}, joinRef); }
                    catch { socket.close(); }
                }, 240000);
            };
            socket.onmessage = ({data}) => {
                let message;
                try { message = JSON.parse(data); } catch { return; }
                if (message.event === 'phx_reply' && message.ref === joinRef) {
                    status.textContent = message.payload?.status === 'ok' ? 'Actualizaciones en tiempo real conectadas.' : 'Actualizaciones desconectadas. Vuelve a iniciar sesión.';
                }
                if (message.event === 'broadcast' && message.payload?.event === 'transactions_changed') {
                    status.textContent = 'Se registró un nuevo movimiento.';
                    refresh.hidden = false;
                }
            };
            socket.onclose = () => {
                clearInterval(heartbeat); clearInterval(renewal);
                status.textContent = 'Actualizaciones desconectadas. Reconectando…';
                if (!stopped) reconnect = setTimeout(connect, 5000);
            };
        } catch {
            status.textContent = 'Actualizaciones desconectadas. Vuelve a iniciar sesión.';
        }
    };
    window.addEventListener('pagehide', () => {
        stopped = true; clearInterval(heartbeat); clearInterval(renewal); clearTimeout(reconnect); socket?.close();
    });
    connect();
})();
