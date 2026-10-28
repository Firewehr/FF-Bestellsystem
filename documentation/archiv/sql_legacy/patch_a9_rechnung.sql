-- A9: Rechnungen (Bondrucker/Firmenrechnung) + Rechnungsnummer pro Jahr

CREATE TABLE IF NOT EXISTS `rechnungen` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `rechnungsnummer` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` varchar(64) NOT NULL,
  `fest_id` int(11) DEFAULT NULL,
  `tischnummer` int(11) DEFAULT NULL,
  `sammelrechnung_id` int(11) DEFAULT NULL,
  `is_firma` tinyint(1) NOT NULL DEFAULT 0,
  `empfaenger_name` varchar(255) DEFAULT NULL,
  `empfaenger_strasse` varchar(255) DEFAULT NULL,
  `empfaenger_plz` varchar(30) DEFAULT NULL,
  `empfaenger_ort` varchar(80) DEFAULT NULL,
  `empfaenger_uid` varchar(40) DEFAULT NULL,
  `total` decimal(10,2) NOT NULL DEFAULT '0.00',
  `gedruckt` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `gedruckt` (`gedruckt`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

ALTER TABLE `bestellungen` ADD COLUMN `rechnung_id` int(11) DEFAULT NULL;

-- Optional: Settings-Tabelle (falls noch nicht vorhanden)
CREATE TABLE IF NOT EXISTS `settings` (
  `k` varchar(64) NOT NULL,
  `v` text NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`k`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;
