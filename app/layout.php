
<?php

function page_header(string $title): void {
    $user = current_user();
    $base = app_base_path();
    ?>
    <!doctype html>
    <html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($title) ?> — GERAR</title>

        <link rel="stylesheet" href="<?= e($base) ?>/assets/app.css">
    </head>
    <body>
        <header class="topbar">
            <a class="brand" href="<?= e($base) ?>/index.php">
                GERAR <span>Ponto de Equilíbrio</span>
            </a>

            <?php if ($user): ?>
                <div class="userbox">
                    <span><?= e($user['name']) ?> · <?= e($user['role']) ?></span>
                    <a href="<?= e($base) ?>/logout.php">Sair</a>
                </div>
            <?php endif; ?>
        </header>

        <?php if ($user): ?>
            <nav class="nav">
                <a href="<?= e($base) ?>/index.php">Visão geral</a>
                <a href="<?= e($base) ?>/imports.php">Importações</a>
                <a href="<?= e($base) ?>/units.php">Subsedes e polos</a>
                <a href="<?= e($base) ?>/users.php">Usuários</a>
                <a href="<?= e($base) ?>/costs.php">Custos</a>
                <a href="<?= e($base) ?>/instructors.php">Instrutores e horas</a>
            </nav>
        <?php endif; ?>

        <main class="container">
            <h1><?= e($title) ?></h1>
    <?php
}

function page_footer(): void {
    echo '</main>
    <footer class="footer">
        GERAR · MVP em desenvolvimento · Não usar como relatório financeiro oficial.
    </footer>
    </body>
    </html>';
}
