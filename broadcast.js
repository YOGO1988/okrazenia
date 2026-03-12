const WebSocket = require('ws');
const http = require('http');
const os = require('os');

console.log('📺 YO&GO Broadcast Server v1.0');
console.log('================================');

// ======================================================
// KONFIGURACJA
// ======================================================
const PORT = 3002;

// ======================================================

function getLocalIP() {
    const interfaces = os.networkInterfaces();
    const validIPs = [];

    for (const name of Object.keys(interfaces)) {
        for (const iface of interfaces[name]) {
            if (iface.family === 'IPv4' && !iface.internal && !iface.address.startsWith('169.254.')) {
                validIPs.push({ name, address: iface.address });
            }
        }
    }

    const wifiIP = validIPs.find(ip =>
        ip.name.toLowerCase().includes('wi-fi') ||
        ip.name.toLowerCase().includes('wireless') ||
        ip.name.toLowerCase().includes('wlan')
    );

    if (wifiIP) return wifiIP.address;
    if (validIPs.length > 0) return validIPs[0].address;
    return '192.168.1.100';
}

const LOCAL_IP = getLocalIP();

// Ostatnie wyniki - cache, żeby nowy klient dostał dane od razu po połączeniu
let cachedResults = null;

// Lista połączonych klientów
let clients = [];
let adminClients = [];   // yogo_lap.html (wysyła wyniki)
let displayClients = []; // yogo_lap_net.html (odbiera wyniki)

// HTTP server ze statusem
const httpServer = http.createServer((req, res) => {
    const clientCount = clients.length;
    const html = `<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>YO&GO Broadcast Server</title>
    <style>
        body { font-family: Arial, sans-serif; background: #1a1a2e; color: white; margin: 40px; }
        .card { background: rgba(255,255,255,0.1); padding: 20px; border-radius: 12px; margin: 16px 0; max-width: 600px; }
        .ok { color: #4ade80; }
        .warn { color: #fb923c; }
        code { background: rgba(0,0,0,0.4); padding: 4px 8px; border-radius: 4px; font-size: 14px; }
        h1 { color: #FF8C00; }
    </style>
    <meta http-equiv="refresh" content="10">
</head>
<body>
    <h1>📺 YO&GO Broadcast Server</h1>

    <div class="card">
        <b>Status:</b> <span class="ok">✅ Działa</span><br>
        <b>IP serwera:</b> <code>${LOCAL_IP}</code><br>
        <b>Port:</b> <code>${PORT}</code><br>
        <b>WebSocket:</b> <code>ws://${LOCAL_IP}:${PORT}</code>
    </div>

    <div class="card">
        <b>Połączeni klienci:</b> ${clientCount}<br>
        <b>Panele admin (yogo_lap.html):</b> ${adminClients.length}<br>
        <b>Ekrany wyników (yogo_lap_net.html):</b> ${displayClients.length}<br>
        <b>Ostatni update:</b> ${cachedResults ? new Date().toLocaleTimeString('pl-PL') : 'Brak danych'}
    </div>

    <div class="card">
        <b>Instrukcja:</b><br><br>
        1. W <code>yogo_lap.html</code> → zakładka Live → wpisz:<br>
        <code>ws://${LOCAL_IP}:${PORT}</code><br><br>
        2. Otwórz ekran wyników pod adresem:<br>
        <code>yogo_lap_net.html?server=ws://${LOCAL_IP}:${PORT}</code>
    </div>

    <p style="color: #888; font-size: 12px;">Strona odświeża się co 10 sekund</p>
</body>
</html>`;
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end(html);
});

const wss = new WebSocket.Server({ server: httpServer });

wss.on('connection', (ws, req) => {
    const clientIP = req.socket.remoteAddress;
    clients.push(ws);
    console.log(`🔌 Nowe połączenie: ${clientIP} (łącznie: ${clients.length})`);

    // Wyślij cached dane od razu po połączeniu, bez czekania na request_results
    if (cachedResults) {
        ws.send(JSON.stringify(cachedResults));
        console.log(`   📤 Wysłano cached wyniki do nowego klienta`);
    }

    ws.on('message', (rawMessage) => {
        let data;
        try {
            data = JSON.parse(rawMessage.toString());
        } catch (e) {
            console.warn(`⚠️  Nie można sparsować wiadomości: ${rawMessage.toString().substring(0, 80)}`);
            return;
        }

        if (data.type === 'results_update') {
            // Wiadomość od admina (yogo_lap.html)
            cachedResults = data;

            if (!adminClients.includes(ws)) {
                adminClients.push(ws);
                // Usuń z displayClients jeśli był tam dodany
                displayClients = displayClients.filter(c => c !== ws);
            }

            // Rozślij do wszystkich ekranów wyników (wszystkich oprócz nadawcy)
            let sentCount = 0;
            clients.forEach(client => {
                if (client !== ws && client.readyState === WebSocket.OPEN) {
                    client.send(JSON.stringify(data));
                    sentCount++;
                }
            });

            console.log(`📊 results_update → rozesłano do ${sentCount} klientów (${data.results?.length ?? 0} zawodników)`);

        } else if (data.type === 'request_results') {
            // Ekran wyników prosi o aktualne dane
            if (!displayClients.includes(ws)) {
                displayClients.push(ws);
                adminClients = adminClients.filter(c => c !== ws);
            }

            if (cachedResults) {
                ws.send(JSON.stringify(cachedResults));
                console.log(`   📤 request_results → wysłano cached dane`);
            } else {
                console.log(`   ℹ️  request_results → brak danych w cache`);
            }
        }
    });

    ws.on('close', () => {
        clients = clients.filter(c => c !== ws);
        adminClients = adminClients.filter(c => c !== ws);
        displayClients = displayClients.filter(c => c !== ws);
        console.log(`🔌 Rozłączono: ${clientIP} (pozostało: ${clients.length})`);
    });

    ws.on('error', (err) => {
        console.error(`❌ Błąd WebSocket od ${clientIP}: ${err.message}`);
    });
});

httpServer.on('error', (err) => {
    if (err.code === 'EADDRINUSE') {
        console.error(`❌ Port ${PORT} jest zajęty! Zatrzymaj inny proces lub zmień PORT w pliku.`);
        process.exit(1);
    } else {
        console.error('❌ Błąd serwera:', err.message);
    }
});

httpServer.listen(PORT, '0.0.0.0', () => {
    console.log(`\n✅ Serwer broadcast uruchomiony:`);
    console.log(`   HTTP:      http://${LOCAL_IP}:${PORT}`);
    console.log(`   WebSocket: ws://${LOCAL_IP}:${PORT}`);
    console.log(`\n📋 Instrukcja:`);
    console.log(`   1. W yogo_lap.html → zakładka Live → wpisz: ws://${LOCAL_IP}:${PORT}`);
    console.log(`   2. Otwórz ekran: yogo_lap_net.html?server=ws://${LOCAL_IP}:${PORT}`);
    console.log('\n🛑 Ctrl+C aby zatrzymać');
});

process.on('SIGINT', () => {
    console.log('\n🛑 Zatrzymywanie serwera broadcast...');
    wss.close();
    httpServer.close();
    process.exit(0);
});
