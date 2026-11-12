// Message synchronisation for one chat thread, kept free of React so it can be tested with a fake server.
//
// Rule that keeps every message visible: the cursor only moves to the highest sequence the *server returned
// to a poll*. Sending never moves it. If a peer's message lands between the last poll and our own send, our
// message gets the next sequence, and the following `after=<cursor>` poll returns both. Moving the cursor to
// our own sequence (the previous behaviour) skipped the peer's message and also marked it as read unseen.

const bySequence = (a, b) => a.sequence - b.sequence;

export function mergeMessages(current, incoming) {
  const map = new Map(current.map((message) => [String(message.id), message]));
  incoming.forEach((message) => map.set(String(message.id), message));
  return [...map.values()].sort(bySequence);
}

const MAX_CATCH_UP_PAGES = 40;

export function createChatSync({ api, scope, id, isHidden = () => false, onAcknowledge = () => {} }) {
  let cursor = 0;
  let started = false; // the first poll loads the latest page; every later one asks for `after` the cursor, even when it is still 0
  let acknowledged = 0;
  let messages = [];
  let meta = null;
  let hasOlder = false;
  let running = null;
  let again = false;

  const check = (result) => {
    if (!Array.isArray(result.items) || result.items.some((item) => String(item.conversationId) !== String(id))) throw new Error('Chưa đọc được tin nhắn.');
  };

  // Tells the server how far this client has actually loaded; a failure just leaves the messages unread.
  async function acknowledge() {
    if (isHidden() || cursor <= acknowledged) return;
    try {
      await api.read(scope, id, cursor);
      acknowledged = cursor;
      onAcknowledge();
    } catch { /* stays unread until the next successful acknowledgement */ }
  }

  async function pollOnce() {
    for (let page = 0; page < MAX_CATCH_UP_PAGES; page += 1) {
      const fromStart = !started;
      const result = await api.messages(scope, id, fromStart ? '' : `?after=${cursor}`);
      check(result);
      started = true;
      meta = result;
      if (fromStart) hasOlder = Boolean(result.hasMore);
      messages = mergeMessages(messages, result.items);
      const top = result.items.reduce((highest, item) => Math.max(highest, item.sequence), cursor);
      const moved = top > cursor;
      cursor = top;
      // `after` pages hold 50 messages: keep going until the backlog is drained instead of waiting for the next tick.
      if (fromStart || !result.hasMore || !moved) break;
    }
    await acknowledge();
  }

  // One poll at a time; a request made while one is in flight runs again afterwards so it cannot miss anything.
  function poll() {
    if (running) { again = true; return running; }
    running = (async () => {
      try { do { again = false; await pollOnce(); } while (again); } finally { running = null; }
    })();
    return running;
  }

  async function send(payload) {
    const message = await api.send(scope, id, payload);
    messages = mergeMessages(messages, [message]);
    // Catch up immediately (the peer's reply may already be there); the send itself already succeeded.
    try { await poll(); } catch { /* the next scheduled poll retries */ }
    return message;
  }

  async function loadOlder() {
    if (!messages.length) return;
    const result = await api.messages(scope, id, `?before=${messages[0].sequence}`);
    check(result);
    hasOlder = Boolean(result.hasMore);
    messages = mergeMessages(messages, result.items);
  }

  return {
    poll,
    send,
    loadOlder,
    get cursor() { return cursor; },
    get messages() { return messages; },
    get meta() { return meta; },
    get hasOlder() { return hasOlder; },
    reset() { cursor = 0; started = false; acknowledged = 0; messages = []; meta = null; hasOlder = false; },
  };
}
