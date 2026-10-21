<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = require_role('admin');
$pageTitle = 'Administrator dashboard';
$metrics = [
    'students' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn(),
    'active' => (int)$pdo->query("SELECT COUNT(*) FROM queue_tickets WHERE status IN ('Waiting','Called','Serving') AND service_date=CURRENT_DATE()")->fetchColumn(),
    'waiting' => (int)$pdo->query("SELECT COUNT(*) FROM queue_tickets WHERE status='Waiting' AND service_date=CURRENT_DATE()")->fetchColumn(),
];
$metrics['completed'] = (int)$pdo->query("SELECT COUNT(*) FROM queue_tickets WHERE status='Completed' AND service_date=CURRENT_DATE()")->fetchColumn();
$offices = $pdo->query(
    "SELECT o.id,o.name,o.is_active,o.accepting_queue,
      SUM(t.service_date=CURRENT_DATE() AND t.status='Waiting') AS waiting,
      SUM(t.service_date=CURRENT_DATE() AND t.status='Completed') AS completed,
      SUM(t.service_date=CURRENT_DATE() AND t.status='No-show') AS no_show
     FROM offices o LEFT JOIN queue_tickets t ON t.office_id=o.id
     GROUP BY o.id ORDER BY o.id"
)->fetchAll();
$users = $pdo->query('SELECT id,full_name,email,role,is_active FROM users ORDER BY created_at DESC LIMIT 30')->fetchAll();
$services = $pdo->query('SELECT s.id,s.name,s.is_active,o.name AS office_name FROM services s JOIN offices o ON o.id=s.office_id ORDER BY o.id,s.name')->fetchAll();
$staff = $pdo->query("SELECT id,full_name,email FROM users WHERE role='staff' ORDER BY full_name")->fetchAll();
$allOffices = $pdo->query('SELECT id,name FROM offices ORDER BY name')->fetchAll();
$activeOffices = $pdo->query('SELECT id,name FROM offices WHERE is_active=1 ORDER BY name')->fetchAll();
$assignments = $pdo->query('SELECT so.user_id,so.office_id FROM staff_offices so')->fetchAll();
$activity = $pdo->query('SELECT a.action,a.details,a.created_at,u.full_name FROM activity_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.created_at DESC LIMIT 10')->fetchAll();
$recentTickets = $pdo->query(
    "SELECT t.queue_number,t.service_date,t.status,o.name AS office_name,s.name AS service_name,u.full_name
     FROM queue_tickets t JOIN offices o ON o.id=t.office_id JOIN services s ON s.id=t.service_id JOIN users u ON u.id=t.user_id
     ORDER BY t.created_at DESC LIMIT 20"
)->fetchAll();
require __DIR__ . '/../includes/header.php';
?>
<div class="mb-4"><p class="text-primary fw-semibold mb-1">ADMINISTRATION</p><h1 class="h2 fw-bold">Campus overview</h1><p class="text-secondary mb-0">Monitor services, queues, accounts, and recent activity.</p></div>
<div class="row g-3 mb-4">
<?php foreach (['students'=>'Registered students','active'=>'Active tickets','waiting'=>'Waiting today','completed'=>'Completed today'] as $key=>$label): ?><div class="col-6 col-xl-3"><div class="metric-card"><div class="small text-secondary"><?= e($label) ?></div><div class="display-6 fw-bold"><?= (int)$metrics[$key] ?></div></div></div><?php endforeach; ?>
</div>
<section class="mb-5"><h2 class="h4 fw-bold mb-3">Office performance</h2><div class="row g-3">
<?php foreach ($offices as $office): ?><div class="col-md-6 col-xl-4"><div class="card border-0 shadow-sm h-100"><div class="card-body p-4"><div class="d-flex justify-content-between"><h3 class="h5 fw-bold"><?= e($office['name']) ?></h3><span class="badge <?= (int)$office['is_active'] ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= (int)$office['is_active'] ? 'Active' : 'Inactive' ?></span></div><div class="small text-secondary">Queue <?= (int)$office['accepting_queue'] ? 'open' : 'paused' ?></div><div class="d-flex gap-3 mt-3"><span>Waiting <strong><?= (int)$office['waiting'] ?></strong></span><span>Completed <strong><?= (int)$office['completed'] ?></strong></span><span>No-show <strong><?= (int)$office['no_show'] ?></strong></span></div></div></div></div><?php endforeach; ?>
</div></section>
<div id="adminMessage" role="status" aria-live="polite"></div>
<div class="row g-4">
  <div class="col-xl-6">
    <section class="card border-0 shadow-sm mb-4"><div class="card-body p-4"><h2 class="h5 fw-bold">Create staff account</h2><form data-admin-form="create-staff" class="row g-2">
      <div class="col-md-6"><label class="form-label" for="staffName">Name</label><input class="form-control" id="staffName" name="full_name" required></div><div class="col-md-6"><label class="form-label" for="staffEmail">Email</label><input class="form-control" id="staffEmail" name="email" type="email" required></div>
      <div class="col-md-6"><label class="form-label" for="staffPassword">Temporary password</label><input class="form-control" id="staffPassword" name="password" type="password" minlength="12" required></div><div class="col-md-6"><label class="form-label" for="staffOffice">Assigned office</label><select class="form-select" id="staffOffice" name="office_id" required><option value="">Select office</option><?php foreach ($activeOffices as $office): ?><option value="<?= (int)$office['id'] ?>"><?= e($office['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-12"><button class="btn btn-primary">Create staff account</button></div>
    </form></div></section>
    <section class="card border-0 shadow-sm mb-4"><div class="card-body p-4"><h2 class="h5 fw-bold">Assign staff to office</h2><form data-admin-form="assign-staff" class="row g-2">
      <div class="col-md-6"><label class="form-label" for="assignedStaff">Staff member</label><select class="form-select" id="assignedStaff" name="user_id" required><option value="">Select staff</option><?php foreach ($staff as $person): ?><option value="<?= (int)$person['id'] ?>"><?= e($person['full_name']) ?> (<?= e($person['email']) ?>)</option><?php endforeach; ?></select></div>
      <div class="col-md-4"><label class="form-label" for="assignOffice">Office</label><select class="form-select" id="assignOffice" name="office_id" required><option value="">Select office</option><?php foreach ($activeOffices as $office): ?><option value="<?= (int)$office['id'] ?>"><?= e($office['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-2 d-flex align-items-end"><button class="btn btn-outline-primary">Assign</button></div>
    </form></div></section>
    <section class="card border-0 shadow-sm mb-4"><div class="card-body p-4"><h2 class="h5 fw-bold">Add office service</h2><form data-admin-form="add-service" class="row g-2"><div class="col-md-5"><label class="form-label" for="serviceOffice">Office</label><select class="form-select" name="office_id" id="serviceOffice" required><?php foreach ($allOffices as $office): ?><option value="<?= (int)$office['id'] ?>"><?= e($office['name']) ?></option><?php endforeach; ?></select></div><div class="col-md-5"><label class="form-label" for="serviceName">Service name</label><input class="form-control" name="name" id="serviceName" maxlength="120" required></div><div class="col-md-2 d-flex align-items-end"><button class="btn btn-outline-primary">Add</button></div></form></div></section>
    <section class="card border-0 shadow-sm mb-4"><div class="card-body p-4"><h2 class="h5 fw-bold">Set office schedule</h2><p class="small text-secondary">Schedules are informational; no default campus hours are assumed.</p><form data-admin-form="save-schedule" class="row g-2">
      <div class="col-md-4"><label class="form-label" for="scheduleOffice">Office</label><select class="form-select" name="office_id" id="scheduleOffice" required><?php foreach ($allOffices as $office): ?><option value="<?= (int)$office['id'] ?>"><?= e($office['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-3"><label class="form-label" for="scheduleWeekday">Weekday</label><select class="form-select" name="weekday" id="scheduleWeekday"><?php foreach (['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'] as $day => $name): ?><option value="<?= $day ?>"><?= e($name) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-2"><label class="form-label" for="opensAt">Opens</label><input class="form-control" type="time" name="opens_at" id="opensAt"></div><div class="col-md-2"><label class="form-label" for="closesAt">Closes</label><input class="form-control" type="time" name="closes_at" id="closesAt"></div>
      <div class="col-md-3"><label class="form-label" for="scheduleOpen">Open that day?</label><select class="form-select" name="is_open" id="scheduleOpen"><option value="1">Open</option><option value="0">Closed</option></select></div>
      <div class="col-md-3 d-flex align-items-end"><button class="btn btn-outline-primary">Save schedule</button></div>
    </form></div></section>
  </div>
  <div class="col-xl-6">
    <section class="card border-0 shadow-sm mb-4"><div class="card-body p-4"><h2 class="h5 fw-bold">Manage accounts</h2><div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Name / email</th><th>Role</th><th>Access</th><th></th></tr></thead><tbody>
    <?php foreach ($users as $account): ?><tr><td><?= e($account['full_name']) ?><div class="small text-secondary"><?= e($account['email']) ?></div></td><td><?= e(ucfirst($account['role'])) ?></td><td><?= (int)$account['is_active'] ? 'Active' : 'Inactive' ?></td><td><?php if ((int)$account['id'] !== (int)$user['id']): ?><button class="btn btn-sm btn-outline-secondary" data-admin-action="toggle-user" data-user-id="<?= (int)$account['id'] ?>" data-active="<?= (int)$account['is_active'] ?>"><?= (int)$account['is_active'] ? 'Deactivate' : 'Activate' ?></button><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div></section>
    <section class="card border-0 shadow-sm mb-4"><div class="card-body p-4"><h2 class="h5 fw-bold">Offices and services</h2>
      <?php foreach ($offices as $office): ?><div class="d-flex flex-wrap justify-content-between align-items-center border-bottom py-2"><div><strong><?= e($office['name']) ?></strong><div class="small text-secondary"><?= (int)$office['is_active'] ? 'Active' : 'Inactive' ?> · queue <?= (int)$office['accepting_queue'] ? 'open' : 'paused' ?></div></div><button class="btn btn-sm btn-outline-primary" data-admin-action="toggle-office" data-office-id="<?= (int)$office['id'] ?>" data-active="<?= (int)$office['is_active'] ?>"><?= (int)$office['is_active'] ? 'Deactivate' : 'Activate' ?></button></div><?php endforeach; ?>
      <?php foreach ($services as $service): ?><div class="d-flex justify-content-between align-items-center py-2"><span><?= e($service['office_name']) ?> · <?= e($service['name']) ?> <span class="small text-secondary"><?= (int)$service['is_active'] ? '' : '(inactive)' ?></span></span><button class="btn btn-sm btn-outline-secondary" data-admin-action="toggle-service" data-service-id="<?= (int)$service['id'] ?>" data-active="<?= (int)$service['is_active'] ?>"><?= (int)$service['is_active'] ? 'Disable' : 'Enable' ?></button></div><?php endforeach; ?>
    </div></section>
  </div>
</div>
<section class="card border-0 shadow-sm mt-4"><div class="card-body p-4"><h2 class="h5 fw-bold">Recent activity</h2><?php if (!$activity): ?><p class="text-secondary mb-0">No activity recorded yet.</p><?php else: ?><div class="table-responsive"><table class="table table-sm"><thead><tr><th>When</th><th>Account</th><th>Action</th><th>Details</th></tr></thead><tbody><?php foreach ($activity as $item): ?><tr><td><?= e($item['created_at']) ?></td><td><?= e($item['full_name'] ?? 'System') ?></td><td><?= e($item['action']) ?></td><td><?= e($item['details']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div></section>
<section class="card border-0 shadow-sm mt-4"><div class="card-body p-4"><h2 class="h5 fw-bold">Recent queue records</h2><div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Date</th><th>Student</th><th>Office</th><th>Service</th><th>Queue</th><th>Status</th></tr></thead><tbody><?php foreach ($recentTickets as $ticket): ?><tr><td><?= e($ticket['service_date']) ?></td><td><?= e($ticket['full_name']) ?></td><td><?= e($ticket['office_name']) ?></td><td><?= e($ticket['service_name']) ?></td><td><?= e(strtoupper(substr($ticket['office_name'], 0, 2)) . '-' . str_pad((string)$ticket['queue_number'], 3, '0', STR_PAD_LEFT)) ?></td><td><?= e($ticket['status']) ?></td></tr><?php endforeach; ?></tbody></table></div></div></section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
