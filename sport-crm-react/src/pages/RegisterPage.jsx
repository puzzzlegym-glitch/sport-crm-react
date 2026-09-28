import { useState, useEffect, useRef } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { api } from '../api/client';
import { isDemoActive, stopDemo } from '../demo';
import Icon from '../components/ui/Icon';
import './RegisterPage.css';

// Оцінка сили пароля (0-5). Логіка 1:1 з register.php.
function pwdStrength(pwd) {
  let sc = 0;
  if (pwd.length >= 8) sc++;
  if (pwd.length >= 12) sc++;
  if (/[A-Z]/.test(pwd)) sc++;
  if (/[0-9]/.test(pwd)) sc++;
  if (/[^A-Za-z0-9]/.test(pwd)) sc++;
  const levels = [
    { w: '0%',   bg: 'transparent',        t: 'Мінімум 8 символів' },
    { w: '25%',  bg: 'var(--danger)',      t: 'Слабкий пароль' },
    { w: '50%',  bg: 'var(--warning)',     t: 'Середній пароль' },
    { w: '75%',  bg: 'var(--info)',        t: 'Гарний пароль' },
    { w: '90%',  bg: 'var(--success)',     t: 'Сильний пароль' },
    { w: '100%', bg: 'var(--success)',     t: 'Відмінний пароль!' },
  ];
  return levels[Math.min(sc, 5)];
}

