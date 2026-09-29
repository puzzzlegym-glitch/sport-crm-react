import { useEffect, useState } from 'react';
import QRCode from 'qrcode';
import AppLayout from '../components/layout/AppLayout';
import Modal from '../components/ui/Modal';
import Icon from '../components/ui/Icon';
import Badge from '../components/ui/Badge';
import { useToast } from '../components/ui/ToastProvider';
import { getClientServiceStats } from '../api/clientService';
import './ClientServicePage.css';

// Значення за замовчуванням — поки сервер не відповів (сервер може перевизначити
// через DRIVEHUB_APP_* у config.php). Бот CRM береться ЛИШЕ з сервера (TELEGRAM_BOT_USERNAME).
const DEFAULT_APP = { name: 'DRIVE SPORT HUB', url: 'https://ds-hub.pp.ua/', bot_username: 'DriveSportHub_bot' };

const STEPS = [
  { n: 1, title: 'Створити клієнта в CRM', desc: 'Додайте нового клієнта або виберіть існуючого.' },
  { n: 2, title: 'Відкрити застосунок', desc: 'Клієнт переходить за посиланням або QR-кодом.' },
  { n: 3, title: 'Реєстрація', desc: 'Клієнт проходить авторизацію через телефон або Telegram.' },
  { n: 4, title: 'Вказати номер телефону', desc: 'Той самий номер, що й у картці клієнта в CRM.' },
  { n: 5, title: 'Автоматична прив’язка', desc: 'CRM знаходить клієнта за номером і виконує прив’язку.' },
];

function inviteText(app) {
  return `Привіт! Керуй своїми тренуваннями, харчуванням і прогресом у застосунку ${app.name}:\n${app.url}` +
    (app.bot_username ? `\n\nАбо одразу через Telegram-бота: https://t.me/${app.bot_username}` : '');
}

function StatCard({ icon, accent, label, value, sub, trend, loading }) {
  return (
    <div className="csp-stat" style={{ '--csp-accent': accent }}>
      <div className="csp-stat-top">
        <div className="csp-stat-icon"><Icon name={icon} size={16} /></div>
        {trend != null && <div className="csp-stat-trend"><Icon name="arrowUp" size={10} /> {trend}%</div>}
      </div>
      <div className="csp-stat-value">{loading ? <span className="spinner" /> : value}</div>
      <div className="csp-stat-label">{label}</div>
      <div className="csp-stat-sub">{sub}</div>
    </div>
  );
}

// status: { variant, text } — справжній стан, а не фіксований «Працює»
function BotCard({ bot }) {
  const toast = useToast();
  return (
    <div className="csp-bot-card">
      <div className="csp-bot-top">
        <div className="csp-bot-icon"><Icon name="send" size={15} /></div>
        <div className="csp-bot-name-wrap">
          <div className="csp-bot-name">{bot.name}</div>
          <div className="csp-bot-username">{bot.username ? `@${bot.username}` : 'бот не вказано'}</div>
        </div>
        <Badge variant={bot.status.variant}>{bot.status.text}</Badge>
      </div>
      <div className="csp-bot-desc">{bot.desc}</div>
      {bot.username && (
        <div className="csp-bot-actions">
          <a className="btn btn-ghost btn-sm" href={`https://t.me/${bot.username}`} target="_blank" rel="noreferrer">
            Відкрити в Telegram <Icon name="chevronRight" size={12} />
          </a>
          <button
            className="csp-icon-btn"
            title="Копіювати username"
            onClick={() => { navigator.clipboard?.writeText(`@${bot.username}`); toast('Username скопійовано', 'success'); }}
          >
            <Icon name="copy" size={13} />
          </button>
        </div>
      )}
    </div>
  );
}

