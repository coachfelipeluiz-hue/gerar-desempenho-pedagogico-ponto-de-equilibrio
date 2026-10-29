# GERAR — Ponto de Equilíbrio Pedagógico e Financeiro (MVP)

Primeira base funcional em PHP + MySQL. Inclui login com sessão, estrutura de autorização por unidade, dashboard inicial, schema MySQL e área de importação preparada para CSV. É uma base de desenvolvimento, ainda não pronta para produção.

## Requisitos
- PHP 8.2+ (extensões `pdo_mysql`, `fileinfo`, `mbstring`)
- MySQL 8+ ou MariaDB compatível
- Servidor web com raiz pública apontando para `public/`

## Instalação
1. Crie um banco vazio, por exemplo `gerar_ponto_equilibrio`.
2. Importe `database/schema.sql` no MySQL.
3. Copie `config/database.example.php` para `config/database.php` e informe os dados locais.
4. Configure o servidor web para servir apenas `public/`.
5. Cadastre o primeiro usuário Master diretamente no banco usando um hash gerado com `password_hash()` em PHP:
   `php -r "echo password_hash('TROQUE-ESTA-SENHA', PASSWORD_DEFAULT), PHP_EOL;"`
   Depois insira o hash em `users.password_hash`, com `role='master'`.
6. Para desenvolvimento local: `php -S 127.0.0.1:8000 -t public`
7. Abra `http://127.0.0.1:8000/login.php`.

## Segurança importante
- Não use credenciais reais no GitHub.
- Não exponha `config/`, `database/` ou `storage/` no servidor público.
- Altere imediatamente a senha inicial e use HTTPS em produção.
- Login e logout usam token CSRF; logout aceita somente POST.
- IDs de usuário enviados por formulário aceitam apenas inteiros não negativos; novos usuários ainda exigem senha mínima.
- Cada requisição protegida revalida no banco se o usuário continua ativo e recarrega perfil e permissões; usuários desativados perdem a sessão.
- Ações de instrutores exigem a permissão correspondente de criar/editar e validam a unidade de origem/destino e o histórico da turma; aprovar horas permanece exclusivo do Master.
- O resumo de instrutores separa custo aprovado, considerado no dashboard, de custo pendente de aprovação.
- Usuários, unidades, custos e importações, por serem globais ou ainda sem autorização por unidade implementada, ficam restritos ao Master nesta etapa.
- Acesso por unidade só vale dentro do intervalo `valid_from`–`valid_until`.
- O módulo de importação nesta versão é apenas um ponto inicial: validar os cabeçalhos reais antes de confirmar importações em massa.
- Não carregar planilhas com dados pessoais em repositórios públicos.

## Escopo atual
- Login e logout com sessões PHP.
- Perfis iniciais Master, Gestor e Consulta.
- Estrutura de permissões por unidade e módulo no banco.
- Dashboard de base com alertas de dados ainda não importados.
- Esquema conceitual MySQL para subsedes, polos, turmas, contratos, instrutores, horas, custos, permissões, importações e auditoria.
- Tela inicial de importação CSV (validação real deve ser concluída com as bases da GERAR).

## Próximas etapas
1. Validar e mapear colunas reais dos arquivos enviados.
2. Implementar importadores com prévia, detecção de duplicidade e rollback.
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
```

Os testes usam somente dados sintéticos; `authorization_policy.php` requer `pdo_sqlite`. Eles não conectam a um banco da aplicação nem usam dados reais.
