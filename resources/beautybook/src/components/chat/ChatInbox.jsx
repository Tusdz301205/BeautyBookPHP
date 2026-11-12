import React, { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { ArrowLeft, CalendarDays, ImagePlus, MessageCircle, RefreshCw, Send, X } from 'lucide-react';
import { chatApi } from '../../api/apiClient';
import { createChatSync } from './chatSync';
import '../../styles/chat.css';

// Adapted from the web package's ChatInbox: same layout and polling, numeric ids, text and booking shares only.
const numeric = (value) => (typeof value === 'string' && /^[1-9]\d{0,17}$/.test(value) ? value : null);
const when = (value) => (value ? new Date(value).toLocaleString('vi-VN', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit' }) : '');
const preview = (message) => message?.body || (message?.booking ? 'Thông tin lịch hẹn' : 'Bắt đầu trò chuyện');

// A private image: fetched with the member's credentials and shown from a blob URL that is released on unmount.
function ChatImage({ scope, conversationId, attachment }) {
  const [url, setUrl] = useState('');
  const [failed, setFailed] = useState(false);
  useEffect(() => {
    let active = true; let created = '';
    chatApi.image(scope, conversationId, attachment.id).then((blob) => { if (!active) return; created = URL.createObjectURL(blob); setUrl(created); }).catch(() => { if (active) setFailed(true); });
    return () => { active = false; if (created) URL.revokeObjectURL(created); };
  }, [scope, conversationId, attachment.id]);
  if (failed) return <span className="gm-chat-image-placeholder">Không thể hiển thị ảnh</span>;
  return url ? <img className="gm-chat-image" src={url} alt="Ảnh trong cuộc trò chuyện" style={{ maxWidth: 280, maxHeight: 240, objectFit: 'contain', borderRadius: 8, display: 'block' }} /> : <span className="gm-chat-image-placeholder">Đang tải ảnh…</span>;
}

function Thread({ scope, id, bookingId, onBack, onUpdate }) {
  const [data, setData] = useState(null);
  const [messages, setMessages] = useState([]);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [text, setText] = useState('');
  const [booking, setBooking] = useState(numeric(bookingId));
  const [retry, setRetry] = useState(false);
  const [image, setImage] = useState(null);
  const [uploading, setUploading] = useState(false);
  const fileInput = useRef(null);
  const [fatal, setFatal] = useState(false);
  const [hasOlder, setHasOlder] = useState(false);
  const pending = useRef(null);
  const sync = useRef(null);
  const scroll = useRef(null);
  const follow = useRef(true);
  const alive = useRef(true);

  // The engine owns the cursor: it only advances to what a poll returned, so a message that lands between the
  // last poll and our own send is still fetched (and never acknowledged before it was loaded).
  const show = useCallback(() => {
    const engine = sync.current;
    if (!engine || !alive.current) return;
    setData(engine.meta); setMessages(engine.messages); setHasOlder(engine.hasOlder);
  }, []);

  useEffect(() => {
    alive.current = true;
    const engine = createChatSync({ api: chatApi, scope, id, isHidden: () => document.hidden, onAcknowledge: onUpdate });
    sync.current = engine;
    let loading = false;
    const load = async () => {
      if (loading || document.hidden) return;
      loading = true;
      try {
        await engine.poll();
        if (!alive.current) return;
        show(); setError('');
      } catch (requestError) {
        if (!alive.current) return;
        setError(requestError.message);
        if ([401, 403, 404].includes(requestError.status)) { setMessages([]); setData(null); setFatal(true); }
      } finally { loading = false; }
    };
    void load();
    const interval = setInterval(() => void load(), 4000);
    return () => { alive.current = false; clearInterval(interval); sync.current = null; };
  }, [scope, id, onUpdate, show]);

  useLayoutEffect(() => { if (follow.current && scroll.current) scroll.current.scrollTop = scroll.current.scrollHeight; }, [messages]);

  const loadOlder = async () => {
    if (!sync.current) return;
    follow.current = false;
    try { await sync.current.loadOlder(); show(); } catch (requestError) { setError(requestError.message); }
  };

  const pick = async (event) => {
    const file = event.target.files?.[0]; event.target.value = '';
    if (!file || busy || uploading || fatal) return;
    setUploading(true); setError('');
    try { setImage(await chatApi.upload(scope, id, file)); }
    catch (requestError) { setError(requestError.message || 'Không tải được ảnh'); } finally { setUploading(false); }
  };

  const send = async (event) => {
    event?.preventDefault();
    if (busy || fatal || !data?.canSend || !sync.current) return;
    setBusy(true); setError('');
    // The same client id is reused until the server acknowledges, so a retry cannot duplicate the message.
    if (!pending.current) pending.current = { clientMessageId: crypto.randomUUID(), ...(text.trim() ? { body: text.trim() } : {}), ...(booking ? { bookingId: Number(booking) } : {}), ...(image ? { attachmentId: Number(image.id) } : {}) };
    try {
      await sync.current.send(pending.current);
      follow.current = true;
      show();
      setText(''); setBooking(null); setImage(null); pending.current = null; setRetry(false); onUpdate();
    } catch (requestError) {
      setError(requestError.message);
      if ([400, 403, 404, 409, 413, 422].includes(requestError.status)) { pending.current = null; setRetry(false); } else setRetry(true);
      if ([401, 403, 404].includes(requestError.status)) { setMessages([]); setData(null); setFatal(true); }
    } finally { setBusy(false); }
  };

  return <section className="gm-chat-thread" aria-label="Cuộc trò chuyện">
    <header><button type="button" className="gm-chat-back" onClick={onBack} aria-label="Về hộp thư"><ArrowLeft size={19} /></button><span className="gm-chat-avatar">{data?.title?.slice(0, 1) || '…'}</span><div><h2>{data?.title || 'Đang mở hội thoại…'}</h2><p>{scope === 'branch' ? data?.branchName : 'Trao đổi trực tiếp với chi nhánh'}</p></div></header>
    <div className="gm-chat-stream" ref={scroll} aria-live="polite" aria-relevant="additions" onScroll={() => { const box = scroll.current; follow.current = box.scrollHeight - box.scrollTop - box.clientHeight < 80; }}>
      {hasOlder && <button type="button" className="gm-chat-history" onClick={loadOlder}>Xem tin nhắn trước</button>}
      {data && !messages.length && <div className="gm-chat-empty"><MessageCircle size={32} /><h3>Bắt đầu cuộc trò chuyện</h3><p>Gửi lời nhắn hoặc chia sẻ lịch hẹn với chi nhánh.</p></div>}
      {messages.map((message) => {
        const own = message.fromBranch === (scope === 'branch');
        return <article key={message.id} className={`gm-chat-message${own ? ' is-own' : ''}`}><small>{message.fromBranch ? `${message.sender.fullName} · Chi nhánh` : message.sender.fullName}</small>
          <div className="gm-chat-bubble">{message.booking && <Link className="gm-chat-booking" to={scope === 'customer' ? '/customer/appointments' : '/salon/appointments'}><CalendarDays size={20} /><span><b>{message.booking.bookingCode}</b><small>{when(message.booking.startAt)} · Xem lịch hẹn</small></span></Link>}{message.attachment && <ChatImage scope={scope} conversationId={id} attachment={message.attachment} />}{message.body && <p>{message.body}</p>}</div>
          <small>{when(message.createdAt)}{own && (data?.peerReadSequence >= message.sequence ? ' · Đã xem' : ' · Đã gửi')}</small></article>;
      })}
    </div>
    {error && <p className="gm-chat-error" role="alert">{error}{retry ? ' Bấm gửi lại để kiểm tra và gửi đúng tin nhắn này.' : ''}</p>}
    <form className="gm-chat-compose" onSubmit={send}>
      {booking && <div className="gm-chat-draft"><CalendarDays size={16} />Đính kèm lịch hẹn đang xem<button type="button" disabled={busy || retry} onClick={() => setBooking(null)} aria-label="Bỏ đính kèm lịch"><X size={16} /></button></div>}
      {image && <div className="gm-chat-draft"><ImagePlus size={16} />Ảnh đã tải lên, sẵn sàng gửi<button type="button" disabled={busy || retry} onClick={() => setImage(null)} aria-label="Bỏ ảnh"><X size={16} /></button></div>}
      <div className="gm-chat-compose-row"><input ref={fileInput} type="file" accept="image/jpeg,image/png,image/webp" hidden onChange={pick} /><button type="button" disabled={busy || uploading || retry || !data?.canSend} onClick={() => fileInput.current?.click()} aria-label="Đính kèm ảnh"><ImagePlus size={20} /></button><textarea rows={2} maxLength={4000} value={text} disabled={busy || retry || !data?.canSend} placeholder={fatal ? 'Không thể truy cập hội thoại' : 'Nhập tin nhắn…'} aria-label="Nội dung tin nhắn" onChange={(event) => setText(event.target.value)}
        onKeyDown={(event) => { if (event.key === 'Enter' && !event.shiftKey && !event.nativeEvent.isComposing) { event.preventDefault(); if (text.trim() || booking || image) void send(); } }} />
        <button className="gm-chat-send" type="submit" disabled={busy || !data?.canSend || (!text.trim() && !booking && !image && !retry)} aria-label={retry ? 'Gửi lại tin nhắn' : 'Gửi tin nhắn'}>{retry ? <RefreshCw size={20} /> : <Send size={20} />}</button></div>
      <small>{busy ? 'Đang gửi…' : retry ? 'Tin nhắn chưa được xác nhận. Có thể gửi lại an toàn.' : 'Tin nhắn không thay thế yêu cầu đổi hoặc hủy lịch.'}</small>
    </form>
  </section>;
}

export default function ChatInbox({ scope = 'customer' }) {
  const [query, setQuery] = useSearchParams();
  const [list, setList] = useState(null);
  const [page, setPage] = useState(1);
  const [error, setError] = useState('');
  const [refresh, setRefresh] = useState(0);
  const [opening, setOpening] = useState(false);
  const id = numeric(query.get('conversationId'));
  const bookingId = numeric(query.get('bookingId'));
  const bump = useCallback(() => setRefresh((value) => value + 1), []);

  useEffect(() => {
    let active = true;
    let loading = false;
    const load = async () => {
      if (loading || document.hidden) return;
      loading = true;
      try { const result = await chatApi.list(scope, { page, limit: 20 }); if (active) { setList(result); setError(''); } }
      catch (requestError) { if (active) { setError(requestError.message); if ([401, 403].includes(requestError.status)) setList(null); } }
      finally { loading = false; }
    };
    void load();
    const timer = setInterval(load, 8000);
    return () => { active = false; clearInterval(timer); };
  }, [scope, page, refresh]);

  useEffect(() => {
    if (!bookingId || id) return undefined;
    let active = true;
    setOpening(true);
    chatApi.open(scope, bookingId).then((result) => { if (active) { setQuery({ conversationId: result.id, bookingId }); bump(); } })
      .catch((requestError) => { if (active) setError(requestError.message); }).finally(() => { if (active) setOpening(false); });
    return () => { active = false; };
  }, [bookingId, id, scope, setQuery, bump]);

  const back = () => setQuery({});
  return <div className="gm-chat-page">
    <div className="gm-chat-heading"><div><p>BEAUTYBOOK · KẾT NỐI</p><h1>Tin nhắn</h1><span>{scope === 'customer' ? 'Trao đổi với chi nhánh về lịch hẹn của bạn.' : 'Hộp thư hỗ trợ khách hàng của cơ sở.'}</span></div><button type="button" onClick={bump} aria-label="Làm mới hộp thư"><RefreshCw size={18} /></button></div>
    {error && <p className="gm-chat-error" role="alert">{error}</p>}
    <div className={`gm-chat-layout${id ? ' has-thread' : ''}`} style={{ minHeight: 520 }}>
      <aside className="gm-chat-inbox"><div className="gm-chat-inbox-title"><h2>Hội thoại</h2><span>{list?.total ?? '…'}</span></div>
        <div className="gm-chat-conversations">{list?.items.map((conversation) => <button type="button" key={conversation.id} className={`gm-chat-conversation${id === conversation.id ? ' is-active' : ''}`} onClick={() => setQuery({ conversationId: conversation.id })}>
          <span className="gm-chat-avatar">{conversation.title?.slice(0, 1)}</span><span><b>{conversation.title}</b>{scope === 'branch' && <small>{conversation.branchName}</small>}<small>{preview(conversation.lastMessage)}</small></span>
          <span><time>{when(conversation.lastMessageAt)}</time>{conversation.unreadCount > 0 && <em>{conversation.unreadCount > 99 ? '99+' : conversation.unreadCount}</em>}</span></button>)}
          {list && !list.items.length && <p className="gm-chat-empty">Chưa có cuộc trò chuyện. Mở chi tiết lịch hẹn và chọn “Nhắn tin”.</p>}</div>
        <footer><button type="button" disabled={page <= 1} onClick={() => setPage((value) => value - 1)}>Trước</button><span>Trang {page}</span><button type="button" disabled={!list || page * 20 >= list.total} onClick={() => setPage((value) => value + 1)}>Sau</button></footer></aside>
      {id ? <Thread key={`${scope}:${id}`} scope={scope} id={id} bookingId={bookingId} onBack={back} onUpdate={bump} />
        : <section className="gm-chat-welcome"><MessageCircle size={44} /><h2>{opening ? 'Đang mở cuộc trò chuyện…' : 'Cùng trao đổi, chăm sóc tốt hơn'}</h2><p>Chọn một hội thoại để xem tin nhắn và lịch hẹn liên quan.</p></section>}
    </div>
  </div>;
}
