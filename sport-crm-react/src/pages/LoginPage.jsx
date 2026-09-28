import { useEffect, useState } from 'react';
import { useNavigate, useSearchParams, Navigate, Link } from 'react-router-dom';
import { login } from '../api/auth';
import { api } from '../api/client';
import { useAuth } from '../context/AuthContext';
import Icon from '../components/ui/Icon';
import './LoginPage.css';

export default function LoginPage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { status, refresh, enterDemo } = useAuth();

  // Посилання з лендінгу (?demo=1) — заходимо в демо одразу, без зайвого кліку
  useEffect(() => {
    if (searchParams.get('demo') === '1' && status !== 'ready') {
      enterDemo();
      navigate('/dashboard', { replace: true });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const [email, setEmail]     = useState('');
  const [password, setPassword] = useState('');
  const [showPwd, setShowPwd] = useState(false);
  const [error, setError]     = useState('');
  const [loading, setLoading] = useState(false);
  // Акаунт чекає підтвердження email → показуємо кнопку повторного листа
  const [needsVerify, setNeedsVerify] = useState(false);
  const [info, setInfo]       = useState(() => {
    const v = searchParams.get('verified');
    if (v === '1') return 'Email підтверджено! Тепер можете увійти.';
    if (v === 'already') return 'Посилання вже використано або воно застаріло. Спробуйте увійти.';
    return '';
  });
  const [resending, setResending] = useState(false);

  // Якщо людина вже залогінена — одразу перекидаємо на дашборд,
  // форму логіну навіть не показуємо
  if (status === 'ready') {
    return <Navigate to="/dashboard" replace />;
  }

  async function handleSubmit(e) {
    e.preventDefault(); // не даємо сторінці перезавантажитись при сабміті форми

    setError('');
    setInfo('');
    setNeedsVerify(false);

    if (!email.trim() || !password) {
      setError('Заповніть всі поля');
      return;
    }

    setLoading(true);

    const res = await login(email.trim(), password);

    if (res.success) {
      await refresh(); // підтягуємо дані користувача (права, клуб тощо) в AuthContext
      navigate(res.redirect || '/dashboard');
    } else {
      setError(res.error || 'Помилка входу');
      setNeedsVerify(res.reason === 'email_not_verified');
      setLoading(false);
    }
  }

  async function handleResend() {
    setResending(true);
    const res = await api('resend_verification', { email: email.trim() }, 'register');
    setResending(false);
    if (!res.success) { setError(res.error || 'Не вдалося надіслати лист'); return; }
    setError('');
    setNeedsVerify(false);
    setInfo(res.message);
  }

  function handleTryDemo() {
    enterDemo();
    navigate('/dashboard');
  }

  return (
    <div className="login-page">
      <div className="login-wrap">
        <div className="login-logo">
          <div className="logo-text">Sport<span>CRM</span></div>
          <div className="logo-sub">Панель адміністратора</div>
        </div>

        <div className="login-card">
          <h1>Вхід до системи</h1>
          <p className="subtitle">Введіть ваші облікові дані</p>

          {info && <div className="info-box">{info}</div>}
          {error && <div className="error-box show">{error}</div>}
          {needsVerify && (
            <button type="button" className="btn-resend" disabled={resending} onClick={handleResend}>
              {resending ? 'Надсилаємо...' : '✉️ Надіслати лист ще раз'}
            </button>
          )}

          <form onSubmit={handleSubmit}>
            <div className="form-group">
              <label htmlFor="email">Email</label>
              <input
                type="email"
                id="email"
                placeholder="admin@example.com"
                autoComplete="email"
                inputMode="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
              />
            </div>

            <div className="form-group">
              <label htmlFor="password">Пароль</label>
              <div className="input-wrap">
                <input
                  type={showPwd ? 'text' : 'password'}
                  id="password"
                  placeholder="Ваш пароль"
                  autoComplete="current-password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  required
                />
                <button
                  className="toggle-pwd"
                  type="button"
                  title="Показати/сховати пароль"
                  onClick={() => setShowPwd((v) => !v)}
                >
                  {showPwd ? '🙈' : '👁'}
                </button>
              </div>
            </div>

            <button className="btn-login" type="submit" disabled={loading}>
              <span>{loading ? 'Входимо...' : 'Увійти'}</span>
            </button>
          </form>

          <button type="button" className="btn-login" style={{ background: 'transparent', border: '1px solid var(--border)', color: 'var(--text-primary)', marginTop: 10, display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 8 }} onClick={handleTryDemo}>
            <Icon name="trendingUp" size={16} />
            <span>Спробувати демо без реєстрації</span>
          </button>

          <div className="login-actions">
            <Link to="/forgot-password">Забули пароль?</Link>
            <Link to="/register">Зареєструвати клуб →</Link>
          </div>
        </div>

        <div className="login-footer">
          Sport CRM &mdash; управління спортивним клубом
        </div>
      </div>
    </div>
  );
}
