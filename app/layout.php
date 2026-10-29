
<?php

function page_header(string $title): void {
    $user = current_user();
    $base = app_base_path();
    $csrfLogout = $user ? csrf_token_value('csrf_logout') : '';
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
            .userbox form { margin: 0; }
            .userbox button.logout-link { padding: 0; border: 0; background: transparent; color: inherit; font: inherit; font-weight: 600; text-decoration: underline; }
            .userbox button.logout-link:hover { background: transparent; color: #dbe7e3; }
            @media (max-width: 700px) {
                .nav-menu { display: block; }
                .nav-submenu { position: static; box-shadow: none; margin: 0.3rem 0 0.5rem 0.5rem; }
            }
        </style>

    </head>
    <body>
        <header class="topbar">
            <a class="brand" href="<?= e($base) ?>/index.php">
                <img class="brand-logo" src="<?= e($base) ?>/assets/jornada-aprendiz-retina.png" alt="Jornada do Aprendiz">
                <span class="brand-copy">GERAR <small>Ponto de Equilíbrio</small></span>
            </a>

            <?php if ($user): ?>
                <div class="userbox">
                    <span><?= e($user['name']) ?> · <?= e($user['role']) ?></span>
                    <form method="post" action="<?= e($base) ?>/logout.php">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfLogout) ?>">
                        <button class="logout-link" type="submit">Sair</button>
                    </form>
                </div>
            <?php endif; ?>
        </header>

        <?php if ($user): ?>
            <nav class="nav">
                <?php if (can_access_module('dashboard')): ?><a href="<?= e($base) ?>/index.php">Visão geral</a><?php endif; ?>
                <?php if (can_access_module('classes')): ?><a href="<?= e($base) ?>/classes.php">Turmas</a><?php endif; ?>
                <?php if (is_master_user()): ?>
                    <details class="nav-menu">
                        <summary>Importar</summary>
                        <div class="nav-submenu">
                            <a href="<?= e($base) ?>/imports.php">Importar contratos</a>
                            <a href="<?= e($base) ?>/import_classes.php">Importar turmas</a>
                        </div>
                    </details>
                    <a href="<?= e($base) ?>/units.php">Subsedes e polos</a>
                    <a href="<?= e($base) ?>/users.php">Usuários</a>
                <?php endif; ?>
                <?php if (is_master_user() || can_access_module('instructors')): ?>
                    <details class="nav-menu">
                        <summary>Custos</summary>
                        <div class="nav-submenu">
                            <?php if (is_master_user()): ?><a href="<?= e($base) ?>/costs.php">Lançamentos e categorias</a><?php endif; ?>
                            <?php if (can_access_module('instructors')): ?><a href="<?= e($base) ?>/instructors.php">Instrutores e horas</a><?php endif; ?>
                        </div>
                    </details>
                <?php endif; ?>
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
