# Plano de reaproveitamento seletivo do webERP

Status: proposta técnica para revisão. Nenhum código do webERP foi copiado para este repositório nesta etapa.

## Fonte de referência
- Projeto: https://github.com/timschofield/webERP
- Site oficial e documentação: https://www.weberp.org/
- O README do repositório descreve um ERP livre de administração empresarial e contabilidade, com PHP 8.1+ e MySQL/MariaDB, demonstração e documentação de instalação.
- O webERP é muito maior do que esta aplicação: deve ser tratado como referência de arquitetura e componentes, não como substituto integral do sistema GERAR.

## Objetivo
Reaproveitar boas soluções de um ERP financeiro maduro sem importar a complexidade de estoque, compras, clientes, fornecedores ou faturamento comercial que não se aplica diretamente ao ponto de equilíbrio de turmas de aprendizagem.

## O que estudar e adaptar

| Área | Referência a estudar no webERP | Adaptação para GERAR | Decisão |
|---|---|---|---|
| Plano de contas/categorias | Estrutura de contas e grupos contábeis | Categorias extensíveis para custos fixos, variáveis e custos de instrutores | Adaptar conceitos; não impor plano contábil sem validação |
| Relatórios financeiros | Relatórios e análises horizontais | Comparar competências, subsedes, polos e turmas; mostrar numerador, denominador, unidade e período | Reaproveitar padrões de relatório, não regras comerciais |
| Auditoria | AuditTrail.php / AuditScripts.php (referência de arquivos no repositório upstream) | Registrar usuário, ação, horário, entidade, competência, antes/depois e resultado de importação | Estudar e implementar equivalente mínimo |
| Autorização | Mecanismos de acesso por usuário/área do webERP | Negação por padrão; Master único perfil global; demais escopos definidos explicitamente | Estudar princípios; não copiar sem auditar |
| Importação e validação | Fluxos de importação do ERP e validações de dados | Prévia, validação de cabeçalhos, linha de origem, idempotência/duplicidade, erros por linha, confirmação transacional | Adaptar ao formato real dos arquivos GERAR |
| Consistência financeira | Separação entre lançamentos e relatórios | Receitas por competência; custos rastreáveis; evitar dupla contagem e demonstrar pendências | Implementar regra específica GERAR |
| Testes | Configuração de testes upstream (ex.: phpunit.dist.xml) | Testes de unidade para fórmulas e autorização + integração com banco isolado | Criar gradualmente sem adotar toda a infraestrutura antes de avaliar dependências |

## O que NÃO copiar diretamente
- O sistema inteiro ou o esquema de banco completo do webERP.
- Módulos de estoque, produção, compras, contas a pagar/receber comerciais e cadastro de clientes/fornecedores quando não se aplicarem ao domínio.
- Lógica contábil ou comercial como se fosse a regra da GERAR.
- Código de autenticação/autorização sem revisão de segurança.
- Qualquer dado de demonstração ou produção do webERP.
- Bibliotecas/arquivos com dependências ou licenças não verificadas.

## Regras próprias do domínio GERAR que devem permanecer
1. Receita originada do valor mensal de cada contrato de jovem aprendiz por competência, com detalhamento por polo e consolidação por subsede.
2. Exibir valor mensal integral e proporcional em contratos ativos apenas em parte da competência; não decidir cenário oficial sem aprovação da convenção de dias.
3. Usar histórico polo–subsede válido na competência. Sem vínculo histórico, deixar em pendência.
4. Separar margem de contribuição de resultado líquido e de ponto de equilíbrio.
5. Meta operacional de 15 jovens por turma é distinta de equilíbrio financeiro.
6. Custos de instrutor podem ser informados como total direto ou horas × custo-hora; mesma origem deve gerar alerta de possível duplicidade, não soma automática.
7. Ponto de equilíbrio em níveis institucional, subsede e turma; rateio dos custos compartilhados ainda exige decisão de negócio.
8. Somente Master tem acesso global. Não presumir permissões para outros perfis.

## Plano de execução em passos pequenos
### Passo A — Inventário comparativo (sem código copiado)
- Confirmar arquivos upstream para contabilidade, relatórios, auditoria, importação, segurança, menus e testes.
- Conferir licença no arquivo LICENSE e avisos nos arquivos que eventualmente sejam reutilizados.
- Identificar dependências e a compatibilidade real com o projeto PHP atual.
- Resultado: tabela de decisão por componente com referência upstream, esforço, risco, licença e plano de adaptação.

### Passo B — Fundamento de segurança e integridade
- Corrigir as permissões backend e escopo por unidade.
- Fazer importadores com prévia e resultado por linha.
- Implementar log de auditoria mínimo para alterações e importações.
- Testar sem banco de produção, com dados sintéticos.
- Preferir implementação própria compatível com a aplicação atual quando transplantar código trouxer mais dependências do que valor.

### Passo C — Camada financeira mínima
- Categorias de custos extensíveis.
- Registro de custo com origem, competência, escopo, método e estado.
- Separar custos variáveis e fixos, mantendo custo sem rateio como pendência explícita.
- Agregar receita e custos por competência, turma, polo e subsede.
- Mostrar valores integrais/proporcionais lado a lado.
- Só implementar o cálculo final do ponto de equilíbrio depois das decisões abertas.

### Passo D — Relatórios
- Painel consolidado com filtros de período.
- Comparação entre subsedes/polos/turmas e evolução histórica.
- Sempre identificar período, unidade, numerador, denominador e método de cálculo.
- Alertas operacionais: turmas abaixo da meta, excesso de capacidade, dados não atribuídos, custos duplicados e custos sem rateio.

