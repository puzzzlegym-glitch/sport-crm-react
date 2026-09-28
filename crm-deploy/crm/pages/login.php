<?php
$pageTitle = 'Вхід';
$pageCss   = null;
$extraCss  = '
body{display:flex;align-items:center;justify-content:center;min-height:100vh;
  background-image:linear-gradient(var(--border) 1px,transparent 1px),linear-gradient(90deg,var(--border) 1px,transparent 1px);
  background-size:40px 40px;background-color:var(--bg-base)}
.login-wrap{width:100%;max-width:400px;padding:24px}
.login-logo{text-align:center;margin-bottom:32px}
.login-logo .logo-text{font-size:26px;font-weight:700;letter-spacing:-1px;color:var(--text-primary)}
.login-logo .logo-text span{color:var(--accent)}
.login-logo .logo-sub{font-size:13px;color:var(--text-muted);margin-top:4px}
.login-card{background:var(--bg-surface);border:1px solid var(--border);border-radius:var(--radius-lg);padding:32px;box-shadow:var(--shadow-md)}
.login-card h1{font-size:18px;font-weight:600;margin-bottom:6px}
.login-card .subtitle{font-size:13px;color:var(--text-muted);margin-bottom:28px}
.btn-login{width:100%;padding:12px;background:var(--accent);color:#fff;border:none;border-radius:var(--radius-sm);font-size:15px;font-weight:600;font-family:inherit;cursor:pointer;transition:background .15s;margin-top:8px;display:flex;align-items:center;justify-content:center;gap:8px}
.btn-login:hover:not(:disabled){background:var(--accent-hover)}
.btn-login:disabled{opacity:.7;cursor:not-allowed}
.error-box{background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.3);border-radius:var(--radius-sm);padding:10px 14px;font-size:13px;color:var(--danger);margin-bottom:16px;display:none}
.error-box.show{display:block}
.input-wrap{position:relative}.input-wrap input{padding-right:40px}
.toggle-pwd{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text-muted);cursor:pointer;padding:4px;font-size:15px}
.toggle-pwd:hover{color:var(--text-secondary)}
.login-footer{text-align:center;margin-top:24px;font-size:12px;color:var(--text-muted)}
';
require __DIR__ . '/../partials/head_auth.php';
?>
<body>

<div class="login-wrap">

  <div class="login-logo">
    <div class="logo-text">Sport<span>CRM</span></div>
    <div class="logo-sub">Панель адміністратора</div>
  </div>

  <div class="login-card">
    <h1>Вхід до системи</h1>
    <p class="subtitle">Введіть ваші облікові дані</p>

    <div class="error-box" id="error-box"></div>

    <div class="form-group">
      <label for="email">Email</label>
      <input
        type="email"
        id="email"
        name="email"
        placeholder="admin@example.com"
        autocomplete="email"
        inputmode="email"
        required
      >
    </div>

    <div class="form-group">
      <label for="password">Пароль</label>
      <div class="input-wrap">
        <input
          type="password"
          id="password"
          name="password"
          placeholder="Ваш пароль"
          autocomplete="current-password"
          required
        >
        <button class="toggle-pwd" type="button" onclick="togglePwd()" title="Показати/сховати пароль">
          <span id="eye-icon">&#128065;</span>
        </button>
      </div>
    </div>

    <button class="btn-login" id="btn-login" onclick="doLogin()">
      <span id="btn-text">Увійти</span>
    </button>

    <p style="text-align:center;margin-top:18px;font-size:13px;color:#515d7a">
      Немає акаунту?
      <a href="/register" style="color:#4f9cf9;font-weight:500">
        Зареєструвати клуб →
      </a>
    </p>
  </div>

  <div class="login-footer">
    Sport CRM &mdash; управління спортивним клубом
  </div>

</div>

<script>
  // Якщо вже авторизований — редиректимо
  (async () => {
    try {
      const r = await fetch('/api/auth_api.php?action=check', {
        credentials: 'include'
      });
      const d = await r.json();
      if (d.authenticated) window.location.href = '/dashboard';
    } catch {}
  })();

  async function doLogin() {
    const email    = document.getElementById('email').value.trim();
    const password = document.getElementById('password').value;
    const btn      = document.getElementById('btn-login');
    const errBox   = document.getElementById('error-box');

    // Скидаємо помилку
    errBox.classList.remove('show');

    // Валідація
    if (!email || !password) {
      showError('Заповніть всі поля');
      return;
    }

    // Блокуємо кнопку і показуємо лоадер
    btn.disabled = true;
    document.getElementById('btn-text').innerHTML =
      '<span class="spinner" style="border-top-color:#fff;width:16px;height:16px;border-width:2px"></span> Входимо...';

    try {
      const resp = await fetch('/api/auth_api.php?action=login', {
        method:      'POST',
        credentials: 'include',
        headers:     { 'Content-Type': 'application/json' },
        body:        JSON.stringify({ email, password }),
      });

      const data = await resp.json();

      if (data.success) {
        // Успіх — редирект на дашборд
        document.getElementById('btn-text').textContent = '✓ Успішно!';
        setTimeout(() => window.location.href = data.redirect || '/dashboard', 400);
      } else {
        showError(data.error || 'Помилка входу');
        resetBtn();
      }
    } catch (err) {
      showError('Не вдалося підключитися до сервера');
      resetBtn();
    }
  }

  function showError(msg) {
    const box = document.getElementById('error-box');
    box.textContent = msg;
    box.classList.add('show');
  }

  function resetBtn() {
    const btn = document.getElementById('btn-login');
    btn.disabled = false;
    document.getElementById('btn-text').textContent = 'Увійти';
  }

  function togglePwd() {
    const inp = document.getElementById('password');
    const ico = document.getElementById('eye-icon');
    if (inp.type === 'password') {
      inp.type = 'text';
      ico.textContent = '🙈';
    } else {
      inp.type = 'password';
      ico.innerHTML = '&#128065;';
    }
  }

  // Enter для сабміту
  document.addEventListener('keydown', e => {
    if (e.key === 'Enter') doLogin();
  });
</script>

</body>

</html>
