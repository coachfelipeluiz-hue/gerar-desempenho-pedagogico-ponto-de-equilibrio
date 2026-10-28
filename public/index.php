
<?php
require __DIR__ . '/../app/bootstrap.php';

require_login();

require __DIR__ . '/../app/layout.php';

page_header('Visão geral');
?>

<div class="notice">
    <strong>Primeira versão em construção.</strong>
    Os cartões abaixo mostram a estrutura dos indicadores. Os valores reais só aparecerão após os importadores serem validados e executados.
</div>

<div class="filters">
    <label>
        Competência
        <input type="month" value="<?= date('Y-m') ?>" disabled>
    </label>

    <label>
        Subsede
        <select disabled>
            <option>Todas as unidades autorizadas</option>
        </select>
    </label>

    <label>
        Polo
        <select disabled>
            <option>Todos os polos autorizados</option>
        </select>
    </label>
</div>

<section class="cards">
    <article class="card">
        <span>Aprendizes ativos</span>
        <strong>—</strong>
        <small>Aguardando importação da base de turmas</small>
    </article>

    <article class="card">
        <span>Turmas abaixo de 15</span>
        <strong>—</strong>
        <small>Risco operacional</small>
    </article>

    <article class="card">
        <span>Média por turma</span>
        <strong>—</strong>
        <small>Aprendizes ÷ turmas elegíveis</small>
    </article>

    <article class="card">
        <span>Receita mensal</span>
        <strong>—</strong>
        <small>Aguardando validação de contratos</small>
    </article>

    <article class="card">
        <span>Custos conhecidos</span>
        <strong>—</strong>
        <small>Instrutores horistas e custos cadastrados</small>
    </article>

    <article class="card">
        <span>Resultado estimado</span>
        <strong>—</strong>
        <small>Não calculado até validar as premissas</small>
    </article>
</section>

<section class="panel">
    <h2>Prioridades de desenvolvimento</h2>
    <ol>
        <li>Mapear corretamente turmas, polos e subsedes.</li>
        <li>Definir como a receita dos contratos será atribuída à unidade/polo.</li>
        <li>Registrar horas ministradas por instrutor e turma.</li>
        <li>Reproduzir as fórmulas da planilha de ponto de equilíbrio.</li>
        <li>Ativar os filtros e validar o isolamento de acesso entre subsedes.</li>
    </ol>
</section>

<?php page_footer(); ?>
