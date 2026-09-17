<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/todo.php';
require_once __DIR__ . '/status.php';

date_default_timezone_set('Europe/Brussels');

$config = load_config();
require_login($config);

$tasks = load_tasks();
$period = selected_period();
$periods = available_closing_periods();
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

<h1>Sluitingskalender</h1>

<form id="period-form">
    <label for="period-select">Sluitingsperiode:</label>
    <select id="period-select" name="period">
        <?php foreach ($periods as $p): ?>
            <option value="<?= h($p) ?>" <?= $p === $period ? 'selected' : '' ?>><?= h($p) ?></option>
        <?php endforeach; ?>
    </select>
</form>

<section id="taken">
    <h2>Taken</h2>
    <table>
        <thead><tr><th>Dag</th><th>Taak</th><th>Type</th><th></th></tr></thead>
        <tbody>
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
    <h2>Te controleren — periode <?= h($period) ?></h2>
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
        <h3><?= h($category) ?></h3>
        <ul class="todo-list" data-category="<?= h($category) ?>">
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
