#!/usr/bin/env php
<?php

declare(strict_types=1);

$mode = $argv[1] ?? '';
$file = getenv('ZED_FILE') ?: '';
$worktree = getenv('ZED_WORKTREE_ROOT') ?: '';
$row = (int) (getenv('ZED_ROW') ?: 0);
$column = (int) (getenv('ZED_COLUMN') ?: 0);

if (!in_array($mode, ['import', 'expand'], true)) {
    fail('Usage: php-namespace-resolver.php import|expand');
}
if ($file === '' || !is_file($file) || !str_ends_with(strtolower($file), '.php')) {
    fail('Not a PHP file');
}

$original = (string) file_get_contents($file);
$newline = str_contains($original, "\r\n") ? "\r\n" : "\n";
$normalized = str_replace("\r\n", "\n", $original);
$hadTrailing = str_ends_with($normalized, "\n");
$lines = explode("\n", $normalized);
if ($hadTrailing) {
    array_pop($lines);
}

$word = wordAt($lines, $row - 1, $column - 1);
if ($word === null) {
    fail('No class is selected');
}
[$start, $end, $name] = $word;
$line = $row - 1;

$fileNamespace = null;
$imports = [];
$lastUse = null;
$namespaceLine = null;
$header = null;
for ($i = 0, $count = count($lines); $i < $count; $i++) {
    $text = $lines[$i];
    if (preg_match('/^\s*(?:<\?php\s+)?namespace\s+([^;{\s]+)/', $text, $m) === 1) {
        $fileNamespace = $m[1];
        $namespaceLine = $i;
        continue;
    }
    if ($namespaceLine === null && preg_match('/^\s*(?:<\?php\b|declare\s*\()/', $text) === 1) {
        $header = $i;
        continue;
    }
    if (preg_match('/^use\s+(?!function\s|const\s)([^;{]+?)(?:\s+as\s+(\w+))?\s*;/i', $text, $m) === 1) {
        $fqcn = ltrim($m[1], '\\');
        $imports[$m[2] ?? shortName($fqcn)] = $fqcn;
    }
    if (preg_match('/^use\s/i', $text) === 1) {
        while ($i < $count - 1 && !str_contains($lines[$i], ';')) {
            $i++;
        }
        $lastUse = $i;
        continue;
    }
    if (preg_match('/^\s*(?:abstract\s+|final\s+|readonly\s+)*(?:class|interface|trait|enum|function)\s/', $text) === 1) {
        break;
    }
}

if ($mode === 'expand') {
    if (str_contains($name, '\\')) {
        fail($name . ' is already qualified');
    }
    $fqcn = $imports[$name] ?? pick(resolve($name, $file, $worktree), $name);
    $lines[$line] = substr_replace($lines[$line], '\\' . $fqcn, $start, $end - $start);
    write($file, $lines, $original, $newline, $hadTrailing);
}

$qualified = str_contains($name, '\\');
$fqcn = $qualified ? ltrim($name, '\\') : null;
$short = $qualified ? shortName($fqcn) : $name;

if (!$qualified && isset($imports[$name])) {
    exit(0);
}
$fqcn ??= pick(resolve($name, $file, $worktree), $name);

if (isset($imports[$short]) && strcasecmp($imports[$short], $fqcn) !== 0) {
    fail($short . ' is already imported as ' . $imports[$short]);
}

if ($qualified) {
    $lines[$line] = substr_replace($lines[$line], $short, $start, $end - $start);
}

$sameNamespace = strcasecmp(namespaceOf($fqcn), $fileNamespace ?? '') === 0;
if (!$sameNamespace && !isset($imports[$short])) {
    $statement = 'use ' . $fqcn . ';';
    if ($lastUse !== null) {
        array_splice($lines, $lastUse + 1, 0, [$statement]);
    } else {
        $after = $namespaceLine ?? $header;
        array_splice($lines, $after === null ? 0 : $after + 1, 0, ['', $statement]);
    }
}

write($file, $lines, $original, $newline, $hadTrailing);

/**
 * @param list<string> $lines
 * @return array{int, int, string}|null
 */
