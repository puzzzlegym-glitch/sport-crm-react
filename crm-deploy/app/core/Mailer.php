<?php
/**
 * Mailer.php — Відправка email через SMTP
 *
 * Використовує PHPMailer якщо є, або власний SMTP-клієнт.
 * Для хостингу Україна зазвичай достатньо вбудованого mail(),
 * але SMTP надійніше і не потрапляє в спам.
 *
 * Використання:
 *   Mailer::send('user@gmail.com', 'Тема листа', '<p>HTML тіло</p>');
 *   Mailer::sendTemplate('welcome', ['name'=>'Іван', 'club'=>'Drive'], 'user@gmail.com');
 */

class Mailer
{
    // ── Головний метод відправки ─────────────────────────────

    public static function send(
        string $to,
        string $subject,
        string $htmlBody,
        string $toName = ''
    ): bool {
        // Спочатку пробуємо SMTP через сокет
        try {
            return self::sendSmtp($to, $subject, $htmlBody, $toName);
        } catch (Throwable $e) {
            error_log('[Mailer] SMTP failed: ' . $e->getMessage() . ' — trying mail()');
        }

        // Fallback на mail() якщо SMTP недоступний
        return self::sendMailFallback($to, $subject, $htmlBody);
    }

    // ── Відправка через SMTP (власна реалізація) ─────────────

