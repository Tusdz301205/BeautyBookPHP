export function createCoalescedRefresh(refresh, { delay = 75, setTimer = setTimeout, clearTimer = clearTimeout } = {}) {
  let closed = false, pending = false, running = false, timer = null;
  const arm = () => { if (!closed && !running && timer === null && pending) timer = setTimer(flush, delay); };
  const flush = async () => {
    timer = null;
    if (closed || !pending) return;
    pending = false; running = true;
    try { await refresh(); }
    finally { running = false; arm(); }
  };
  return {
    request() { if (closed) return; pending = true; arm(); },
    cancel() { closed = true; pending = false; if (timer !== null) clearTimer(timer); timer = null; },
  };
}
