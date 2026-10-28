<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require_login();
if (!can_access_module('units') && (current_user()['role'] ?? '') !== 'master') {
    http_response_code(403); page_header('Acesso negado'); echo '<div class="error">Você não tem permissão para gerenciar unidades.</div>'; page_footer(); exit;
}
page_header('Subsedes e polos');
?>
<div class="notice">O cadastro CRUD de subsedes, polos e histórico de responsabilidade será conectado ao banco na próxima etapa. Cada polo deverá ter subsede responsável e vigência do vínculo.</div>
<div class="panel"><h2>Estrutura prevista</h2><p>GERAR → Subsede → Polo → Grupo → Turma</p><p class="muted">Acesso de usuários e resultados serão filtrados conforme as unidades autorizadas e o vínculo vigente na competência consultada.</p></div>
<?php page_footer(); ?>