    private static function sendSmtp(
        string $to,
        string $subject,
        string $htmlBody,
        string $toName = ''
    ): bool {
        $host    = SMTP_HOST;
        $port    = (int)SMTP_PORT;
        $user    = SMTP_USERNAME;
        $pass    = SMTP_PASSWORD;
        $secure  = SMTP_SECURE; // 'ssl' або 'tls'
        $from    = SMTP_FROM_EMAIL;
        $fromName= SMTP_FROM_NAME;

        // Підключення
        $context = stream_context_create([
            'ssl' => [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false,
            ],
        ]);

        $proto = ($secure === 'ssl') ? 'ssl' : 'tcp';
        $socket = @stream_socket_client(
            "{$proto}://{$host}:{$port}",
            $errno, $errstr, 15,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$socket) {
            throw new RuntimeException("SMTP connect failed: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, 15);

        $read = fn() => fgets($socket, 515);
        $write = fn($cmd) => fwrite($socket, $cmd . "\r\n");

        // SMTP handshake
        $read(); // 220 greeting
        $write("EHLO " . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        while ($line = $read()) { if ($line[3] === ' ') break; } // читаємо всі 250-

        // STARTTLS якщо порт 587
        if ($secure === 'tls') {
            $write("STARTTLS");
            $read();
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $write("EHLO " . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
            while ($line = $read()) { if ($line[3] === ' ') break; }
        }

        // AUTH LOGIN
        $write("AUTH LOGIN");
        $read();
        $write(base64_encode($user));
        $read();
        $write(base64_encode($pass));
        $resp = $read();
        if (substr($resp, 0, 3) !== '235') {
            throw new RuntimeException("SMTP auth failed: {$resp}");
        }

        // Конверт
        $write("MAIL FROM:<{$from}>");
        $read();
        $write("RCPT TO:<{$to}>");
        $read();
        $write("DATA");
        $read();

        // Будуємо лист
        $boundary = md5(uniqid('', true));
        $toDisplay = $toName ? "{$toName} <{$to}>" : $to;
        $date = date('r');
        $subjectEncoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $fromEncoded = '=?UTF-8?B?' . base64_encode($fromName) . '?=';

        $message  = "From: {$fromEncoded} <{$from}>\r\n";
        $message .= "To: {$toDisplay}\r\n";
        $message .= "Subject: {$subjectEncoded}\r\n";
        $message .= "Date: {$date}\r\n";
        $message .= "MIME-Version: 1.0\r\n";
        $message .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        $message .= "\r\n";

        // Plain text версія (автоматично з HTML)
        $plainText = strip_tags(str_replace(['<br>', '<br/>', '</p>'], "\n", $htmlBody));
        $message .= "--{$boundary}\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $message .= chunk_split(base64_encode($plainText)) . "\r\n";

        // HTML версія
        $message .= "--{$boundary}\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $message .= chunk_split(base64_encode($htmlBody)) . "\r\n";

        $message .= "--{$boundary}--\r\n";
        $message .= ".\r\n";

        fwrite($socket, $message);
        $resp = $read();

        $write("QUIT");
        fclose($socket);

        if (substr($resp, 0, 3) !== '250') {
            throw new RuntimeException("SMTP send failed: {$resp}");
        }

        return true;
    }

    // ── Fallback через mail() ────────────────────────────────

    private static function sendMailFallback(string $to, string $subject, string $htmlBody): bool
    {
        $headers  = "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM_EMAIL . ">\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        return mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $htmlBody, $headers);
    }

    // ── Шаблони листів ──────────────────────────────────────

    /**
     * Відправляє лист за шаблоном.
     *
     * Доступні шаблони:
     *   welcome          — вітання після реєстрації
     *   trial_warning_7  — 7 днів до кінця тріалу
     *   trial_warning_1  — 1 день до кінця тріалу
     *   trial_expired    — тріал закінчився
     *   payment_success  — оплата пройшла
     *   payment_failed   — оплата не пройшла
     *   new_club_admin   — SuperAdmin: новий клуб зареєстровано
     */
    public static function sendTemplate(string $template, array $vars, string $to, string $toName = ''): bool
    {
        [$subject, $html] = self::buildTemplate($template, $vars);
        return self::send($to, $subject, $html, $toName);
    }

    private static function buildTemplate(string $tpl, array $v): array
    {
        $appName = APP_NAME;
        $appUrl  = APP_URL;

        // Загальна обгортка
        $wrap = fn(string $title, string $body) => self::wrapHtml($title, $body, $appName, $appUrl);

        return match ($tpl) {

            'club_invite' => [
                "Вас додано до клубу — {$appName}",
                $wrap('Вас додано до клубу', "
                    <p>Привіт, <strong>{$v['full_name']}</strong>!</p>
                    <p>Вас додано до клубу <strong>«{$v['club_name']}»</strong> як <strong>{$v['role_label']}</strong>.</p>
                    <p style='margin-top:20px'>
                      <a href='{$appUrl}/dashboard' style='background:#4f9cf9;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600'>
                        Відкрити клуб →
                      </a>
                    </p>
                "),
            ],

            'welcome' => [
                "Ласкаво просимо до {$appName}!",
                $wrap("Ласкаво просимо до {$appName}!", "
                    <p>Привіт, <strong>{$v['owner_name']}</strong>!</p>
                    <p>Ваш клуб <strong>«{$v['club_name']}»</strong> успішно зареєстровано.</p>
                    <p>У вас є <strong>14 днів безкоштовного тріалу</strong> плану Business — повний доступ до всіх функцій.</p>
                    <p>Тріал діє до: <strong>{$v['trial_ends']}</strong></p>
                    <p style='margin-top:24px'>
                      <a href='{$appUrl}/login' style='background:#4f9cf9;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600'>
                        Увійти в систему →
                      </a>
                    </p>
                    <p style='margin-top:24px;color:#888;font-size:13px'>
                      Є питання? Напишіть нам: " . SMTP_FROM_EMAIL . "
                    </p>
                "),
            ],

            'trial_warning_7' => [
                "⏰ До кінця тріалу залишилось 7 днів — {$appName}",
                $wrap('Тріал закінчується через 7 днів', "
                    <p>Привіт, <strong>{$v['owner_name']}</strong>!</p>
                    <p>Нагадуємо: тріал вашого клубу <strong>«{$v['club_name']}»</strong> закінчується <strong>{$v['trial_ends']}</strong>.</p>
                    <p>Щоб продовжити роботу без перерви — оберіть план підписки:</p>
                    <p style='margin-top:20px'>
                      <a href='{$appUrl}/billing' style='background:#4f9cf9;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600'>
                        Обрати план →
                      </a>
                    </p>
                "),
            ],

            'trial_warning_1' => [
                "🔔 Завтра закінчується тріал — {$appName}",
                $wrap('Завтра закінчується тріал', "
                    <p>Привіт, <strong>{$v['owner_name']}</strong>!</p>
                    <p>Завтра, <strong>{$v['trial_ends']}</strong>, закінчується тріал клубу <strong>«{$v['club_name']}»</strong>.</p>
                    <p>Після закінчення ви зможете переглядати дані, але не редагувати.</p>
                    <p style='margin-top:20px'>
                      <a href='{$appUrl}/billing' style='background:#f59e0b;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600'>
                        Оплатити зараз →
                      </a>
                    </p>
                "),
            ],

            'trial_expired' => [
                "Тріал закінчився — оберіть план — {$appName}",
                $wrap('Тріал закінчився', "
                    <p>Привіт, <strong>{$v['owner_name']}</strong>!</p>
                    <p>Тріал клубу <strong>«{$v['club_name']}»</strong> закінчився.</p>
                    <p>Ваші дані збережені. Для продовження роботи оберіть план підписки.</p>
                    <p style='color:#888;font-size:13px'>Дані зберігаються ще 90 днів.</p>
                    <p style='margin-top:20px'>
                      <a href='{$appUrl}/billing' style='background:#ef4444;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600'>
                        Обрати план →
                      </a>
                    </p>
                "),
            ],

            'payment_success' => [
                "✅ Оплату підтверджено — {$appName}",
                $wrap('Оплату підтверджено', "
                    <p>Привіт, <strong>{$v['owner_name']}</strong>!</p>
                    <p>Оплату за план <strong>{$v['plan_name']}</strong> підтверджено.</p>
                    <table style='width:100%;border-collapse:collapse;margin:16px 0;font-size:14px'>
                      <tr><td style='padding:8px 0;border-bottom:1px solid #2d3748;color:#888'>Рахунок №</td><td style='padding:8px 0;border-bottom:1px solid #2d3748'>{$v['invoice_id']}</td></tr>
                      <tr><td style='padding:8px 0;border-bottom:1px solid #2d3748;color:#888'>Сума</td><td style='padding:8px 0;border-bottom:1px solid #2d3748'>{$v['amount']} грн</td></tr>
                      <tr><td style='padding:8px 0;border-bottom:1px solid #2d3748;color:#888'>Період</td><td style='padding:8px 0;border-bottom:1px solid #2d3748'>{$v['period']}</td></tr>
                      <tr><td style='padding:8px 0;color:#888'>Наступне списання</td><td style='padding:8px 0'>{$v['next_billing']}</td></tr>
                    </table>
                    <p style='margin-top:20px'>
                      <a href='{$appUrl}/billing' style='background:#4f9cf9;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600'>
                        Переглянути білінг →
                      </a>
                    </p>
                "),
            ],

            'new_club_admin' => [
                "🆕 Новий клуб: {$v['club_name']}",
                $wrap("Новий клуб зареєстровано", "
                    <p>Новий клуб зареєстровано на платформі.</p>
                    <table style='width:100%;border-collapse:collapse;font-size:14px'>
                      <tr><td style='padding:8px 0;border-bottom:1px solid #2d3748;color:#888;width:140px'>Клуб</td><td style='padding:8px 0;border-bottom:1px solid #2d3748'><strong>{$v['club_name']}</strong></td></tr>
                      <tr><td style='padding:8px 0;border-bottom:1px solid #2d3748;color:#888'>Місто</td><td style='padding:8px 0;border-bottom:1px solid #2d3748'>{$v['club_city']}</td></tr>
                      <tr><td style='padding:8px 0;border-bottom:1px solid #2d3748;color:#888'>Власник</td><td style='padding:8px 0;border-bottom:1px solid #2d3748'>{$v['owner_name']}</td></tr>
                      <tr><td style='padding:8px 0;color:#888'>Email</td><td style='padding:8px 0'>{$v['owner_email']}</td></tr>
                    </table>
                    <p style='margin-top:20px'>
                      <a href='{$appUrl}/clubs' style='background:#4f9cf9;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600'>
                        Переглянути клуби →
                      </a>
                    </p>
                "),
            ],

            'password_reset' => [
                "Відновлення пароля — {$appName}",
                $wrap('Відновлення пароля', "
                    <p>Привіт" . (!empty($v['full_name']) ? ", <strong>{$v['full_name']}</strong>" : "") . "!</p>
                    <p>Ми отримали запит на відновлення пароля для вашого акаунту в {$appName}.</p>
                    <p style='margin-top:20px'>
                      <a href='{$v['reset_url']}' style='background:#4f9cf9;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600'>
                        Встановити новий пароль →
                      </a>
                    </p>
                    <p style='margin-top:16px;color:#888;font-size:13px'>Посилання дійсне 1 годину.</p>
                    <p style='color:#888;font-size:12px'>Якщо ви не запитували відновлення пароля — просто ігноруйте цей лист, пароль залишиться незмінним.</p>
                "),
            ],

            'verify_email' => [
                "Підтвердіть email — {$appName}",
                $wrap('Підтвердіть вашу електронну адресу', "
                    <p>Привіт, <strong>{$v['owner_name']}</strong>!</p>
                    <p>Дякуємо за реєстрацію клубу <strong>«{$v['club_name']}»</strong>.</p>
                    <p>Для активації акаунту підтвердіть вашу email-адресу:</p>
                    <p style='margin-top:20px'>
                      <a href='{$v['verify_url']}' style='background:#4f9cf9;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600'>
                        Підтвердити email →
                      </a>
                    </p>
                    <p style='margin-top:16px;color:#888;font-size:13px'>
                      Тріал розпочнеться після підтвердження і діятиме до <strong>{$v['trial_ends']}</strong>.
                    </p>
                    <p style='color:#888;font-size:12px'>Якщо ви не реєструвались — просто ігноруйте цей лист.</p>
                "),
            ],

            default => throw new InvalidArgumentException("Невідомий шаблон: {$tpl}"),
        };
    }

    /**
     * Простий лист без шаблону — для довільних повідомлень.
     * Повертає готовий HTML для передачі в send().
     */
    public static function simpleMessage(string $title, string $body): string
    {
        return self::wrapHtml($title, "<h2 style='font-size:18px;margin-bottom:16px'>{$title}</h2>{$body}", APP_NAME, APP_URL);
    }

    // ── HTML обгортка листа ──────────────────────────────────

    private static function wrapHtml(string $title, string $body, string $appName, string $appUrl): string
    {
        return <<<HTML
        <!DOCTYPE html>
        <html lang="uk">
        <head>
          <meta charset="UTF-8">
          <meta name="viewport" content="width=device-width,initial-scale=1">
          <title>{$title}</title>
        </head>
        <body style="margin:0;padding:0;background:#0f1117;font-family:'DM Sans',Arial,sans-serif;color:#e2e8f0">
          <table width="100%" cellpadding="0" cellspacing="0" style="padding:40px 20px">
            <tr><td align="center">
              <table width="540" cellpadding="0" cellspacing="0" style="background:#1a1f2e;border-radius:12px;border:1px solid #252d40;overflow:hidden;max-width:100%">

                <!-- Хедер -->
                <tr>
                  <td style="padding:24px 32px;border-bottom:1px solid #252d40">
                    <span style="font-size:20px;font-weight:700;color:#e2e8f0">{$appName}</span>
                  </td>
                </tr>

                <!-- Тіло -->
                <tr>
                  <td style="padding:32px;font-size:15px;line-height:1.7;color:#e2e8f0">
                    {$body}
                  </td>
                </tr>

                <!-- Футер -->
                <tr>
                  <td style="padding:20px 32px;border-top:1px solid #252d40;font-size:12px;color:#515d7a">
                    © {$appName} · <a href="{$appUrl}" style="color:#4f9cf9;text-decoration:none">{$appUrl}</a>
                    <br>Якщо ви не реєструвались — просто ігноруйте цей лист.
                  </td>
                </tr>

              </table>
            </td></tr>
          </table>
        </body>
        </html>
        HTML;
    }
}
