<?php
declare(strict_types=1);

require_once __DIR__ . '/../yuki.php';
require_once __DIR__ . '/../todo.php';
require_once __DIR__ . '/../status.php';

// --- Calibration constants -------------------------------------------------
const PO_NUMBER_MIN_DIGITS = 3;
const PO_NUMBER_MAX_DIGITS = 6;

// How far back 604003 has real data. Only used the very first time the cache is built —
// after that, the cache only ever fetches forward from where it left off.
const ACCOUNT_HISTORY_START = '2015-01-01'; // TODO: confirm real start date

/**
 * Extract ALL PO numbers from a description: "PO####" occurrences, or bare standalone
 * digit runs within the configured length range — and a single description can hold
 * more than one, comma-separated ("PO1884, PO1885" or "1884, 1885"). Each PO number in
 * a line is checked independently: one missing PO in a multi-PO invoice should still be
 * flagged even if the others are fine.
 */
function extract_po_numbers(string $text): array
{
    $pattern = '/PO\s*0*(\d{' . PO_NUMBER_MIN_DIGITS . ',' . PO_NUMBER_MAX_DIGITS . '})'
        . '|(?<!\d)(\d{' . PO_NUMBER_MIN_DIGITS . ',' . PO_NUMBER_MAX_DIGITS . '})(?!\d)/i';
    preg_match_all($pattern, $text, $matches, PREG_SET_ORDER);

    $numbers = [];
    foreach ($matches as $m) {
        $num = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
        if ($num !== '') {
            $numbers[$num] = true; // dedupe via keys
        }
    }
    return array_keys($numbers);
}

/**
 * OPEN QUESTION: sign convention at the transaction level is not yet formally confirmed
 * from the raw SOAP response (only from the Yuki web UI screenshot, where Debet lines
 * showed a positive Bedrag and Credit lines a negative one) — treat this as provisional
 * until checked against a real GLAccountTransactions response for this account.
 */
function is_debit_posting(array $transaction): bool
{
    return ((float) $transaction['amount']) > 0;
}

function is_credit_posting(array $transaction): bool
{
    return ((float) $transaction['amount']) < 0;
}

/**
 * The debit (invoice) and credit (matching) postings for a PO both live on 604003
 * itself — confirmed 2026-09-15 from a real Yuki screenshot ("Awan - Inkooporder 1884/
 * 1885" credit lines sit in the same account list as the "PO1884"/"PO1885" debit
 * lines). There is no separate account to check. But the credit line can post in a
 * different period than the debit line, so this account's full history still needs to
 * be searched for a match — not just the current book year — the same caching approach
 * as before, just pointed at 604003 instead of 444000.
 */
