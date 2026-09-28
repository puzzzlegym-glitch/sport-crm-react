-- ============================================================================
-- Видалення власника клубу з усіма даними (SuperAdmin) — подвійний захист:
-- попередження з причиною + одноразовий код підтвердження в Telegram.
-- Використовується: api/clubs_api.php (дії request_delete / confirm_delete),
--                    src/api/clubs.js, src/pages/ClubsPage.jsx
-- База: er452618_crm4fitness (застосувати вручну через phpMyAdmin)
-- Дата: 2026-08-20
-- ============================================================================

-- 1) Одноразові коди підтвердження видалення клубу -----------------------------
CREATE TABLE IF NOT EXISTS club_deletion_requests (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    club_id      INT UNSIGNED NOT NULL,
    requested_by INT UNSIGNED NOT NULL,
    reason       TEXT         NOT NULL,
    code_hash    CHAR(64)     NOT NULL,
    attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at   DATETIME     NOT NULL,
    used_at      DATETIME     NULL DEFAULT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cdr_club (club_id),
    CONSTRAINT fk_cdr_club FOREIGN KEY (club_id)      REFERENCES sys_clubs(id) ON DELETE CASCADE,
    CONSTRAINT fk_cdr_user FOREIGN KEY (requested_by) REFERENCES sys_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Постійний журнал видалених клубів (переживає саме видалення) --------------
--    club_id навмисно БЕЗ FK — рядок має лишитись і після того, як клуб зникне.
CREATE TABLE IF NOT EXISTS deleted_clubs_audit (
    id                       INT AUTO_INCREMENT PRIMARY KEY,
    club_id                  INT          NOT NULL,
    club_name                VARCHAR(255) NOT NULL,
    owner_name               VARCHAR(255) NOT NULL,
    owner_email              VARCHAR(255) NOT NULL,
    reason                   TEXT         NOT NULL,
    deleted_by               INT UNSIGNED NOT NULL,
    deleted_by_name          VARCHAR(255) NOT NULL,
    total_paid_before_delete DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
