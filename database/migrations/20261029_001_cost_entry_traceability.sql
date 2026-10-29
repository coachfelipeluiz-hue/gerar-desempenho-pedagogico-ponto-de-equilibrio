-- GERAR: rastreabilidade e revisão dos custos manuais.
-- Execute somente depois de backup. Validar versão do MySQL/MariaDB antes da aplicação.
-- Esta migração não altera nem aprova automaticamente lançamentos existentes.

ALTER TABLE cost_entries
  ADD COLUMN source_type ENUM('manual','instructor_hours','import') NOT NULL DEFAULT 'manual' AFTER notes,
  ADD COLUMN source_reference VARCHAR(190) NULL AFTER source_type,
  ADD COLUMN calculation_method ENUM('manual_total','hours_rate','imported_total') NOT NULL DEFAULT 'manual_total' AFTER source_reference,
  ADD COLUMN status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending' AFTER calculation_method,
  ADD COLUMN possible_duplicate_of BIGINT UNSIGNED NULL AFTER status,
  ADD COLUMN reviewed_by BIGINT UNSIGNED NULL AFTER possible_duplicate_of,
  ADD COLUMN reviewed_at DATETIME NULL AFTER reviewed_by,
  ADD COLUMN review_note VARCHAR(500) NULL AFTER reviewed_at,
  ADD INDEX ix_cost_entries_status_competence (status, competence),
  ADD INDEX ix_cost_entries_source (source_type, source_reference),
  ADD CONSTRAINT fk_cost_duplicate_of FOREIGN KEY (possible_duplicate_of) REFERENCES cost_entries(id),
  ADD CONSTRAINT fk_cost_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id);

-- Os lançamentos existentes ficam pendentes até revisão humana.
-- Não atribuir custo a nenhuma unidade nem fazer rateio automaticamente.
