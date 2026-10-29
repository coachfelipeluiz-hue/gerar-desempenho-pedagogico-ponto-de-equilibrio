<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/class_import_helpers.php';

$checks = 0;
$failures = [];
$assertSame = static function (string $label, mixed $expected, mixed $actual) use (&$checks, &$failures): void {
    $checks++;
    if ($expected !== $actual) $failures[] = $label . ': esperado ' . var_export($expected, true) . ', obtido ' . var_export($actual, true);
};
$assertNull = static function (string $label, mixed $actual) use (&$checks, &$failures): void {
    $checks++;
    if ($actual !== null) $failures[] = $label . ': esperado null, obtido ' . var_export($actual, true);
};
$readCsv = static function (string $content): array {
    $path = tempnam(sys_get_temp_dir(), 'gerar-csv-');
    if ($path === false) throw new RuntimeException('Não foi possível criar CSV temporário.');
    file_put_contents($path, $content);
    try { return class_csv_read($path); }
    finally { @unlink($path); }
};

$comma = $readCsv("codigo,nome,curso\n1,Turma A,Frentista\n2,Turma B,Auxiliar\n");
$assertSame('delimitador vírgula', ',', $comma['delimiter']);
$assertSame('cabeçalho vírgula', ['codigo','nome','curso'], $comma['header']);
$assertSame('quantidade de linhas vírgula', 2, count($comma['rows']));
$assertSame('número de linha CSV', 3, $comma['rows'][1]['line']);

$semicolon = $readCsv("\xEF\xBB\xBFcodigo;nome;curso\r\n1;\"Turma, Centro\";Frentista\r\n2;Turma B;Auxiliar\r\n");
$assertSame('delimitador ponto e vírgula', ';', $semicolon['delimiter']);
$assertSame('remover BOM do primeiro cabeçalho', 'codigo', $semicolon['header'][0]);
$assertSame('separador dentro de aspas preservado', 'Turma, Centro', $semicolon['rows'][0]['values'][1]);

$tab = $readCsv("codigo\tnome\tcurso\n1\tTurma A\tFrentista\n");
$assertSame('delimitador tabulação', "\t", $tab['delimiter']);
$assertSame('linha com tabulação', ['1','Turma A','Frentista'], $tab['rows'][0]['values']);

$assertSame('normalização de cabeçalho acentuado', 'subsede', class_norm_header('Subséde'));
$assertSame('valor inteiro simples', 15, class_int_value('15'));
$assertSame('valor com milhar', 1200, class_int_value('1.200'));
$assertNull('rejeitar inteiro com letras', class_int_value('12abc'));
$assertNull('rejeitar decimal ambíguo', class_int_value('12,5'));
$assertSame('data ISO', '2026-10-29', class_date_value('2026-10-29'));
$assertSame('competência mês/ano', '2026-10-01', class_date_value('10/2026'));
$assertNull('rejeitar data impossível', class_date_value('31/02/2026'));
$assertSame('ler campo mapeado', 'Turma A', class_value(['1','Turma A'], ['name'=>1], 'name'));
$assertSame('campo sem mapeamento fica vazio', '', class_value(['1','Turma A'], ['name'=>-1], 'name'));

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo "OK: {$checks} verificações do importador CSV." . PHP_EOL;
