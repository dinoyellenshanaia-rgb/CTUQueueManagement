<?php
require_once __DIR__ . '/includes/bootstrap.php';
$count = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
$setupComplete = (int)$pdo->query('SELECT admin_setup_complete FROM setup_state WHERE id = 1')->fetchColumn();
$setupClosed = $count > 0 || $setupComplete === 1;
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$setupClosed) {
    verify_csrf();
    $name = trim((string)($_POST['full_name'] ?? ''));
    $email = filter_var(trim((string)($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
    $password = (string)($_POST['password'] ?? '');
    if (mb_strlen($name) < 2 || !$email || strlen($password) < 14) {
        $message = 'Provide a valid name and email, and a password of at least 14 characters.';
    } else {
        try {
            $pdo->beginTransaction();
            $lock = (int)$pdo->query('SELECT admin_setup_complete FROM setup_state WHERE id = 1 FOR UPDATE')->fetchColumn();
            $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
            if ($lock !== 0 || $adminCount !== 0) {
                $pdo->rollBack();
                $message = 'An administrator already exists. Setup is disabled.';
            } else {
                $insert = $pdo->prepare("INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, 'admin')");
                $insert->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
                $adminId = (int)$pdo->lastInsertId();
                $pdo->exec('UPDATE setup_state SET admin_setup_complete = 1 WHERE id = 1');
                log_activity($pdo, $adminId, 'initial_admin_created');
                $pdo->commit();
                $count = 1;
                $setupClosed = true;
                $message = 'Administrator created. Remove or restrict access to this setup page, then log in.';
            }
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Initial administrator setup failed: ' . $exception->getMessage());
            $message = 'Setup failed. Check that the email address is not already registered.';
        }
    }
}
$pageTitle = 'Initial administrator setup';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-card card border-0 shadow-sm mx-auto"><div class="card-body p-4 p-md-5">
  <h1 class="h3 fw-bold">Initial administrator setup</h1>
  <?php if ($message): ?><div class="alert alert-info"><?= e($message) ?></div><?php endif; ?>
  <?php if (!$setupClosed): ?>
  <p class="text-secondary">This one-time form is enabled only while no administrator exists.</p>
  <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <label class="form-label" for="full_name">Administrator name</label><input class="form-control mb-3" id="full_name" name="full_name" required>
    <label class="form-label" for="email">Email address</label><input class="form-control mb-3" type="email" id="email" name="email" required>
    <label class="form-label" for="password">Strong password</label><input class="form-control mb-1" type="password" id="password" name="password" minlength="14" required><div class="form-text mb-4">At least 14 characters.</div>
    <button class="btn btn-primary w-100">Create administrator</button>
  </form>
  <?php else: ?><p class="text-secondary">Setup is closed because an administrator account already exists.</p><a href="/CTUQueueManagement/login.php" class="btn btn-primary">Go to login</a><?php endif; ?>
</div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
