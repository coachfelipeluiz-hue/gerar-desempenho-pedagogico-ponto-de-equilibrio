<?php
declare(strict_types=1);

function import_csv_rows(string $path): Generator {
    $fh = fopen($path, 'rb');
    if (!$fh) {
        throw new RuntimeException('Não foi possível ler o arquivo enviado.');
    }

    try {
        $header = fgetcsv($fh, 0, ',', '"', '\\');
        if (!$header) {
            throw new RuntimeException('O CSV está vazio ou não pôde ser lido.');
        }

        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
        $header = array_map(static fn($value) => trim((string)$value), $header);
        $line = 1;

        while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            $line++;
            if (count($row) === 1 && trim((string)$row[0]) === '') {
                continue;
            }
            yield [$line, $header, $row];
        }
    } finally {
        fclose($fh);
    }
}

function contract_csv_import_rows(string $path): Generator {
    $map = null;
    foreach (import_csv_rows($path) as [$line, $header, $row]) {
        if ($map === null) {
            $map = contract_csv_map($header);
        }
        yield [$line, $map, $row];
    }
}

function money_to_float(mixed $value): float {
    $value = trim((string)$value);
    if ($value === '') {
        return NAN;
    }

    $value = str_replace(['R$', "\xc2\xa0", ' '], '', $value);
    if (str_contains($value, ',') && str_contains($value, '.')) {
        $value = str_replace('.', '', $value);
    }
    $value = str_replace(',', '.', $value);

    return is_numeric($value) ? (float)$value : NAN;
}

function date_or_null(mixed $value): ?string {
    $value = trim((string)$value);
    if ($value === '' || $value === '0000-00-00') {
        return null;
    }

    foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
        if ($date && $date->format($format) === $value) {
            return $date->format('Y-m-d');
        }
    }

    return null;
}

function contract_csv_map(array $header): array {
    $required = [
        '2 Contrato',
        '8 Subsede',
        '9 Subsede',
        '10 Polo (Local Curso)',
        '12 Empresa',
        '15 Dt Inicio Ctr',
        '16 Dt Final Ctr',
        '17 Desligado',
        '18 Desligado',
        '19 $ Mensal',
    ];
    $map = [];

    foreach ($required as $name) {
        $index = array_search($name, $header, true);
        if ($index === false) {
            throw new RuntimeException('Cabeçalho obrigatório não encontrado: ' . $name);
        }
        $map[$name] = $index;
    }

    return $map;
}

function assert_unique_contract_code(string $code, array &$seenCodes): void {
    $normalized = mb_strtolower(trim($code), 'UTF-8');
    if ($normalized === '' || isset($seenCodes[$normalized])) {
        throw new RuntimeException('O CSV contém número de contrato vazio ou repetido. Corrija o arquivo antes de importar.');
    }
    $seenCodes[$normalized] = true;
}
