# Motor financeiro inicial — GERAR

Status: primeira implementação isolada em branch de desenvolvimento. Não conectado ao banco, telas, importadores ou dashboard; não está homologado para uso decisório.

## Arquivos
- `src/Finance/BreakEvenCalculator.php`: funções puras para receita integral/proporcional, margem de contribuição, receita de equilíbrio e faixa operacional da turma.
- `tests/test-financial-calculator.php`: teste executável sem banco e sem dependências externas.

## Como executar
Na raiz do repositório, com PHP CLI instalado:

```bash
php tests/test-financial-calculator.php
```

A execução deve imprimir `OK: 16 verificações de cálculo financeiro.` e terminar com código 0. Esta documentação descreve o resultado esperado; só registrar como executado depois de rodar o comando no ambiente.

## Regras codificadas
1. Receita integral e proporcional são calculadas em paralelo; a função não escolhe cenário oficial.
2. Receita proporcional usa `valor_mensal × dias_ativos / dias_do_período`. A convenção de contagem de dias continua pendente de aprovação.
3. Margem de contribuição = receita − custos variáveis elegíveis.
4. Taxa de margem = margem de contribuição / receita, somente quando receita é maior que zero.
5. Receita de equilíbrio = custos fixos / taxa de margem de contribuição. Se a taxa for nula, negativa ou maior que 100%, o cálculo retorna `null`, sem produzir um número enganoso.
6. Meta operacional padrão é 15 jovens; de 15 a 25 a faixa fica regular; acima de 25 fica acima da faixa. Esse indicador não determina equilíbrio financeiro.
7. Valores e dias inválidos geram exceção explícita.

## Limites e próximos passos
- Quem chama a função deve filtrar contratos elegíveis para a competência e obter a atribuição histórica polo–subsede; esta classe não consulta nem presume esses dados.
- Ainda falta modelar custos fixos/variáveis e seus lançamentos, método/origem/competência, estados de aprovação e alertas de duplicidade de custo de instrutor.
- Ainda falta decidir convenção de dias e política de inclusão de horas de instrutor pendentes/aprovadas.
- O rateio de custos compartilhados não é implementado.
- Não há autenticação/autorização, persistência, tela ou integração com dashboard nesta etapa.
- Antes de integrar ao fluxo da aplicação, executar os testes em PHP, acrescentar testes de integração com banco isolado e revisão por quem responde pelas regras financeiras.
- Não usar dados pessoais ou banco produtivo para os testes.

## Segurança de implementação
As funções não executam SQL nem recebem entrada HTTP diretamente. A validação de valores é uma última barreira, não substitui validação de formulário, autorização no servidor ou consultas parametrizadas na camada de persistência.