### Passo E — Testes e revisão
- Testes unitários de fórmulas.
- Testes de integração no banco isolado.
- Testes de autorização por papel e escopo.
- Testes de importação: cabeçalho, linha vazia, zero legítimo, duplicidade, coluna desconhecida e falha transacional.
- Revisão manual de qualquer código de terceiros e dos avisos de licença.

## Licença e cuidado jurídico
Este documento não conclui a licença aplicável a cada arquivo. Antes de copiar código do webERP para este repositório, confirmar o LICENSE atual, cabeçalho de copyright e avisos de cada arquivo, as dependências e as obrigações de redistribuição/atribuição. Se isso não for claro, usar apenas ideias/padrões e implementar solução própria. Não apresentar o produto como webERP nem remover créditos.

## Limites de ambiente
- Trabalhar em branch isolada.
- Não modificar `main`, não fazer merge/deploy, não conectar ao banco produtivo.
- Utilizar dados sintéticos; separar validação estática, teste aritmético, teste PHP e teste funcional.
- Nunca afirmar que teste foi executado sem evidência.

## Referências
- https://github.com/timschofield/webERP
- https://github.com/timschofield/webERP/blob/master/README.md
- https://github.com/timschofield/webERP/blob/master/AuditTrail.php
- https://github.com/timschofield/webERP/blob/master/AuditScripts.php
- https://github.com/timschofield/webERP/blob/master/AccountGroups.php
- https://github.com/timschofield/webERP/blob/master/AnalysisHorizontalIncome.php
- https://github.com/timschofield/webERP/blob/master/phpunit.dist.xml
- https://www.weberp.org/


## Resultado da revisão estática recebida por e-mail — 2026-10-29

**Conclusão registrada:** não copiar código webERP nesta etapa. Reimplementar os padrões úteis no projeto GERAR, após revisão de requisitos, segurança e licença. Esta revisão foi estática; não houve execução de PHP ou testes funcionais do webERP.

| Componente upstream | Observação da revisão | Decisão para GERAR |
|---|---|---|
| `AccountGroups.php` | Grupos hierárquicos, validação de duplicidade/ciclos e proteção de exclusão; fortemente acoplado a tabelas/globais e APIs DB_*; mistura SQL e HTML e foi apontada concatenação de entradas em SQL. | Reimplementar categorias/taxonomia com IDs estáveis, validação e integridade referencial; não copiar o arquivo. |
| `AnalysisHorizontalIncome.php` | Comparação entre períodos, variações absolutas/relativas e drill-down; depende do modelo contábil webERP e convenções de sinal. | Criar serviço próprio de comparação por competência, subsede, polo e turma. |
| `AuditTrail.php` | Consulta de eventos, mas com interpretação de SQL bruto armazenado e dependência de sessão. | Criar eventos estruturados e append-only; não armazenar/interpretar SQL bruto como trilha de auditoria. |
| `AuditScripts.php` / `AuditScriptsFunctions.php` | Telemetria de duração com dependências legadas; a revisão apontou SQL concatenado e escaping insuficiente. | Registrar duração, status, ator, contexto e erros com consultas parametrizadas e escaping contextual. |
| `Z_ImportGLAccountGroups.php`, `ImportBankTrans.php`, `DefineImportBankTransClass.php` | Padrão de ingestão e revisão antes de postagem; importador usa parsing CSV simplista e depende do modelo contábil do ERP. | Pipeline GERAR com staging, parsing CSV adequado, validação por linha, prévia, idempotência, confirmação e resultado por linha. |
| `PageSecurity.php`, `SecurityTokens.php`, `UserRolesBySecurityToken.php` | Catálogo de permissões e associações papel/recurso, com IDs fixos e dependências legadas. | Criar autorização server-side por papel, ação e unidade; negar por padrão quando a permissão não estiver definida. |
| `phpunit.dist.xml` | Configuração pequena, mas ligada aos caminhos, schema e alvo HTTP do webERP. | Criar configuração de testes própria para GERAR, sem copiar caminhos/configurações do upstream. |

### Decisão sobre licença
A revisão informa que o README upstream declara GPL-2.0 e que não encontrou arquivo LICENSE/LICENCE/COPYING no commit da GERAR que examinou. Antes de incorporar qualquer código GPL, confirmar a licença vigente, os avisos por arquivo, as dependências e as obrigações de distribuição com o responsável competente. Esta nota não é aconselhamento jurídico. Enquanto isso, reutilizar conceitos e implementar código próprio.

### Alternativas educacionais citadas para comparação, não selecionadas
- Frappe Education / ERPNext: https://frappe.io/education
- Gibbon Finance: https://gibbonedu.org/features/other/finance
- OpenEduCat: https://openeducat.org/compare-editions/

Nenhuma das alternativas foi demonstrada na revisão como pronta para todas as regras específicas da GERAR. Uma migração integral exigiria matriz de aderência, análise de dependências, licença, esforço de migração e lacunas funcionais.

### Próximos passos recomendados
1. Continuar a correção de segurança e importadores na aplicação existente.
2. Implementar trilha de auditoria estruturada e telemetria mínima.
3. Criar camada financeira própria, com categorias, lançamentos e fonte/competência rastreáveis.
4. Criar testes próprios de fórmulas, permissões, escopo por unidade e importações.
5. Só depois comparar custo/benefício de continuar a aplicação atual versus migrar para uma plataforma educacional.

**Limites mantidos:** nenhuma cópia de código upstream, nenhuma alteração em `main`, nenhum merge/deploy e nenhum acesso ao banco de produção.
