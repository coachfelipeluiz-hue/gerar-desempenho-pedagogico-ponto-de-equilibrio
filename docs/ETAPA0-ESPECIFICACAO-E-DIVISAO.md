# Etapa 0 — Especificação consolidada e divisão de trabalho

Status: especificação inicial aprovada pelo responsável para orientar implementação isolada. Não é homologação contábil. Não usar dados de produção.

## Decisões confirmadas
- Análise nos níveis institucional/consolidado, subsede, polo e turma.
- Exibir médias por contrato, polo, subsede, competência e período histórico selecionado; sempre mostrar numerador, denominador, unidade e período.
- Para contratos ativos em parte da competência, calcular lado a lado receita pelo valor mensal integral e receita proporcional aos dias ativos. Mostrar o cenário selecionado sem ocultar a comparação.
- Conceito financeiro de margem: margem de contribuição, com componentes explícitos e configuráveis.
- Meta operacional confiável: 15 jovens por turma/curso. Faixas: <15 abaixo do mínimo; 15–25 regular; >25 ótima. A meta operacional não prova equilíbrio financeiro.
- Custos de instrutores aceitam valor total direto ou horas × custo-hora. Registrar método, origem e competência; sinalizar possível duplicidade.
- Capacidade da subsede deve ser informada por período. Acima da capacidade é um sinal de demanda, não motivo para excluir/inutilizar registros.
- Atribuição histórica polo–subsede deve usar o vínculo vigente na competência; registros órfãos ficam em pendências.
- Somente Master tem acesso global/administrativo. Permissões dos outros perfis não devem ser ampliadas por inferência.
- Outros custos podem ser cadastrados manualmente e categorias devem ser extensíveis.

## Matriz de fórmulas candidatas
- Receita integral = soma do valor mensal dos contratos ativos em algum momento da competência.
- Receita proporcional = soma(valor mensal × dias ativos na competência / dias do período), usando convenção de dias configurável.
- Média por contrato = receita elegível / quantidade de contratos elegíveis.
- Média por polo/subsede = receita atribuída historicamente / quantidade de contratos elegíveis daquela unidade. Outros indicadores, como jovens por turma, devem ter denominadores próprios.
- Custo direto de instrutor = soma dos lançamentos diretos válidos não duplicados.
- Custo por horas = soma(horas × custo-hora) conforme status incluído na configuração.
- Margem de contribuição = receita reconhecida − custos variáveis elegíveis. Percentual = MC / receita, somente se receita > 0.
- Resultado preliminar = receita reconhecida − custos elegíveis incluídos, sem dupla contagem; mostrar custos não rateados separadamente.
- Déficit operacional para meta = max(0, 15 − jovens na turma/curso).
- Ocupação da capacidade = jovens atribuídos / capacidade, se capacidade > 0; se ausente/zero, sinalizar “capacidade não configurada”.
- Ponto de equilíbrio financeiro requer definição dos custos fixos e rateios. A fórmula simplificada teto(custos fixos / contribuição líquida média por turma) só vale para contribuição positiva e turmas comparáveis; não codificar como regra geral sem validar o mix.

## Decisões ainda em aberto
1. Convenção exata de dias para cálculo proporcional e seleção do cenário oficial.
2. Status de aprovação de horas que entra no resultado oficial.
3. Política de rateio de custos fixos/compartilhados entre turmas, polos e subsedes.
4. Tratamento final de contratos sem polo/subsede/competência: ficam em pendência; nunca atribuir automaticamente.
5. Permissões específicas de gestor/consulta, sem acesso global por padrão.

## Cenários sintéticos de aceitação
1. Frentista com 3 jovens: déficit para meta = 15 - 3 = 12; recomendar captar 12. Isso não declara equilíbrio financeiro.
2. Contrato com valor mensal R$300, ativo 10 de 30 dias: integral R$300; proporcional R$100; comparação visível.
3. Polo ligado à Subsede A em março e Subsede B em abril: março atribuído à A, abril à B; vínculo ausente gera pendência.
4. Polo com 10 contratos e receita integral de R$3.000: média por contrato R$300. Duas turmas com 8 e 12 jovens: média de jovens/turma = 10; indicadores separados.
5. Custo instrutor direto R$480 e horas 12 × R$40 = R$480, mesma origem/serviço: sinalizar duplicidade, não somar R$960 silenciosamente.
6. Receita R$10.000 e custo variável R$6.000: MC R$4.000 e MC% 40%; custos fixos não são subtraídos da margem de contribuição.
7. Capacidade 100 e jovens 112: ocupação 112%, excesso 12; manter registros e sinalizar capacidade excedida.
8. Contrato sem associação histórica válida: receita fica em pendência de atribuição.
9. **Cenário aritmético independente (não derivado do dataset principal):** Subsede A: receita R$10.000/custos R$7.000/resultado R$3.000. Subsede B: receita R$8.000/custos R$9.500/resultado -R$1.500. Consolidado: receita R$18.000/custos R$16.500/resultado R$1.500, desde que custos não duplicados.

## Validação aritmética da fixture
- `expected_results.dataset_checks` contém resultados derivados dos registros reais desta fixture sintética. Os cinco contratos somam R$1.500 em valores mensais integrais; quatro registros têm turma e polo atribuídos e somam R$1.220? **Atenção: a soma atribuída deve ser calculada a partir dos registros e não presumida; a validação automatizada é a fonte de conferência.** Receita integral por competência considera todos os registros, inclusive os sem atribuição, que devem continuar sinalizados como pendência.
- `expected_results.independent_scenarios` contém exemplos didáticos isolados (média, margem de contribuição e consolidado) e não deve ser comparado com os arrays `contracts`, `instructor_costs` ou `costs`.
- `expected_results.arithmetic_validation` documenta as fórmulas. O JSON deve passar por parse e por validação aritmética; isso não substitui testes da aplicação PHP, banco ou navegador.

## Divisão de trabalho proposta
### GPT (esta branch)
- Especificação funcional, dados fictícios versionados e casos de aceitação determinísticos.
- Validar fórmulas aritméticas dos fixtures e manter os testes independentes de dados reais.
- Não alterar schema ou lógica de produção nesta primeira entrega.

### Manus (branch própria, separada)
- Revisar riscos P1 em segurança/autorização, escopo por unidade, importações e auditoria.
- Propor e implementar correções pequenas apenas em branch separada, com diff e testes reproduzíveis.
- Não editar os mesmos arquivos da branch GPT; comunicar arquivos/rotas tocados antes de ampliar escopo.
- Não fazer merge, push direto em main, deploy nem alterações em banco de produção.
- Revisar a especificação e os fixtures desta branch e apontar incompatibilidades sem modificar estes arquivos.

## Regras de colaboração
- Base confirmada no início: main @ 9901e4d3effc65d0ca32bc3c738b4c88b6c5e90a.
- Branch GPT: gpt/fixtures-sinteticas-etapa0.
- Não fazer trabalho concorrente nos mesmos caminhos.
- Cada alteração deve informar arquivos, motivo, testes executados, resultado observado e limitações.
- Nenhuma funcionalidade pode ser chamada de testada sem execução real.
- Não usar dados pessoais/comerciais reais. Não conectar a banco de produção.
- Antes de integração, comparar branches e revisar conflitos; nenhuma integração automática.

## Próxima decisão técnica
Após a revisão dos fixtures, criar um módulo de demonstração isolado (ou fixtures para banco de teste) em etapa separada. O banco real não será preenchido com dados fictícios.
