<?php
declare(strict_types=1);

require __DIR__ . '/../app/finance.php';

function assert_financial_projection(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$atBreakEven = financial_projection(10000, 12500, 0, 5);
assert_financial_projection($atBreakEven['operating_result'] === -2500.0, 'O saldo preliminar deve refletir receita menos custos.');
assert_financial_projection($atBreakEven['current_margin_percent'] === -25.0, 'A margem atual deve ser saldo dividido pela receita.');
assert_financial_projection($atBreakEven['required_revenue'] === 12500.0, 'Com margem-alvo zero, receita necessária equivale aos custos atuais.');
assert_financial_projection($atBreakEven['additional_revenue'] === 2500.0, 'A lacuna até o equilíbrio deve ser positiva.');
assert_financial_projection($atBreakEven['average_revenue_per_contract'] === 2000.0, 'A média deve usar o total recebido dividido pelo número de contratos.');
assert_financial_projection($atBreakEven['additional_contracts'] === 2, 'A estimativa deve arredondar para cima contratos parciais.');

$targetMargin = financial_projection(15000, 12000, 10, 3);
assert_financial_projection($targetMargin['current_margin_percent'] === 20.0, 'A margem atual deve refletir o resultado observado.');
assert_financial_projection($targetMargin['required_revenue'] === 13333.33, 'Receita requerida deve cobrir custos constantes e margem-alvo.');
assert_financial_projection($targetMargin['additional_revenue'] === 0.0, 'A receita atual já supera a receita requerida para a meta.');
assert_financial_projection($targetMargin['additional_contracts'] === 0, 'Nenhum contrato adicional é necessário quando a meta já foi atingida.');

$noRevenue = financial_projection(0, 5000, 0, 0);
assert_financial_projection($noRevenue['current_margin_percent'] === null, 'A margem atual deve ser indisponível sem receita, não infinita.');
assert_financial_projection($noRevenue['additional_contracts'] === null, 'Não se estima contratos sem receita média observável.');
assert_financial_projection($noRevenue['required_revenue'] === 5000.0, 'Custos positivos devem produzir receita mínima requerida.');
assert_financial_projection(financial_projection(100, 0, 0, '0002')['average_revenue_per_contract'] === 50.0, 'Contagem textual válida deve ser normalizada.');

foreach ([
    [-1, 1, 0, 1],
    [1, -1, 0, 1],
    [1, 1, 100, 1],
    [1, 1, -0.1, 1],
    [1, 1, 0, -1],
    [1, 1, 0, '999999999999999999999999999999999999999'],
    [NAN, 1, 0, 1],
] as [$revenue, $costs, $margin, $count]) {
    $rejected = false;
    try {
        financial_projection($revenue, $costs, $margin, $count);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    assert_financial_projection($rejected, 'Entrada financeira inválida deve ser rejeitada.');
}

fwrite(STDOUT, "OK: margem, ponto de cobertura, meta e limites foram verificados.\n");
