<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$teams = get_top10_teams($pdo);
$createError = '';
$joinError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $teamId = (int)($_POST['team_id'] ?? 0);
    $overs = max(1, min(20, (int)($_POST['overs'] ?? 5)));
    $wickets = max(1, min(10, (int)($_POST['wickets'] ?? 3)));

    if (!get_team($pdo, $teamId)) {
        $createError = 'Pick a team.';
    } else {
        $code = generate_join_code();
        $matchId = create_match_row($pdo, [
            'mode' => 'online',
            'status' => 'waiting',
            'team1_id' => $teamId,
            'user1_id' => current_user_id(),
            'overs_limit' => $overs,
            'wickets_limit' => $wickets,
            'join_code' => $code,
        ]);
        header('Location: /cricket/play.php?match=' . $matchId);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'join') {
    $code = strtoupper(trim($_POST['join_code'] ?? ''));
    $teamId = (int)($_POST['opponent_team_id'] ?? 0);

    $stmt = $pdo->prepare("SELECT * FROM matches WHERE join_code = ? AND status = 'waiting'");
    $stmt->execute([$code]);
    $match = $stmt->fetch();

    if (!$match) {
        $joinError = 'No waiting match found with that code.';
    } elseif ((int)$match['user1_id'] === current_user_id()) {
        $joinError = 'You cannot join your own match.';
    } elseif (!get_team($pdo, $teamId)) {
        $joinError = 'Pick a team.';
    } elseif ($teamId === (int)$match['team1_id']) {
        $joinError = 'Choose a different team than your opponent.';
    } else {
        $pdo->prepare('UPDATE matches SET team2_id = ?, user2_id = ? WHERE id = ?')
            ->execute([$teamId, current_user_id(), $match['id']]);
        assign_toss_caller($pdo, (int)$match['id']);
        header('Location: /cricket/play.php?match=' . $match['id']);
        exit;
    }
}

$pageTitle = 'Multiplayer Setup';
require __DIR__ . '/includes/header.php';
?>
<div class="setup-card">
    <h1>Multiplayer</h1>

    <h2>Create a Match</h2>
    <?php if ($createError): ?><div class="alert"><?= htmlspecialchars($createError) ?></div><?php endif; ?>
    <form method="post" class="stack-form">
        <input type="hidden" name="action" value="create">
        <label>Your Team
            <select name="team_id" required>
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
        <button type="submit" class="btn btn-primary">Create Match &amp; Get Code</button>
    </form>

    <h2>Join a Match</h2>
    <?php if ($joinError): ?><div class="alert"><?= htmlspecialchars($joinError) ?></div><?php endif; ?>
    <form method="post" class="stack-form">
        <input type="hidden" name="action" value="join">
        <label>Join Code
            <input type="text" name="join_code" maxlength="8" required style="text-transform:uppercase">
        </label>
        <label>Your Team
            <select name="opponent_team_id" required>
                <option value="">-- choose --</option>
                <?= team_options_html($teams) ?>
            </select>
        </label>
        <button type="submit" class="btn btn-primary">Join Match</button>
    </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
