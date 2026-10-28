<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require_login();
if (!can_access_module('imports') && (current_user()['role'] ?? '') !== 'master') {
    http_response_code(403); page_header('Acesso negado'); echo '<div class="error">Você não tem permissão para acessar importações.</div>'; page_footer(); exit;
}
page_header('Importação de dados');
?>
<div class="notice"><strong>Importador em preparação.</strong> Nesta primeira versão, a tela documenta o fluxo; o processamento definitivo será liberado após mapear os cabeçalhos e as regras das bases reais.</div>
<div class="panel"><h2>Fontes previstas</h2>
<div class="import-row"><div><strong>Turmas (CSV)</strong><p class="muted">Grupos, turmas, polos, subsedes e quantidade de aprendizes.</p></div><span class="pill">Mapeamento pendente</span></div>
<div class="import-row"><div><strong>Contratos GERAR (CSV)</strong><p class="muted">Receita mensal e competência; validar colunas de subsede e regra de atribuição.</p></div><span class="pill">Mapeamento pendente</span></div>
<div class="import-row"><div><strong>Base de instrutores (XLSX)</strong><p class="muted">Cadastro e vínculo com subsede; custos-hora serão informados separadamente.</p></div><span class="pill">Mapeamento pendente</span></div>
<div class="import-row"><div><strong>Custos e premissas</strong><p class="muted">Cadastro manual inicial de custo-hora, custos locais e institucionais.</p></div><span class="pill">A construir</span></div>
</div>
<?php page_footer(); ?>
