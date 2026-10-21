<?php
require_once __DIR__ . '/includes/bootstrap.php';

$databaseError = false;
$offices = [];
$icons = [
    'Clinic' => 'bi-heart-pulse',
    'Registrar' => 'bi-journal-text',
    'MIS' => 'bi-laptop',
    'SAO' => 'bi-mortarboard',
    'Cashier' => 'bi-credit-card',
];

try {
    $statement = $pdo->query(
        'SELECT o.id, o.name, o.description, o.accepting_queue,
                s.id AS service_id, s.name AS service_name
         FROM offices o
         LEFT JOIN services s ON s.office_id = o.id AND s.is_active = 1
         WHERE o.is_active = 1
         ORDER BY o.id, s.name'
    );

    foreach ($statement->fetchAll() as $row) {
        $officeId = (int)$row['id'];
        if (!isset($offices[$officeId])) {
            $offices[$officeId] = [
                'id' => $officeId,
                'name' => $row['name'],
                'description' => $row['description'] ?: 'Student services and assistance.',
                'accepting_queue' => (int)$row['accepting_queue'],
                'services' => [],
            ];
        }
        if ($row['service_id'] !== null) {
            $offices[$officeId]['services'][] = [
                'id' => (int)$row['service_id'],
                'name' => $row['service_name'],
            ];
        }
    }
} catch (PDOException $exception) {
    error_log('Homepage offices could not be loaded: ' . $exception->getMessage());
    $databaseError = true;
}

$user = current_user();
$pageTitle = 'Student services and queue management';
require __DIR__ . '/includes/header.php';
?>
<section class="hero rounded-4 p-4 p-lg-5 mb-5">
  <div class="row align-items-center g-4">
    <div class="col-lg-8">
      <span class="badge rounded-pill text-bg-light text-primary px-3 py-2 mb-3">CTU DAANBANTAYAN CAMPUS</span>
      <h1 class="display-5 fw-bold mb-3">Campus services, with less waiting.</h1>
      <p class="lead mb-4">Find the right office, choose a service, and follow your queue ticket from your phone or computer.</p>
      <?php if ($user && $user['role'] === 'student'): ?>
        <a class="btn btn-primary btn-lg px-4" href="/CTUQueueManagement/student/index.php">Go to your queue <i class="bi bi-arrow-right ms-1"></i></a>
      <?php elseif (!$user): ?>
        <a class="btn btn-primary btn-lg px-4" href="/CTUQueueManagement/register.php">Get started <i class="bi bi-arrow-right ms-1"></i></a>
        <a class="btn btn-outline-primary btn-lg px-4 ms-sm-2 mt-2 mt-sm-0" href="/CTUQueueManagement/login.php">Log in</a>
      <?php else: ?>
        <a class="btn btn-primary btn-lg px-4" href="/CTUQueueManagement/dashboard.php">Open your dashboard <i class="bi bi-arrow-right ms-1"></i></a>
      <?php endif; ?>
    </div>
    <div class="col-lg-4 d-none d-lg-block" aria-hidden="true">
      <div class="hero-icon"><i class="bi bi-ticket-perforated"></i></div>
    </div>
  </div>
</section>

<section aria-labelledby="offices-heading">
  <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
    <div>
      <p class="text-primary fw-semibold mb-1">STUDENT SERVICES</p>
      <h2 class="h2 fw-bold mb-1" id="offices-heading">Choose an office</h2>
      <p class="text-secondary mb-0">Explore available services and queue status.</p>
    </div>
  </div>

  <?php if ($databaseError): ?>
    <div class="alert alert-danger" role="alert">
      Campus offices are temporarily unavailable. Please try again later or contact the campus administrator.
    </div>
  <?php elseif (!$offices): ?>
    <div class="alert alert-info" role="status">
      No active offices are currently listed. Please check back later.
    </div>
  <?php else: ?>
    <div class="row g-3 g-lg-4">
      <?php foreach ($offices as $office): ?>
        <div class="col-sm-6 col-xl-4">
          <article class="office-card h-100">
            <div class="d-flex justify-content-between align-items-start mb-3">
              <div class="office-icon" aria-hidden="true">
                <i class="bi <?= e($icons[$office['name']] ?? 'bi-building') ?>"></i>
              </div>
              <span class="badge <?= $office['accepting_queue'] ? 'text-bg-success' : 'text-bg-secondary' ?>">
                <?= $office['accepting_queue'] ? 'Accepting queue requests' : 'Queue paused' ?>
              </span>
            </div>
            <h3 class="h4 fw-bold mb-2"><?= e($office['name']) ?></h3>
            <p class="text-secondary mb-3"><?= e($office['description']) ?></p>
            <?php if ($office['services']): ?>
              <p class="small fw-semibold mb-2">Available services</p>
              <ul class="office-services">
                <?php foreach (array_slice($office['services'], 0, 3) as $service): ?>
                  <li><?= e($service['name']) ?></li>
                <?php endforeach; ?>
                <?php if (count($office['services']) > 3): ?>
                  <li class="text-secondary">and <?= count($office['services']) - 3 ?> more</li>
                <?php endif; ?>
              </ul>
            <?php else: ?>
              <p class="small text-secondary mb-3">Services are not listed yet. Contact the office for assistance.</p>
            <?php endif; ?>
            <a class="btn btn-outline-primary mt-auto" href="/CTUQueueManagement/office.php?id=<?= (int)$office['id'] ?>">
              View services <i class="bi bi-arrow-right ms-1"></i>
            </a>
          </article>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
