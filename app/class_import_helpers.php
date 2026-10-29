<?php
declare(strict_types=1);

/**
 * Funções puras/isoladas usadas pelo importador de turmas.
 * Sem sessão, banco de dados ou dependências da interface.
 */
function class_csv_read(string $path): array {
    $fh = fopen($path, 'rb');
    if (!$fh) throw new RuntimeException('Não foi possível ler o CSV enviado.');
    $first = fgets($fh);
    if ($first === false) { fclose($fh); throw new RuntimeException('O arquivo está vazio.'); }

    // Escolhe o delimitador pela consistência do número de colunas nas primeiras linhas,
    // e não pela contagem bruta de separadores (que falha com separadores dentro de aspas).
    $candidates = [',' , ';', "\t"];
    $scores = [];
    foreach ($candidates as $candidate) {
        $probe = fopen($path, 'rb');
        if (!$probe) continue;
        $counts = [];
        $sampled = 0;
        while (($probeRow = fgetcsv($probe, 0, $candidate, '"', '\\')) !== false && $sampled < 12) {
            if (count($probeRow) === 1 && trim((string)$probeRow[0]) === '') continue;
            $counts[] = count($probeRow);
            $sampled++;
        }
        fclose($probe);
        if (!$counts) { $scores[$candidate] = -1; continue; }
        $freq = array_count_values($counts);
        arsort($freq);
        $dominant = (int)array_key_first($freq);
        $consistent = (int)($freq[$dominant] ?? 0);
        $scores[$candidate] = ($dominant > 1 ? 1000 : 0) + ($consistent * 10) + $dominant;
    }
    arsort($scores);
    $delimiter = (string)array_key_first($scores);
    if (($scores[$delimiter] ?? 0) < 0) { fclose($fh); throw new RuntimeException('Não foi possível identificar o separador do CSV.'); }
    rewind($fh);
    $header = fgetcsv($fh, 0, $delimiter, '"', '\\');
    if (!$header) { fclose($fh); throw new RuntimeException('Cabeçalho não encontrado.'); }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    $header = array_map(static fn($v) => trim((string)$v), $header);
    // O formulário envia o texto do cabeçalho como valor do campo; cabeçalhos
    // duplicados tornariam impossível distinguir com segurança as colunas.
    $normalizedHeaders = [];
    foreach ($header as $index => $headerValue) {
        $normalized = class_norm_header((string)$headerValue);
        if ($normalized === '') {
            fclose($fh);
            throw new RuntimeException('O cabeçalho contém uma coluna sem nome na posição ' . ($index + 1) . '. Corrija o CSV e envie novamente.');
        }
        if (isset($normalizedHeaders[$normalized])) {
            fclose($fh);
            throw new RuntimeException('O cabeçalho contém colunas duplicadas ou equivalentes: "' . $headerValue . '" (colunas ' . ($normalizedHeaders[$normalized] + 1) . ' e ' . ($index + 1) . '). Renomeie-as para que o mapeamento seja inequívoco.');
        }
        $normalizedHeaders[$normalized] = $index;
    }
    $rows = [];
    $line = 1;
    while (($row = fgetcsv($fh, 0, $delimiter, '"', '\\')) !== false) {
        $line++;
        if (count($row) === 1 && trim((string)$row[0]) === '') continue;
        $rows[] = ['line'=>$line, 'values'=>$row];
    }
    fclose($fh);
    return ['header'=>$header, 'rows'=>$rows, 'delimiter'=>$delimiter];
}
function class_norm_header(string $v): string {
    $v = mb_strtolower(trim($v), 'UTF-8');
    $v = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $v) ?: $v;
    return preg_replace('/[^a-z0-9]+/', '', $v);
}
function class_header_guess(array $header, array $aliases): string {
    foreach ($header as $h) {
        if (in_array(class_norm_header($h), $aliases, true)) return $h;
    }
    foreach ($header as $h) {
        $n = class_norm_header($h);
        foreach ($aliases as $alias) if ($alias !== '' && str_contains($n, $alias)) return $h;
    }
    return '';
}
function class_int_value(mixed $v): ?int {
    $s = trim((string)$v);
    if ($s === '') return null;
    // Aceita inteiro simples ou separadores de milhar consistentes; não remove
    // letras/símbolos arbitrariamente, pois isso poderia transformar "12abc" em 12.
    if (preg_match('/^-?\d+$/', $s)) {
        $normalized = $s;
    } elseif (preg_match('/^-?\d{1,3}(?:[., ]\d{3})+$/', $s)) {
        $normalized = str_replace([',', '.', ' '], '', $s);
    } else {
        return null;
    }
    return max(0, min(1000000, (int)$normalized));
}
function class_date_value(mixed $v): ?string {
    $s = trim((string)$v);
    if ($s === '') return null;
    foreach (['Y-m-d','d/m/Y','d-m-Y','m/Y'] as $format) {
        $d = DateTimeImmutable::createFromFormat('!' . $format, $s);
        if ($d && $d->format($format) === $s) return $format === 'm/Y' ? $d->format('Y-m-01') : $d->format('Y-m-d');
    }
    return null;
}
function class_value(array $row, array $map, string $field): string {
    $idx = $map[$field] ?? -1;
    return $idx >= 0 ? trim((string)($row[$idx] ?? '')) : '';
}
