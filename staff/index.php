<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = require_role('staff');
$officeIds = office_staff_ids($pdo, (int)$user['id']);
if (!$officeIds) {
    http_response_code(403);
    exit('No office assignment is configured for this account.');
}
$selectedId = filter_input(INPUT_GET, 'office', FILTER_VALIDATE_INT);
if (!$selectedId || !in_array($selectedId, $officeIds, true)) {
    $selectedId = $officeIds[0];
}
$placeholders = implode(',', array_fill(0, count($officeIds), '?'));
$officeStmt = $pdo->prepare("SELECT id, name, accepting_queue FROM offices WHERE id IN ($placeholders) ORDER BY name");
$officeStmt->execute($officeIds);
$assignedOffices = $officeStmt->fetchAll();
$filterError = '';
$selectedDate = date('Y-m-d');
$requestedDate = filter_input(INPUT_GET, 'date', FILTER_UNSAFE_RAW);
if ($requestedDate !== null) {
    $dateValue = is_string($requestedDate) ? DateTimeImmutable::createFromFormat('!Y-m-d', $requestedDate) : false;
    if (!$dateValue || $dateValue->format('Y-m-d') !== $requestedDate || $requestedDate > $selectedDate) {
        $filterError = 'Choose a valid date that is today or earlier.';
    } else {
        $selectedDate = $requestedDate;
    }
}
$serviceStmt = $pdo->prepare('SELECT id, name FROM services WHERE office_id = ? ORDER BY name');
$serviceStmt->execute([$selectedId]);
$officeServices = $serviceStmt->fetchAll();
$serviceIds = array_map(static fn($service) => (int)$service['id'], $officeServices);
$rawServiceId = $_GET['service'] ?? null;
$requestedServiceId = $rawServiceId === null || $rawServiceId === ''
    ? null
    : (is_string($rawServiceId) ? filter_var($rawServiceId, FILTER_VALIDATE_INT) : false);
$selectedServiceId = $requestedServiceId && in_array($requestedServiceId, $serviceIds, true)
    ? $requestedServiceId
    : null;
if ($rawServiceId !== null && $rawServiceId !== '' && !$selectedServiceId) {
    $filterError = 'Choose a service offered by this office.';
}

$currentTicket = null;
if ($selectedDate === date('Y-m-d')) {
    $currentSql = "SELECT t.id,t.queue_number,t.status,u.full_name,s.name AS service_name
                   FROM queue_tickets t
                   JOIN users u ON u.id=t.user_id
                   JOIN services s ON s.id=t.service_id
                   WHERE t.office_id=? AND t.service_date=? AND t.status IN ('Called','Serving')";
    $currentParams = [$selectedId, $selectedDate];
    $currentStmt = $pdo->prepare($currentSql . ' ORDER BY t.queue_number LIMIT 1');
    $currentStmt->execute($currentParams);
    $currentTicket = $currentStmt->fetch();
}
$statsSql = "SELECT SUM(status='Completed') completed, SUM(status='Waiting') waiting, SUM(status='No-show') no_show
             FROM queue_tickets WHERE office_id=? AND service_date=?";
$statsParams = [$selectedId, $selectedDate];
if ($selectedServiceId) {
    $statsSql .= ' AND service_id=?';
    $statsParams[] = $selectedServiceId;
}
$statsStmt = $pdo->prepare($statsSql);
$statsStmt->execute($statsParams);
$stats = $statsStmt->fetch();
$completedSql = "SELECT t.queue_number,t.completed_at,u.full_name,s.name AS service_name
                 FROM queue_tickets t
                 JOIN users u ON u.id=t.user_id
                 JOIN services s ON s.id=t.service_id
                 WHERE t.office_id=? AND t.service_date=? AND t.status='Completed'";
