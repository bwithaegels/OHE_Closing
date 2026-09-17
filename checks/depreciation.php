<?php
declare(strict_types=1);

require_once __DIR__ . '/../yuki.php';
require_once __DIR__ . '/../todo.php';
require_once __DIR__ . '/../status.php';

/**
 * Whether depreciation was booked for the period: uses the same "difference of two
 * trial-balance snapshots" technique confirmed in yuki-reporting-dashboard, rather than
 * pulling every transaction on every 63xx account — two GLAccountBalance calls total,
 * regardless of how many depreciation accounts exist.
 *
 * Fiscal-year boundary (confirmed = calendar year): if the period is January, the
 * cumulative balance resets, so the "before" snapshot (31 Dec) belongs to a different
 * fiscal year and can't be subtracted — the balance on 31 Jan is taken as-is instead,
 * same rule as the reporting app's month-movement calculation.
 *
 * Result is a plain boolean, stored via status.php: true = postings exist for the
 * period, false = they don't. The badge on the calendar follows this boolean directly,
 * not a todo checkbox — on false, one "Afschrijvingen Boeken" todo entry is added so
 * there's something to act on and check off once booked.
 */
function run_check_depreciation(string $sessionId, array $config, string $period): array
{
    [$start, $end] = period_month_range($period);
    $isJanuary = substr($start, 5, 2) === '01';
    $dayBefore = date('Y-m-d', strtotime($start . ' -1 day'));

    $balanceAfter = yuki_gl_balance($sessionId, $config, $end);
    $balanceBefore = $isJanuary ? [] : yuki_gl_balance($sessionId, $config, $dayBefore);

    $accountsWithMovement = [];
    $allCodes = array_unique(array_merge(array_keys($balanceBefore), array_keys($balanceAfter)));
    $checkedCount = 0;

    foreach ($allCodes as $code) {
        $code = (string) $code; // array_keys() casts numeric-string GL codes to int
        if (strpos($code, '63') !== 0) {
            continue;
        }
        $checkedCount++;
        $after = $balanceAfter[$code]['amount'] ?? 0.0;
        $before = $isJanuary ? 0.0 : ($balanceBefore[$code]['amount'] ?? 0.0);
        $movement = $after - $before;
        if (abs($movement) > 0.01) {
            $accountsWithMovement[$code] = $movement;
        }
    }

    $depreciationBooked = (bool) $accountsWithMovement;
    status_set('depreciation', $period, $depreciationBooked);

    $newFailures = [];
    if (!$depreciationBooked) {
        $existing = todo_load('AFSCHRIJVINGEN');
        $alreadyFlagged = array_column($existing, 'period');
        if (!in_array($period, $alreadyFlagged, true)) {
            $newFailures[] = [
                'period'     => $period,
                'text'       => 'Afschrijvingen Boeken',
                'checked'    => false,
                'created_at' => date('c'),
            ];
            todo_append('AFSCHRIJVINGEN', $newFailures);
        }
    }

    return [
        'checked' => $checkedCount,
        'depreciation_booked' => $depreciationBooked,
        'accounts_with_movement' => $accountsWithMovement,
        'new_failures' => $newFailures,
    ];
}
