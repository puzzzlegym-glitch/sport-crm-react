-- SuperAdmin: перемикання ролі перегляду клубу (owner/manager/trainer) для швидшого пошуку багів.
-- Зберігається в сесії, не в БД-профілі юзера — скидається при зміні/виході з клубу.
ALTER TABLE sys_sessions
  ADD COLUMN view_as_role_slug VARCHAR(30) NULL AFTER active_club_id;
