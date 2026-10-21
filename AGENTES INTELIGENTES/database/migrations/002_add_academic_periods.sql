-- Agrega el catalogo de periodos academicos sincronizados desde Brightspace.
CREATE TABLE IF NOT EXISTS academic_periods (
    code VARCHAR(100) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    last_synced_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_academic_periods_active (active)
) ENGINE=InnoDB;