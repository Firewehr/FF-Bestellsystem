-- A6: Feste + Payment Mode + Tisch Flags + Bestellungen.fest_id

CREATE TABLE IF NOT EXISTS feste (
  id INT(11) NOT NULL AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  code VARCHAR(16) NOT NULL,
  fest_datum DATE NULL,
  aktiv TINYINT(1) NOT NULL DEFAULT 1,
  payment_mode ENUM('after','instant') NOT NULL DEFAULT 'after',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY aktiv (aktiv)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(64) NOT NULL,
  v TEXT NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (k)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- Current fest pointer
INSERT IGNORE INTO settings (k,v) VALUES ('current_fest_id','0');
INSERT IGNORE INTO settings (k,v) VALUES ('fast_refresh','0');
INSERT IGNORE INTO settings (k,v) VALUES ('kellner_nur_eigene','1');

-- Add columns to tische (flags)
-- NOTE: If you already added these columns, MySQL will error. In that case ignore the error.
ALTER TABLE tische
  ADD COLUMN is_sammelrechnung TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE tische
  ADD COLUMN is_ehrengast TINYINT(1) NOT NULL DEFAULT 0;

-- Add fest_id to bestellungen
ALTER TABLE bestellungen
  ADD COLUMN fest_id INT(11) NULL;
