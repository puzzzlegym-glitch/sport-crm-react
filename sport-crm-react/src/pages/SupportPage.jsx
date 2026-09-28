import { useEffect, useState } from 'react';
import Modal from '../components/ui/Modal';
import FormGroup from '../components/ui/FormGroup';
import Badge from '../components/ui/Badge';
import Icon from '../components/ui/Icon';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../components/ui/ToastProvider';
import { listTickets, getTicket, createTicket, sendMessage, updateTicketStatus, deleteTicket } from '../api/support';
import { formatRelativeDate, formatTime, formatDate } from '../utils/format';
import './SupportPage.css';

const STATUS_LABEL = { open: 'Нове', in_progress: 'В роботі', resolved: 'Вирішено', closed: 'Закрито' };
const STATUS_VARIANT = { open: 'pending', in_progress: 'info', resolved: 'active', closed: 'inactive' };
const STATUS_OPTIONS = ['open', 'in_progress', 'resolved', 'closed'];

/**
 * Переписка з підтримкою Sport CRM — вміст вкладки "Підтримка" на об'єднаній сторінці
 * Довідка+Підтримка (див. HelpPage.jsx). Без власного AppLayout — рендериться всередині.
 * Клуб бачить і веде лише свої звернення; SuperAdmin поза club-режимом бачить звернення
 * з усіх клубів і відповідає як підтримка (isAdminInbox перемикає роль відправника
 * й видимі елементи керування).
 */
