<?php
function page_header(string $title): void {
    $user = current_user();
    ?>
    <!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> — GERAR</title><link rel="stylesheet" href="/assets/app.css"></head><body>
    <header class="topbar"><a class="brand" href="/index.php">GERAR <span>Ponto de Equilíbrio</span></a>
    <?php if ($user): ?><div class="userbox"><span><?= e($user['name']) ?> · <?= e($user['role']) ?></span><a href="/logout.php">Sair</a></div><?php endif; ?></header>
    <?php if ($user): ?><nav class="nav"><a href="/index.php">Visão geral</a><a href="/imports.php">Importações</a><a href="/units.php">Subsedes e polos</a><a href="/users.php">Usuários</a></nav><?php endif; ?>
    <main class="container"><h1><?= e($title) ?></h1>
    <?php
}
function page_footer(): void { echo '</main><footer class="footer">GERAR · MVP em desenvolvimento · Não usar como relatório financeiro oficial.</footer></body></html>'; }
