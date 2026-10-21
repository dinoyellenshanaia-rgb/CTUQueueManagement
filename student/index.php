<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = require_role('student');
$pageTitle = 'Student dashboard';
$offices = $pdo->query(
    'SELECT o.id, o.name, o.accepting_queue, s.id AS service_id, s.name AS service_name
     FROM offices o LEFT JOIN services s ON s.office_id = o.id AND s.is_active = 1
     WHERE o.is_active = 1 ORDER BY o.id, s.name'
)->fetchAll();
$historyStmt = $pdo->prepare(
    "SELECT t.queue_number, t.status, t.service_date, o.name AS office_name, s.name AS service_name
     FROM queue_tickets t JOIN offices o ON o.id = t.office_id JOIN services s ON s.id = t.service_id
     WHERE t.user_id = ? AND t.status IN ('Completed','Cancelled','No-show')
     ORDER BY t.created_at DESC LIMIT 8"
);
$historyStmt->execute([$user['id']]);
$history = $historyStmt->fetchAll();
$noticeStmt = $pdo->prepare('SELECT message, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 8');
$noticeStmt->execute([$user['id']]);
$notifications = $noticeStmt->fetchAll();
require __DIR__ . '/../includes/header.php';
$groupedOffices = [];
$requestedOffice = filter_input(INPUT_GET, 'office', FILTER_VALIDATE_INT);
foreach ($offices as $row) {
    if (!isset($groupedOffices[$row['id']])) {
        $groupedOffices[$row['id']] = ['id' => $row['id'], 'name' => $row['name'], 'accepting_queue' => $row['accepting_queue'], 'services' => []];
    }
    if ($row['service_id']) {
        $groupedOffices[$row['id']]['services'][] = ['id' => $row['service_id'], 'name' => $row['service_name']];
    }
}
?>
<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-4">
  <div><p class="text-primary fw-semibold mb-1">STUDENT DASHBOARD</p><h1 class="h2 fw-bold mb-1">Welcome, <?= e($user['full_name']) ?></h1><p class="text-secondary mb-0">Manage your campus service visits here.</p></div>
</div>
<div class="row g-4">
  <div class="col-lg-8">
    <section class="card border-0 shadow-sm mb-4">
      <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-center mb-3"><div><h2 class="h5 fw-bold mb-1">Join a queue</h2><p class="small text-secondary mb-0">You may hold one active ticket per office.</p></div><i class="bi bi-ticket-perforated fs-3 text-primary"></i></div>
        <form id="joinQueueForm" class="row g-3">
          <div class="col-md-5"><label for="officeSelect" class="form-label">Office</label><select class="form-select" id="officeSelect" name="office_id" required><option value="">Choose office</option>
            <?php foreach ($groupedOffices as $office): ?><option value="<?= (int)$office['id'] ?>" data-accepting="<?= (int)$office['accepting_queue'] ?>" data-services="<?= e(json_encode($office['services'], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>" <?= $requestedOffice === (int)$office['id'] ? 'selected' : '' ?>><?= e($office['name']) ?><?= !(int)$office['accepting_queue'] ? ' — paused' : '' ?></option><?php endforeach; ?>
          </select></div>
          <div class="col-md-5"><label for="serviceSelect" class="form-label">Service</label><select class="form-select" id="serviceSelect" name="service_id" required disabled><option value="">Choose a service</option></select></div>
          <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100" id="joinButton" type="submit">Join</button></div>
        </form>
        <div id="joinMessage" class="mt-3" role="status" aria-live="polite"></div>
      </div>
    </section>
    <section class="card border-0 shadow-sm mb-4">
      <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h5 fw-bold mb-0">Your active tickets</h2><span class="small text-secondary">Updates automatically</span></div>
        <div id="activeTickets" class="row g-3" data-queue-role="student"><div class="col-12 text-secondary">Loading your tickets…</div></div>
      </div>
    </section>
    <section class="card border-0 shadow-sm">
      <div class="card-body p-4"><h2 class="h5 fw-bold mb-3">Recent queue history</h2>
        <?php if (!$history): ?><p class="text-secondary mb-0">Your completed or closed tickets will appear here.</p><?php else: ?>
        <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Office</th><th>Service</th><th>Queue</th><th>Date</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($history as $item): ?><tr><td><?= e($item['office_name']) ?></td><td><?= e($item['service_name']) ?></td><td><?= e(strtoupper(substr($item['office_name'], 0, 2)) . '-' . str_pad((string)$item['queue_number'], 3, '0', STR_PAD_LEFT)) ?></td><td><?= e($item['service_date']) ?></td><td><span class="badge text-bg-light"><?= e($item['status']) ?></span></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
      </div>
    </section>
  </div>
  <aside class="col-lg-4">
    <section class="card border-0 shadow-sm"><div class="card-body p-4"><h2 class="h5 fw-bold mb-3"><i class="bi bi-bell text-primary me-2"></i>Notifications</h2>
      <?php if (!$notifications): ?><p class="text-secondary mb-0">Queue updates will appear here.</p><?php endif; ?><ul class="list-unstyled notification-list mb-0"><?php foreach ($notifications as $note): ?><li><p class="mb-1"><?= e($note['message']) ?></p><span class="small text-secondary"><?= e($note['created_at']) ?></span></li><?php endforeach; ?></ul>
    </div></section>
  </aside>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