function get_604003_full_history(string $sessionId, array $config, string $until): array
{
    $cacheFile = __DIR__ . '/../cache/604003_history.json';
    $cache = is_file($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : null;
    if (!is_array($cache) || !isset($cache['transactions'])) {
        $cache = ['last_fetched_until' => null, 'transactions' => []];
    }

    $fetchStart = $cache['last_fetched_until']
        ? date('Y-m-d', strtotime($cache['last_fetched_until'] . ' +1 day'))
        : ACCOUNT_HISTORY_START;

    if ($fetchStart <= $until) {
        $newRows = yuki_gl_transactions($sessionId, $config, '604003', $fetchStart, $until);
        foreach ($newRows as $row) {
            $cache['transactions'][$row['id']] = $row; // keyed by id, re-fetch just overwrites
        }
        $cache['last_fetched_until'] = $until;
        file_put_contents($cacheFile, json_encode($cache));
    }

    return array_values($cache['transactions']);
}

/**
 * Read-only diagnostic: real description/amount for a sample of postings, split into
 * what looks like debit (invoice) vs credit (matching) lines, and what PO numbers were
 * extracted from each. Writes nothing.
 *
 * Only shows postings inside [costStart, costEnd] (the current book year) — not
 * whatever happens to be first in the full cached history, which starts at
 * ACCOUNT_HISTORY_START and may be mostly old/empty years before real data begins.
 */
function debug_dump_po_604003(string $sessionId, array $config, string $period, int $limit = 20): array
{
    [$costStart, $costEnd] = book_year_range($period);
    $all = get_604003_full_history($sessionId, $config, $costEnd);

    $windowed = array_values(array_filter(
        $all,
        fn($t) => $t['date'] >= $costStart && $t['date'] <= $costEnd
    ));

    $sample = [];
    foreach (array_slice($windowed, 0, $limit) as $t) {
        $sample[] = [
            'date'          => $t['date'],
            'description'   => $t['description'],
            'amount_raw'    => $t['amount'],
            'side'          => is_debit_posting($t) ? 'debit' : (is_credit_posting($t) ? 'credit' : 'zero/unknown'),
            'extracted_pos' => extract_po_numbers($t['description']),
        ];
    }

    return [
        'total_cached'    => count($all),
        'window'          => [$costStart, $costEnd],
        'total_in_window' => count($windowed),
        'sample'          => $sample,
    ];
}

/**
 * Run the check for a given closing period. Debit (invoice) lines are scoped to the
 * current book year as of that period; credit (matching) lines are checked across the
 * full cached history of the account. Each PO number on a debit line is checked
 * independently. Returns ['checked' => n, 'new_failures' => [...]].
 */
function run_check_po_604003(string $sessionId, array $config, string $period): array
{
    [$costStart, $costEnd] = book_year_range($period);
    $allPostings = get_604003_full_history($sessionId, $config, $costEnd);

    // Satisfied PO numbers: any PO number appearing on ANY credit line, anywhere in
    // history — not scoped to the current book year.
    $satisfiedPoNumbers = [];
    foreach ($allPostings as $t) {
        if (!is_credit_posting($t)) {
            continue;
        }
        foreach (extract_po_numbers($t['description']) as $po) {
            $satisfiedPoNumbers[$po] = true;
        }
    }

    $existingTodo = todo_load('PO NTOF');
    $existingPoNumbers = array_column($existingTodo, 'po_number');

    $newFailures = [];
    $checkedCount = 0;

    foreach ($allPostings as $t) {
        if (!is_debit_posting($t)) {
            continue;
        }
        // Debit side scoped to the current book year only.
        if ($t['date'] < $costStart || $t['date'] > $costEnd) {
            continue;
        }

        $poNumbers = extract_po_numbers($t['description']);
        if (!$poNumbers) {
            continue; // no PO reference on this line, nothing to check
        }

        foreach ($poNumbers as $poNumber) {
            $checkedCount++;

            if (isset($satisfiedPoNumbers[$poNumber])) {
                continue; // matched — check passes for this PO
            }
            if (in_array($poNumber, $existingPoNumbers, true)) {
                continue; // already on the todo list, don't duplicate
            }

            $newFailures[] = [
                'invoice_date' => $t['date'],
                'supplier'     => $t['description'], // best available until a real Contact/Relatie field is confirmed
                'po_number'    => $poNumber,
                'period'       => $period,
                'text'         => "{$t['date']} - {$t['description']} - {$poNumber}",
                'checked'      => false,
                'created_at'   => date('c'),
            ];
        }
    }

    if ($newFailures) {
        todo_append('PO NTOF', $newFailures);
    }

    // Badge state: "clean" means nothing open for this category+period at all — either
    // this run found nothing new AND nothing from an earlier run is still unchecked, or
    // (separately, checked live at display time) every open item has since been ticked.
    // This half only covers "the check itself just confirmed zero open items."
    $stillOpen = array_filter(
        todo_entries_for_period('PO NTOF', $period),
        fn($e) => empty($e['checked'])
    );
    status_set('po_604003_selfmatch', $period, empty($stillOpen));

    return ['checked' => $checkedCount, 'new_failures' => $newFailures];
}
