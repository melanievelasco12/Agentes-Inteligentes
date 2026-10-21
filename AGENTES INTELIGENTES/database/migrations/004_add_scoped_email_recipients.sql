-- Esquema historico de destinatarios por modulo/clase; conservar para instalaciones antiguas.
CREATE TABLE shared_email_recipients (
    period VARCHAR(32) NOT NULL,
    career VARCHAR(64) NOT NULL,
    module VARCHAR(64) NOT NULL,
    class_org_unit_id BIGINT UNSIGNED NOT NULL,
    recipient VARCHAR(254) NOT NULL,
    updated_by VARCHAR(255) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (period, career, module, class_org_unit_id)
) ENGINE=InnoDB;
