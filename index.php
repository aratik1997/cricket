<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$uid = current_user_id();

$stmt = $pdo->prepare("SELECT m.*, t1.short_code AS team1_code, t2.short_code AS team2_code
    FROM matches m
    JOIN teams t1 ON t1.id = m.team1_id
    LEFT JOIN teams t2 ON t2.id = m.team2_id
    WHERE m.status IN ('waiting','toss_call','toss','in_progress')
      AND m.mode != 'worldcup'
      AND (m.user1_id = ? OR m.user2_id = ?)
    ORDER BY m.updated_at DESC");
$stmt->execute([$uid, $uid]);
$savedMatches = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT w.*, t.short_code AS user_team_code
    FROM worldcups w JOIN teams t ON t.id = w.user_team_id
    WHERE w.user_id = ? AND w.stage != 'done'
    ORDER BY w.updated_at DESC");
$stmt->execute([$uid]);
$savedWorldcups = $stmt->fetchAll();

$pageTitle = 'Home';
require __DIR__ . '/includes/header.php';
?>
<h1>Welcome, <?= htmlspecialchars(current_username()) ?></h1>

<?php if ($savedMatches || $savedWorldcups): ?>
<section class="card">
    <h2>Resume a saved game</h2>
    <ul class="saved-list">
        <?php foreach ($savedMatches as $m): ?>
        <li>
            <span><?= htmlspecialchars(ucfirst($m['mode'])) ?>: <?= htmlspecialchars($m['team1_code']) ?> vs <?= htmlspecialchars($m['team2_code'] ?? '?') ?></span>
            <a class="btn btn-small" href="/cricket/play.php?match=<?= (int)$m['id'] ?>">Resume</a>
        </li>
        <?php endforeach; ?>
        <?php foreach ($savedWorldcups as $w): ?>
        <li>
            <span>World Cup (<?= (int)$w['num_teams'] ?> teams) &mdash; playing as <?= htmlspecialchars($w['user_team_code']) ?></span>
            <a class="btn btn-small" href="/cricket/worldcup.php?id=<?= (int)$w['id'] ?>">Resume</a>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<section class="mode-grid">
    <a class="mode-card" href="/cricket/setup_single.php">
        <h2>Single Player</h2>
        <p>Bat and bowl against the computer.</p>
    </a>
    <a class="mode-card" href="/cricket/setup_multiplayer.php">
        <h2>Multiplayer</h2>
        <p>Play online against a friend on another device.</p>
    </a>
    <a class="mode-card" href="/cricket/setup_local.php">
        <h2>Same Screen Multiplayer</h2>
        <p>Two players, one device &mdash; pass it back and forth.</p>
    </a>
    <a class="mode-card" href="/cricket/setup_worldcup.php">
        <h2>World Cup</h2>
        <p>Single player tournament: pick your team and take on the world.</p>
    </a>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
