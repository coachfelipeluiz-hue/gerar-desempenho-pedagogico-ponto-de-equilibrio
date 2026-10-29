# Etapa 1 — Rastreabilidade e revisão de custos

## Objetivo
Impedir que custos informados manualmente sejam confundidos automaticamente com custos validados para análise financeira. A implementação cria um fluxo explícito de revisão e preserva a origem/método do lançamento.

## Alterações desta etapa
- Migração `database/migrations/20261029_001_cost_entry_traceability.sql` adiciona origem, referência, método de cálculo, status, possível duplicidade e campos de revisão.
- Novos lançamentos manuais são gravados como `pending`.
- A tela de custos separa totais aprovados, pendentes e rejeitados.
- Somente o perfil Master pode aprovar/rejeitar lançamentos pendentes; cada revisão registra usuário, data/hora e observação.
- A revisão não cria rateios nem muda o escopo de unidade do lançamento.
- A auditoria registra o evento de aprovação/rejeição.

## Aplicação da migração
1. Faça backup do banco.
2. Teste primeiro em uma cópia de desenvolvimento.
3. Execute o SQL da migração no banco de desenvolvimento GERAR.
4. Publique a versão da aplicação que consulta os novos campos somente depois da migração estar aplicada.
5. Confirme que os custos existentes ficaram pendentes; revise manualmente antes de usá-los em cálculos.
6. Não executar diretamente em produção sem validação técnica e aprovação responsável.

A migração não altera os dados monetários existentes nem aprova lançamentos automaticamente. Ela não foi executada contra banco real nesta etapa.

## Limites conhecidos
- A coluna `possible_duplicate_of` registra um vínculo manual de duplicidade potencial; a detecção automática de duplicidades ainda não está implementada.
- O fluxo de revisão está limitado a custos manuais. A aprovação das horas de instrutores continua no módulo próprio e precisa de uma política de inclusão confirmada.
- Ainda não há serviço de agregação financeiro conectado ao dashboard.
- O código PHP da tela precisa de lint e teste funcional em ambiente local com a migração aplicada. Não há banco conectado neste trabalho.
- A revisão dos custos não substitui a definição do rateio de despesas compartilhadas.

## Testes já realizados
- O motor financeiro e suas 16 verificações foram executados em PHP CLI 8.4.24 em uma cópia local de verificação: sintaxe válida e 16 verificações passaram.
- Isso valida a lógica e o teste copiados para a verificação local; não equivale a execução automatizada no GitHub nem a um teste de integração com banco.
- A tela de custos e a migração ainda não foram testadas contra MySQL/MariaDB.

## Próximo passo
Integrar os lançamentos aprovados e as horas de instrutor elegíveis num serviço de leitura por competência, preservando pendências, atribuição histórica e os cenários integral/proporcional lado a lado.
