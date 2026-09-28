import { useState } from 'react';
import { Link } from 'react-router-dom';
import { forgotPassword } from '../api/auth';
import './LoginPage.css';

export default function ForgotPasswordPage() {
  const [email, setEmail] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const [sent, setSent] = useState(false);

  async function handleSubmit(e) {
    e.preventDefault();
    setError('');

    if (!email.trim()) {
      setError('Введіть email');
      return;
    }

    setLoading(true);
    const res = await forgotPassword(email.trim());
    setLoading(false);

    // Повідомлення однакове незалежно від результату — бекенд навмисно
    // не розкриває, чи існує такий email
    if (res.success) {
      setSent(true);
    } else {
      setError(res.error || 'Помилка. Спробуйте пізніше');
    }
  }

  return (
    <div className="login-page">
      <div className="login-wrap">
        <div className="login-logo">
          <div className="logo-text">Sport<span>CRM</span></div>
          <div className="logo-sub">Панель адміністратора</div>
        </div>

        <div className="login-card">
          <h1>Відновлення пароля</h1>
          <p className="subtitle">Введіть email — надішлемо посилання для скидання пароля</p>

          {error && <div className="error-box show">{error}</div>}

          {sent ? (
            <div className="auth-msg-box">
              Якщо такий email зареєстровано — на нього надіслано лист з інструкціями. Перевірте пошту (і папку "Спам").
            </div>
          ) : (
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

              <button className="btn-login" type="submit" disabled={loading}>
                <span>{loading ? 'Надсилаємо...' : 'Надіслати посилання'}</span>
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