$completedParams = [$selectedId, $selectedDate];
if ($selectedServiceId) {
    $completedSql .= ' AND t.service_id=?';
    $completedParams[] = $selectedServiceId;
}
$completedStmt = $pdo->prepare($completedSql . ' ORDER BY t.completed_at DESC LIMIT 10');
$completedStmt->execute($completedParams);
$completedTickets = $completedStmt->fetchAll();
$pageTitle = 'Office staff dashboard';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
  <div><p class="text-primary fw-semibold mb-1">OFFICE STAFF</p><h1 class="h2 fw-bold mb-0">Queue management</h1></div>
  <?php if (count($assignedOffices) > 1): ?><form method="get"><input type="hidden" name="date" value="<?= e($selectedDate) ?>"><?php if ($selectedServiceId): ?><input type="hidden" name="service" value="<?= (int)$selectedServiceId ?>"><?php endif; ?><label class="visually-hidden" for="officePicker">Assigned office</label><select class="form-select" id="officePicker" name="office" onchange="this.form.submit()"><?php foreach ($assignedOffices as $item): ?><option value="<?= (int)$item['id'] ?>" <?= (int)$item['id'] === (int)$selectedId ? 'selected' : '' ?>><?= e($item['name']) ?></option><?php endforeach; ?></select></form><?php endif; ?>
