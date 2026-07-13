<?php
require_once __DIR__ . '/../includes/auth.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    if (!preg_match('/^[A-Za-z0-9_]{3,20}$/', $username)) {
        $error = 'Username must be 3-20 characters (letters, numbers, underscore only).';
    } elseif (strlen($password) < 4) {
        $error = 'Password must be at least 4 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            $error = 'That username is already taken.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)');
            $stmt->execute([$username, $hash]);
            login_user((int)$pdo->lastInsertId(), $username);
            header('Location: /cricket/index.php');
            exit;
        }
    }
}

$pageTitle = 'Register';
require __DIR__ . '/../includes/header.php';
?>
<div class="auth-card">
    <h1>Create Account</h1>
    <p class="muted">An account lets you save matches and resume them later.</p>
    <?php if ($error): ?><div class="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post" class="stack-form">
        <label>Username
            <input type="text" name="username" required maxlength="20" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
        </label>
        <label>Password
            <input type="password" name="password" required minlength="4">
        </label>
        <label>Confirm Password
            <input type="password" name="confirm" required minlength="4">
        </label>
        <button type="submit" class="btn btn-primary">Register</button>
    </form>
    <p>Already have an account? <a href="/cricket/auth/login.php">Login here</a>.</p>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
