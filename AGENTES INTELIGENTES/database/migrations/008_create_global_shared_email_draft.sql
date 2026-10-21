-- Migracion historica para consolidar el mensaje en un borrador global singleton.
CREATE TABLE shared_email_global_draft (
    id TINYINT UNSIGNED PRIMARY KEY,
    body TEXT NOT NULL,
    updated_by VARCHAR(255) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_shared_email_global_draft_singleton CHECK (id = 1)
) ENGINE=InnoDB;

INSERT INTO shared_email_global_draft (id, body, updated_by, updated_at)
SELECT 1, body, updated_by, updated_at
FROM shared_email_scope_drafts
ORDER BY updated_at DESC, period DESC, career DESC
LIMIT 1;

INSERT INTO shared_email_global_draft (id, body, updated_by, updated_at)
SELECT 1, body, updated_by, COALESCE(updated_at, CURRENT_TIMESTAMP)
FROM shared_email_draft
WHERE id = 1
  AND NOT EXISTS (
      SELECT 1
      FROM shared_email_scope_drafts
  );

INSERT INTO shared_email_global_draft (id, body, updated_by)
SELECT 1, '', NULL
WHERE NOT EXISTS (
    SELECT 1
    FROM shared_email_global_draft
    WHERE id = 1
);
