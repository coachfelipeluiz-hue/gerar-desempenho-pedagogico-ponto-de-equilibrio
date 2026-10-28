CREATE DATABASE IF NOT EXISTS gerar_ponto_equilibrio CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE gerar_ponto_equilibrio;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('master','gestor','consulta') NOT NULL DEFAULT 'consulta',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS subsedes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL UNIQUE,
  source_code VARCHAR(100) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS polos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  source_code VARCHAR(100) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_polo_source (source_code)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS polo_subsede_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  polo_id BIGINT UNSIGNED NOT NULL,
  subsede_id BIGINT UNSIGNED NOT NULL,
  valid_from DATE NOT NULL,
  valid_until DATE NULL,
  created_by BIGINT UNSIGNED NULL,
  CONSTRAINT fk_psh_polo FOREIGN KEY (polo_id) REFERENCES polos(id),
  CONSTRAINT fk_psh_subsede FOREIGN KEY (subsede_id) REFERENCES subsedes(id),
  CONSTRAINT fk_psh_user FOREIGN KEY (created_by) REFERENCES users(id),
  INDEX ix_psh_dates (polo_id, valid_from, valid_until)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_unit_access (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  subsede_id BIGINT UNSIGNED NOT NULL,
  valid_from DATE NOT NULL DEFAULT (CURRENT_DATE),
  valid_until DATE NULL,
  UNIQUE KEY uq_user_unit (user_id, subsede_id, valid_from),
  CONSTRAINT fk_uua_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_uua_subsede FOREIGN KEY (subsede_id) REFERENCES subsedes(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_module_permissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  module_key VARCHAR(60) NOT NULL,
  can_view TINYINT(1) NOT NULL DEFAULT 0,
  can_create TINYINT(1) NOT NULL DEFAULT 0,
  can_edit TINYINT(1) NOT NULL DEFAULT 0,
  can_import TINYINT(1) NOT NULL DEFAULT 0,
  can_export TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_user_module (user_id, module_key),
  CONSTRAINT fk_ump_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS groups_catalog (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  source_code VARCHAR(100) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS classes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_code VARCHAR(120) NULL,
  name VARCHAR(200) NOT NULL,
  group_id BIGINT UNSIGNED NULL,
  polo_id BIGINT UNSIGNED NULL,
  course_name VARCHAR(200) NULL,
  instructor_source_code VARCHAR(120) NULL,
  active_apprentices INT UNSIGNED NOT NULL DEFAULT 0,
  capacity INT UNSIGNED NULL,
  status VARCHAR(60) NULL,
  competence DATE NULL,
  imported_batch_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX ix_classes_polo (polo_id),
  INDEX ix_classes_competence (competence),
  CONSTRAINT fk_classes_group FOREIGN KEY (group_id) REFERENCES groups_catalog(id),
  CONSTRAINT fk_classes_polo FOREIGN KEY (polo_id) REFERENCES polos(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS contracts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_code VARCHAR(140) NULL,
  subsede_id BIGINT UNSIGNED NULL,
  polo_id BIGINT UNSIGNED NULL,
  customer_name VARCHAR(200) NULL,
  monthly_value DECIMAL(13,2) NOT NULL DEFAULT 0,
  start_date DATE NULL,
  end_date DATE NULL,
  competence DATE NULL,
  status VARCHAR(60) NULL,
  attribution_method VARCHAR(100) NULL,
  imported_batch_id BIGINT UNSIGNED NULL,
  INDEX ix_contracts_competence (competence),
  CONSTRAINT fk_contracts_subsede FOREIGN KEY (subsede_id) REFERENCES subsedes(id),
  CONSTRAINT fk_contracts_polo FOREIGN KEY (polo_id) REFERENCES polos(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS instructors (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_code VARCHAR(120) NULL,
  name VARCHAR(180) NOT NULL,
  subsede_id BIGINT UNSIGNED NULL,
  category VARCHAR(120) NULL,
  position_name VARCHAR(160) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  imported_batch_id BIGINT UNSIGNED NULL,
  CONSTRAINT fk_instructors_subsede FOREIGN KEY (subsede_id) REFERENCES subsedes(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS instruction_hours (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  instructor_id BIGINT UNSIGNED NOT NULL,
  class_id BIGINT UNSIGNED NOT NULL,
  competence DATE NOT NULL,
  hours DECIMAL(9,2) NOT NULL DEFAULT 0,
  cost_per_hour DECIMAL(11,2) NOT NULL DEFAULT 0,
  cost_hour_includes_charges TINYINT(1) NOT NULL DEFAULT 0,
  approved TINYINT(1) NOT NULL DEFAULT 0,
  source_note VARCHAR(255) NULL,
  UNIQUE KEY uq_instruction_record (instructor_id, class_id, competence),
  CONSTRAINT fk_ih_instructor FOREIGN KEY (instructor_id) REFERENCES instructors(id),
  CONSTRAINT fk_ih_class FOREIGN KEY (class_id) REFERENCES classes(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cost_categories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL UNIQUE,
  cost_type ENUM('fixed','variable','direct','shared') NOT NULL,
  attribution_level ENUM('class','polo','subsede','institution') NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cost_entries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(13,2) NOT NULL,
  competence DATE NOT NULL,
  class_id BIGINT UNSIGNED NULL,
  polo_id BIGINT UNSIGNED NULL,
  subsede_id BIGINT UNSIGNED NULL,
  notes VARCHAR(500) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cost_category FOREIGN KEY (category_id) REFERENCES cost_categories(id),
  CONSTRAINT fk_cost_class FOREIGN KEY (class_id) REFERENCES classes(id),
  CONSTRAINT fk_cost_polo FOREIGN KEY (polo_id) REFERENCES polos(id),
  CONSTRAINT fk_cost_subsede FOREIGN KEY (subsede_id) REFERENCES subsedes(id),
  CONSTRAINT fk_cost_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS import_batches (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_type VARCHAR(60) NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  competence DATE NULL,
  status ENUM('uploaded','validated','imported','failed','cancelled') NOT NULL DEFAULT 'uploaded',
  total_rows INT UNSIGNED NOT NULL DEFAULT 0,
  valid_rows INT UNSIGNED NOT NULL DEFAULT 0,
  rejected_rows INT UNSIGNED NOT NULL DEFAULT 0,
  error_summary TEXT NULL,
  uploaded_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_import_user FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  entity_type VARCHAR(100) NOT NULL,
  entity_id BIGINT UNSIGNED NULL,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id),
  INDEX ix_audit_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS simulation_scenarios (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(180) NOT NULL,
  scope_type ENUM('class','polo','subsede','institution') NOT NULL,
  scope_id BIGINT UNSIGNED NULL,
  competence DATE NOT NULL,
  parameters_json JSON NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_scenario_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS simulation_results (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  scenario_id BIGINT UNSIGNED NOT NULL,
  revenue DECIMAL(13,2) NOT NULL DEFAULT 0,
  costs DECIMAL(13,2) NOT NULL DEFAULT 0,
  result_amount DECIMAL(13,2) NOT NULL DEFAULT 0,
  gap_to_break_even DECIMAL(13,2) NOT NULL DEFAULT 0,
  assumptions_json JSON NULL,
  CONSTRAINT fk_sim_result_scenario FOREIGN KEY (scenario_id) REFERENCES simulation_scenarios(id)
) ENGINE=InnoDB;
