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
- [x] Mapear cabeçalhos do CSV Gerar.csv sem copiar registros pessoais para o repositório
- [x] Prévia do importador de contratos, validação e detecção de códigos duplicados
- [x] Importação transacional de contratos e auditoria; falta reconciliar em cópia de banco
- [ ] Importar cursos/turmas e instrutores; concluir demais fontes
- [ ] Reprocessamento e histórico

## Fase 3 — cálculos
- [x] Primeira projeção testável de margem preliminar, receita para margem-alvo e ticket mensal nominal médio nos filtros/grupos; custos atuais são mantidos constantes e a projeção não substitui o motor completo de ponto de equilíbrio
- [x] Ratear receita contratual por dias ativos e sinalizar status/desligamento sem dados válidos
- [x] Agregar receita e ticket nominal médio em linhas separadas por subsede e polo
- [ ] Reproduzir fórmulas da planilha de ponto de equilíbrio
- [ ] Ocupação média ponderada por turma, polo, subsede e GERAR
- [ ] Custos de instrutores horistas por horas efetivamente ministradas
- [ ] Separação entre custos diretos, compartilhados e institucionais
- [ ] Testes de reconciliação financeira

## Fase 4 — dashboard e simulador
- [x] Filtros de competência, subsede e polo; filtro de turma permanece pendente
- [x] Receita, custos conhecidos, margem preliminar e lacuna para margem-alvo
- [x] Detalhe de receita por subsede/polo; recomendações de curso aguardam vínculo de contratos, turmas e capacidade por período
- [ ] Simular ajuste de contrato, aprendizes, preenchimento de capacidade e novas turmas
- [ ] Exportações protegidas por permissão
