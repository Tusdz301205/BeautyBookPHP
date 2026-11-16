import fs from 'node:fs';
import path from 'node:path';
import { spawn } from 'node:child_process';
import { setTimeout as delay } from 'node:timers/promises';
import { runtimeEnvironment } from './environment.mjs';

const root = path.resolve(import.meta.dirname, '../..');
const runtime = path.join(root, 'storage/app/private/absa-runtime');
const ownerFile = path.join(runtime, 'supervisor.json');
const nonce = process.argv[2];
const validNonce = /^[a-f0-9-]{36}$/.test(nonce || '');
const stopFile = validNonce ? path.join(runtime, `stop-${nonce}`) : null;
const logFile = path.join(runtime, 'supervisor.log');
let child = null;
let stopping = false;
let monitor;
const readOwner = () => {
  try { return JSON.parse(fs.readFileSync(ownerFile, 'utf8')); }
  catch { return null; }
};
const owns = () => {
  const owner = readOwner();
  return owner?.nonce === nonce && owner.pid === process.pid;
};
// Only fixed lifecycle messages and numeric PIDs/exit codes reach this log.
// Python output is deliberately not persisted: third-party output can contain secrets.
const log = (event, pid = null, code = null) => {
  fs.appendFileSync(logFile, JSON.stringify({ at: new Date().toISOString(), event, pid, code: Number.isInteger(code) ? code : null }) + '\n', { mode: 0o600 });
};
const publish = () => {
  if (!owns()) throw new Error('Ownership lost');
  const temporary = path.join(runtime, `state-${nonce}.tmp`);
  fs.writeFileSync(temporary, JSON.stringify({ nonce, pid: process.pid, childPid: child?.pid ?? null }), { mode: 0o600 });
  fs.renameSync(temporary, ownerFile);
};
const requestStop = () => {
  if(stopping)return;
  stopping = true;
  // Windows venv's python.exe is a launcher; reap its owned Python/Java tree too.
  // Never signal a PID read from disk or enumerate unrelated Python processes.
  const owned=child;
  if (owned && owned.exitCode === null && owned.signalCode === null) {
    if(process.platform==='win32'){
      const killer=spawn('taskkill.exe',['/PID',String(owned.pid),'/T','/F'],{windowsHide:true,stdio:'ignore'});
      killer.once('error',()=>owned.kill());
    }else owned.kill();
  }
};

async function main() {
  if (!validNonce) return;
  // Allow the launching CLI to publish our PID; never start without ownership.
  for (let attempt = 0; attempt < 100 && !owns(); attempt++) {
    if (readOwner()?.nonce !== nonce) return;
    await delay(100);
  }
  if (!owns()) return;
  const envFile = path.join(root, '.env');
  if (fs.existsSync(envFile)) process.loadEnvFile(envFile);
  if (!process.env.ABSA_SERVICE_TOKEN || process.env.ABSA_SERVICE_TOKEN.length < 24) throw new Error('Invalid configuration');
  const python = path.join(root, 'ml/.venv', process.platform === 'win32' ? 'Scripts/python.exe' : 'bin/python');
  const env = { ...runtimeEnvironment(), ABSA_SERVICE_HOST: '127.0.0.1', ABSA_SERVICE_PORT: process.env.ABSA_SERVICE_PORT || '8766' };
  process.on('SIGINT', requestStop);
  process.on('SIGTERM', requestStop);
  monitor = setInterval(() => {
    if (!owns() || fs.existsSync(stopFile)) requestStop();
  }, 500);
  log('supervisor_started', process.pid);
  let failures = 0;
  while (!stopping && owns() && !fs.existsSync(stopFile)) {
    const started = Date.now();
    child = spawn(python, ['-B', '-u', '-m', 'glowme_ml.service.absa_service'], {
      cwd: path.join(root, 'ml'), env, windowsHide: true, stdio: 'ignore',
    });
    // Attach immediately, including spawn failures; wait for close before restarting.
    let spawnFailed = false;
    const closed = new Promise(resolve => {
      child.once('error', () => { spawnFailed = true; });
      child.once('close', code => resolve(code));
    });
    publish();
    log('python_started', child.pid ?? null);
    const code = await closed;
    child = null;
    publish();
    if (spawnFailed) log('python_spawn_failed');
    log('python_exited', null, code);
    if (stopping) break;
    failures = Date.now() - started > 60_000 ? 0 : failures + 1;
    const wait = Math.min(30_000, 1000 * 2 ** Math.min(failures, 5));
    for (let elapsed = 0; elapsed < wait && !stopping; elapsed += 250) await delay(250);
  }
}

try {
  await main();
} catch {
  process.exitCode = 1;
  if (validNonce && owns()) {
    try { log('supervisor_failed'); } catch { /* Never echo exception contents. */ }
  }
} finally {
  if (monitor) clearInterval(monitor);
  // Also reap the direct Python child if state/log I/O failed during supervision.
  if (child && child.exitCode === null && child.signalCode === null) {
    const closed = new Promise(resolve => child.once('close', resolve));
    requestStop();
    await closed;
  }
  if (validNonce && owns()) {
    fs.rmSync(ownerFile, { force: true });
    fs.rmSync(stopFile, { force: true });
  }
}
