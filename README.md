# GERAR — Ponto de Equilíbrio Pedagógico e Financeiro (MVP)

Primeira base funcional em PHP + MySQL. Inclui login com sessão, estrutura de autorização por unidade, dashboard inicial, schema MySQL e importador de contratos CSV com prévia. É uma base de desenvolvimento, ainda não pronta para produção.

## Requisitos
- PHP 8.2+ (extensões `pdo_mysql`, `fileinfo`, `mbstring`)
- MySQL 8+ ou MariaDB compatível
- Servidor web com raiz pública apontando para `public/`

## Instalação
1. Crie um banco vazio, por exemplo `gerar_ponto_equilibrio`.
2. Importe `database/schema.sql` no MySQL.
3. Copie `config/database.example.php` para `config/database.php` e informe os dados locais.
4. Para um banco existente, faça backup e execute uma vez `database/migrations/20261029_contract_termination_date.sql`; depois reimporte `Gerar.csv` para preencher as datas de desligamento.
5. Configure o servidor web para servir apenas `public/`.
6. Cadastre o primeiro usuário Master diretamente no banco usando um hash gerado com `password_hash()` em PHP:
   `php -r "echo password_hash('TROQUE-ESTA-SENHA', PASSWORD_DEFAULT), PHP_EOL;"`
   Depois insira o hash em `users.password_hash`, com `role='master'`.
7. Para desenvolvimento local: `php -S 127.0.0.1:8000 -t public`
8. Abra `http://127.0.0.1:8000/login.php`.

## Identidade visual
O cabeçalho usa o logo e a paleta observados no site oficial da [Jornada do Aprendiz](https://ajornadadoaprendiz.com.br/): roxo `#342A84`, turquesa `#50BAB0`, magenta `#C93082` e laranja `#EB823C`. O logo local corresponde ao ativo oficial `https://ajornadadoaprendiz.com.br/wp-content/uploads/2024/02/jornada-aprendiz-retina.png`.

## Segurança importante
- Não use credenciais reais no GitHub.
- Não exponha `config/`, `database/` ou `storage/` no servidor público.
- Altere imediatamente a senha inicial e use HTTPS em produção.
- Login e logout usam token CSRF; logout aceita somente POST.
- IDs de usuário enviados por formulário aceitam apenas inteiros não negativos; novos usuários ainda exigem senha mínima.
- Confirmação/cancelamento de importação exige o token da prévia atualmente armazenada na sessão.
- O dashboard calcula margem preliminar e receita/contratos necessários para uma margem-alvo, mantendo os custos conhecidos do mês constantes; a simulação não grava dados.
- Receita contratual é rateada pelos dias ativos do mês, incluindo o último dia; status vazio/desconhecido, desligamento sem data válida, datas preenchidas malformadas ou períodos invertidos ficam pendentes. Início/fim opcionais vazios são tratados como limites abertos da competência.
- Cada requisição protegida revalida no banco se o usuário continua ativo e recarrega perfil e permissões; usuários desativados perdem a sessão.
- Ações de instrutores exigem a permissão correspondente de criar/editar e validam a unidade de origem/destino e o histórico da turma; aprovar horas permanece exclusivo do Master.
- O resumo de instrutores separa custo aprovado, considerado no dashboard, de custo pendente de aprovação.
- Usuários, unidades, custos e importações, por serem globais ou ainda sem autorização por unidade implementada, ficam restritos ao Master nesta etapa.
- Acesso por unidade só vale dentro do intervalo `valid_from`–`valid_until`.
- O importador de contratos apresenta prévia, separa status/data pendentes e atualiza contratos pelo código de origem. A validação do fluxo com banco de teste e totais reconciliados ainda é necessária antes de produção.
- Não carregar planilhas com dados pessoais em repositórios públicos.

## Escopo atual
- Login e logout com sessões PHP.
- Perfis iniciais Master, Gestor e Consulta.
- Estrutura de permissões por unidade e módulo no banco.
- Dashboard preliminar com receita proporcional por competência, detalhe separado por subsede/polo, custos conhecidos e margem-alvo.
- Esquema conceitual MySQL para subsedes, polos, turmas, contratos, instrutores, horas, custos, permissões, importações e auditoria.
- Importador de contratos `Gerar.csv`, sujeito a reconciliação em cópia de banco antes de uso operacional.

## Próximas etapas
1. Aplicar migração em banco de teste, reimportar contratos e reconciliar valores com a planilha de referência.
2. Completar importadores de turmas e instrutores com prévia, detecção de duplicidade e rollback.
3. Criar CRUD de subsedes/polos e histórico de vínculo.
4. Completar testes de autorização e dashboards por unidade.
5. Implementar cálculos do ponto de equilíbrio e simulador, comparando com a planilha de referência.

## Testes locais
Execute com PHP CLI:

```sh
php tests/authorization_policy.php
php tests/instructor_cost_totals.php
php tests/contract_import_parsing.php
php tests/class_import_fields.php
php tests/csrf_policy.php
php tests/user_id_validation.php
php tests/import_preview_binding.php
php tests/financial_projection.php
php tests/contract_monthly_revenue.php
php tests/contract_monthly_candidates.php
```

Os testes usam somente dados sintéticos; `authorization_policy.php` e `contract_monthly_candidates.php` requerem `pdo_sqlite`. Eles não conectam a um banco da aplicação nem usam dados reais.
