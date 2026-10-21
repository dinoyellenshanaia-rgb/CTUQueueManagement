<?php
require_once __DIR__ . '/includes/bootstrap.php';
if (current_user()) {
    header('Location: /CTUQueueManagement/dashboard.php');
    exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim((string)($_POST['full_name'] ?? ''));
    $email = filter_var(trim((string)($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
    $studentId = trim((string)($_POST['student_id'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $passwordConfirmation = (string)($_POST['password_confirmation'] ?? '');
    if (mb_strlen($name) < 2 || mb_strlen($name) > 120 || !$email || mb_strlen($studentId) > 50 || strlen($password) < 10) {
        $error = 'Enter a valid name and email, an optional student ID, and a password of at least 10 characters.';
    } elseif ($password !== $passwordConfirmation) {
        $error = 'The password and confirmation do not match.';
    } else {
        try {
            $statement = $pdo->prepare("INSERT INTO users (full_name, email, student_id, password_hash, role) VALUES (?, ?, ?, ?, 'student')");
            $statement->execute([$name, $email, $studentId !== '' ? $studentId : null, password_hash($password, PASSWORD_DEFAULT)]);
            set_flash('success', 'Your student account has been created. You can now log in.');
            header('Location: /CTUQueueManagement/login.php');
            exit;
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                $error = 'An account already uses that email address or student ID.';
            } else {
                error_log('Student registration failed: ' . $exception->getMessage());
                $error = 'Registration could not be completed. Please try again.';
            }
        }
    }
}
$pageTitle = 'Student registration';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-card card border-0 shadow-sm mx-auto"><div class="card-body p-4 p-md-5">
  <h1 class="h3 fw-bold">Create your student account</h1><p class="text-secondary mb-4">Register to join campus service queues.</p>
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <label class="form-label" for="full_name">Full name</label><input class="form-control mb-3" id="full_name" name="full_name" maxlength="120" required>
    <label class="form-label" for="student_id">Student ID <span class="text-secondary">(optional)</span></label><input class="form-control mb-3" id="student_id" name="student_id" maxlength="50">
    <label class="form-label" for="email">Email address</label><input class="form-control mb-3" type="email" id="email" name="email" maxlength="190" required>
    <label class="form-label" for="password">Password</label><input class="form-control mb-3" type="password" id="password" name="password" minlength="10" required autocomplete="new-password">
    <label class="form-label" for="password_confirmation">Confirm password</label><input class="form-control mb-1" type="password" id="password_confirmation" name="password_confirmation" minlength="10" required autocomplete="new-password"><div class="form-text mb-4">Use at least 10 characters.</div>
    <button class="btn btn-primary w-100">Create account</button>
  </form>
</div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
