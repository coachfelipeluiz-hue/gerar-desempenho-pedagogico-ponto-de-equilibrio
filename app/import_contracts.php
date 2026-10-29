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

    if (preg_match('~\A(\d{4}-\d{2}-\d{2}|\d{1,2}[/-]\d{1,2}[/-]\d{4})[ T]\d{1,2}:\d{2}(?::\d{2})?\z~', $value, $datePart)) {
        $value = $datePart[1];
    }

    foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
        if ($date && $date->format($format) === $value) {
            return $date->format('Y-m-d');
        }
    }

    return null;
}

function contract_period_data(mixed $startRaw, mixed $endRaw): array {
    $startText = trim((string)$startRaw);
    $endText = trim((string)$endRaw);
    $startDate = date_or_null($startText);
    $endDate = date_or_null($endText);
    $reason = null;

    if ($startText !== '' && $startDate === null) {
        $reason = 'invalid_start_date';
    } elseif ($endText !== '' && $endDate === null) {
        $reason = 'invalid_end_date';
    } elseif ($startDate !== null && $endDate !== null && $endDate < $startDate) {
        $reason = 'invalid_contract_period';
    }

    return [
        'start_date' => $startDate,
        'end_date' => $endDate,
        'pending' => $reason !== null,
        'pending_reason' => $reason,
    ];
}

function date_looks_like_value(mixed $value): bool {
    $value = trim((string)$value);
    return $value !== '' && preg_match('~\A(?:\d{4}[-/]\d{1,2}[-/]\d{1,2}|\d{1,2}[-/]\d{1,2}[-/]\d{2,4})(?:[ T].*)?\z~', $value) === 1;
}

function normalize_contract_marker(mixed $value): string {
    $value = mb_strtolower(trim((string)$value), 'UTF-8');
    $value = strtr($value, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c']);
    return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
}

function contract_discharge_data(mixed $flag, mixed $dateRaw): array {
    $flag = mb_strtolower(trim((string)$flag), 'UTF-8');
    $discharged = in_array($flag, ['sim', 'yes', 's'], true);
    $active = in_array($flag, ['não', 'nao', 'no', 'n'], true);

    if ($discharged) {
        $terminationDate = date_or_null($dateRaw);
        return [
            'status' => 'Desligado',
            'termination_date' => $terminationDate,
            'pending' => $terminationDate === null,
            'pending_reason' => $terminationDate === null ? 'missing_or_invalid_termination_date' : null,
        ];
    }
    if ($active) {
        $dateText = trim((string)$dateRaw);
        $parsedDate = date_or_null($dateRaw);
        $invalidDate = $parsedDate === null && date_looks_like_value($dateRaw);
        $unexpectedDate = $parsedDate !== null;
        $knownActiveMarker = $dateText === '' || in_array(normalize_contract_marker($dateText), ['ativo', 'nao', 'no', 'n'], true);
        $unrecognizedText = !$knownActiveMarker && !$unexpectedDate && !$invalidDate;
        $pending = $unexpectedDate || $invalidDate || $unrecognizedText;
        return [
            'status' => $pending ? 'Pendente' : 'Ativo',
            'termination_date' => null,
            'pending' => $pending,
            'pending_reason' => $unexpectedDate ? 'status_date_conflict' : (($invalidDate || $unrecognizedText) ? 'invalid_termination_date' : null),
        ];
    }

    return [
        'status' => 'Pendente',
        'termination_date' => null,
        'pending' => true,
        'pending_reason' => 'invalid_discharge_flag',
    ];
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
