<?php
declare(strict_types=1);

session_start();

function load_config(): array
{
    return require __DIR__ . '/config.php';
}

function require_login(array $config): void
{
    if (!empty($_SESSION['ingelogd'])) {
        return;
    }
    if (($_POST['password'] ?? '') !== '' && hash_equals($config['password'], (string) $_POST['password'])) {
        $_SESSION['ingelogd'] = true;
        return;
    }
    ?>
    <!doctype html>
    <html lang="nl"><head><meta charset="utf-8"><title>Sluitingskalender</title></head>
    <body>
    <form method="post">
        <input type="password" name="password" placeholder="Wachtwoord" autofocus>
        <button type="submit">Inloggen</button>
    </form>
    </body></html>
    <?php
    exit;
}

function load_tasks(): array
{
    $data = json_decode((string) file_get_contents(__DIR__ . '/tasks.json'), true);
    return is_array($data) ? $data : [];
}

/** The only two people using this app right now. A closed, known list beats a free-text
 *  field -- same reasoning as groep_klanten name-matching in the reporting app. Update
 *  here (only) if that ever changes. */
const TAAK_EIGENAARS = ['Bjorge Withaegels', 'Sven Garcia (CFO)'];

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * The closing period follows the "close by the 15th of the following month" rule: on or
 * before the 15th, you're still closing last month; from the 16th on, the deadline for
 * last month has passed and the default rolls to the current month (even though it isn't
 * over yet — checks like the PO backlog are meant to run pre-close, during the month).
 */
function current_closing_period(): string
{
    $day = (int) date('j');
    return $day <= 15
        ? date('Y-m', strtotime('first day of last month'))
        : date('Y-m');
}

/** Recent closing periods for the dropdown, most recent first, current default included. */
function available_closing_periods(int $count = 12): array
{
    $periods = [];
    $cursor = current_closing_period() . '-01';
    for ($i = 0; $i < $count; $i++) {
        $periods[] = date('Y-m', strtotime($cursor));
        $cursor = date('Y-m-d', strtotime($cursor . ' -1 month'));
    }
    return $periods;
}

/** The period currently selected via ?period=YYYY-MM, falling back to the default. */
function selected_period(): string
{
    $raw = $_GET['period'] ?? '';
    return preg_match('/^\d{4}-\d{2}$/', $raw) ? $raw : current_closing_period();
}

/** Calendar-month bounds of a period, e.g. "2026-08" -> ["2026-08-01", "2026-08-31"]. */
function period_month_range(string $period): array
{
    $start = $period . '-01';
    $end = date('Y-m-t', strtotime($start));
    return [$start, $end];
}

/**
 * Current book year (fiscal year = calendar year, confirmed) from Jan 1 of the period's
 * year through the end of that period's month (capped at yesterday if that month hasn't
 * finished yet). Used for the 604003 (cost) side of the PO check — a P&L account resets
 * each fiscal year, so there's no reason to look further back than Jan 1 of that year.
 */
function book_year_range(string $period): array
{
    $year = substr($period, 0, 4);
    [, $monthEnd] = period_month_range($period);
    $yesterday = date('Y-m-d', strtotime('yesterday'));
    $end = $monthEnd < $yesterday ? $monthEnd : $yesterday;
    return [$year . '-01-01', $end];
}
