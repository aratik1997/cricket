<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$teams = get_top10_teams($pdo);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $teamId = (int)($_POST['team_id'] ?? 0);
    $oppId = (int)($_POST['opponent_id'] ?? 0);
    $overs = max(1, min(20, (int)($_POST['overs'] ?? 5)));
    $wickets = max(1, min(10, (int)($_POST['wickets'] ?? 3)));

    if ($teamId === $oppId) {
        $error = 'Pick two different teams.';
    } elseif (!get_team($pdo, $teamId) || !get_team($pdo, $oppId)) {
        $error = 'Invalid team selection.';
    } else {
        $matchId = start_new_match($pdo, [
            'mode' => 'single',
            'status' => 'in_progress',
            'team1_id' => $teamId,
            'team2_id' => $oppId,
            'user1_id' => current_user_id(),
            'overs_limit' => $overs,
            'wickets_limit' => $wickets,
        ]);
        header('Location: /cricket/play.php?match=' . $matchId);
        exit;
    }
}

$pageTitle = 'Single Player Setup';
require __DIR__ . '/includes/header.php';
?>
<div class="setup-card">
    <h1>Single Player</h1>
    <?php if ($error): ?><div class="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post" class="stack-form">
        <label>Your Team
            <select name="team_id" required>
                <option value="">-- choose --</option>
                <?= team_options_html($teams) ?>
            </select>
        </label>
        <label>Opponent (Computer)
            <select name="opponent_id" required>
                <option value="">-- choose --</option>
                <?= team_options_html($teams) ?>
            </select>
        </label>
        <label>Overs per innings
            <input type="number" name="overs" value="5" min="1" max="20" required>
        </label>
        <label>Wickets to lose
            <input type="number" name="wickets" value="3" min="1" max="10" required>
        </label>
        <button type="submit" class="btn btn-primary">Start Match</button>
    </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
