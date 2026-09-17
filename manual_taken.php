<?php
declare(strict_types=1);

// Manually-added ad hoc tasks, on top of the built-in tasks.json list. Stored in their
// own file rather than mixed into tasks.json: tasks.json entries are hand-authored and
// wired to a check_id/todo_category by whoever edits the code, while these are created
// at runtime by whoever is using the app. Same separation tasks.json already keeps from
// todo/*.json -- config vs. runtime data, never in the same file.
//
// A task is either:
//  - 'once'      -> applies only to its own start_period
//  - 'recurring' -> applies to every period from start_period through end_period,
//                   inclusive. YYYY-MM strings compare correctly with plain string
//                   comparison, same trick used for closing periods elsewhere in this app.
//
// Completion is tracked per period (checked_periods, a list of YYYY-MM strings), not as
// one global boolean -- a recurring task can be done in June and still open in July.

function manual_taken_path(): string
{
    return __DIR__ . '/manual_taken.json';
}

/** @return array<int, array> */
function manual_taken_load(): array
{
    $path = manual_taken_path();
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function manual_taken_save(array $tasks): void
{
    file_put_contents(manual_taken_path(), json_encode(array_values($tasks), JSON_PRETTY_PRINT));
}

/** Add one manually-created task. Prepended (not appended) so it lands at the top of
 *  storage -- combined with rendering manual tasks before tasks.json's entries in
 *  index.php, this is what keeps newly-added tasks at the top of the visible list. */
function manual_taak_add(array $task): array
{
    $task['id'] = 'm-' . bin2hex(random_bytes(6));
    $task['checked_periods'] = [];
    $tasks = manual_taken_load();
    array_unshift($tasks, $task);
    manual_taken_save($tasks);
    return $task;
}

/** Manual tasks that apply to a given period, most-recently-added first (storage order). */
function manual_taken_for_period(string $period): array
{
    $all = manual_taken_load();
    return array_values(array_filter($all, function ($t) use ($period) {
        if (($t['kind'] ?? 'once') === 'recurring') {
            return $period >= ($t['start_period'] ?? '') && $period <= ($t['end_period'] ?? '');
        }
        return $period === ($t['start_period'] ?? '');
    }));
}

/** Flip completion for one task, scoped to the period being viewed. Returns true if the
 *  task was found. */
function manual_taak_toggle(string $id, string $period): bool
{
    $tasks = manual_taken_load();
    foreach ($tasks as &$t) {
        if ($t['id'] === $id) {
            $checkedPeriods = $t['checked_periods'] ?? [];
            if (in_array($period, $checkedPeriods, true)) {
                $checkedPeriods = array_values(array_diff($checkedPeriods, [$period]));
            } else {
                $checkedPeriods[] = $period;
            }
            $t['checked_periods'] = $checkedPeriods;
            manual_taken_save($tasks);
            return true;
        }
    }
    return false;
}
