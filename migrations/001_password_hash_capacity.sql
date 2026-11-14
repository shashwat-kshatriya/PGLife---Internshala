-- Back up first. Run once against the existing selected database.
-- All seven tables and all existing rows are preserved.
ALTER TABLE users MODIFY COLUMN password VARCHAR(255) NOT NULL;
