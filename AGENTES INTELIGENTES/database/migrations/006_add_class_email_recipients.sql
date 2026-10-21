-- Crea el almacenamiento de destinatarios por clase y alcance academico.
CREATE TABLE shared_email_class_recipients (
    period VARCHAR(32) NOT NULL,
    career VARCHAR(64) NOT NULL,
    class_org_unit_id BIGINT UNSIGNED NOT NULL,
    recipient VARCHAR(254) NOT NULL,
    updated_by VARCHAR(255) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (period, career, class_org_unit_id),
    INDEX idx_shared_email_class_recipients_class (class_org_unit_id)
) ENGINE=InnoDB;
