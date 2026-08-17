-- Startseite nach Login (Benutzer mit Rolle „Benutzer“; Admins immer Hauptmenü)
-- Einmalig ausführen, wenn die Spalten noch fehlen (alternativ legt die App sie per login.php/admin.php an).

ALTER TABLE `users`
  ADD COLUMN `start_page` VARCHAR(32) NOT NULL DEFAULT 'menu' AFTER `admin`,
  ADD COLUMN `start_print_target` INT(11) NULL DEFAULT NULL AFTER `start_page`;
