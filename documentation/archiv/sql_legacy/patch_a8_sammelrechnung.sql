-- A8 Sammelrechnungen
CREATE TABLE IF NOT EXISTS sammelrechnungen (
  id INT AUTO_INCREMENT PRIMARY KEY,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  created_by VARCHAR(255) DEFAULT NULL,
  tables_text TEXT,
  total_amount DECIMAL(10,2) DEFAULT 0.00
);

ALTER TABLE bestellungen ADD COLUMN sammelrechnung_id INT NULL;
