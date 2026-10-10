import http from 'node:http';
import { Server } from 'socket.io';

const backend = process.env.LARAVEL_URL || 'http://app:8000';
const origins = (process.env.REALTIME_ORIGINS || 'http://localhost:8000,http://127.0.0.1:8000').split(',');
const clients = new Map();
const server = http.createServer((req, res) => {
  if (req.url === '/health') { res.writeHead(200, { 'Content-Type': 'application/json' }); res.end('{"status":"ok"}'); }
  else { res.writeHead(404); res.end(); }
});
const io = new Server(server, {
  cors: { origin: origins, credentials: true },
  allowRequest: (request, callback) => callback(null, !request.headers.origin || origins.includes(request.headers.origin)),
  maxHttpBufferSize: 65536,
});

async function authorize(entry, after) {
  const endpoint = new URL('/api/v1/realtime/session', backend);
  if (after !== undefined) endpoint.searchParams.set('after', String(after));
  const response = await fetch(endpoint, {
    headers: { Accept: 'application/json', Authorization: `Bearer ${entry.token}`, Cookie: entry.cookie },
    signal: AbortSignal.timeout(10000),
    redirect: 'error',
  });
  if (!response.ok) throw new Error(`Authorization rejected: ${response.status}`);
  const data = await response.json();
  if (!Number.isSafeInteger(data.cursor) || !data.authorization || data.validUntil * 1000 <= Date.now()) throw new Error('Invalid authorization lease');
  return data;
}

io.use(async (socket, next) => {
  const token = socket.handshake.auth?.token;
  const cookie = socket.handshake.headers.cookie;
  try {
    if (typeof token !== 'string' || token.length !== 64 || !cookie) throw new Error('Missing session credentials');
    const entry = { socket, token, cookie, refreshing: false };
    Object.assign(entry, await authorize(entry));
    socket.data.entry = entry;
    next();
  } catch {
    const error = new Error('WebSocket authentication failed');
    error.data = { code: 'WS_AUTH_FAILED' };
    next(error);
  }
});

io.on('connection', socket => {
  const entry = socket.data.entry;
  clients.set(socket.id, entry);
  socket.emit('scheduler_resync');
  socket.on('disconnect', () => clients.delete(socket.id));
});

async function refresh(entry) {
  if (entry.refreshing || !entry.socket.connected) return;
  entry.refreshing = true;
  try {
    const data = await authorize(entry, entry.cursor);
    if (!entry.socket.connected || clients.get(entry.socket.id) !== entry) return;
    const changed = entry.authorization !== data.authorization;
    Object.assign(entry, { cursor: data.cursor, authorization: data.authorization, validUntil: data.validUntil });
    if (changed) {
      entry.socket.emit('scheduler_access_changed');
      return;
    }
    // Every emission follows a fresh Laravel session/account/scope check.
    // A slow dependency cannot deliver after the authorization lease expires.
    for (const event of data.events || []) {
      if (entry.validUntil * 1000 <= Date.now()) throw new Error('Expired lease');
      if (['booking_created','booking_updated','booking_deleted','scheduler_resync'].includes(event.event)) entry.socket.emit(event.event, event.payload);
    }
  } catch {
    if (clients.get(entry.socket.id) === entry) {
      clients.delete(entry.socket.id);
      entry.socket.emit('scheduler_auth_failed', { code: 'WS_AUTH_FAILED' });
      entry.socket.disconnect(true);
    }
  } finally { entry.refreshing = false; }
}

let polling = false;
const timer = setInterval(async () => {
  if (polling) return;
  polling = true;
  try {
    const entries = [...clients.values()];
    for (let start = 0; start < entries.length; start += 20) await Promise.all(entries.slice(start, start + 20).map(refresh));
  } finally { polling = false; }
}, 2000);

server.listen(Number(process.env.PORT || 3001), '0.0.0.0');
for (const signal of ['SIGINT','SIGTERM']) process.on(signal, () => {
  clearInterval(timer); io.close(); server.close(() => process.exit(0));
});
