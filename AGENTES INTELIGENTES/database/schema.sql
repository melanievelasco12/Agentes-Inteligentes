-- Esquema inicial para una instalacion nueva; las instalaciones existentes usan migraciones.
CREATE DATABASE IF NOT EXISTS agentes_inteligentes
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE agentes_inteligentes;

-- Cuentas individuales con roles de administrador y usuario operativo.
CREATE TABLE app_users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_by VARCHAR(190) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_app_users_role_active (role, is_active)
) ENGINE=InnoDB;

-- Tokens cifrados para reutilizar la autorizacion OAuth del administrador.
CREATE TABLE oauth_tokens (
    id TINYINT UNSIGNED PRIMARY KEY,
    access_token_ciphertext TEXT NOT NULL,
    access_token_iv VARBINARY(16) NOT NULL,
    access_token_tag VARBINARY(16) NOT NULL,
    refresh_token_ciphertext TEXT NULL,
    refresh_token_iv VARBINARY(16) NULL,
    refresh_token_tag VARBINARY(16) NULL,
    expires_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Catalogo local de estudiantes, cursos y periodos sincronizados desde Brightspace.
CREATE TABLE students (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    brightspace_user_id BIGINT UNSIGNED NOT NULL UNIQUE,
    institutional_email VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    last_synced_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_students_email (institutional_email),
    INDEX idx_students_active (active)
) ENGINE=InnoDB;

CREATE TABLE courses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    brightspace_org_unit_id BIGINT UNSIGNED NOT NULL UNIQUE,
    code VARCHAR(100) NULL,
    name VARCHAR(255) NOT NULL,
    academic_period VARCHAR(100) NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    last_synced_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_courses_active (active)
) ENGINE=InnoDB;

CREATE TABLE academic_periods (
    code VARCHAR(100) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    last_synced_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_academic_periods_active (active)
) ENGINE=InnoDB;

-- Vincula estudiantes con cursos y conserva su ultima actividad conocida.
CREATE TABLE enrollments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NOT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    last_activity_at DATETIME NULL,
    last_synced_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_enrollment (student_id, course_id),
    INDEX idx_enrollments_activity (last_activity_at),
    CONSTRAINT fk_enrollments_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE,
    CONSTRAINT fk_enrollments_course FOREIGN KEY (course_id) REFERENCES courses (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Campanas preparadas para revision y sus destinatarios individuales.
CREATE TABLE reminder_campaigns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    inactivity_days SMALLINT UNSIGNED NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    status ENUM('draft', 'review', 'sending', 'completed', 'cancelled') NOT NULL DEFAULT 'draft',
    created_by VARCHAR(255) NOT NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_campaigns_status (status)
) ENGINE=InnoDB;

CREATE TABLE reminder_recipients (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id BIGINT UNSIGNED NOT NULL,
    enrollment_id BIGINT UNSIGNED NOT NULL,
    email VARCHAR(255) NOT NULL,
    status ENUM('pending', 'sent', 'failed', 'skipped') NOT NULL DEFAULT 'pending',
    provider_message_id VARCHAR(255) NULL,
    error_message TEXT NULL,
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_campaign_recipient (campaign_id, enrollment_id),
    INDEX idx_recipients_status (status),
    CONSTRAINT fk_recipients_campaign FOREIGN KEY (campaign_id) REFERENCES reminder_campaigns (id) ON DELETE CASCADE,
    CONSTRAINT fk_recipients_enrollment FOREIGN KEY (enrollment_id) REFERENCES enrollments (id) ON DELETE CASCADE
) ENGINE=InnoDB;

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

CREATE TABLE shared_email_scope_drafts (
    period VARCHAR(32) NOT NULL,
    career VARCHAR(64) NOT NULL,
    recipient VARCHAR(254) NOT NULL,
    body TEXT NOT NULL,
    updated_by VARCHAR(255) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (period, career)
) ENGINE=InnoDB;

CREATE TABLE shared_email_class_recipients (
    period VARCHAR(32) NOT NULL,
    career VARCHAR(64) NOT NULL,
    class_org_unit_id BIGINT UNSIGNED NOT NULL,
    recipient TEXT NOT NULL,
    updated_by VARCHAR(255) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (period, career, class_org_unit_id),
    INDEX idx_shared_email_class_recipients_class (class_org_unit_id)
) ENGINE=InnoDB;

CREATE TABLE synchronization_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type ENUM('students', 'courses', 'enrollments', 'activity') NOT NULL,
    status ENUM('running', 'completed', 'failed') NOT NULL DEFAULT 'running',
    records_processed INT UNSIGNED NOT NULL DEFAULT 0,
    error_message TEXT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    INDEX idx_sync_runs_type_status (type, status)
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor VARCHAR(255) NOT NULL,
    action VARCHAR(100) NOT NULL,
    context JSON NOT NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_logs_created_at (created_at),
    INDEX idx_audit_logs_action (action)
) ENGINE=InnoDB;