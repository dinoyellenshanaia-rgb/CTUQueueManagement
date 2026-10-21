<?php
require_once __DIR__ . '/includes/bootstrap.php';

$officeId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$officeId || $officeId < 1) {
    http_response_code(404);
    $office = null;
    $services = [];
} else {
    $officeStatement = $pdo->prepare(
        'SELECT id, name, description, accepting_queue
         FROM offices
         WHERE id = ? AND is_active = 1'
    );
    $officeStatement->execute([$officeId]);
    $office = $officeStatement->fetch();
    if (!$office) {
        http_response_code(404);
        $services = [];
    } else {
        $serviceStatement = $pdo->prepare(
            'SELECT id, name
             FROM services
             WHERE office_id = ? AND is_active = 1
             ORDER BY name'
        );
        $serviceStatement->execute([$officeId]);
        $services = $serviceStatement->fetchAll();
    }
}

$user = current_user();
$pageTitle = $office ? $office['name'] . ' services' : 'Office not found';
require __DIR__ . '/includes/header.php';
?>
<p class="mb-3"><a href="/CTUQueueManagement/" class="text-decoration-none"><i class="bi bi-arrow-left me-1"></i>All campus offices</a></p>
<?php if (!$office): ?>
  <div class="alert alert-warning" role="status">
    This office is unavailable or may have been removed.
    <a href="/CTUQueueManagement/" class="alert-link">Return to the office list.</a>
  </div>
<?php else: ?>
  <section class="card border-0 shadow-sm mb-4">
    <div class="card-body p-4 p-lg-5">
      <span class="badge <?= (int)$office['accepting_queue'] ? 'text-bg-success' : 'text-bg-secondary' ?> mb-3">
        <?= (int)$office['accepting_queue'] ? 'Accepting queue requests' : 'Queue paused' ?>
      </span>
      <h1 class="h2 fw-bold"><?= e($office['name']) ?></h1>
      <p class="text-secondary mb-0"><?= e($office['description'] ?: 'Student services and assistance.') ?></p>
    </div>
  </section>

  <section aria-labelledby="services-heading">
    <h2 class="h4 fw-bold mb-3" id="services-heading">Available services</h2>
    <?php if (!$services): ?>
      <div class="alert alert-info" role="status">
        This office has no active services listed right now. Contact the office directly for help.
      </div>
    <?php else: ?>
      <div class="row g-3 mb-4">
        <?php foreach ($services as $service): ?>
          <div class="col-md-6">
            <article class="feature-card">
              <i class="bi bi-check2-circle" aria-hidden="true"></i>
              <h3 class="h5 fw-bold mt-3"><?= e($service['name']) ?></h3>
              <p class="text-secondary mb-0">Select this service when you join the office queue.</p>
            </article>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if ((int)$office['accepting_queue']): ?>
        <?php if ($user && $user['role'] === 'student'): ?>
          <a class="btn btn-primary btn-lg" href="/CTUQueueManagement/student/index.php?office=<?= (int)$office['id'] ?>#joinQueueForm">Join this queue</a>
        <?php elseif (!$user): ?>
          <a class="btn btn-primary btn-lg" href="/CTUQueueManagement/register.php">Register to join the queue</a>
          <a class="btn btn-outline-primary btn-lg ms-2" href="/CTUQueueManagement/login.php">Already registered? Log in</a>
        <?php else: ?>
          <p class="text-secondary">Only student accounts can join a queue.</p>
        <?php endif; ?>
      <?php else: ?>
        <p class="text-secondary">This office has paused queue requests. Please check again later.</p>
      <?php endif; ?>
    <?php endif; ?>
  </section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
