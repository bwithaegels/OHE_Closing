<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/todo.php';
require_once __DIR__ . '/status.php';
require_once __DIR__ . '/manual_taken.php';

date_default_timezone_set('Europe/Brussels');

$config = load_config();
require_login($config);

$tasks = load_tasks();
$period = selected_period();
$periods = available_closing_periods();
$manualTasks = manual_taken_for_period($period);
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex">
<title>Sluitingskalender — Osaka Hockey Europe</title>
<link rel="stylesheet" href="stijl.css?v=<?= filemtime(__DIR__ . '/stijl.css') ?>">
</head>
<body>

<h1 class="mb-2">Sluitingskalender</h1>

<form id="period-form" class="mb-5">
    <label for="period-select">Sluitingsperiode:</label>
    <select id="period-select" name="period">
        <?php foreach ($periods as $p): ?>
            <option value="<?= h($p) ?>" <?= $p === $period ? 'selected' : '' ?>><?= h($p) ?></option>
        <?php endforeach; ?>
    </select>
</form>

<section id="taken">
    <div class="sectie-kop mb-3">
        <h2>Taken</h2>
        <button type="button" id="taak-toevoegen-knop" title="Taak toevoegen">+</button>
    </div>

    <dialog id="taak-modal">
        <form id="taak-formulier">
            <div class="modal-kop">
                <h3>Nieuwe taak</h3>
                <button type="button" id="taak-sluiten-knop" class="sluiten-knop" aria-label="Sluiten">&times;</button>
            </div>

            <div class="modal-inhoud">
                <div class="veld">
                    <label for="taak-titel">Taak</label>
                    <input type="text" id="taak-titel" name="title" placeholder="Bv. VAT-aangifte doorsturen" required>
                </div>

                <div class="veld">
                    <span class="veld-label">Type</span>
                    <div class="segmentgroep">
                        <label class="segment">
                            <input type="radio" name="kind" value="once" checked>
                            <span>Eenmalig</span>
                        </label>
                        <label class="segment">
                            <input type="radio" name="kind" value="recurring">
                            <span>Terugkerend</span>
                        </label>
                    </div>
                    <p class="veld-hint" id="taak-hint-eenmalig">Verschijnt enkel op periode <?= h($period) ?>.</p>
                    <div class="veld veld-onderaan" id="taak-eind-periode-wrap" hidden>
                        <label for="taak-eind-periode">Terugkerend tot en met</label>
                        <input type="month" id="taak-eind-periode" name="end_period">
                    </div>
                </div>

                <div class="veld-rij">
                    <div class="veld">
                        <label for="taak-eigenaar">Eigenaar</label>
                        <select id="taak-eigenaar" name="owner" required>
                            <option value="" disabled selected>Kies...</option>
                            <?php foreach (TAAK_EIGENAARS as $naam): ?>
                                <option value="<?= h($naam) ?>"><?= h($naam) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="veld">
                        <label for="taak-vervaldatum">Vervaldatum</label>
                        <input type="date" id="taak-vervaldatum" name="due">
                    </div>
                </div>

                <p class="foutmelding" id="taak-foutmelding"></p>
            </div>

            <div class="modal-voet">
                <button type="button" id="taak-annuleren-knop" class="knop-secundair">Annuleren</button>
                <button type="submit" class="knop-primair">Taak toevoegen</button>
            </div>
        </form>
    </dialog>

    <table>
        <thead><tr><th>Dag</th><th>Taak</th><th>Eigenaar</th><th>Type</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($manualTasks as $mtask):
            $afgevinkt = in_array($period, $mtask['checked_periods'] ?? [], true);
            $verlopen = !$afgevinkt && !empty($mtask['due']) && $mtask['due'] < date('Y-m-d');
            $dagLabel = $mtask['due'] !== '' ? 'Voor ' . $mtask['due'] : '—';
            $typeLabel = ($mtask['kind'] ?? 'once') === 'recurring'
                ? 'Terugkerend t/m ' . h($mtask['end_period'])
                : 'Eenmalig';
        ?>
            <tr class="<?= $afgevinkt ? 'afgevinkt' : '' ?> <?= $verlopen ? 'verlopen' : '' ?>" data-manual-id="<?= h($mtask['id']) ?>">
                <td><?= h($dagLabel) ?></td>
                <td><?= h($mtask['title']) ?></td>
                <td><?= h($mtask['owner']) ?></td>
                <td><?= $typeLabel ?></td>
                <td>
                    <label>
                        <input type="checkbox" class="taak-check" <?= $afgevinkt ? 'checked' : '' ?>>
                        Klaar
                    </label>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php foreach ($tasks as $task): ?>
            <tr data-task-id="<?= h($task['id']) ?>">
                <td><?= h($task['day']) ?></td>
                <td>
                    <?php
                    $badgeSource = $task['badge_source'] ?? 'todo';
                    $showBadge = $badgeSource === 'check_result'
                        ? status_get($task['check_id'], $period) === true
                        : (!empty($task['todo_category']) && (
                            todo_category_fully_checked($task['todo_category'], $period)
                            || status_get($task['check_id'], $period) === true
                        ));
                    ?>
                    <?php if ($showBadge): ?>
                        <span class="badge-done" title="<?= $badgeSource === 'check_result' ? 'Check geslaagd' : 'Alles afgevinkt' ?>">✅</span>
                    <?php endif; ?>
                    <?= h($task['title']) ?>
                </td>
                <td><?= h($task['owner'] ?? '—') ?></td>
                <td><?= h($task['type']) ?></td>
                <td>
                    <?php if ($task['type'] === 'check'): ?>
                        <button class="run-check" data-id="<?= h($task['id']) ?>">Check uitvoeren</button>
                        <span class="check-result"></span>
                    <?php elseif ($task['type'] === 'csv'): ?>
                        <em>CSV-taak — nog niet geautomatiseerd</em>
                    <?php else: ?>
                        <em>Handmatige taak</em>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section id="todos">
    <h2 class="mb-4">Te controleren — periode <?= h($period) ?></h2>
    <?php foreach (todo_categories() as $categorySlug):
        $category = str_replace('_', ' ', $categorySlug);
        $allEntries = todo_load($category);
        // Entries tagged with a different period are hidden. Legacy entries without a
        // 'period' field (from before this concept existed) always show, rather than
        // silently disappearing.
        $entries = array_values(array_filter(
            $allEntries,
            fn($e) => !isset($e['period']) || $e['period'] === $period
        ));
        if (!$entries) continue;
    ?>
        <h3 class="mb-2"><?= h($category) ?></h3>
        <ul class="todo-list mb-5" data-category="<?= h($category) ?>">
            <?php foreach ($entries as $entry): ?>
                <li data-id="<?= h($entry['id']) ?>" class="<?= !empty($entry['checked']) ? 'checked' : '' ?>">
                    <label>
                        <input type="checkbox" class="todo-check" <?= !empty($entry['checked']) ? 'checked' : '' ?>>
                        <span class="todo-text"><?= h($entry['text'] ?? '') ?></span>
                    </label>
                    <input type="text" class="todo-comment" placeholder="Opmerking..." value="<?= h($entry['comment'] ?? '') ?>">
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endforeach; ?>
</section>

