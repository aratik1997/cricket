<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$allTeams = get_all_teams($pdo);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $teamId = (int)($_POST['team_id'] ?? 0);
    $numTeams = max(6, min(16, (int)($_POST['num_teams'] ?? 8)));
    $overs = max(1, min(20, (int)($_POST['overs'] ?? 5)));
    $wickets = max(1, min(10, (int)($_POST['wickets'] ?? 3)));

    if (!get_team($pdo, $teamId)) {
        $error = 'Pick your team.';
    } elseif (count($allTeams) < $numTeams) {
        $error = 'Not enough teams available.';
    } else {
        $otherIds = array_values(array_diff(array_map(fn($t) => (int)$t['id'], $allTeams), [$teamId]));
        shuffle($otherIds);
        $selected = array_slice($otherIds, 0, $numTeams - 1);
        $selected[] = $teamId;

        $stmt = $pdo->prepare('INSERT INTO worldcups (user_id, user_team_id, num_teams, overs_limit, wickets_limit, stage)
            VALUES (?, ?, ?, ?, ?, "group")');
        $stmt->execute([current_user_id(), $teamId, $numTeams, $overs, $wickets]);
        $worldcupId = (int)$pdo->lastInsertId();

        setup_worldcup_fixtures($pdo, $worldcupId, $selected, $teamId);
        progress_worldcup($pdo, $worldcupId);

        header('Location: /cricket/worldcup.php?id=' . $worldcupId);
        exit;
    }
}

$pageTitle = 'World Cup Setup';
require __DIR__ . '/includes/header.php';
?>
<div class="setup-card">
    <h1>World Cup</h1>
    <p class="muted">Single player only. Pick your team; the rest of the field is filled in automatically. 6-8 teams play a straight knockout, 9-16 teams play groups then knockout.</p>
    <?php if ($error): ?><div class="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post" class="stack-form">
        <label>Your Team
            <select name="team_id" required>
                <option value="">-- choose --</option>
                <?= team_options_html($allTeams) ?>
            </select>
        </label>
        <label>Number of Teams (6-16)
            <input type="number" name="num_teams" value="8" min="6" max="16" required>
        </label>
        <label>Overs per innings
            <input type="number" name="overs" value="5" min="1" max="20" required>
        </label>
        <label>Wickets to lose
            <input type="number" name="wickets" value="3" min="1" max="10" required>
        </label>
        <button type="submit" class="btn btn-primary">Start World Cup</button>
    </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
