-- Crea el borrador de correo singleton usado por las versiones iniciales del panel.
CREATE TABLE shared_email_draft (
    id TINYINT UNSIGNED PRIMARY KEY,
    recipient VARCHAR(254) NOT NULL DEFAULT '',
    subject VARCHAR(255) NOT NULL DEFAULT '',
    body TEXT NOT NULL,
    updated_by VARCHAR(255) NULL,
    updated_at DATETIME NULL DEFAULT NULL,
    CONSTRAINT chk_shared_email_draft_singleton CHECK (id = 1)
) ENGINE=InnoDB;

INSERT INTO shared_email_draft (id, recipient, subject, body)
VALUES (1, '', '', '');