<script>
const periodSelect = document.getElementById('period-select');
function currentPeriod() { return periodSelect.value; }

periodSelect.addEventListener('change', () => {
    const url = new URL(location.href);
    url.searchParams.set('period', currentPeriod());
    location.href = url.toString();
});

document.querySelectorAll('.run-check').forEach(btn => {
    btn.addEventListener('click', async () => {
        const id = btn.dataset.id;
        const resultSpan = btn.parentElement.querySelector('.check-result');
        resultSpan.textContent = 'bezig...';
        try {
            const res = await fetch(`check.php?id=${encodeURIComponent(id)}&period=${encodeURIComponent(currentPeriod())}`);
            const data = await res.json();
            if (data.error) {
                resultSpan.textContent = 'Fout: ' + data.error;
            } else {
                resultSpan.textContent = `${data.checked} nagekeken, ${data.new_failures.length} nieuw op de lijst`;
                if (data.new_failures.length) {
                    const url = new URL(location.href);
                    url.searchParams.set('period', currentPeriod());
                    location.href = url.toString();
                }
            }
        } catch (e) {
            resultSpan.textContent = 'Fout: ' + e.message;
        }
    });
});

document.querySelectorAll('.todo-check').forEach(cb => {
    cb.addEventListener('change', async () => {
        const li = cb.closest('li');
        const category = li.closest('.todo-list').dataset.category;
        const id = li.dataset.id;
        li.classList.toggle('checked', cb.checked);
        await fetch('toggle.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `category=${encodeURIComponent(category)}&id=${encodeURIComponent(id)}`,
        });
    });
});

