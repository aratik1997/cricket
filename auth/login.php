<?php
require_once __DIR__ . '/../includes/auth.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare('SELECT id, username, password_hash FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        login_user((int)$user['id'], $user['username']);
        header('Location: /cricket/index.php');
        exit;
    }
    $error = 'Invalid username or password.';
}

$pageTitle = 'Login';
require __DIR__ . '/../includes/header.php';
?>
<div class="auth-card">
    <h1>Login</h1>
    <?php if ($error): ?><div class="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post" class="stack-form">
        <label>Username
            <input type="text" name="username" required maxlength="20" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
        </label>
        <label>Password
            <input type="password" name="password" required>
        </label>
        <button type="submit" class="btn btn-primary">Login</button>
    </form>
    <p>New here? <a href="/cricket/auth/register.php">Create an account</a>.</p>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
