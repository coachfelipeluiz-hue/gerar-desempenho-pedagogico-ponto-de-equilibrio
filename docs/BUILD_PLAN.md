# Plano incremental

## Fase 1 — fundação
- [x] Estrutura PHP e conexão PDO
- [x] Login/sessão e perfis base
- [x] Schema conceitual MySQL para unidades, usuários e finanças
- [x] Páginas iniciais e placeholders
- [ ] CRUD real de subsedes e polos
- [ ] CRUD de usuários e permissões por unidade
- [ ] Testes de isolamento de acesso

## Fase 2 — importações
- [ ] Inspecionar e mapear cabeçalhos reais das bases
- [ ] Prévia CSV/XLSX, validação e detecção de duplicidades
- [ ] Importação transacional e auditoria
- [ ] Reprocessamento e histórico

## Fase 3 — cálculos
- [x] Primeira projeção testável de margem preliminar, receita para margem-alvo e ticket médio global por contrato; custos atuais são mantidos constantes e a projeção não substitui o motor completo de ponto de equilíbrio
- [ ] Reproduzir fórmulas da planilha de ponto de equilíbrio
- [ ] Ocupação média ponderada por turma, polo, subsede e GERAR
- [ ] Custos de instrutores horistas por horas efetivamente ministradas
- [ ] Separação entre custos diretos, compartilhados e institucionais
- [ ] Testes de reconciliação financeira

## Fase 4 — dashboard e simulador
- [ ] Filtros de competência, subsede, polo e turma
- [ ] Receita, custos, margem e lacuna até o equilíbrio
- [ ] Abrir a receita por subsede/polo e recomendar cursos somente após relacionar contratos, turmas e capacidade do período
- [ ] Simular ajuste de contrato, aprendizes, preenchimento de capacidade e novas turmas
- [ ] Exportações protegidas por permissão