// --- Taken toevoegen ---
const taakKnop = document.getElementById('taak-toevoegen-knop');
const taakModal = document.getElementById('taak-modal');
const taakFormulier = document.getElementById('taak-formulier');
const taakSluitenKnop = document.getElementById('taak-sluiten-knop');
const taakAnnulerenKnop = document.getElementById('taak-annuleren-knop');
const taakEindPeriodeWrap = document.getElementById('taak-eind-periode-wrap');
const taakHintEenmalig = document.getElementById('taak-hint-eenmalig');
const taakEindPeriode = document.getElementById('taak-eind-periode');
const taakFoutmelding = document.getElementById('taak-foutmelding');

function taakModalOpenen() {
    taakFormulier.reset();
    taakFormulier.querySelectorAll('.segment').forEach(seg => {
        seg.classList.toggle('geselecteerd', seg.querySelector('input').checked);
    });
    taakEindPeriodeWrap.hidden = true;
    taakHintEenmalig.hidden = false;
    taakFoutmelding.textContent = '';
    taakModal.showModal();
    document.getElementById('taak-titel').focus();
}
function taakModalSluiten() {
    taakModal.close();
}

taakKnop.addEventListener('click', taakModalOpenen);
taakSluitenKnop.addEventListener('click', taakModalSluiten);
taakAnnulerenKnop.addEventListener('click', taakModalSluiten);
// Clicking the dimmed backdrop (a click that lands on the <dialog> element itself,
// outside the form card) closes the modal, same as the X and Annuleren.
taakModal.addEventListener('click', (e) => {
    if (e.target === taakModal) taakModalSluiten();
});

taakFormulier.querySelectorAll('input[name="kind"]').forEach(radio => {
    radio.addEventListener('change', () => {
        taakFormulier.querySelectorAll('.segment').forEach(seg => {
            seg.classList.toggle('geselecteerd', seg.querySelector('input').checked);
        });
        const herhaald = taakFormulier.querySelector('input[name="kind"]:checked').value === 'recurring';
        taakEindPeriodeWrap.hidden = !herhaald;
        taakHintEenmalig.hidden = herhaald;
        if (!herhaald) taakEindPeriode.value = '';
    });
});

taakFormulier.addEventListener('submit', async (e) => {
    e.preventDefault();
    taakFoutmelding.textContent = '';
    const formulierData = new FormData(taakFormulier);
    const herhaald = formulierData.get('kind') === 'recurring';
    if (herhaald && !formulierData.get('end_period')) {
        taakFoutmelding.textContent = 'Kies een einddatum voor de herhaling.';
        return;
    }
    const submitKnop = taakFormulier.querySelector('button[type="submit"]');
    submitKnop.disabled = true;
    const body = new URLSearchParams({
        title: formulierData.get('title') || '',
        kind: formulierData.get('kind') || 'once',
        end_period: herhaald ? formulierData.get('end_period') : '',
        owner: formulierData.get('owner') || '',
        due: formulierData.get('due') || '',
        period: currentPeriod(),
    });
    try {
        const res = await fetch('taak_toevoegen.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body.toString(),
        });
        const data = await res.json();
        if (data.error) {
            taakFoutmelding.textContent = data.error;
            submitKnop.disabled = false;
            return;
        }
        location.reload();
    } catch (err) {
        taakFoutmelding.textContent = 'Fout: ' + err.message;
        submitKnop.disabled = false;
    }
});

document.querySelectorAll('.taak-check').forEach(cb => {
    cb.addEventListener('change', async () => {
        const tr = cb.closest('tr');
        const id = tr.dataset.manualId;
        tr.classList.toggle('afgevinkt', cb.checked);
        await fetch('taak_toggle.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `id=${encodeURIComponent(id)}&period=${encodeURIComponent(currentPeriod())}`,
        });
    });
});

document.querySelectorAll('.todo-comment').forEach(input => {
    input.addEventListener('blur', async () => {
        const li = input.closest('li');
        const category = li.closest('.todo-list').dataset.category;
        const id = li.dataset.id;
        await fetch('comment.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `category=${encodeURIComponent(category)}&id=${encodeURIComponent(id)}&comment=${encodeURIComponent(input.value)}`,
        });
    });
});
</script>

</body>
</html>
