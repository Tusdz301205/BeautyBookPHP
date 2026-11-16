import fs from 'node:fs';
import path from 'node:path';
import { randomUUID } from 'node:crypto';
import { spawn } from 'node:child_process';

const root = path.resolve(import.meta.dirname, '../..');
const runtime = path.join(root, 'storage/app/private/absa-runtime');
const ownerFile = path.join(runtime, 'supervisor.json');
const controlLock = path.join(runtime, 'control.lock');
const command = process.argv[2];
class ControlError extends Error {}
const readOwner = () => {
  try { return JSON.parse(fs.readFileSync(ownerFile, 'utf8')); }
  catch (error) { if (error.code === 'ENOENT') return null; throw error; }
};
const alive = (pid) => {
  if (!Number.isInteger(pid) || pid <= 0) return false;
  try { process.kill(pid, 0); return true; }
  catch (error) { return error.code !== 'ESRCH'; }
};
const stopFile = (nonce) => path.join(runtime, `stop-${nonce}`);

async function main() {
  if (!['start', 'status', 'stop'].includes(command) || process.argv.length !== 3) {
    throw new ControlError('Usage: node scripts/ml/background.mjs start|status|stop');
  }
  if (command === 'status') {
    const owner = readOwner();
    console.log(!owner ? 'Stopped.' : alive(owner.pid)
      ? `${fs.existsSync(stopFile(owner.nonce)) ? 'Stopping' : 'Running'}: supervisor PID ${owner.pid}, Python PID ${owner.childPid ?? 'starting/restarting'}.`
      : `Stale supervisor record; Python PID ${owner.childPid ?? 'none'}. Run stop before start.`);
    return;
  }
  fs.mkdirSync(runtime, { recursive: true });
  let lock;
  try { lock = fs.openSync(controlLock, 'wx', 0o600); }
  catch (error) {
    if (error.code === 'EEXIST') throw new ControlError('Another control command holds control.lock. If interrupted, verify no control command is running before removing that file.');
    throw error;
  }
  try {
    const owner = readOwner();
    if (command === 'stop') {
      if (!owner) { console.log('Already stopped.'); return; }
      if (!/^[a-f0-9-]{36}$/.test(owner.nonce)) throw new ControlError('Invalid supervisor ownership record.');
      // Only the supervisor consumes this request and signals its own ChildProcess.
      fs.writeFileSync(stopFile(owner.nonce), owner.nonce, { mode: 0o600 });
      if (alive(owner.pid)) { console.log('Stop requested for the owned supervisor.'); return; }
      if (alive(owner.childPid)) throw new ControlError('Supervisor is unavailable but recorded Python PID is live; refusing to signal a PID or clear ownership.');
      fs.rmSync(ownerFile);
      fs.rmSync(stopFile(owner.nonce), { force: true });
      console.log('Stopped; stale ownership record cleared.');
      return;
    }
    if (owner) {
      if (alive(owner.pid)) { console.log('Supervisor already running (or stopping); no new server started.'); return; }
      throw new ControlError('Stale ownership record. Run stop before start; no new server started.');
    }
    const envFile = path.join(root, '.env');
    if (fs.existsSync(envFile)) process.loadEnvFile(envFile);
    if (!process.env.ABSA_SERVICE_TOKEN || process.env.ABSA_SERVICE_TOKEN.length < 24) {
      throw new ControlError('Configure ABSA_SERVICE_TOKEN (at least 24 characters) in .env.');
    }
    const python = path.join(root, 'ml/.venv', process.platform === 'win32' ? 'Scripts/python.exe' : 'bin/python');
    if (!fs.existsSync(python)) throw new ControlError('Run npm run ai:install first.');
    const nonce = randomUUID();
    fs.writeFileSync(ownerFile, JSON.stringify({ nonce, pid: null, childPid: null }), { flag: 'wx', mode: 0o600 });
    const child = spawn(process.execPath, [path.join(import.meta.dirname, 'supervisor.mjs'), nonce], {
      cwd: root, env: process.env, windowsHide: true, detached: true, stdio: 'ignore',
    });
    try {
      await new Promise((resolve, reject) => { child.once('spawn', resolve); child.once('error', reject); });
      // The supervisor waits for this PID handoff before it can spawn Python.
      const temporary = path.join(runtime, `handoff-${nonce}.tmp`);
      fs.writeFileSync(temporary, JSON.stringify({ nonce, pid: child.pid, childPid: null }), { mode: 0o600 });
      fs.renameSync(temporary, ownerFile);
      child.unref();
      console.log(`Supervisor started: PID ${child.pid}. Use status to inspect ownership; this is not a health check.`);
    } catch {
      fs.writeFileSync(stopFile(nonce), nonce, { mode: 0o600 });
      if (!child.pid) {
        fs.rmSync(ownerFile, { force: true });
        fs.rmSync(stopFile(nonce), { force: true });
      }
      child.unref();
      throw new ControlError('Unable to start supervisor; inspect status before retrying.');
    }
  } finally {
    fs.closeSync(lock);
    fs.rmSync(controlLock);
  }
}

main().catch((error) => {
  // Do not print arbitrary exception text: it may contain environment file contents.
  console.error(error instanceof ControlError ? error.message : 'Background command failed. Check configuration, ownership records and control.lock; no arbitrary PID was signalled.');
  process.exitCode = 1;
});