export default function RegisterPage() {
  const navigate = useNavigate();

  // Реєстрація — реальна дія, завжди має бити в реальний бекенд. /register не
  // обгорнутий у ProtectedRoute, тому AuthContext тут ніколи не викликає refresh()
  // і isDemo з контексту завжди false — покладатись на нього не можна. Чистимо
  // sessionStorage-прапорець демо напряму, синхронно, до першого запиту get_plans
  // нижче: інакше api() підміняє відповідь фейковими DEMO_PLANS, а сабміт форми
  // взагалі впаде з "недоступно у демо-версії" (apiType 'register' не мокається).
  if (isDemoActive()) stopDemo();

  // Дані форми
  const [name, setName]       = useState('');
  const [email, setEmail]     = useState('');
  const [pwd, setPwd]         = useState('');
  const [pwd2, setPwd2]       = useState('');
  const [club, setClub]       = useState('');
  const [city, setCity]       = useState('');

  // Плани
  const [plans, setPlans]     = useState([]);
  const [plansLoading, setPlansLoading] = useState(true);
  const [selectedPlan, setSelectedPlan] = useState(null); // { id, name, price_monthly }

  // Стан email-перевірки: null = ще не перевіряли, true = вільний, false = зайнятий
  const [emailOk, setEmailOk] = useState(null);
  const [emailChecking, setEmailChecking] = useState(false);
  const emailTimer = useRef(null);

  // Загальний стан форми
  const [error, setError]     = useState('');
  const [loading, setLoading] = useState(false);
  const [done, setDone]       = useState(false); // екран успіху

  // Завантаження тарифних планів при відкритті сторінки
  useEffect(() => {
    (async () => {
      const res = await api('get_plans', {}, 'billing');
      if (res.success) {
        setPlans(res.plans);
        // Автоматично обираємо рекомендований план (другий у списку)
        if (res.plans.length > 1) setSelectedPlan(res.plans[1]);
      }
      setPlansLoading(false);
    })();
  }, []);

  // Перевірка email з затримкою 600мс після зупинки набору тексту
  function handleEmailChange(value) {
    setEmail(value);
    setError('');
    setEmailOk(null);
    clearTimeout(emailTimer.current);
    if (!value.includes('@')) return;
    emailTimer.current = setTimeout(async () => {
      setEmailChecking(true);
      const res = await api('check_email', { email: value.trim() }, 'register');
      setEmailOk(res.available === true);
      setEmailChecking(false);
    }, 600);
  }

  async function handleSubmit(e) {
    e.preventDefault();
    setError('');

    if (!name.trim())        return setError("Введіть ваше ім'я");
    if (!email.trim())       return setError('Введіть email');
    if (emailOk === false)   return setError('Цей email вже зареєстровано');
    if (pwd.length < 8)      return setError('Пароль — мінімум 8 символів');
    if (pwd !== pwd2)        return setError('Паролі не збігаються');
    if (!club.trim())        return setError('Введіть назву клубу');
    if (!selectedPlan)       return setError('Оберіть тарифний план');

    setLoading(true);
    const res = await api('register', {
      owner_name: name.trim(),
      email: email.trim(),
      password: pwd,
      password2: pwd2,
      club_name: club.trim(),
      club_city: city.trim(),
      plan_id: selectedPlan.id,
    }, 'register');

    if (res.success) {
      setDone(true);
      window.scrollTo(0, 0);
    } else {
      setError(res.error || 'Помилка реєстрації');
      setLoading(false);
    }
  }

  const strength = pwdStrength(pwd);

  // ── Екран успіху ──────────────────────────────────────────
  if (done) {
    return (
      <div className="reg-wrap">
        <div className="reg-header">
          <div className="reg-logo">Sport<span>CRM</span></div>
          <div className="reg-tagline">Система управління спортивним клубом</div>
        </div>
        <div className="success-screen" style={{ display: 'block' }}>
          <div className="success-icon"><Icon name="checkCircle" size={52} strokeWidth={1.5} /></div>
          <h2>Вітаємо! Реєстрацію завершено</h2>
          <p>
            Ваш клуб підключено до Sport CRM.<br /><br />
            На пошту <strong>{email}</strong> надіслано листа з <strong>посиланням для підтвердження</strong>.<br /><br />
            Перейдіть за посиланням у листі — після цього зможете увійти в систему.<br /><br />
            <span style={{ fontSize: 13, color: 'var(--text-muted)' }}>
              Не отримали листа? Перевірте папку «Спам».
            </span>
          </p>
          <button className="btn btn-primary" style={{ marginTop: 24 }} onClick={() => navigate('/login')}>
            Перейти до входу →
          </button>
        </div>
      </div>
    );
  }

  // ── Основна форма ─────────────────────────────────────────
  return (
    <div className="reg-wrap">
      <div className="reg-header">
        <div className="reg-logo">Sport<span>CRM</span></div>
        <div className="reg-tagline">Система управління спортивним клубом</div>
      </div>

      <div className="reg-grid">
        {/* Ліворуч: плани */}
        <div>
          <div className="section-label">Оберіть тарифний план</div>
          <div className="plan-cards">
            {plansLoading && (
              <div className="loader"><div className="spinner" /></div>
            )}
            {!plansLoading && plans.map((p, i) => (
              <div
                key={p.id}
                className={`plan-card ${i === 1 ? 'featured' : ''} ${selectedPlan?.id === p.id ? 'selected' : ''}`}
                onClick={() => setSelectedPlan(p)}
              >
                {i === 1 ? (
                  <div className="plan-featured-badge">Рекомендовано</div>
                ) : p.discount_active && (
                  <div className="plan-featured-badge" style={{ background: 'var(--danger)', display: 'flex', alignItems: 'center', gap: 4 }}>
                    <Icon name="tag" size={12} /> {p.discount_label || `Акція −${p.discount_percent}%`}
                  </div>
                )}
                <div className="plan-head">
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    <div className="plan-select-mark">✓</div>
                    <div className="plan-name">{p.name}</div>
                  </div>
                  <div className="plan-price">
                    {p.discount_active ? (
                      <>
                        <span style={{ textDecoration: 'line-through', fontSize: 12, fontWeight: 400, color: 'var(--text-muted)', marginRight: 6 }}>{p.price_monthly} грн</span>
                        <span style={{ color: 'var(--danger)' }}>{p.price_effective}</span> <span>грн/міс</span>
                      </>
                    ) : <>{p.price_monthly} <span>грн/міс</span></>}
                  </div>
                </div>
                <div style={{ fontSize: 12, color: 'var(--text-muted)', marginBottom: 6 }}>
                  {p.clients_limit ? `до ${p.clients_limit} клієнтів` : 'Необмежено клієнтів'} ·{' '}
                  {p.users_limit ? `до ${p.users_limit} співробітників` : 'Необмежено співробітників'}
                </div>
                <div className="plan-features">
                  {(p.features || []).map((f, idx) => (
                    <div className="plan-feature" key={idx}>{f}</div>
                  ))}
                </div>
              </div>
            ))}
          </div>
          <div className="trial-note">
            <div className="trial-note-title"><Icon name="gift" size={15} style={{ verticalAlign: -3, marginRight: 6 }} />14 днів безкоштовно</div>
            Після реєстрації ви отримуєте повний доступ до обраного плану без оплати. Картка не потрібна.
          </div>
        </div>

        {/* Праворуч: форма */}
        <div className="reg-form-card">
          <div className="reg-form-title">Реєстрація клубу</div>
          <div className="reg-form-sub">Почніть безкоштовно — 14 днів тріалу</div>

          {selectedPlan && (
            <div className="selected-plan-summary" style={{ display: 'block' }}>
              Обраний план: <strong>{selectedPlan.name}</strong>
              <span style={{ marginLeft: 8, color: 'var(--text-secondary)' }}>
                · {selectedPlan.discount_active && (
                  <span style={{ textDecoration: 'line-through', marginRight: 4 }}>{selectedPlan.price_monthly} грн</span>
                )}
                {selectedPlan.discount_active ? selectedPlan.price_effective : selectedPlan.price_monthly} грн/міс після тріалу
                {selectedPlan.discount_active && (
                  <span style={{ color: 'var(--danger)', marginLeft: 6, display: 'inline-flex', alignItems: 'center', gap: 3 }}>
                    <Icon name="tag" size={12} /> акція
                  </span>
                )}
              </span>
            </div>
          )}

          <div className="trial-badge">
            <Icon name="clock" size={14} /> 14 днів безкоштовно · картка не потрібна
          </div>

          {error && <div className="alert alert-error" style={{ display: 'block' }}>{error}</div>}

          <form onSubmit={handleSubmit}>
            <div className="section-label small">Про вас</div>

            <div className="form-group">
              <label>Ім'я та прізвище *</label>
              <input
                type="text"
                placeholder="Іван Петренко"
                maxLength={120}
                value={name}
                onChange={(e) => { setName(e.target.value); setError(''); }}
              />
            </div>

            <div className="form-group">
              <label>Email *</label>
              <input
                type="email"
                placeholder="ivan@gmail.com"
                autoComplete="email"
                value={email}
                onChange={(e) => handleEmailChange(e.target.value)}
              />
              <div className={`email-status ${emailOk === true ? 'ok' : emailOk === false ? 'err' : ''}`}>
                {emailChecking && 'Перевіряємо...'}
                {!emailChecking && emailOk === true && '✓ Email вільний'}
                {!emailChecking && emailOk === false && '✗ Цей email вже зареєстровано'}
              </div>
            </div>

            <div className="form-row-2">
              <div className="form-group">
                <label>Пароль *</label>
                <input
                  type="password"
                  placeholder="Мінімум 8 символів"
                  autoComplete="new-password"
                  value={pwd}
                  onChange={(e) => { setPwd(e.target.value); setError(''); }}
                />
                <div className="pwd-strength">
                  <div className="pwd-strength-bar" style={{ width: strength.w, background: strength.bg }} />
                </div>
                <div className="pwd-hint">{strength.t}</div>
              </div>
              <div className="form-group">
                <label>Повторіть пароль *</label>
                <input
                  type="password"
                  placeholder="Повторіть"
                  autoComplete="new-password"
                  value={pwd2}
                  onChange={(e) => { setPwd2(e.target.value); setError(''); }}
                />
              </div>
            </div>

            <div className="section-label small" style={{ marginTop: 20 }}>Про клуб</div>

            <div className="form-row-2">
              <div className="form-group" style={{ gridColumn: '1/-1' }}>
                <label>Назва клубу *</label>
                <input
                  type="text"
                  placeholder="Drive Sport Club"
                  maxLength={120}
                  value={club}
                  onChange={(e) => { setClub(e.target.value); setError(''); }}
                />
              </div>
              <div className="form-group">
                <label>Місто</label>
                <input
                  type="text"
                  placeholder="Київ"
                  maxLength={80}
                  value={city}
                  onChange={(e) => setCity(e.target.value)}
                />
              </div>
            </div>

            <button className="btn-register" type="submit" disabled={loading}>
              {loading ? 'Реєструємо...' : 'Зареєструватись безкоштовно'}
            </button>
          </form>

          <p className="login-link">
            Вже маєте акаунт? <Link to="/login">Увійти →</Link>
          </p>
        </div>
      </div>
    </div>
  );
}
