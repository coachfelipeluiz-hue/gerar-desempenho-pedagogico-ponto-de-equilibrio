<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require_login();
if ((current_user()['role'] ?? '') !== 'master') {
    http_response_code(403); page_header('Acesso negado'); echo '<div class="error">Somente o Master pode gerenciar usuários.</div>'; page_footer(); exit;
}
page_header('Usuários e permissões');
?>
<div class="notice">Somente o Master poderá criar usuários, atribuir uma ou mais subsedes, conceder módulos e ações, e revogar acessos. O formulário CRUD será implementado após validar o schema de unidades.</div>
<div class="panel"><h2>Perfis iniciais</h2><ul><li><strong>Master:</strong> todas as unidades e configurações.</li><li><strong>Gestor:</strong> unidades e módulos explicitamente autorizados.</li><li><strong>Consulta:</strong> leitura dos módulos e unidades autorizados.</li></ul><p class="muted">As restrições precisam ser verificadas no servidor e em todas as consultas ao banco, não apenas na interface.</p></div>
<?php page_footer(); ?>
