
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
        <style>
            .nav-menu { position: relative; display: inline-block; }
            .nav-menu > summary { cursor: pointer; list-style: none; padding: 0.4rem 0; color: inherit; }
            .nav-menu > summary::-webkit-details-marker { display: none; }
            .nav-menu > summary::after { content: " ▾"; font-size: 0.75em; }
            .nav-submenu { position: absolute; top: 100%; left: 0; z-index: 1000; min-width: 190px; display: flex; flex-direction: column; gap: 0.15rem; padding: 0.45rem; background: #fff; border: 1px solid #d9dee7; border-radius: 6px; box-shadow: 0 6px 18px rgba(0,0,0,0.12); }
            .nav-submenu a { display: block; padding: 0.55rem 0.7rem; white-space: nowrap; text-decoration: none; border-radius: 4px; }
            .nav-submenu a:hover { background: #f1f4f8; }
            @media (max-width: 700px) {
                .nav-menu { display: block; }
                .nav-submenu { position: static; box-shadow: none; margin: 0.3rem 0 0.5rem 0.5rem; }
            }
        </style>

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
                <details class="nav-menu">
                    <summary>Importar</summary>
                    <div class="nav-submenu">
                        <a href="<?= e($base) ?>/imports.php">Importar contratos</a>
                        <a href="<?= e($base) ?>/import_classes.php">Importar turmas</a>
                    </div>
                </details>
                <a href="<?= e($base) ?>/units.php">Subsedes e polos</a>
                <a href="<?= e($base) ?>/users.php">Usuários</a>
                <details class="nav-menu">
                    <summary>Custos</summary>
                    <div class="nav-submenu">
                        <a href="<?= e($base) ?>/costs.php">Lançamentos e categorias</a>
                        <a href="<?= e($base) ?>/instructors.php">Instrutores e horas</a>
                    </div>
                </details>
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
