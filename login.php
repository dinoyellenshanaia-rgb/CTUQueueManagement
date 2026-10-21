<?php
require_once __DIR__ . '/includes/bootstrap.php';
if (current_user()) {
    header('Location: /CTUQueueManagement/dashboard.php');
    exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = filter_var(trim((string)($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
    $password = (string)($_POST['password'] ?? '');
    $statement = $pdo->prepare('SELECT id, full_name, email, password_hash, role, is_active FROM users WHERE email = ? LIMIT 1');
    $statement->execute([$email ?: '']);
    $account = $statement->fetch();
    if ($account && $account['is_active'] && password_verify($password, $account['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => (int)$account['id'], 'full_name' => $account['full_name'], 'email' => $account['email'], 'role' => $account['role']];
        log_activity($pdo, (int)$account['id'], 'login');
        header('Location: /CTUQueueManagement/dashboard.php');
        exit;
    }
    $error = 'Email or password is incorrect, or the account is unavailable.';
}
$pageTitle = 'Log in';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-card card border-0 shadow-sm mx-auto"><div class="card-body p-4 p-md-5">
  <h1 class="h3 fw-bold">Welcome back</h1><p class="text-secondary mb-4">Log in to continue to student services.</p>
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <form method="post" novalidate>
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <label class="form-label" for="email">Email address</label><input class="form-control mb-3" type="email" id="email" name="email" required autocomplete="username">
    <label class="form-label" for="password">Password</label><input class="form-control mb-4" type="password" id="password" name="password" required autocomplete="current-password">
    <button class="btn btn-primary w-100">Log in</button>
  </form>
  <p class="small text-center mt-4 mb-0">Student? <a href="/CTUQueueManagement/register.php">Create an account</a></p>
</div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
