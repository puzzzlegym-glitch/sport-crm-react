-- ============================================================================
-- Модуль "Еквайринг" (оплата карткою через POS-термінал ПриватБанку) —
-- налаштування підключення до Device Manager Proxy API від Вчасно.Каса
-- (https://wiki-kasa.vchasno.ua/uk/DeviceManager/Functionality/Cloud%26devices).
--
-- Схема роботи: клуб самостійно реєструє й оплачує власний кабінет
-- Вчасно.Каса, ставить у себе застосунок Device Manager, підключає до нього
-- POS-термінал ПриватБанку (Ethernet/Wi-Fi/USB — налаштовується в самому DM,
-- CRM це не стосується) і отримує в кабінеті Вчасно API-токен
-- (X-AP-DM-PROXY-TOKEN). CRM зберігає лише цей токен + назву пристрою
-- ("device" з JSON) — фізичне підключення термінала повністю на боці клубу.
--
-- Виклик оплати (POST https://kasa.vchasno.ua/ws/ap/dm-proxy, type:3 "pay")
-- ЩЕ НЕ РЕАЛІЗОВАНО — це лише зберігання налаштувань, заготовка під наступний
-- крок. Фіскалізація чека лишається на Checkbox (CheckboxService.php),
-- Device Manager тут використовується виключно для проведення оплати на
-- фізичному терміналі — ці дві системи незалежні одна від одної.
--
-- Використовується: api/acquiring_api.php, src/api/acquiring.js,
--                    src/pages/PrroSettingsPage.jsx (секція "Еквайринг")
-- База: er452618_crm4fitness (застосувати вручну через phpMyAdmin)
-- Дата: 2026-09-02
--
-- Права керування — той самий prro.manage, що й підключення Checkbox: це
-- одна сторінка налаштувань "ПРРО / Термінали", нового permission не треба.
--
-- ВАЖЛИВО (безпека): dm_proxy_token_enc зберігається зашифрованим тим самим
-- механізмом, що й секрети Checkbox — CheckboxService::encrypt/decrypt
-- (AES-256-CBC, ключ PRRO_ENCRYPTION_KEY з app/config.php). Назва класу
-- історична (клас з'явився для Checkbox раніше) — сама криптофункція
-- застосунково-нейтральна, і заводити ще один ключ шифрування лише для
-- цього поля немає сенсу.
-- ============================================================================

CREATE TABLE IF NOT EXISTS club_acquiring_settings (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    club_id            INT UNSIGNED NOT NULL,
    provider           VARCHAR(20)  NOT NULL DEFAULT 'vchasno_kasa', -- заготовка на майбутнє: vchasno_kasa | ...
    device_name        VARCHAR(100) NULL,     -- "device" з JSON — назва термінала в кабінеті Device Manager
    dm_proxy_token_enc  VARCHAR(500) NULL,     -- X-AP-DM-PROXY-TOKEN з кабінету Вчасно.Каса, зашифровано
    is_active          TINYINT(1)   NOT NULL DEFAULT 0,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_acquiring_club (club_id),
    CONSTRAINT fk_acquiring_settings_club FOREIGN KEY (club_id) REFERENCES sys_clubs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
