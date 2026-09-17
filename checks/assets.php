<?php
declare(strict_types=1);

require_once __DIR__ . '/../yuki.php';
require_once __DIR__ . '/../todo.php';
require_once __DIR__ . '/../status.php';

// GL accounts holding fixed-asset postings, per the screenshot confirmed 2026-09-15.
// This is not a pass/fail check — there's no way to see from Yuki whether asset details
// have been filled in, so every posting on these accounts is simply listed for the
// period, and gets checked off manually once the details are entered in Yuki.
const ASSET_GL_ACCOUNTS = [
    '204000', // Herstructureringskosten
    '214000', // Software en software licenties
    '230000', // Installaties, machines & uitrusting
    '240000', // Meubilair
    '240100', // Kantoormachines
    '241000', // Personenwagens
    '241100', // Bedrijfswagens
    '271010', // Activa in aanbouw en vooruitbetalingen - Beginbalans VA - Navision
    '261000', // Overige materiële vaste activa - Kosten van inrichting gehuurde gebouwen
];

/**
 * List every posting on the asset GL accounts within the given closing period. Dedupes
 * by Yuki's own transaction id (not by anything parsed from text), since there's no PO
 * number or similar reference to key on here — just "was this specific posting already
 * listed."
 */
function run_check_assets(string $sessionId, array $config, string $period): array
{
    [$start, $end] = period_month_range($period);

    $existing = todo_load('VASTE ACTIVA');
    $existingIds = array_column($existing, 'transaction_id');

    $newEntries = [];
    $checkedCount = 0;

    foreach (ASSET_GL_ACCOUNTS as $glCode) {
        $postings = yuki_gl_transactions($sessionId, $config, $glCode, $start, $end);
        foreach ($postings as $t) {
            $checkedCount++;
            if (in_array($t['id'], $existingIds, true)) {
                continue; // already listed from an earlier run
            }
            $newEntries[] = [
                'transaction_id' => $t['id'],
                'invoice_date'   => $t['date'],
                'gl_account'     => $glCode,
                'description'    => $t['description'],
                'amount'         => $t['amount'],
                'period'         => $period,
                'text'           => "{$t['date']} - {$glCode} - {$t['description']} - {$t['amount']}",
                'checked'        => false,
                'created_at'     => date('c'),
            ];
        }
    }

    if ($newEntries) {
        todo_append('VASTE ACTIVA', $newEntries);
    }

    // See po_604003_selfmatch.php for why this exists: records "the check itself just
    // confirmed zero open items" separately from the live all-checked case in index.php.
    $stillOpen = array_filter(
        todo_entries_for_period('VASTE ACTIVA', $period),
        fn($e) => empty($e['checked'])
    );
    status_set('assets', $period, empty($stillOpen));

    return ['checked' => $checkedCount, 'new_failures' => $newEntries];
}