</div>
<?php if ($filterError): ?><div class="alert alert-warning" role="alert"><?= e($filterError) ?></div><?php endif; ?>
<form method="get" class="card border-0 shadow-sm mb-4">
  <div class="card-body row g-3 align-items-end">
    <input type="hidden" name="office" value="<?= (int)$selectedId ?>">
    <div class="col-sm-5 col-md-4"><label class="form-label" for="queueDate">Queue date</label><input class="form-control" type="date" id="queueDate" name="date" max="<?= e(date('Y-m-d')) ?>" value="<?= e($selectedDate) ?>"></div>
    <div class="col-sm-5 col-md-4"><label class="form-label" for="queueService">Service</label><select class="form-select" id="queueService" name="service"><option value="">All services</option><?php foreach ($officeServices as $service): ?><option value="<?= (int)$service['id'] ?>" <?= $selectedServiceId === (int)$service['id'] ? 'selected' : '' ?>><?= e($service['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-sm-2"><button class="btn btn-outline-primary w-100" type="submit">Apply filters</button></div>
  </div>
</form>
<?php $selectedOffice = array_values(array_filter($assignedOffices, static fn($o) => (int)$o['id'] === (int)$selectedId))[0]; ?>
<div class="row g-3 mb-4">
  <div class="col-md-6"><div class="card border-0 shadow-sm h-100"><div class="card-body p-4 d-flex justify-content-between align-items-center"><div><div class="small text-secondary">OFFICE</div><div class="h4 fw-bold mb-0"><?= e($selectedOffice['name']) ?></div><div id="officeStatus" class="small mt-1"><?= (int)$selectedOffice['accepting_queue'] ? 'Accepting tickets' : 'Queue paused' ?></div></div><button class="btn btn-outline-primary" data-staff-action="<?= (int)$selectedOffice['accepting_queue'] ? 'pause' : 'resume' ?>" data-office-id="<?= (int)$selectedId ?>"><?= (int)$selectedOffice['accepting_queue'] ? 'Pause queue' : 'Resume queue' ?></button></div></div></div>
  <div class="col-md-6"><div class="card border-0 shadow-sm h-100"><div class="card-body p-4"><div class="small text-secondary mb-2"><?= e($selectedDate) ?></div><div class="d-flex gap-4"><div><strong class="h4"><?= (int)($stats['waiting'] ?? 0) ?></strong><div class="small text-secondary">Waiting</div></div><div><strong class="h4"><?= (int)($stats['completed'] ?? 0) ?></strong><div class="small text-secondary">Completed</div></div><div><strong class="h4"><?= (int)($stats['no_show'] ?? 0) ?></strong><div class="small text-secondary">No-show</div></div></div></div></div></div>
</div>
<div class="card border-0 shadow-sm mb-4"><div class="card-body p-4">
  <h2 class="h5 fw-bold">Currently called / serving</h2>
  <div id="currentTicket" class="current-ticket mb-3"><?= $currentTicket ? '<strong>' . e(strtoupper(substr($selectedOffice['name'], 0, 2)) . '-' . str_pad((string)$currentTicket['queue_number'], 3, '0', STR_PAD_LEFT)) . '</strong> · ' . e($currentTicket['full_name']) . ' · ' . e($currentTicket['service_name']) . ' · ' . e($currentTicket['status']) : ($selectedDate === date('Y-m-d') ? 'No student is currently called.' : 'Live queue actions are available for today only.') ?></div>
  <div class="d-flex flex-wrap gap-2">
    <?php if ($selectedDate === date('Y-m-d') && !$currentTicket): ?><button class="btn btn-primary" data-staff-action="call" data-office-id="<?= (int)$selectedId ?>" <?= $selectedServiceId ? 'data-service-id="' . (int)$selectedServiceId . '"' : '' ?>><i class="bi bi-megaphone me-1"></i>Call next</button><?php endif; ?>
    <?php if ($currentTicket && $selectedDate === date('Y-m-d')): ?>
      <?php if ($currentTicket['status'] === 'Called'): ?><button class="btn btn-outline-primary" data-staff-action="serving" data-office-id="<?= (int)$selectedId ?>" data-ticket-id="<?= (int)$currentTicket['id'] ?>">Mark serving</button><?php endif; ?>
      <?php if ($currentTicket['status'] === 'Serving'): ?><button class="btn btn-success" data-staff-action="complete" data-office-id="<?= (int)$selectedId ?>" data-ticket-id="<?= (int)$currentTicket['id'] ?>">Complete</button><?php endif; ?>
      <?php if ($currentTicket['status'] === 'Called'): ?><button class="btn btn-outline-secondary" data-staff-action="skip" data-office-id="<?= (int)$selectedId ?>" data-ticket-id="<?= (int)$currentTicket['id'] ?>">Skip to end</button><button class="btn btn-outline-danger" data-staff-action="no-show" data-office-id="<?= (int)$selectedId ?>" data-ticket-id="<?= (int)$currentTicket['id'] ?>">Mark no-show</button><?php endif; ?>
    <?php endif; ?>
  </div><div id="staffMessage" class="mt-3" role="status" aria-live="polite"></div>
</div></div>
<section class="card border-0 shadow-sm"><div class="card-body p-4"><div class="d-flex justify-content-between mb-3"><h2 class="h5 fw-bold mb-0">Queue tickets</h2><span class="small text-secondary">Refreshes every 15 seconds</span></div>
  <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Queue</th><th>Student</th><th>Service</th><th>Status</th><th>Joined</th></tr></thead><tbody id="staffQueue" data-queue-role="staff" data-office-id="<?= (int)$selectedId ?>" data-date="<?= e($selectedDate) ?>" data-service-id="<?= (int)($selectedServiceId ?? 0) ?>"><tr><td colspan="5" class="text-secondary">Loading queue…</td></tr></tbody></table></div>
</div></section>
<section class="card border-0 shadow-sm mt-4"><div class="card-body p-4"><h2 class="h5 fw-bold">Completed transactions · <?= e($selectedDate) ?></h2><?php if (!$completedTickets): ?><p class="text-secondary mb-0">No completed transactions match these filters.</p><?php else: ?><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Queue</th><th>Student</th><th>Service</th><th>Completed at</th></tr></thead><tbody><?php foreach ($completedTickets as $ticket): ?><tr><td><?= e(strtoupper(substr($selectedOffice['name'], 0, 2)) . '-' . str_pad((string)$ticket['queue_number'], 3, '0', STR_PAD_LEFT)) ?></td><td><?= e($ticket['full_name']) ?></td><td><?= e($ticket['service_name']) ?></td><td><?= e($ticket['completed_at']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div></section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
