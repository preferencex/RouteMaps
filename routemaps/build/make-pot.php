<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$messages = [];

$add = static function (string $message, string $reference) use (&$messages): void {
    if ('' === $message) {
        return;
    }
    $messages[$message] ??= [];
    $messages[$message][$reference] = true;
};

$decode = static function (string $quote, string $body): string {
    $body = str_replace(['\\n', '\\r', '\\t'], ["\n", "\r", "\t"], $body);
    $body = str_replace('\\\\', '\\', $body);
    return "'" === $quote ? str_replace("\\'", "'", $body) : str_replace('\\"', '"', $body);
};

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile()) {
        continue;
    }
    $path = $file->getPathname();
    $relative = ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR);
    $parts = explode(DIRECTORY_SEPARATOR, $relative);
    if (array_intersect($parts, ['vendor', 'node_modules', 'dist', '.git'])) {
        continue;
    }

    $extension = strtolower($file->getExtension());
    if (!in_array($extension, ['php', 'js'], true)) {
        continue;
    }
    $source = (string) file_get_contents($path);
    $patterns = 'php' === $extension
        ? ['/(?:__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\(\s*([\'\"])((?:\\\\.|(?!\1).)*)\1\s*,\s*[\'\"]routemaps[\'\"]/s']
        : ['/\b__\(\s*([\'\"])((?:\\\\.|(?!\1).)*)\1\s*\)/s'];

    foreach ($patterns as $pattern) {
        if (!preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        foreach ($matches[2] as $index => [$body, $offset]) {
            $quote = $matches[1][$index][0];
            $line = substr_count(substr($source, 0, (int) $offset), "\n") + 1;
            $add($decode($quote, $body), str_replace(DIRECTORY_SEPARATOR, '/', $relative) . ':' . $line);
        }
    }
}

uksort($messages, static fn(string $left, string $right): int => strcasecmp($left, $right));

$escape = static fn(string $value): string => '"' . str_replace(["\\", '"', "\n"], ["\\\\", '\\"', '\\n'], $value) . '"';
$versionSource = (string) file_get_contents($root . '/routemaps.php');
preg_match('/^ \* Version:\s*([^\s]+)/m', $versionSource, $versionMatch);
$version = $versionMatch[1] ?? 'unknown';
$epoch = getenv('SOURCE_DATE_EPOCH');
$timestamp = false !== $epoch && ctype_digit((string) $epoch) ? (int) $epoch : time();
$date = gmdate('Y-m-d H:i+0000', $timestamp);

$output = [
    'msgid ""',
    'msgstr ""',
    '"Project-Id-Version: RouteMaps ' . $version . '\\n"',
    '"Report-Msgid-Bugs-To: \\n"',
    '"POT-Creation-Date: ' . $date . '\\n"',
    '"MIME-Version: 1.0\\n"',
    '"Content-Type: text/plain; charset=UTF-8\\n"',
    '"Content-Transfer-Encoding: 8bit\\n"',
    '"X-Domain: routemaps\\n"',
    '',
];

foreach ($messages as $message => $references) {
    $refs = array_keys($references);
    sort($refs, SORT_STRING);
    $output[] = '#: ' . implode(' ', $refs);
    if (str_contains($message, '%')) {
        $output[] = '#, php-format';
    }
    $output[] = 'msgid ' . $escape($message);
    $output[] = 'msgstr ""';
    $output[] = '';
}

$languages = $root . '/languages';
if (!is_dir($languages) && !mkdir($languages, 0775, true) && !is_dir($languages)) {
    fwrite(STDERR, "Unable to create languages directory.\n");
    exit(1);
}
file_put_contents($languages . '/routemaps.pot', implode("\n", $output));
printf("Generated languages/routemaps.pot (%d messages).\n", count($messages));
