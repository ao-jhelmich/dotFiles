#!/usr/bin/env php
<?php

declare(strict_types=1);

$file = getenv('ZED_FILE') ?: ($argv[1] ?? '');
$worktree = getenv('ZED_WORKTREE_ROOT') ?: ($argv[2] ?? '');
$language = getenv('ZED_LANGUAGE') ?: '';

if ($file === '' || !is_file($file)) {
    fail('No file open');
}

$isPhp = str_ends_with(strtolower($file), '.php') || strcasecmp($language, 'PHP') === 0;
if (!$isPhp) {
    fail('Not a PHP file');
}

$composer = findComposer($file, $worktree);
if ($composer === null) {
    fail('No composer.json file found, automatic namespace generation failed');
}

$composerJson = json_decode((string) file_get_contents($composer), true);
if (!is_array($composerJson)) {
    fail('Could not parse composer.json');
}

$psr4 = mappings($composerJson, 'psr-4');
$psr0 = mappings($composerJson, 'psr-0');
if ($psr4 === [] && $psr0 === []) {
    fail('No psr-4 or psr-0 key in composer.json autoload, automatic namespace generation failed');
}

$fileDir = str_replace('\\', '/', dirname($file));
$composerDir = str_replace('\\', '/', dirname($composer));
if (!str_starts_with($fileDir, $composerDir)) {
    fail('File is outside the composer.json directory');
}

$relative = substr($fileDir, strlen($composerDir));
if ($relative === '') {
    $relative = '/';
}

$namespace = resolveNamespace($relative, $psr4, true) ?? resolveNamespace($relative, $psr0, false);
if ($namespace === null || $namespace === '') {
    fail('Could not resolve namespace from composer.json autoload configuration');
}

$original = (string) file_get_contents($file);
$newline = str_contains($original, "\r\n") ? "\r\n" : "\n";
$normalized = str_replace("\r\n", "\n", $original);
$hadTrailing = str_ends_with($normalized, "\n");
$lines = explode("\n", $normalized);
if ($hadTrailing) {
    array_pop($lines);
}

$phpTag = null;
$declare = null;
$namespaceLine = null;
foreach ($lines as $i => $line) {
    if ($phpTag === null && preg_match('/^\s*<\?php\b/', $line) === 1) {
        $phpTag = $i;
        if (preg_match('/^\s*<\?php\s+namespace\s/', $line) === 1) {
            $namespaceLine = $i;
            break;
        }
        continue;
    }

    if ($namespaceLine === null && preg_match('/^\s*declare\s*\(/', $line) === 1) {
        $declare = $i;
        continue;
    }

    if ($namespaceLine === null && preg_match('/^\s*namespace\s/', $line) === 1) {
        $namespaceLine = $i;
        break;
    }

    if (preg_match('/^\s*(?:abstract\s+|final\s+|readonly\s+)*(?:class|interface|trait|enum)\s+/', $line) === 1) {
        break;
    }
}

$statement = 'namespace ' . $namespace;

if ($namespaceLine !== null) {
    $replaced = preg_replace('/namespace\s+[^;{\s]+/', $statement, $lines[$namespaceLine], 1);
    if (!is_string($replaced)) {
        fail('Could not replace the existing namespace line');
    }
    if ($replaced === $lines[$namespaceLine]) {
        exit(0);
    }
    $lines[$namespaceLine] = $replaced;
} else {
    $after = $declare ?? $phpTag;
    $insertAt = $after === null ? 0 : $after + 1;
    array_splice($lines, $insertAt, 0, ['', $statement . ';']);
}

$updated = implode("\n", $lines);
if ($hadTrailing || $updated !== '') {
    $updated .= "\n";
}
$updated = $newline === "\r\n" ? str_replace("\n", "\r\n", $updated) : $updated;

if ($updated === $original) {
    exit(0);
}

if (file_put_contents($file, $updated) === false) {
    fail('Could not write ' . $file);
}

exit(0);

/**
 * @param list<array{namespace: string, paths: list<string>}> $mappings
 */
function resolveNamespace(string $relative, array $mappings, bool $psr4): ?string
{
    $rel = str_ends_with($relative, '/') ? $relative : $relative . '/';
    $best = null;

    foreach ($mappings as $mapping) {
        foreach ($mapping['paths'] as $base) {
            $base = trim(str_replace('\\', '/', $base), '/');
            $needle = $base === '' ? '/' : '/' . $base . '/';
            $idx = strpos($rel, $needle);
            if ($idx === false) {
                continue;
            }

            $len = strlen($needle);
            if ($best !== null && ($idx > $best['idx'] || ($idx === $best['idx'] && $len <= $best['len']))) {
                continue;
            }

            $suffix = str_replace('/', '\\', trim(substr($rel, $idx + $len), '/'));
            $prefix = $mapping['namespace'];
            if ($psr4) {
                if ($prefix === '') {
                    $ns = $suffix;
                } elseif ($suffix === '') {
                    $ns = $prefix;
                } else {
                    $ns = $prefix . '\\' . $suffix;
                }
            } else {
                $ns = $suffix !== '' ? $suffix : $prefix;
            }

            if ($ns === '') {
                continue;
            }

            $best = ['idx' => $idx, 'len' => $len, 'ns' => $ns];
        }
    }

    return $best['ns'] ?? null;
}

/**
 * @return list<array{namespace: string, paths: list<string>}>
 */
function mappings(array $composerJson, string $key): array
{
    $mappings = [];
    foreach (['autoload', 'autoload-dev'] as $section) {
        $config = $composerJson[$section][$key] ?? null;
        if (!is_array($config)) {
            continue;
        }
        foreach ($config as $namespace => $paths) {
            $pathList = is_array($paths) ? $paths : [$paths];
            $mappings[] = [
                'namespace' => rtrim((string) $namespace, '\\'),
                'paths' => array_map(
                    static fn (mixed $path): string => rtrim(str_replace('\\', '/', (string) $path), '/'),
                    $pathList,
                ),
            ];
        }
    }

    return $mappings;
}

function findComposer(string $file, string $worktree): ?string
{
    $dir = str_replace('\\', '/', dirname($file));
    $stop = $worktree !== '' ? rtrim(str_replace('\\', '/', $worktree), '/') : null;

    while (true) {
        $candidate = $dir . '/composer.json';
        if (is_file($candidate)) {
            return $candidate;
        }
        if ($stop !== null && $dir === $stop) {
            return null;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            return null;
        }
        $dir = $parent;
    }
}

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $message);
    $script = 'display notification "' . $escaped . '" with title "PHP namespace"';
    exec('osascript -e ' . escapeshellarg($script));
    exit(1);
}
