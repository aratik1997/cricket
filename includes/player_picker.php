<?php
/**
 * Shared "just for fun" picker: lets the batting side choose who comes in next on a wicket and
 * who bowls the next over, instead of the automatic batting order / rotation.
 * Expects $pdo and $innings (the active innings row) to already be in scope.
 */
$availableBatsmen = get_available_batsmen($pdo, $innings);
$availableBowlers = get_available_bowlers($pdo, $innings);
?>
<div class="picker-row">
    <label class="picker-label">Next batsman
        <select id="pick-batsman" class="picker-select">
            <option value="0">Auto (next in order)</option>
            <?php foreach ($availableBatsmen as $p): ?>
                <option value="<?= (int)$p['id'] ?>"<?= (int)($innings['manual_next_batsman_id'] ?? 0) === (int)$p['id'] ? ' selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="picker-label">Next over's bowler
        <select id="pick-bowler" class="picker-select">
            <option value="0">Auto (rotation)</option>
            <?php foreach ($availableBowlers as $p): ?>
                <option value="<?= (int)$p['id'] ?>"<?= (int)($innings['manual_next_bowler_id'] ?? 0) === (int)$p['id'] ? ' selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
</div>
<p id="picker-status" class="muted"></p>
