import { useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { resetPassword } from '../api/auth';
import './LoginPage.css';

export default function ResetPasswordPage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const token = searchParams.get('token') || '';

  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [showPwd, setShowPwd] = useState(false);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const [done, setDone] = useState(false);

  async function handleSubmit(e) {
    e.preventDefault();
    setError('');

    if (!token) {
      setError('Посилання недійсне. Запросіть нове на сторінці відновлення пароля');
      return;
    }
    if (password.length < 8) {
      setError('Пароль має містити мінімум 8 символів');
      return;
    }
    if (password !== confirm) {
      setError('Паролі не збігаються');
      return;
    }

    setLoading(true);
    const res = await resetPassword(token, password);
    setLoading(false);

    if (!res.success) {
      setError(res.error || 'Не вдалося змінити пароль');
      return;
    }
    setDone(true);
    setTimeout(() => navigate('/login'), 2500);
  }

  return (
    <div className="login-page">
      <div className="login-wrap">
        <div className="login-logo">
          <div className="logo-text">Sport<span>CRM</span></div>
          <div className="logo-sub">Панель адміністратора</div>
        </div>

        <div className="login-card">
          <h1>Новий пароль</h1>
          <p className="subtitle">Придумайте новий пароль для входу</p>

          {error && <div className="error-box show">{error}</div>}

          {done ? (
            <div className="auth-msg-box">
              Пароль змінено! Перенаправляємо на сторінку входу...
            </div>
          ) : !token ? (
            <div className="error-box show">
              Посилання недійсне. Перейдіть на сторінку відновлення пароля і запросіть нове.
            </div>
          ) : (
            <form onSubmit={handleSubmit}>
              <div className="form-group">
                <label htmlFor="password">Новий пароль</label>
                <div className="input-wrap">
                  <input
                    type={showPwd ? 'text' : 'password'}
                    id="password"
                    placeholder="Мін. 8 символів"
                    autoComplete="new-password"
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

              <div className="form-group">
                <label htmlFor="confirm">Повторіть пароль</label>
                <input
                  type={showPwd ? 'text' : 'password'}
                  id="confirm"
                  autoComplete="new-password"
                  value={confirm}
                  onChange={(e) => setConfirm(e.target.value)}
                  required
                />
              </div>

              <button className="btn-login" type="submit" disabled={loading}>
                <span>{loading ? 'Зберігаємо...' : 'Зберегти новий пароль'}</span>
              </button>
            </form>
          )}

          <p className="back-to-login">
            <Link to="/login">← Повернутись до входу</Link>
          </p>
        </div>

        <div className="login-footer">
          Sport CRM &mdash; управління спортивним клубом
        </div>
      </div>
    </div>
  );
}
