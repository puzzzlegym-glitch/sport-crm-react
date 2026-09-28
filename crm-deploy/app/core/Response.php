<?php
/**
 * Response.php — Стандартні JSON-відповіді API
 *
 * Замість того щоб писати json_encode() скрізь —
 * викликаємо один метод. Однаковий формат в усьому API.
 *
 * Приклади:
 *   Response::ok(['user' => $user]);
 *   Response::error('Клієнта не знайдено', 404);
 *   Response::unauthorized();
 */

class Response
{
    /**
     * Успішна відповідь (HTTP 200)
     * $data — масив даних, які передаємо фронтенду
     */
    public static function ok(array $data = [], string $message = ''): never
    {
        self::send(200, array_merge(
            ['success' => true],
            $message ? ['message' => $message] : [],
            $data
        ));
    }

    /**
     * Помилка з кастомним HTTP-кодом
     * $code: 400 = невірний запит, 403 = немає прав, 404 = не знайдено, 500 = помилка сервера
     * $extra — додаткові поля для фронтенду (напр. reason/invoice_id), щоб той міг
     * запропонувати конкретну дію замість просто показу тексту помилки.
     */
    public static function error(string $message, int $code = 400, array $extra = []): never
    {
        self::send($code, array_merge([
            'success' => false,
            'error'   => $message,
        ], $extra));
    }

    /** HTTP 401 — не авторизовано (немає сесії або прострочена) */
    public static function unauthorized(string $msg = 'Необхідна авторизація'): never
    {
        self::send(401, ['success' => false, 'error' => $msg]);
    }

    /** HTTP 403 — авторизований, але недостатньо прав */
    public static function forbidden(string $msg = 'Недостатньо прав'): never
    {
        self::send(403, ['success' => false, 'error' => $msg]);
    }

    /** Внутрішня помилка сервера — логуємо деталі, клієнту загальне */
    public static function serverError(string $detail = ''): never
    {
        if ($detail) {
            error_log('[API] Server Error: ' . $detail);
        }
        self::send(500, ['success' => false, 'error' => 'Внутрішня помилка сервера']);
    }

    // ── Приватні методи ──────────────────────────────────────

    private static function send(int $code, array $payload): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');

        // CORS — дозволяємо запити з нашого домену
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin === APP_URL) {
            header("Access-Control-Allow-Origin: {$origin}");
            header('Access-Control-Allow-Credentials: true');
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
