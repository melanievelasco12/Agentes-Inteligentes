-- Permite guardar listas de destinatarios, no solo una direccion por clase.
ALTER TABLE shared_email_class_recipients
    MODIFY COLUMN recipient TEXT NOT NULL;