function wordAt(array $lines, int $line, int $offset): ?array
{
    $text = $lines[$line] ?? '';
    $isWordChar = static fn (int $i): bool => $i >= 0 && $i < strlen($text)
        && preg_match('/[\w\\\\]/', $text[$i]) === 1;

    if (!$isWordChar($offset)) {
        $offset--;
    }
    if (!$isWordChar($offset)) {
        return null;
    }

    $start = $offset;
    while ($isWordChar($start - 1)) {
        $start--;
    }
    $end = $offset + 1;
    while ($isWordChar($end)) {
        $end++;
    }

    $name = rtrim(substr($text, $start, $end - $start), '\\');
    if ($name === '' || ($text[$start - 1] ?? '') === '$' || preg_match('/^\\\\?[A-Za-z_]/', $name) !== 1) {
        return null;
    }

    return [$start, $start + strlen($name), $name];
}

/**
 * @return list<string>
 */
function resolve(string $class, string $file, string $worktree): array
{
    $builtin = [];
    foreach (['class_exists', 'interface_exists', 'trait_exists', 'enum_exists'] as $exists) {
        if ($exists($class, false)) {
            $builtin[] = $class;
            break;
        }
    }

    $root = projectRoot($file, $worktree);
    $command = sprintf(
        'find %s \( -name .git -o -name node_modules -o -path %s \) -prune -o -type f -name %s -print 2>/dev/null',
        escapeshellarg($root),
        escapeshellarg($root . '/var'),
        escapeshellarg($class . '.php'),
    );
    exec($command, $paths);

    $project = [];
    $vendor = [];
    foreach ($paths as $path) {
        $head = (string) file_get_contents($path, false, null, 0, 8192);
        if (preg_match('/^\s*(?:<\?php\s+)?namespace\s+([^;{\s]+)/m', $head, $m) !== 1) {
            continue;
        }
        $fqcn = $m[1] . '\\' . $class;
        if (str_contains($path, '/vendor/')) {
            $vendor[] = $fqcn;
        } else {
            $project[] = $fqcn;
        }
    }

    return array_values(array_unique([...$builtin, ...$project, ...$vendor]));
}

/**
 * @param list<string> $candidates
 */
function pick(array $candidates, string $class): string
{
    if ($candidates === []) {
        fail('Class ' . $class . ' not found');
    }
    if (count($candidates) === 1) {
        return $candidates[0];
    }

    $items = implode(', ', array_map(static fn (string $c): string => appleString($c), $candidates));
    $script = 'choose from list {' . $items . '} with title "PHP namespace resolver"'
        . ' with prompt "Select the namespace for ' . appleString($class, false) . '"'
        . ' default items {' . appleString($candidates[0]) . '}';
    $choice = trim((string) shell_exec('osascript -e ' . escapeshellarg($script) . ' 2>/dev/null'));

    if ($choice === '' || $choice === 'false') {
        exit(0);
    }

    return $choice;
}

function projectRoot(string $file, string $worktree): string
{
    $dir = dirname($file);
    $stop = rtrim($worktree, '/');
    while (true) {
        if (is_file($dir . '/composer.json')) {
            return $dir;
        }
        $parent = dirname($dir);
        if ($dir === $stop || $parent === $dir) {
            return $stop !== '' ? $stop : dirname($file);
        }
        $dir = $parent;
    }
}

function shortName(string $fqcn): string
{
    $pos = strrpos($fqcn, '\\');

    return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
}

function namespaceOf(string $fqcn): string
{
    $pos = strrpos($fqcn, '\\');

    return $pos === false ? '' : substr($fqcn, 0, $pos);
}

/**
 * @param list<string> $lines
 */
function write(string $file, array $lines, string $original, string $newline, bool $hadTrailing): never
{
    $updated = implode("\n", $lines) . ($hadTrailing ? "\n" : '');
    $updated = $newline === "\r\n" ? str_replace("\n", "\r\n", $updated) : $updated;

    if ($updated !== $original && file_put_contents($file, $updated) === false) {
        fail('Could not write ' . $file);
    }

    exit(0);
}

function appleString(string $value, bool $quoted = true): string
{
    $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);

    return $quoted ? '"' . $escaped . '"' : $escaped;
}

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    $script = 'display notification "' . appleString($message, false) . '" with title "PHP namespace resolver"';
    exec('osascript -e ' . escapeshellarg($script));
    exit(1);
}
