<?php
declare(strict_types=1);

// Yuki SOAP wrapper for the closing calendar. Deliberately minimal — this app only
// needs read operations to run checks, never balances-over-time or the P&L assembly
// that OHE_Reporting does. See the yuki-reporting-dashboard skill for the fuller set
// of confirmed API lessons; only what's needed here is duplicated.

define('YUKI_BASE', 'https://api.yukiworks.be/ws/');
define('YUKI_NS', 'http://www.theyukicompany.com/');

class YukiError extends RuntimeException {}

/** Authenticate (or reuse a cached session) and return a session ID good for ~24h. */
function yuki_session(array $config): string
{
    $cacheFile = __DIR__ . '/cache/session.json';
    if (is_file($cacheFile)) {
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($cached) && ($cached['expires'] ?? 0) > time()) {
            return $cached['sessionId'];
        }
    }

    $client = new SoapClient(YUKI_BASE . 'Accounting.asmx?WSDL', ['exceptions' => true]);
    $result = $client->Authenticate(['accessKey' => $config['yuki_api_key']]);
    $sessionId = $result->AuthenticateResult ?? null;
    if (!$sessionId) {
        throw new YukiError('Authenticate did not return a session ID');
    }

    file_put_contents($cacheFile, json_encode([
        'sessionId' => $sessionId,
        // Cache for 23h, not the full 24h, to avoid a request landing right on the edge.
        'expires' => time() + 23 * 3600,
    ]));

    return $sessionId;
}

/**
 * Fetch raw transactions for one GL account over a date range.
 * Returns the parsed <GLAccountTransaction> rows as plain arrays.
 *
 * OPEN QUESTION (see yuki-closing-calendar skill): whether Amount's sign here follows
 * the same "credit negative" convention confirmed for GLAccountBalance, or something
 * else at the line level, and whether there's a separate debit/credit indicator. This
 * function returns the raw Amount untouched — do not assume a sign convention until
 * confirmed against a real response. Dump $raw and inspect before trusting any of this.
 */
function yuki_gl_transactions(string $sessionId, array $config, string $glCode, string $startDate, string $endDate, bool $debug = false): array
{
    $client = new SoapClient(YUKI_BASE . 'Accounting.asmx?WSDL', ['exceptions' => true]);
    $result = $client->GLAccountTransactions([
        'sessionID'         => $sessionId, // capital D — Accounting service casing
        'administrationID'  => $config['yuki_administration_id'],
        'GLAccountCode'     => $glCode,
        'StartDate'         => $startDate,
        'EndDate'           => $endDate,
    ]);

    $raw = $result->GLAccountTransactionsResult->any ?? '';
    if ($debug) {
        file_put_contents(__DIR__ . "/cache/raw_{$glCode}.xml", $raw);
    }
    if ($raw === '') {
        return [];
    }

    $xml = simplexml_load_string($raw);
    if ($xml === false) {
        throw new YukiError("Could not parse GLAccountTransactions XML for {$glCode}");
    }

    $rows = [];
    foreach ($xml->GLAccountTransaction as $t) {
        $rows[] = [
            'id'          => (string) ($t['ID'] ?? ''),
            'date'        => (string) ($t->Date ?? ''),
            'description' => (string) ($t->Description ?? ''),
            'amount'      => (string) ($t->Amount ?? ''), // raw, sign not yet interpreted
            'project'     => (string) ($t->Project['Code'] ?? ''),
            'gl_account'  => (string) ($t->GLAccountCode ?? $glCode),
        ];
    }
    return $rows;
}

/**
 * Trial balance at a single date, keyed by GL code. Reuses the fetch strategy confirmed
 * in yuki-reporting-dashboard: GLAccountBalance is a snapshot at a date, and a period's
 * movement is the difference between two such snapshots — far cheaper than pulling every
 * transaction. Returns raw amounts, sign not reinterpreted.
 */
function yuki_gl_balance(string $sessionId, array $config, string $asOfDate): array
{
    $client = new SoapClient(YUKI_BASE . 'Accounting.asmx?WSDL', ['exceptions' => true]);
    $result = $client->GLAccountBalance([
        'sessionID'        => $sessionId, // capital D — Accounting service casing
        'administrationID' => $config['yuki_administration_id'],
        'transactionDate'  => $asOfDate,
    ]);

    $raw = $result->GLAccountBalanceResult->any ?? '';
    if ($raw === '') {
        return [];
    }

    $xml = simplexml_load_string($raw);
    if ($xml === false) {
        throw new YukiError("Could not parse GLAccountBalance XML for {$asOfDate}");
    }

    $rows = [];
    foreach ($xml->GLAccount as $g) {
        $code = (string) $g['Code'];
        $rows[$code] = [
            'code'         => $code,
            'balance_type' => (string) $g['BalanceType'],
            'description'  => (string) ($g->Description ?? ''),
            'amount'       => (float) ($g->Amount ?? 0),
        ];
    }
    return $rows;
}

