<?php
$pageTitle = $pageTitle ?? 'CTU Student Services';
$user = current_user();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <title><?= e($pageTitle) ?> | CTU Daanbantayan</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="/CTUQueueManagement/assets/css/app.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom sticky-top">
  <div class="container">
    <a class="navbar-brand fw-bold text-primary" href="/CTUQueueManagement/"><i class="bi bi-people-fill me-2"></i>CTU Queue</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-label="Toggle navigation"><span class="navbar-toggler-icon"></span></button>
    <div class="collapse navbar-collapse" id="mainNav">
      <div class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
        <a class="nav-link" href="/CTUQueueManagement/#offices-heading">Campus offices</a>
        <?php if ($user): ?>
          <span class="nav-link text-secondary"><?= e($user['full_name']) ?> · <?= e(ucfirst($user['role'])) ?></span>
          <form method="post" action="/CTUQueueManagement/logout.php" class="m-0"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button class="btn btn-outline-primary btn-sm">Log out</button></form>
        <?php else: ?>
          <a class="nav-link" href="/CTUQueueManagement/login.php">Log in</a>
          <a class="btn btn-primary btn-sm px-3" href="/CTUQueueManagement/register.php">Student registration</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</nav>
<main class="container py-4 py-lg-5">
<?php if (!empty($_SESSION['flash'])): $flash = $_SESSION['flash']; unset($_SESSION['flash']); ?>
  <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
    <?= e($flash['message']) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>
