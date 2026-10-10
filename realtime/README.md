# BeautyBook realtime transport

`server.mjs` is a Socket.IO transport; Laravel remains the authority for authentication, assignments, booking and financial operations. The copied scheduler connects to the existing same-origin `/socket.io/` URL.

Install root dependencies with `npm ci`, build with `npm run build`, then run `docker compose -f docker-compose.laravel.yml up -d --build` and `docker compose -f docker-compose.laravel.yml exec app php artisan migrate --force`.

The public entry stays at port 8000 through nginx. The Laravel development server is available for diagnostics only at `127.0.0.1:8001`. Socket.IO has no exposed host port. The transport forwards the original HttpOnly session cookie and memory-only opaque access token to `/api/v1/realtime/session`; every dispatch requires a fresh Laravel authorization lease. Invalid or revoked accounts/sessions/assignments disconnect with the source `WS_AUTH_FAILED` contract.

MySQL triggers append a durable event journal atomically for bookings and booking-service inserts, updates and deletes, including query-builder bulk writes. Rollbacks discard their events. The transport reads committed journal pages every two seconds, then pushes actual WebSocket/Socket.IO events to the browser. There are no simulated events or frontend interval refreshes replacing the source socket. Staff receive invalidation only, without unrelated booking IDs or contact data.

`node --test realtime/server.test.mjs` exercises actual Socket.IO handshakes and event delivery against an isolated authorization fixture. `BeautybookRealtimeTest` exercises the real MySQL journal, rollback, bulk writes, tenant scoping and revoked credentials; it must use the isolated test database and the shared test lease.

After deployment, `node realtime/laravel-smoke.mjs` checks actual Laravel login, the nginx WebSocket upgrade, scheduler resynchronization, server authorization leases and disconnection after token rotation. It uses the seeded owner account, logs out afterwards, and does not change bookings or assignments. Set `BEAUTYBOOK_URL`, `BEAUTYBOOK_EMAIL` and `BEAUTYBOOK_PASSWORD` to override the local defaults.