export default function ClientServicePage() {
  const toast = useToast();
  const [stats, setStats] = useState(null);
  const [heroQr, setHeroQr] = useState('');
  const [bigQrOpen, setBigQrOpen] = useState(false);
  const [instructionsOpen, setInstructionsOpen] = useState(false);

  const app = { ...DEFAULT_APP, ...(stats?.app || {}) };
  const APP_URL = app.url;
  const APP_NAME = app.name;

  useEffect(() => {
    (async () => {
      const statsRes = await getClientServiceStats();
      if (statsRes.success) setStats(statsRes);
    })();
  }, []);

  useEffect(() => {
    QRCode.toDataURL(APP_URL, { width: 400, margin: 1 }).then(setHeroQr).catch(() => setHeroQr(''));
  }, [APP_URL]);

  function copyAppLink() {
    navigator.clipboard?.writeText(APP_URL);
    toast('Посилання скопійовано', 'success');
  }

  function copyInvite() {
    navigator.clipboard?.writeText(inviteText(app));
    toast('Текст запрошення скопійовано — вставте в будь-який месенджер', 'success');
  }

  const hubConnected = stats?.hub_connected;
  const totalClients = stats?.total_clients ?? 0;
  const crmBot = stats?.crm_bot;

  const loadingStatus = { variant: 'inactive', text: '…' };
  const BOTS = [
    {
      name: 'Бот клубу',
      username: crmBot?.username || null,
      desc: crmBot && !crmBot.configured
        ? 'Бот не налаштовано: у файлі app/config.php на сервері вкажіть TELEGRAM_BOT_TOKEN і TELEGRAM_BOT_USERNAME (див. Налаштування → Telegram).'
        : 'Бот для сповіщень клієнтів клубу та прив’язки їхнього Telegram до картки в CRM.',
      status: !stats ? loadingStatus
        : crmBot?.configured ? { variant: 'active', text: '🟢 Налаштовано' } : { variant: 'inactive', text: '🔴 Не налаштовано' },
    },
    {
      name: 'Бот застосунку',
      username: app.bot_username || null,
      desc: `Бот застосунку ${APP_NAME}. Допомагає з авторизацією, підтримкою та сповіщеннями.`,
      status: !stats ? loadingStatus
        : hubConnected ? { variant: 'active', text: '🟢 Зв’язано з CRM' } : { variant: 'pending', text: '🟡 Не зв’язано' },
    },
  ];

  const QUICK_ACTIONS = [
    { icon: 'copy', accent: 'var(--accent)', title: 'Скопіювати посилання', desc: `${APP_URL}`, onClick: copyAppLink },
    { icon: 'qrCode', accent: '#c4b5fd', title: 'Показати QR-код', desc: 'Великий QR для сканування клієнтом', onClick: () => setBigQrOpen(true) },
    { icon: 'send', accent: 'var(--success)', title: 'Скопіювати текст запрошення', desc: 'Готовий текст із посиланнями — вставте в будь-який месенджер', onClick: copyInvite },
    { icon: 'bookOpen', accent: 'var(--warning)', title: 'Як підключити клієнта', desc: 'Покроковий процес нижче на сторінці', onClick: () => setInstructionsOpen(true) },
  ];

  return (
    <AppLayout title="Клієнтський сервіс" subtitle="Застосунок, Telegram-боти та підключення клієнтів">
      <div className="csp-page">

        {/* HERO */}
        <div className="csp-hero">
          <div className="csp-hero-logo"><Icon name="zap" size={22} /></div>
          <div className="csp-hero-main">
            <div className="csp-hero-top">
              <div className="csp-hero-title">{APP_NAME}</div>
              <Badge variant="info">Клієнтський застосунок</Badge>
            </div>
            <div className="csp-hero-status">
              {!stats ? <Badge variant="inactive">…</Badge>
                : hubConnected ? <Badge variant="active">🟢 Підключено до CRM</Badge>
                  : <Badge variant="pending">🟡 Статистику застосунку не підключено</Badge>}
            </div>
            <p className="csp-hero-desc">Тренування, харчування, прогрес — в одному застосунку.</p>
            <div className="csp-hero-url"><Icon name="globe" size={13} /> {APP_URL.replace(/^https?:\/\//, '').replace(/\/$/, '')}</div>
            <div className="csp-hero-actions">
              <a className="btn btn-primary" href={APP_URL} target="_blank" rel="noreferrer">
                Відкрити застосунок <Icon name="chevronRight" size={13} />
              </a>
              <button className="btn btn-ghost" onClick={copyAppLink}><Icon name="copy" size={13} /> Скопіювати посилання</button>
            </div>
          </div>
          <div className="csp-hero-qr">
            {heroQr && <img src={heroQr} alt="QR-код застосунку" />}
            <div className="csp-hero-qr-label">QR для швидкого доступу</div>
          </div>
        </div>

        {/* СТАТИСТИКА */}
        <div className="csp-stats-grid">
          <StatCard
            icon="smartphone"
            accent="var(--success)"
            label="Клієнти застосунку"
            value={hubConnected ? stats.app_users : '—'}
            sub={hubConnected ? `із ${totalClients} клієнтів CRM` : 'інтеграцію ще не підключено'}
            trend={hubConnected && totalClients ? Math.round((stats.app_users / totalClients) * 100) : null}
            loading={!stats}
          />
          <StatCard
            icon="link"
            accent="#c4b5fd"
            label="Прив’язані до CRM"
            value={hubConnected ? stats.app_users_verified : '—'}
            sub={hubConnected ? `із ${stats.app_users} користувачів застосунку` : 'інтеграцію ще не підключено'}
            trend={hubConnected && stats.app_users ? Math.round((stats.app_users_verified / stats.app_users) * 100) : null}
            loading={!stats}
          />
          <StatCard
            icon="send"
            accent="var(--accent)"
            label="Активні в Telegram"
            value={stats ? stats.telegram_linked : '—'}
            sub={`із ${totalClients} клієнтів CRM`}
            trend={stats && totalClients ? Math.round((stats.telegram_linked / totalClients) * 100) : null}
            loading={!stats}
          />
        </div>

        {/* TELEGRAM БОТИ + ШВИДКІ ДІЇ */}
        <div className="csp-row">
          <div className="csp-bots-section">
            <div className="csp-section-title">Telegram боти</div>
            <div className="csp-bots-grid">
              {BOTS.map((bot) => <BotCard key={bot.name} bot={bot} />)}
            </div>
          </div>

          <div className="csp-actions-section">
            <div className="csp-section-title">Швидкі дії</div>
            <div className="csp-actions-list">
              {QUICK_ACTIONS.map((a) => (
                <button key={a.title} className="csp-action-item" onClick={a.onClick}>
                  <div className="csp-action-icon" style={{ '--csp-accent': a.accent }}><Icon name={a.icon} size={15} /></div>
                  <div className="csp-action-body">
                    <div className="csp-action-title">{a.title}</div>
                    <div className="csp-action-desc">{a.desc}</div>
                  </div>
                  <Icon name="chevronRight" size={14} className="csp-action-arrow" />
                </button>
              ))}
            </div>
          </div>
        </div>

        {/* WORKFLOW */}
        <div className="csp-workflow-wrap">
          <div className="csp-workflow-header">
            <div className="csp-section-title" style={{ marginBottom: 0 }}>Як підключити клієнта</div>
            <button className="csp-link-btn" onClick={() => setInstructionsOpen(true)}>
              Детальна інструкція <Icon name="chevronRight" size={12} />
            </button>
          </div>
          <div className="csp-workflow">
            {STEPS.map((s, i) => (
              <div className="csp-workflow-step" key={s.n}>
                <div className="csp-step-badge">{s.n}</div>
                <div className="csp-step-title">{s.title}</div>
                <div className="csp-step-desc">{s.desc}</div>
                {i < STEPS.length - 1 && <div className="csp-step-arrow">→</div>}
              </div>
            ))}
          </div>
        </div>
      </div>

      {/* Великий QR */}
      <Modal size="sm" open={bigQrOpen} onClose={() => setBigQrOpen(false)} title={`QR-код — ${APP_NAME}`}>
        <div style={{ textAlign: 'center' }}>
          {heroQr && <img src={heroQr} alt="QR-код застосунку" style={{ width: 260, height: 260, margin: '0 auto 16px', display: 'block' }} />}
          <p style={{ color: 'var(--text-muted)', fontSize: 13, marginBottom: 12 }}>Покажіть цей QR клієнту — сканування відкриє {APP_URL}</p>
          <button className="btn btn-ghost" onClick={copyAppLink}><Icon name="copy" size={13} /> Скопіювати посилання</button>
        </div>
      </Modal>

      {/* Інструкція для клієнтів */}
      <Modal size="md" open={instructionsOpen} onClose={() => setInstructionsOpen(false)} title="Як підключити клієнта до застосунку">
        <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
          {STEPS.map((s) => (
            <div key={s.n} style={{ display: 'flex', gap: 12, alignItems: 'flex-start' }}>
              <div className="csp-step-badge" style={{ flexShrink: 0 }}>{s.n}</div>
              <div>
                <div style={{ fontWeight: 600, fontSize: 14 }}>{s.title}</div>
                <div style={{ fontSize: 13, color: 'var(--text-secondary)' }}>{s.desc}</div>
              </div>
            </div>
          ))}
        </div>
      </Modal>
    </AppLayout>
  );
}
