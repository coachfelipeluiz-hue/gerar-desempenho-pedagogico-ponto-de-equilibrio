-- Execute uma vez no banco GERAR existente, após selecionar o schema correto.
-- Faça backup antes de aplicar migrações em ambiente com dados.
ALTER TABLE contracts
    ADD COLUMN termination_date DATE NULL AFTER end_date;
