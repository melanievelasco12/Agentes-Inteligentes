-- Migra el borrador compartido al alcance de carrera y periodo.
CREATE TABLE shared_email_scope_drafts (
    period VARCHAR(32) NOT NULL,
    career VARCHAR(64) NOT NULL,
    recipient VARCHAR(254) NOT NULL,
    body TEXT NOT NULL,
    updated_by VARCHAR(255) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (period, career)
) ENGINE=InnoDB;
