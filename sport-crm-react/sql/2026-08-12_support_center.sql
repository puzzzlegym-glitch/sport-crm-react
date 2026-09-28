-- ============================================================================
-- Розділ підтримки: переписка клубу з підтримкою CRM4Fitness (тікети + чат)
-- Використовується: api/support_api.php, src/api/support.js, src/pages/SupportPage.jsx
-- База: er452618_crm4fitness (застосувати вручну через phpMyAdmin)
-- Дата: 2026-08-12
-- ============================================================================
-- Звернення відкриває будь-який співробітник клубу (trainer/manager/owner).
-- Відповідає SuperAdmin — бачить звернення з усіх клубів (client_id-подібного
-- scoping тут нема, SuperAdmin поза club-режимом бачить усе, як і в /clubs).
-- ============================================================================

-- 1) Звернення ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS support_tickets (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    club_id           INT UNSIGNED NOT NULL,
    created_by        INT UNSIGNED NOT NULL,
    subject           VARCHAR(191) NOT NULL,
    status            ENUM('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
    last_message_at   DATETIME     NOT NULL,
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_st_club (club_id, status),
    KEY idx_st_last_message (last_message_at),
    CONSTRAINT fk_st_club    FOREIGN KEY (club_id)    REFERENCES sys_clubs(id) ON DELETE CASCADE,
    CONSTRAINT fk_st_creator FOREIGN KEY (created_by)  REFERENCES sys_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Повідомлення в межах звернення ---------------------------------------------
CREATE TABLE IF NOT EXISTS support_messages (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id       INT          NOT NULL,
    sender_type     ENUM('club','admin') NOT NULL,
    sender_user_id  INT UNSIGNED NOT NULL,
    message         TEXT         NOT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sm_ticket (ticket_id, created_at),
    CONSTRAINT fk_sm_ticket FOREIGN KEY (ticket_id)      REFERENCES support_tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_sm_sender FOREIGN KEY (sender_user_id) REFERENCES sys_users(id)       ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) Право доступу — за золотим-стандарт патерном з 2026-08-08_permissions_matrix.sql,
--    відкрито для всієї команди клубу (trainer + manager + owner): технічні питання
--    можуть виникнути в будь-якої ролі --------------------------------------------
INSERT INTO sys_permissions (slug, label, category, sort_order) VALUES
    ('support.view', 'Переписка з підтримкою CRM4Fitness', 'support', 10)
ON DUPLICATE KEY UPDATE label = VALUES(label), category = VALUES(category), sort_order = VALUES(sort_order);

INSERT IGNORE INTO sys_role_permissions (role_id, permission_slug)
SELECT r.id, p.slug
FROM sys_roles r
JOIN sys_permissions p ON p.slug = 'support.view'
WHERE r.slug IN ('trainer', 'manager', 'owner');
