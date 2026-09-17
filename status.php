<?php
declare(strict_types=1);

// Stores a plain boolean result per check per period, for checks that are genuinely
// pass/fail (like depreciation) rather than "a list of items to work through" (like the
// PO check or the asset listing). Separate from todo.php on purpose: the badge for this
// kind of check follows the check's own verdict, not whether someone has ticked boxes.

function status_path(): string
{
    return __DIR__ . '/status/results.json';
}

function status_load(): array
{
    $path = status_path();
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function status_save(array $data): void
{
    file_put_contents(status_path(), json_encode($data, JSON_PRETTY_PRINT));
}

function status_set(string $checkId, string $period, bool $result): void
{
    $data = status_load();
    $data[$checkId][$period] = $result;
    status_save($data);
}

/** Null when the check hasn't been run for that period yet. */
function status_get(string $checkId, string $period): ?bool
{
    $data = status_load();
    return $data[$checkId][$period] ?? null;
}
