#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * zerodrop — the zero PHP treats as empty.
 *
 * array_filter() without a callback drops 0 and "0".
 * The ?: operator replaces them. empty("0") is true.
 * No dependencies.
 */

const ZERODROP_LEVELS = [
    'low' => 1,
    'medium' => 2,
    'high' => 3,
];

const ZERODROP_SKIP_DIRS = [
    'vendor',
    'node_modules',
    '.git',
];

const ZERODROP_EXTS = [
    'php' => true,
    'phtml' => true,
    'inc' => true,
];

function zerodrop_main(array $argv): int
{
    $opts = zerodrop_options(array_slice($argv, 1));
    if (isset($opts['error'])) {
        fwrite(STDERR, $opts['error'] . PHP_EOL);
        return 2;
    }

    $lang = $opts['lang'];
    $messages = zerodrop_messages($lang);

    if ($opts['help']) {
        fwrite(STDOUT, zerodrop_help($lang));
        return 0;
    }

    if ($opts['self_test']) {
        return zerodrop_self_test($lang);
    }

    if ($opts['prove']) {
        fwrite(STDOUT, zerodrop_prove_text($lang));
        return 0;
    }

    if ($opts['paths'] === []) {
        fwrite(STDERR, $messages['usage'] . PHP_EOL);
        return 2;
    }

    $findings = [];
    $seen = [];
    foreach ($opts['paths'] as $path) {
        if (!file_exists($path)) {
            fwrite(STDERR, sprintf($messages['missing'], $path) . PHP_EOL);
            return 2;
        }
        foreach (zerodrop_scan_path($path) as $finding) {
            $key = $finding['file'] . ':' . $finding['line'] . ':' . $finding['rule'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $findings[] = $finding;
        }
    }

    $findings = array_values(array_filter(
        $findings,
        static function (array $finding) use ($opts): bool {
            if (in_array($finding['rule'], $opts['ignore'], true)) {
                return false;
            }
            return ZERODROP_LEVELS[$finding['severity']] >= ZERODROP_LEVELS[$opts['min']];
        }
    ));

    if ($opts['json']) {
        $encoded = array_map(static function (array $finding) use ($messages): array {
            $finding['message'] = $messages[$finding['message']];
            $finding['fix'] = $messages[$finding['fix']];
            return $finding;
        }, $findings);
        fwrite(STDOUT, json_encode([
            'tool' => 'zerodrop',
            'min' => $opts['min'],
            'count' => count($encoded),
            'findings' => $encoded,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    } else {
        fwrite(STDOUT, zerodrop_render($findings, $messages, $opts['color']));
    }

    return $findings === [] ? 0 : 1;
}

/**
 * @param list<string> $args
 * @return array{
 *   help: bool,
 *   prove: bool,
 *   self_test: bool,
 *   json: bool,
 *   color: bool,
 *   lang: string,
 *   min: string,
 *   ignore: list<string>,
 *   paths: list<string>,
 *   error?: string
 * }
 */
function zerodrop_options(array $args): array
{
    $opts = [
        'help' => false,
        'prove' => false,
        'self_test' => false,
        'json' => false,
        'color' => getenv('NO_COLOR') === false && stream_isatty(STDOUT),
        'lang' => zerodrop_default_lang(),
        'min' => 'high',
        'ignore' => [],
        'paths' => [],
    ];

    foreach ($args as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $opts['help'] = true;
            continue;
        }
        if ($arg === '--prove') {
            $opts['prove'] = true;
            continue;
        }
        if ($arg === '--self-test') {
            $opts['self_test'] = true;
            continue;
        }
        if ($arg === '--json') {
            $opts['json'] = true;
            continue;
        }
        if ($arg === '--no-color') {
            $opts['color'] = false;
            continue;
        }
        if (str_starts_with($arg, '--lang=')) {
            $lang = substr($arg, 7);
            if ($lang !== 'fr' && $lang !== 'en') {
                $opts['error'] = 'zerodrop: --lang must be fr or en';
                return $opts;
            }
            $opts['lang'] = $lang;
            continue;
        }
        if (str_starts_with($arg, '--min=')) {
            $min = substr($arg, 6);
            if (!isset(ZERODROP_LEVELS[$min])) {
                $opts['error'] = 'zerodrop: --min must be low, medium or high';
                return $opts;
            }
            $opts['min'] = $min;
            continue;
        }
        if (str_starts_with($arg, '--ignore=')) {
            $parts = array_values(array_filter(array_map('trim', explode(',', substr($arg, 9))), static fn (string $part): bool => $part !== ''));
            $opts['ignore'] = array_values(array_unique([...$opts['ignore'], ...$parts]));
            continue;
        }
        if (str_starts_with($arg, '-')) {
            $opts['error'] = 'zerodrop: unknown option ' . $arg;
            return $opts;
        }
        $opts['paths'][] = $arg;
    }

    return $opts;
}

function zerodrop_default_lang(): string
{
    $lang = getenv('LANG');
    if ($lang === false || $lang === '') {
        $lang = getenv('LC_ALL');
    }
    if (!is_string($lang) || $lang === '') {
        return 'en';
    }
    return str_starts_with(strtolower($lang), 'fr') ? 'fr' : 'en';
}

/**
 * @return list<array{file: string, line: int, rule: string, severity: string, snippet: string, message: string, fix: string}>
 */
function zerodrop_scan_path(string $path): array
{
    $findings = [];
    foreach (zerodrop_files($path) as $file) {
        $source = file_get_contents($file);
        if ($source === false || str_contains($source, "\0")) {
            continue;
        }
        foreach (zerodrop_scan_source($source) as $finding) {
            $finding['file'] = $file;
            $findings[] = $finding;
        }
    }
    return $findings;
}

/**
 * @return list<string>
 */
function zerodrop_files(string $path): array
{
    if (is_file($path)) {
        return zerodrop_wanted_file($path) ? [$path] : [];
    }
    if (!is_dir($path)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $item) {
        if (!$item instanceof SplFileInfo || !$item->isFile()) {
            continue;
        }
        $full = $item->getPathname();
        if (zerodrop_skipped($full) || !zerodrop_wanted_file($full)) {
            continue;
        }
        $files[] = $full;
    }
    sort($files);
    return $files;
}

function zerodrop_skipped(string $path): bool
{
    $parts = preg_split('#[\\\\/]#', $path);
    if ($parts === false) {
        $parts = [];
    }
    foreach ($parts as $part) {
        if (in_array($part, ZERODROP_SKIP_DIRS, true)) {
            return true;
        }
    }
    return false;
}

function zerodrop_wanted_file(string $path): bool
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return isset(ZERODROP_EXTS[$ext]);
}

/**
 * @return list<array{file: string, line: int, rule: string, severity: string, snippet: string, message: string, fix: string}>
 */
function zerodrop_scan_source(string $source): array
{
    $blank = zerodrop_blank_non_code($source);
    $lines = preg_split("/\r\n|\n|\r/", $source);
    if ($lines === false) {
        $lines = [];
    }
    $findings = [];

    $patterns = [
        ['array_filter', false],
        ['filter', true],
    ];

    foreach ($patterns as [$name, $method]) {
        $regex = $method
            ? '/->\s*filter\s*\(/'
            : '/(?<![\w\\\\])\\\\?array_filter\s*\(/';
        if (!preg_match_all($regex, $blank, $hits, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        foreach ($hits[0] as $hit) {
            $open = $hit[1] + strlen($hit[0]) - 1;
            $close = zerodrop_match_paren($blank, $open);
            if ($close === null) {
                continue;
            }
            $inside = substr($blank, $open + 1, $close - $open - 1);
            $rule = zerodrop_filter_rule($inside, $method);
            if ($rule === null) {
                continue;
            }
            $line = zerodrop_line_at($blank, $hit[1]);
            zerodrop_push($findings, $lines, $line, $rule);
        }
    }

    if (preg_match_all('/(?<![\w\\\\])empty\s*\(/', $blank, $hits, PREG_OFFSET_CAPTURE)) {
        foreach ($hits[0] as $hit) {
            $line = zerodrop_line_at($blank, $hit[1]);
            zerodrop_push($findings, $lines, $line, 'empty-zero');
        }
    }

    if (preg_match_all('/\?(?!\?|->)\s*:/', $blank, $hits, PREG_OFFSET_CAPTURE)) {
        foreach ($hits[0] as $hit) {
            $line = zerodrop_line_at($blank, $hit[1]);
            zerodrop_push($findings, $lines, $line, 'elvis-zero');
        }
    }

    usort($findings, static function (array $a, array $b): int {
        return [$a['line'], $a['rule']] <=> [$b['line'], $b['rule']];
    });

    return $findings;
}

function zerodrop_filter_rule(string $inside, bool $method): ?string
{
    $args = [];
    foreach (zerodrop_split_args($inside) as $arg) {
        if (trim($arg) === '') {
            continue;
        }
        $args[] = trim(preg_replace('/\s+/', ' ', $arg) ?? $arg);
    }

    if ($method) {
        if ($args === []) {
            return 'collect-filter';
        }
        $callback = $args[0];
    } else {
        if (count($args) <= 1) {
            return 'filter-bare';
        }
        $callback = $args[1];
    }

    if ($callback === 'null') {
        return 'filter-null';
    }
    if (zerodrop_is_truthy_callback($callback)) {
        return 'filter-truthy';
    }
    return null;
}

function zerodrop_is_truthy_callback(string $callback): bool
{
    $patterns = [
        '/^(?:static )?fn\s*\(\s*(?:(?:[\w\\\\|?]+)\s+)?\$(\w+)\s*\)\s*=>\s*(?:\(bool\)\s*|!!\s*)?\$\1$/',
        '/^(?:static )?fn\s*\(\s*(?:(?:[\w\\\\|?]+)\s+)?\$(\w+)\s*\)\s*=>\s*!\s*empty\s*\(\s*\$\1\s*\)$/',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $callback) === 1) {
            return true;
        }
    }
    return false;
}

/**
 * @param list<array{file: string, line: int, rule: string, severity: string, snippet: string, message: string, fix: string}> $findings
 * @param list<string> $lines
 */
function zerodrop_push(array &$findings, array $lines, int $line, string $rule): void
{
    $snippet = trim($lines[$line - 1] ?? '');
    if (str_contains($snippet, 'zerodrop:ignore')) {
        return;
    }
    $meta = zerodrop_rule_meta()[$rule];
    $findings[] = [
        'file' => '',
        'line' => $line,
        'rule' => $rule,
        'severity' => $meta['severity'],
        'snippet' => $snippet,
        'message' => $meta['message_key'],
        'fix' => $meta['fix_key'],
    ];
}

/**
 * @return array<string, array{severity: string, message_key: string, fix_key: string}>
 */
function zerodrop_rule_meta(): array
{
    return [
        'filter-bare' => [
            'severity' => 'high',
            'message_key' => 'filter-bare',
            'fix_key' => 'filter-bare.fix',
        ],
        'collect-filter' => [
            'severity' => 'high',
            'message_key' => 'collect-filter',
            'fix_key' => 'collect-filter.fix',
        ],
        'filter-null' => [
            'severity' => 'high',
            'message_key' => 'filter-null',
            'fix_key' => 'filter-bare.fix',
        ],
        'filter-truthy' => [
            'severity' => 'high',
            'message_key' => 'filter-truthy',
            'fix_key' => 'filter-bare.fix',
        ],
        'elvis-zero' => [
            'severity' => 'high',
            'message_key' => 'elvis-zero',
            'fix_key' => 'elvis-zero.fix',
        ],
        'empty-zero' => [
            'severity' => 'medium',
            'message_key' => 'empty-zero',
            'fix_key' => 'empty-zero.fix',
        ],
    ];
}

/**
 * @return list<string>
 */
function zerodrop_split_args(string $inside): array
{
    $args = [];
    $buf = '';
    $depth = 0;
    $length = strlen($inside);
    for ($i = 0; $i < $length; $i++) {
        $char = $inside[$i];
        if ($char === '(' || $char === '[' || $char === '{') {
            $depth++;
            $buf .= $char;
            continue;
        }
        if ($char === ')' || $char === ']' || $char === '}') {
            $depth = max(0, $depth - 1);
            $buf .= $char;
            continue;
        }
        if ($char === ',' && $depth === 0) {
            $args[] = $buf;
            $buf = '';
            continue;
        }
        $buf .= $char;
    }
    $args[] = $buf;
    return $args;
}

function zerodrop_match_paren(string $code, int $open): ?int
{
    $depth = 0;
    $length = strlen($code);
    for ($i = $open; $i < $length; $i++) {
        $char = $code[$i];
        if ($char === '(') {
            $depth++;
            continue;
        }
        if ($char === ')') {
            $depth--;
            if ($depth === 0) {
                return $i;
            }
        }
    }
    return null;
}

function zerodrop_line_at(string $code, int $offset): int
{
    return substr_count(substr($code, 0, $offset), "\n") + 1;
}

/**
 * Replace comments and string literals with placeholders, keeping length and newlines.
 * String bytes become S so a callback passed as a string still counts as an argument.
 */
function zerodrop_blank_non_code(string $source): string
{
    $length = strlen($source);
    $out = '';
    $i = 0;
    while ($i < $length) {
        $char = $source[$i];
        $next = $i + 1 < $length ? $source[$i + 1] : '';

        if ($char === '/' && $next === '/') {
            while ($i < $length && $source[$i] !== "\n") {
                $out .= ' ';
                $i++;
            }
            continue;
        }
        if ($char === '#' && $next !== '[') {
            while ($i < $length && $source[$i] !== "\n") {
                $out .= ' ';
                $i++;
            }
            continue;
        }
        if ($char === '/' && $next === '*') {
            $out .= '  ';
            $i += 2;
            while ($i < $length - 1 && !($source[$i] === '*' && $source[$i + 1] === '/')) {
                $out .= $source[$i] === "\n" ? "\n" : ' ';
                $i++;
            }
            if ($i < $length - 1) {
                $out .= '  ';
                $i += 2;
            }
            continue;
        }
        if ($char === "'" || $char === '"') {
            $quote = $char;
            $out .= 'S';
            $i++;
            while ($i < $length) {
                if ($source[$i] === '\\') {
                    $out .= 'S';
                    $i++;
                    if ($i < $length) {
                        $out .= $source[$i] === "\n" ? "\n" : 'S';
                        $i++;
                    }
                    continue;
                }
                if ($source[$i] === $quote) {
                    $out .= 'S';
                    $i++;
                    break;
                }
                $out .= $source[$i] === "\n" ? "\n" : 'S';
                $i++;
            }
            continue;
        }
        if ($char === '<') {
            $heredoc = zerodrop_blank_heredoc($source, $i);
            if ($heredoc !== null) {
                $out .= $heredoc['text'];
                $i = $heredoc['next'];
                continue;
            }
        }
        if ($char === '`') {
            $out .= 'S';
            $i++;
            while ($i < $length) {
                if ($source[$i] === '\\') {
                    $out .= 'S';
                    $i++;
                    if ($i < $length) {
                        $out .= $source[$i] === "\n" ? "\n" : 'S';
                        $i++;
                    }
                    continue;
                }
                if ($source[$i] === '`') {
                    $out .= 'S';
                    $i++;
                    break;
                }
                $out .= $source[$i] === "\n" ? "\n" : 'S';
                $i++;
            }
            continue;
        }
        $out .= $char;
        $i++;
    }
    return $out;
}

/**
 * @return array{text: string, next: int}|null
 */
function zerodrop_blank_heredoc(string $source, int $start): ?array
{
    if (substr($source, $start, 3) !== '<<<') {
        return null;
    }
    $length = strlen($source);
    $j = $start + 3;
    if ($j < $length && ($source[$j] === "'" || $source[$j] === '"')) {
        $j++;
    }
    $idStart = $j;
    while ($j < $length && ctype_alnum($source[$j]) || ($j < $length && $source[$j] === '_')) {
        if ($j === $idStart && ctype_digit($source[$j])) {
            return null;
        }
        $j++;
    }
    if ($j === $idStart) {
        return null;
    }
    $id = substr($source, $idStart, $j - $idStart);
    if ($start + 3 < $length && ($source[$start + 3] === "'" || $source[$start + 3] === '"')) {
        if ($j >= $length || $source[$j] !== $source[$start + 3]) {
            return null;
        }
        $j++;
    }
    while ($j < $length && $source[$j] !== "\n") {
        if ($source[$j] !== ' ' && $source[$j] !== "\t" && $source[$j] !== "\r") {
            return null;
        }
        $j++;
    }
    if ($j >= $length) {
        return null;
    }

    $text = '';
    $i = $start;
    while ($i <= $j) {
        $text .= $source[$i] === "\n" ? "\n" : 'S';
        $i++;
    }
    $close = '/^[ \t]*' . preg_quote($id, '/') . ';?[ \t]*\r?$/';
    while ($i < $length) {
        $end = strpos($source, "\n", $i);
        $lineEnd = $end === false ? $length : $end;
        $line = substr($source, $i, $lineEnd - $i);
        $isClose = preg_match($close, $line) === 1;
        while ($i < $lineEnd) {
            $text .= $isClose || $source[$i] === "\r" ? ($source[$i] === "\n" ? "\n" : 'S') : 'S';
            $i++;
        }
        if ($end !== false) {
            $text .= "\n";
            $i++;
        }
        if ($isClose) {
            break;
        }
        if ($end === false) {
            break;
        }
    }
    return ['text' => $text, 'next' => $i];
}

/**
 * @return array<string, string>
 */
function zerodrop_messages(string $lang): array
{
    if ($lang === 'fr') {
        return [
            'title' => 'zerodrop — le zéro que PHP traite comme vide',
            'clean' => 'zerodrop — rien à signaler',
            'usage' => 'Usage : php zerodrop.php [options] <fichier-ou-dossier>...',
            'missing' => 'zerodrop: chemin introuvable : %s',
            'filter-bare' => 'array_filter() sans callback jette 0, "0", false, "" et null. Une quantité à zéro disparaît.',
            'filter-bare.fix' => 'Garde le zéro : array_filter($rows, static fn ($v) => $v !== null && $v !== \'\'). array_values() si tu encodes en JSON.',
            'collect-filter' => '->filter() sans callback fait le même tri que array_filter(). 0 et "0" disparaissent (Collection Laravel comprise).',
            'collect-filter.fix' => '->filter(static fn ($v) => $v !== null && $v !== \'\')',
            'filter-null' => 'Le callback null est le filtre par défaut : 0 et "0" sont jetés.',
            'filter-truthy' => 'Ce callback garde seulement ce qui est truthy. 0 et "0" sont falsy, donc ils sautent encore.',
            'elvis-zero' => '?: remplace 0, "0", false et "". "0" ?: 1 vaut 1. La quantité saisie devient le défaut.',
            'elvis-zero.fix' => 'Pour seulement null : $qty ?? 1. Pour null ou "" mais pas "0" : ($qty === null || $qty === \'\') ? 1 : $qty',
            'empty-zero' => 'empty("0"), empty(0) et empty(0.0) valent true. empty("0.0") vaut false. Un code ou un stock à zéro passe pour absent.',
            'empty-zero.fix' => 'Écris $v === null || $v === \'\'. Ne passe pas par empty() si 0 est une vraie valeur.',
            'ok' => 'ok',
            'fail' => 'échec',
        ];
    }

    return [
        'title' => 'zerodrop — the zero PHP treats as empty',
        'clean' => 'zerodrop — nothing to report',
        'usage' => 'Usage: php zerodrop.php [options] <file-or-directory>...',
        'missing' => 'zerodrop: path not found: %s',
        'filter-bare' => 'array_filter() with no callback drops 0, "0", false, "" and null. A quantity of zero disappears.',
        'filter-bare.fix' => 'Keep the zero: array_filter($rows, static fn ($v) => $v !== null && $v !== \'\'). array_values() before json_encode().',
        'collect-filter' => '->filter() with no callback is the same test as array_filter(). 0 and "0" vanish, including on a Laravel collection.',
        'collect-filter.fix' => '->filter(static fn ($v) => $v !== null && $v !== \'\')',
        'filter-null' => 'A null callback is the default filter: 0 and "0" are dropped.',
        'filter-truthy' => 'This callback keeps only truthy values. 0 and "0" are falsy, so they still disappear.',
        'elvis-zero' => '?: replaces 0, "0", false and "". "0" ?: 1 is 1. The submitted quantity becomes the default.',
        'elvis-zero.fix' => 'Only null: $qty ?? 1. Null or "" but not "0": ($qty === null || $qty === \'\') ? 1 : $qty',
        'empty-zero' => 'empty("0"), empty(0) and empty(0.0) are true. empty("0.0") is false. A code or a stock of zero looks missing.',
        'empty-zero.fix' => 'Write $v === null || $v === \'\'. Do not use empty() when 0 is a real value.',
        'ok' => 'ok',
        'fail' => 'failed',
    ];
}

function zerodrop_help(string $lang): string
{
    if ($lang === 'fr') {
        return <<<TXT
zerodrop — le zéro que PHP traite comme vide

Usage :
  php zerodrop.php [options] <fichier-ou-dossier>...

Options :
  --lang=fr|en     Langue des messages (défaut : en, ou fr si LANG commence par fr)
  --json           Sortie JSON
  --min=high       Seuil : high (défaut), medium, low
  --ignore=a,b     Tait des règles
  --no-color       Pas d'ANSI
  --prove          Montre le zéro jeté, sur ce PHP
  --self-test      Joue les fixtures du dépôt
  -h, --help       Cette aide

Règles high : filter-bare, collect-filter, filter-null, filter-truthy, elvis-zero
Règle medium : empty-zero (vide aussi les tableaux ; passe --min=medium)

Une ligne qui contient zerodrop:ignore est sautée.
Dossiers ignorés : vendor/, node_modules/, .git/

TXT;
    }

    return <<<TXT
zerodrop — the zero PHP treats as empty

Usage:
  php zerodrop.php [options] <file-or-directory>...

Options:
  --lang=fr|en     Message language (default: en, or fr when LANG starts with fr)
  --json           JSON output
  --min=high       Threshold: high (default), medium, low
  --ignore=a,b     Silence rules
  --no-color       No ANSI
  --prove          Show the dropped zero on this PHP
  --self-test      Run the repository fixtures
  -h, --help       This help

High rules: filter-bare, collect-filter, filter-null, filter-truthy, elvis-zero
Medium rule: empty-zero (also flags arrays; pass --min=medium)

A line containing zerodrop:ignore is skipped.
Skipped directories: vendor/, node_modules/, .git/

TXT;
}

/**
 * @return array{
 *   filtered: list<mixed>,
 *   kept: list<mixed>,
 *   elvis: mixed,
 *   empty: bool,
 *   coalesce: mixed,
 *   json: string,
 *   zeroFloat: mixed,
 *   zeroDot: mixed
 * }
 */
function zerodrop_proof(): array
{
    $input = ['ok', '0', 0, '', null, false];
    $filter = 'array_filter';
    $filtered = array_values($filter($input));
    $kept = array_values($filter(
        $input,
        static fn ($value) => $value !== null && $value !== ''
    ));
    $qty = '0';
    return [
        'filtered' => $filtered,
        'kept' => $kept,
        'elvis' => ($qty ?: 'absent'), // zerodrop:ignore
        'empty' => empty($qty), // zerodrop:ignore
        'coalesce' => ($qty ?? 'absent'),
        'json' => json_encode($filter(['', 'ok'])),
        'zeroFloat' => (0.0 ?: 'absent'), // zerodrop:ignore
        'zeroDot' => ('0.0' ?: 'absent'), // zerodrop:ignore
    ];
}

function zerodrop_prove_text(string $lang): string
{
    $proof = zerodrop_proof();
    $filtered = json_encode($proof['filtered'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $kept = json_encode($proof['kept'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $elvis = json_encode($proof['elvis'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $coalesce = json_encode($proof['coalesce'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $float = json_encode($proof['zeroFloat'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $dotted = json_encode($proof['zeroDot'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $empty = $proof['empty'] ? 'true' : 'false';

    if ($lang === 'fr') {
        return <<<TXT
Preuve, sur cet interpréteur PHP :

  array_filter(['ok', '0', 0, '', null, false])
  → {$filtered}
    0, "0", false, "" et null sont jetés. Il ne reste que "ok".

  json_encode(array_filter(['', 'ok']))
  → {$proof['json']}
    La clé 0 a sauté, donc ce n'est plus un tableau JSON.

  '0' ?: 'absent'   → {$elvis}
  empty('0')        → {$empty}
  '0' ?? 'absent'   → {$coalesce}

  0.0 ?: 'absent'   → {$float}     (0.0 est falsy)
  '0.0' ?: 'absent' → {$dotted}    ("0.0" ne l'est pas)

  Le même tableau, avec un callback qui garde le zéro :
  → {$kept}

TXT;
    }

    return <<<TXT
Proof, on this PHP interpreter:

  array_filter(['ok', '0', 0, '', null, false])
  → {$filtered}
    0, "0", false, "" and null are dropped. Only "ok" remains.

  json_encode(array_filter(['', 'ok']))
  → {$proof['json']}
    Key 0 is gone, so this is no longer a JSON array.

  '0' ?: 'absent'   → {$elvis}
  empty('0')        → {$empty}
  '0' ?? 'absent'   → {$coalesce}

  0.0 ?: 'absent'   → {$float}     (0.0 is falsy)
  '0.0' ?: 'absent' → {$dotted}    ("0.0" is not)

  The same list, with a callback that keeps zero:
  → {$kept}

TXT;
}

/**
 * @param list<array{file: string, line: int, rule: string, severity: string, snippet: string, message: string, fix: string}> $findings
 * @param array<string, string> $messages
 */
function zerodrop_render(array $findings, array $messages, bool $color): string
{
    if ($findings === []) {
        return $messages['clean'] . PHP_EOL;
    }

    $byFile = [];
    foreach ($findings as $finding) {
        $byFile[$finding['file']][] = $finding;
    }

    $out = $messages['title'] . PHP_EOL . PHP_EOL;
    foreach ($byFile as $file => $group) {
        $out .= $file . PHP_EOL;
        foreach ($group as $finding) {
            $label = strtoupper($finding['severity']);
            $pad = str_pad('', max(0, 6 - strlen($label)));
            if ($color) {
                $label = $finding['severity'] === 'high'
                    ? "\033[31m{$label}\033[0m"
                    : "\033[33m{$label}\033[0m";
            }
            $label .= $pad;
            $rule = str_pad($finding['rule'], 16);
            $line = str_pad((string) $finding['line'], 4, ' ', STR_PAD_LEFT);
            $out .= sprintf("  %s  %s %s  %s\n", $label, $rule, $line, $finding['snippet']);
            $out .= '        ' . $messages[$finding['message']] . PHP_EOL;
            $out .= '        → ' . $messages[$finding['fix']] . PHP_EOL;
        }
        $out .= PHP_EOL;
    }
    return $out;
}

function zerodrop_self_test(string $lang): int
{
    $root = dirname(__FILE__);
    $failures = [];
    $proof = zerodrop_proof();

    $expect = [
        'filtered' => ['ok'],
        'kept' => ['ok', '0', 0, false],
        'elvis' => 'absent',
        'empty' => true,
        'coalesce' => '0',
        'json' => '{"1":"ok"}',
        'zeroFloat' => 'absent',
        'zeroDot' => '0.0',
    ];
    foreach ($expect as $key => $value) {
        if ($proof[$key] !== $value) {
            $failures[] = 'proof ' . $key . ' = ' . json_encode($proof[$key]);
        }
    }

    $prove = zerodrop_prove_text('en') . zerodrop_prove_text('fr');
    foreach (['["ok"]', '{"1":"ok"}', 'absent', '["ok","0",0,false]', 'true'] as $needle) {
        if (!str_contains($prove, $needle)) {
            $failures[] = 'prove text missing ' . $needle;
        }
    }

    $buggy = zerodrop_scan_path($root . '/examples/buggy.php');
    $rules = array_map(static fn (array $finding): string => $finding['rule'], $buggy);
    $wanted = [
        'filter-bare' => 1,
        'collect-filter' => 1,
        'filter-null' => 1,
        'filter-truthy' => 1,
        'elvis-zero' => 1,
        'empty-zero' => 1,
    ];
    foreach ($wanted as $rule => $count) {
        $got = count(array_filter($rules, static fn (string $item): bool => $item === $rule));
        if ($got !== $count) {
            $failures[] = 'buggy ' . $rule . ' count ' . $got;
        }
    }
    if (count($buggy) !== array_sum($wanted)) {
        $failures[] = 'buggy extra findings ' . json_encode($rules);
    }

    foreach (['examples/safe.php', 'examples/fixed.php', 'zerodrop.php'] as $relative) {
        $found = zerodrop_scan_path($root . '/' . $relative);
        if ($found !== []) {
            $names = array_map(static fn (array $finding): string => $finding['rule'] . ':' . $finding['line'], $found);
            $failures[] = $relative . ' not silent: ' . implode(',', $names);
        }
    }

    $examples = zerodrop_scan_path($root . '/examples');
    foreach ($examples as $finding) {
        if (str_contains(str_replace('\\', '/', $finding['file']), '/vendor/')) {
            $failures[] = 'vendor scanned: ' . $finding['file'];
        }
    }
    $exampleRules = array_map(static fn (array $finding): string => $finding['rule'], $examples);
    if ($exampleRules !== $rules) {
        $failures[] = 'examples/ scan differs from buggy.php';
    }

    $fixed = zerodrop_run_php($root . '/examples/fixed.php');
    if ($fixed['code'] !== 0) {
        $failures[] = 'fixed.php exit ' . $fixed['code'];
    }
    $expectedOut = "[\"ok\",\"0\",0,false]\n0\n";
    if ($fixed['out'] !== $expectedOut) {
        $failures[] = 'fixed.php output ' . json_encode($fixed['out']);
    }

    $high = array_values(array_filter(
        $buggy,
        static fn (array $finding): bool => $finding['severity'] === 'high'
    ));
    if (count($high) !== 5) {
        $failures[] = 'expected 5 high findings, got ' . count($high);
    }

    $messages = zerodrop_messages($lang);
    if ($failures === []) {
        fwrite(STDOUT, $messages['ok'] . PHP_EOL);
        return 0;
    }
    fwrite(STDERR, $messages['fail'] . PHP_EOL);
    foreach ($failures as $failure) {
        fwrite(STDERR, '  - ' . $failure . PHP_EOL);
    }
    return 1;
}

/**
 * @return array{code: int, out: string}
 */
function zerodrop_run_php(string $script): array
{
    $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script);
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return ['code' => $code, 'out' => implode("\n", $out) . ($out === [] ? '' : "\n")];
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    exit(zerodrop_main($argv));
}
