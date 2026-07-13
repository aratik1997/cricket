<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$teams = get_top10_teams($pdo);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p1 = trim($_POST['player1_name'] ?? 'Player 1');
    $p2 = trim($_POST['player2_name'] ?? 'Player 2');
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
            'mode' => 'local',
            'status' => 'in_progress',
            'team1_id' => $teamId,
            'team2_id' => $oppId,
            'user1_id' => current_user_id(),
            'player1_name' => $p1 !== '' ? $p1 : 'Player 1',
            'player2_name' => $p2 !== '' ? $p2 : 'Player 2',
            'overs_limit' => $overs,
            'wickets_limit' => $wickets,
        ]);
        header('Location: /cricket/play.php?match=' . $matchId);
        exit;
    }
}

$pageTitle = 'Same Screen Multiplayer Setup';
require __DIR__ . '/includes/header.php';
?>
<div class="setup-card">
    <h1>Same Screen Multiplayer</h1>
    <p class="muted">Both players share this device, passing it back and forth on each innings.</p>
    <?php if ($error): ?><div class="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post" class="stack-form">
        <label>Player 1 Name
            <input type="text" name="player1_name" maxlength="40" value="Player 1">
        </label>
        <label>Player 1's Team
            <select name="team_id" required>
                <option value="">-- choose --</option>
                <?= team_options_html($teams) ?>
            </select>
        </label>
        <label>Player 2 Name
            <input type="text" name="player2_name" maxlength="40" value="Player 2">
        </label>
        <label>Player 2's Team
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