export default function SupportPage() {
  const { permissions, inClubMode } = useAuth();
  const toast = useToast();
  const isAdminInbox = permissions.isSuperAdmin && !inClubMode;

  const [view, setView] = useState('list'); // 'list' | 'thread'
  const [tickets, setTickets] = useState([]);
  const [listLoading, setListLoading] = useState(true);
  const [statusFilter, setStatusFilter] = useState('');

  const [ticket, setTicket] = useState(null);
  const [messages, setMessages] = useState([]);
  const [threadLoading, setThreadLoading] = useState(false);

  const [reply, setReply] = useState('');
  const [sending, setSending] = useState(false);

  const [newModal, setNewModal] = useState(null); // { subject, message, error, submitting }

  async function reloadList(status = statusFilter) {
    setListLoading(true);
    const res = await listTickets(status);
    setListLoading(false);
    if (!res.success) { toast(res.error, 'error'); return; }
    setTickets(res.tickets || []);
  }

  useEffect(() => {
    reloadList('');
    // eslint-disable-next-line
  }, []);

  function changeStatusFilter(status) {
    setStatusFilter(status);
    reloadList(status);
  }

  async function openThread(row) {
    setView('thread');
    setThreadLoading(true);
    setTicket(row);
    setMessages([]);
    const res = await getTicket(row.id);
    setThreadLoading(false);
    if (!res.success) { toast(res.error, 'error'); setView('list'); return; }
    setTicket(res.ticket);
    setMessages(res.messages || []);
  }

  function backToList() {
    setView('list');
    setTicket(null);
    setMessages([]);
    reloadList();
  }

  async function submitReply() {
    const text = reply.trim();
    if (!text || !ticket) return;
    setSending(true);
    const res = await sendMessage(ticket.id, text);
    setSending(false);
    if (!res.success) { toast(res.error, 'error'); return; }
    setReply('');
    const fresh = await getTicket(ticket.id);
    if (fresh.success) { setTicket(fresh.ticket); setMessages(fresh.messages || []); }
  }

  async function changeStatus(status) {
    const res = await updateTicketStatus(ticket.id, status);
    if (!res.success) { toast(res.error, 'error'); return; }
    setTicket((t) => ({ ...t, status }));
    toast('Статус оновлено', 'success');
  }

  async function handleDeleteTicket() {
    if (!window.confirm('Видалити звернення разом з усією перепискою? Це незворотньо.')) return;
    const res = await deleteTicket(ticket.id);
    if (!res.success) { toast(res.error, 'error'); return; }
    toast('Звернення видалено', 'success');
    backToList();
  }

  function openNewModal() {
    setNewModal({ subject: '', message: '', error: '', submitting: false });
  }

  async function submitNewTicket() {
    const subject = newModal.subject.trim();
    const message = newModal.message.trim();
    if (!subject || !message) { setNewModal({ ...newModal, error: 'Заповніть тему і опис питання' }); return; }
    setNewModal({ ...newModal, submitting: true, error: '' });
    const res = await createTicket(subject, message);
    if (!res.success) { setNewModal({ ...newModal, submitting: false, error: res.error }); return; }
    setNewModal(null);
    toast('Звернення надіслано', 'success');
    reloadList();
  }

  return (
    <>
      <div className="sup-subtitle">{isAdminInbox ? 'Звернення клубів' : 'Переписка зі службою підтримки Sport CRM'}</div>
      {view === 'list' && (
        <>
          <div className="sup-toolbar">
            <select style={{ width: 'auto' }} value={statusFilter} onChange={(e) => changeStatusFilter(e.target.value)}>
              <option value="">Всі статуси</option>
              {STATUS_OPTIONS.map((s) => <option key={s} value={s}>{STATUS_LABEL[s]}</option>)}
            </select>
            {!isAdminInbox && (
              <button className="btn btn-primary" onClick={openNewModal}><Icon name="plus" size={15} /> Нове звернення</button>
            )}
          </div>

          {listLoading && <div className="loader" style={{ padding: '60px 0' }}><span className="spinner" /></div>}

          {!listLoading && tickets.length === 0 && (
            <div className="card sup-empty">
              <Icon name="messageCircle" size={26} />
              <div>{isAdminInbox ? 'Звернень немає' : 'Звернень ще немає. Якщо виникло питання чи технічна проблема — напишіть нам.'}</div>
            </div>
          )}

          {!listLoading && tickets.length > 0 && (
            <div className="sup-list">
              {tickets.map((t) => {
                const unread = !!(isAdminInbox ? t.unread_by_admin : t.unread_by_club);
                return (
                  <div key={t.id} className={`sup-row card ${unread ? 'sup-row-unread' : ''}`} onClick={() => openThread(t)}>
                    <div className="sup-row-main">
                      <div className="sup-row-subject">
                        {unread && <span className="sup-unread-dot" title="Є нова відповідь" />}
                        {t.subject}
                      </div>
                      {isAdminInbox && <div className="sup-row-club">{t.club_name}</div>}
                    </div>
                    <div className="sup-row-side">
                      <Badge variant={STATUS_VARIANT[t.status]}>{STATUS_LABEL[t.status] || t.status}</Badge>
                      <span className="sup-row-time">{formatRelativeDate(t.last_message_at)}</span>
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </>
      )}

      {view === 'thread' && (
        <>
          <div style={{ marginBottom: 16 }}>
            <button className="btn btn-ghost btn-sm" onClick={backToList}>← До списку</button>
          </div>

          {threadLoading && <div className="loader" style={{ padding: '60px 0' }}><span className="spinner" /></div>}

          {!threadLoading && ticket && (
            <>
              <div className="card sup-thread-header">
                <div>
                  <div className="sup-thread-subject">{ticket.subject}</div>
                  {isAdminInbox && <div className="sup-row-club">{ticket.club_name}</div>}
                  <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 4 }}>Відкрито {formatDate(ticket.created_at)}</div>
                </div>
                {isAdminInbox ? (
                  <select style={{ width: 'auto' }} value={ticket.status} onChange={(e) => changeStatus(e.target.value)}>
                    {STATUS_OPTIONS.map((s) => <option key={s} value={s}>{STATUS_LABEL[s]}</option>)}
                  </select>
                ) : (
                  <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-end', gap: 8 }}>
                    <Badge variant={STATUS_VARIANT[ticket.status]}>{STATUS_LABEL[ticket.status] || ticket.status}</Badge>
                    <div style={{ display: 'flex', gap: 8 }}>
                      {ticket.status !== 'closed' && (
                        <button className="btn btn-ghost btn-sm" onClick={() => changeStatus('closed')}>Закрити</button>
                      )}
                      <button className="btn btn-ghost btn-sm" style={{ color: 'var(--danger)' }} onClick={handleDeleteTicket}>
                        <Icon name="trash" size={13} /> Видалити
                      </button>
                    </div>
                  </div>
                )}
              </div>

              <div className="card sup-thread">
                {messages.map((m) => {
                  const mine = isAdminInbox ? m.sender_type === 'admin' : m.sender_type === 'club';
                  return (
                    <div key={m.id} className={`sup-msg ${mine ? 'sup-msg-mine' : 'sup-msg-other'}`}>
                      <div className="sup-msg-bubble">
                        <div className="sup-msg-sender">{m.sender_type === 'admin' ? 'Підтримка Sport CRM' : (m.sender_name || 'Клуб')}</div>
                        <div className="sup-msg-text">{m.message}</div>
                        <div className="sup-msg-time">{formatTime(m.created_at)}</div>
                      </div>
                    </div>
                  );
                })}
              </div>

              {ticket.status !== 'closed' && (
                <div className="sup-reply">
                  <textarea
                    rows={2}
                    placeholder="Напишіть повідомлення..."
                    value={reply}
                    onChange={(e) => setReply(e.target.value)}
                    onKeyDown={(e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) submitReply(); }}
                  />
                  <button className="btn btn-primary" disabled={sending || !reply.trim()} onClick={submitReply}>Надіслати</button>
                </div>
              )}
            </>
          )}
        </>
      )}

      <Modal
        open={!!newModal}
        onClose={() => setNewModal(null)}
        title="Нове звернення до підтримки"
        footer={
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" disabled={newModal?.submitting} onClick={submitNewTicket}>Надіслати</button>
            <button className="btn btn-ghost" onClick={() => setNewModal(null)}>Скасувати</button>
          </div>
        }
      >
        {newModal && (
          <>
            {newModal.error && <div className="alert alert-error">{newModal.error}</div>}
            <FormGroup label="Тема *">
              <input type="text" placeholder="Коротко опишіть питання" value={newModal.subject} onChange={(e) => setNewModal({ ...newModal, subject: e.target.value })} />
            </FormGroup>
            <FormGroup label="Опис *">
              <textarea rows={5} placeholder="Детальніше — що сталося, коли, що очікували побачити" value={newModal.message} onChange={(e) => setNewModal({ ...newModal, message: e.target.value })} />
            </FormGroup>
          </>
        )}
      </Modal>
    </>
  );
}
