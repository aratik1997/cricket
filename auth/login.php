<?php
require_once __DIR__ . '/../includes/auth.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');

    if (!preg_match('/^[A-Za-z0-9_ ]{2,20}$/', $username)) {
        $error = 'Name must be 2-20 characters (letters, numbers, spaces, underscore only).';
    } else {
        $stmt = $pdo->prepare('SELECT id, username FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user) {
            $stmt = $pdo->prepare('INSERT INTO users (username) VALUES (?)');
            $stmt->execute([$username]);
            $user = ['id' => $pdo->lastInsertId(), 'username' => $username];
        }

        login_user((int)$user['id'], $user['username']);
        header('Location: /cricket/index.php');
        exit;
    }
}

$pageTitle = 'Enter Name';
require __DIR__ . '/../includes/header.php';
?>
<div class="auth-card">
    <h1>Welcome</h1>
    <p class="muted">Enter a name to start playing.</p>
    <?php if ($error): ?><div class="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post" class="stack-form">
        <label>Your name
            <input type="text" name="username" required maxlength="20" autofocus value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
        </label>
        <button type="submit" class="btn btn-primary">Play</button>
    </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
