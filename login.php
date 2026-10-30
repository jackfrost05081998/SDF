<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

if (logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';
$flash = consume_flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Your form session expired. Please try again.';
    } else {
        $statement = db()->prepare(
            'SELECT id, username, full_name, password_hash, role, permissions, is_active
             FROM users WHERE username = ? LIMIT 1'
        );
        $statement->execute([$username]);
        $user = $statement->fetch();

        // Always run a password hash comparison, even when the username does
        // not exist, so response time does not reveal whether an account is
        // valid (prevents username enumeration via timing).
        $dummyHash = '$2b$10$fGbRayXhhws8f8BYZLGDNeA1Sg/SER0a8zhlVHHv60ws..nLTjf.K';
        $passwordValid = password_verify(
            $password,
            $user['password_hash'] ?? $dummyHash
        );

        if (!$user || !(bool) $user['is_active'] || !$passwordValid) {
            $error = 'Invalid username or password.';
            if ($user) {
                audit((int) $user['id'], 'Failed login attempt');
            }
        } else {
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id' => (int) $user['id'],
                'username' => $user['username'],
                'full_name' => $user['full_name'],
                'role' => $user['role'],
                'permissions' => $user['permissions'],
            ];
            audit((int) $user['id'], 'Logged in');
            header('Location: index.php');
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | Beanson Brew Cafe POS</title>
    <link rel="stylesheet" href="assets/css/style.css?v=4">
</head>
<body class="login-hero">
<main class="auth-card">
    <h1>Login</h1>
    <?php if ($flash): ?>
        <div class="alert <?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <div class="field-row">
            <label class="field-tab" for="username">Username</label>
            <input id="username" name="username" required autofocus maxlength="50" placeholder="Enter your username" autocomplete="username">
        </div>
        <div class="field-row">
            <label class="field-tab" for="password">Password</label>
            <input id="password" type="password" name="password" required placeholder="Enter your password" autocomplete="current-password">
        </div>



        <button class="button primary full" type="submit">Log In</button>
    </form>

    <p class="auth-footer">&copy; <?= date('Y') ?> Beanson Brew Cafe &middot; POS</p>
</main>
</body>
</html>
