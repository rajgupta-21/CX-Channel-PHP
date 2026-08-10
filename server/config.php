<?php

declare(strict_types=1);

function env_path(): string
{
    return __DIR__ . '/../.env';
}

function load_env(): array
{
    static $loaded = null;
    if ($loaded !== null) {
        return $loaded;
    }

    $loaded = [];
    $file = env_path();
    if (!is_file($file)) {
        return $loaded;
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || str_contains($line, '=') === false) {
            continue;
        }
        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $key = trim($key);
        $value = trim($value);
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
            $value = substr($value, 1, -1);
        }
        $loaded[$key] = $value;
    }

    return $loaded;
}

function env(string $key, $default = ''): string
{
    static $vals = null;
    if ($vals === null) {
        $vals = array_merge(load_env(), $_ENV, getenv());
    }
    $val = $vals[$key] ?? $default;
    return is_string($val) ? $val : (string) $default;
}