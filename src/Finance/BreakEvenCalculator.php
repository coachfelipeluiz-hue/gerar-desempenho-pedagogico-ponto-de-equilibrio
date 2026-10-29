<?php
declare(strict_types=1);

namespace Gerar\\Finance;

/**
 * Regras financeiras puras do domínio GERAR.
 *
 * Não acessa banco, não aplica rateio e não decide qual cenário de receita
 * é oficial. Os chamadores devem fornecer valores já validados e elegíveis.
 */
final class BreakEvenCalculator
{
    /**
     * Calcula receita integral e proporcional de contratos por competência.
     *
     * Cada contrato deve conter monthly_value, active_days e period_days.
     * A convenção de dias deve ser definida antes de montar esses números.
     *
     * @param list<array{monthly_value:int|float,active_days:int,period_days:int}> $contracts
     * @return array{full_revenue:float,prorated_revenue:float,contracts_count:int}
     */
    public static function contractRevenue(array $contracts): array
    {
        $full = 0.0;
        $prorated = 0.0;

        foreach ($contracts as $index => $contract) {
            $value = (float) ($contract['monthly_value'] ?? -1);
            $activeDays = $contract['active_days'] ?? null;
            $periodDays = $contract['period_days'] ?? null;

            if (!is_finite($value) || $value < 0) {
                throw new InvalidArgumentException("Valor mensal inválido no contrato {$index}.");
            }
            if (!is_int($activeDays) || !is_int($periodDays)
                || $periodDays <= 0 || $activeDays < 0 || $activeDays > $periodDays) {
                throw new InvalidArgumentException("Dias inválidos no contrato {$index}.");
            }

            $full += $value;
            $prorated += $value * ($activeDays / $periodDays);
        }

        return [
            'full_revenue' => round($full, 2),
            'prorated_revenue' => round($prorated, 2),
            'contracts_count' => count($contracts),
        ];
    }

    /**
     * Margem de contribuição = receita - custos variáveis elegíveis.
     *
     * @return array{contribution_margin:float,contribution_margin_rate:?float}
     */
    public static function contributionMargin(float $revenue, float $variableCosts): array
    {
        self::assertNonNegativeFinite($revenue, 'Receita');
        self::assertNonNegativeFinite($variableCosts, 'Custos variáveis');

        $margin = round($revenue - $variableCosts, 2);

        return [
            'contribution_margin' => $margin,
            'contribution_margin_rate' => $revenue > 0
                ? round($margin / $revenue, 6)
                : null,
        ];
    }

    /**
     * Calcula receita de equilíbrio = custos fixos / taxa de margem de contribuição.
     * Retorna null quando a taxa não permite cálculo válido.
     */
    public static function breakEvenRevenue(float $fixedCosts, float $contributionMarginRate): ?float
    {
        self::assertNonNegativeFinite($fixedCosts, 'Custos fixos');
        if (!is_finite($contributionMarginRate) || $contributionMarginRate <= 0
            || $contributionMarginRate > 1) {
            return null;
        }

        return round($fixedCosts / $contributionMarginRate, 2);
    }

    /** @return array{count:int,target:int,additional_needed:int,status:string} */
    public static function operationalTarget(int $youthCount, int $target = 15): array
    {
        if ($youthCount < 0 || $target < 1) {
            throw new InvalidArgumentException('Quantidade de jovens ou meta inválida.');
        }

        return [
            'count' => $youthCount,
            'target' => $target,
            'additional_needed' => max(0, $target - $youthCount),
            'status' => $youthCount < $target ? 'below_target'
                : ($youthCount <= 25 ? 'within_range' : 'above_range'),
        ];
    }

    private static function assertNonNegativeFinite(float $value, string $label): void
    {
        if (!is_finite($value) || $value < 0) {
            throw new InvalidArgumentException($label . ' deve ser um número finito não negativo.');
        }
    }
}
