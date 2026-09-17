<?php
declare(strict_types=1);

// Flat-JSON todo storage, one file per category, per the yuki-closing-calendar skill.
// A todo line here is never auto-removed by a check re-run — only a manual toggle
// changes it (checked -> strikethrough). Re-runs only ever append new, not-yet-seen
// failures (matched by po_number so the same discrepancy isn't listed twice).

function todo_slug(string $category): string
{
    return preg_replace('/[^A-Za-z0-9]+/', '_', trim($category));
}

function todo_path(string $category): string
{
    return __DIR__ . '/todo/' . todo_slug($category) . '.json';
}

/** @return array<int, array> */
function todo_load(string $category): array
{
    $path = todo_path($category);
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function todo_save(string $category, array $entries): void
{
    file_put_contents(todo_path($category), json_encode(array_values($entries), JSON_PRETTY_PRINT));
}

/** Append new entries, assigning each a stable id. Does not deduplicate — callers
 *  (like a check function) are responsible for deciding what counts as "already there"
 *  before calling this. */
function todo_append(string $category, array $newEntries): void
{
    $existing = todo_load($category);
    foreach ($newEntries as $entry) {
        $entry['id'] = bin2hex(random_bytes(6));
        $existing[] = $entry;
    }
    todo_save($category, $existing);
}

/** Flip the checked state of one entry by id. Returns true if found. */
function todo_toggle(string $category, string $id): bool
{
    $entries = todo_load($category);
    foreach ($entries as &$entry) {
        if ($entry['id'] === $id) {
            $entry['checked'] = !($entry['checked'] ?? false);
            todo_save($category, $entries);
            return true;
        }
    }
    return false;
}

/** Set the free-text comment on one entry by id. Returns true if found. */
function todo_set_comment(string $category, string $id, string $comment): bool
{
    $entries = todo_load($category);
    foreach ($entries as &$entry) {
        if ($entry['id'] === $id) {
            $entry['comment'] = $comment;
            todo_save($category, $entries);
            return true;
        }
    }
    return false;
}

/** All entries in a category that apply to a given period: entries tagged with a
 *  different period are excluded, but legacy entries with no period field always
 *  count — same rule as the main display filter in index.php. */
function todo_entries_for_period(string $category, string $period): array
{
    $all = todo_load($category);
    return array_values(array_filter(
        $all,
        fn($e) => !isset($e['period']) || $e['period'] === $period
    ));
}

/** Whether every entry in a category (for a period) is checked — and there's at least
 *  one, since an empty list isn't "all done," it's "nothing to show yet." */
function todo_category_fully_checked(string $category, string $period): bool
{
    $entries = todo_entries_for_period($category, $period);
    if (!$entries) {
        return false;
    }
    foreach ($entries as $entry) {
        if (empty($entry['checked'])) {
            return false;
        }
    }
    return true;
}


/** All category names currently on disk (for rendering the todo section). */
function todo_categories(): array
{
    $files = glob(__DIR__ . '/todo/*.json') ?: [];
    $categories = [];
    foreach ($files as $file) {
        $data = json_decode((string) file_get_contents($file), true);
        if (is_array($data) && $data) {
            // Recover a human label from the first entry if we stored one, else the slug.
            $categories[] = basename($file, '.json');
        }
    }
    return $categories;
}
