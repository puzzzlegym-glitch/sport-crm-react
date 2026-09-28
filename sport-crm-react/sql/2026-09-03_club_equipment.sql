-- ============================================================================
-- Модуль "Обладнання" — простий облік тренажерів/інвентарю клубу зі статусом
-- справності. Мінімальна версія: без прив'язки до залів/локацій (їх у системі
-- немає — club_id і є фактичною локацією), без QR/NFC/шафок/складів з
-- місткістю — ці частини вихідної специфікації визнано незастосовними.
--
-- Використовується: api/equipment_api.php, src/api/equipment.js,
--                    src/pages/EquipmentPage.jsx
-- База: er452618_crm4fitness (застосувати вручну через phpMyAdmin)
-- Дата: 2026-09-03
-- ============================================================================

CREATE TABLE IF NOT EXISTS club_equipment (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    club_id       INT UNSIGNED NOT NULL,
    name          VARCHAR(150) NOT NULL,
    category      VARCHAR(100) NULL,
    location_note VARCHAR(200) NULL,     -- вільний текст "де стоїть" (не FK — залів як сутності нема)
    status        VARCHAR(20)  NOT NULL DEFAULT 'working', -- working | maintenance | broken
    notes         TEXT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_equipment_club (club_id),
    CONSTRAINT fk_equipment_club FOREIGN KEY (club_id) REFERENCES sys_clubs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
