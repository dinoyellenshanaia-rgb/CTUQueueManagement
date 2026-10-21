<?php
require_once __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
$user = require_role('admin');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method not allowed.']));
}
verify_csrf();
$action = (string)($_POST['action'] ?? '');
try {
    if ($action === 'create-staff') {
        $name = trim((string)($_POST['full_name'] ?? ''));
        $email = filter_var(trim((string)($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $password = (string)($_POST['password'] ?? '');
        $officeId = filter_input(INPUT_POST, 'office_id', FILTER_VALIDATE_INT);
        if (mb_strlen($name) < 2 || !$email || strlen($password) < 12 || !$officeId) {
            http_response_code(422);
            exit(json_encode(['error' => 'Provide valid staff details, a 12-character password, and an office.']));
        }
        $pdo->beginTransaction();
        $insert = $pdo->prepare("INSERT INTO users (full_name,email,password_hash,role) VALUES (?,?,?,'staff')");
        $insert->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        $staffId = (int)$pdo->lastInsertId();
        $assign = $pdo->prepare('INSERT INTO staff_offices (user_id,office_id) SELECT ?,id FROM offices WHERE id=? AND is_active=1');
        $assign->execute([$staffId, $officeId]);
        if ($assign->rowCount() !== 1) {
            throw new RuntimeException('The selected office is not available.');
        }
        log_activity($pdo, (int)$user['id'], 'staff_created', 'Staff user ID ' . $staffId);
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Staff account created and assigned. Reload the page to update the lists.']);
        exit;
    }
    if ($action === 'assign-staff') {
        $staffId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        $officeId = filter_input(INPUT_POST, 'office_id', FILTER_VALIDATE_INT);
        $stmt = $pdo->prepare("INSERT IGNORE INTO staff_offices (user_id,office_id) SELECT u.id,o.id FROM users u JOIN offices o ON o.id=? WHERE u.id=? AND u.role='staff' AND o.is_active=1");
        $stmt->execute([$officeId, $staffId]);
        if ($stmt->rowCount() !== 1) {
            http_response_code(422);
            exit(json_encode(['error' => 'Select an active staff account and office, and avoid duplicate assignments.']));
        }
        log_activity($pdo, (int)$user['id'], 'staff_assigned', 'Staff ID ' . $staffId . ', office ID ' . $officeId);
        echo json_encode(['success' => true, 'message' => 'Staff assignment saved.']);
        exit;
    }
    if ($action === 'add-service') {
        $officeId = filter_input(INPUT_POST, 'office_id', FILTER_VALIDATE_INT);
        $name = trim((string)($_POST['name'] ?? ''));
        if (!$officeId || mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            http_response_code(422);
            exit(json_encode(['error' => 'Select an office and enter a service name (2–120 characters).']));
        }
        $stmt = $pdo->prepare('INSERT INTO services (office_id,name) SELECT id,? FROM offices WHERE id=?');
        $stmt->execute([$name, $officeId]);
        log_activity($pdo, (int)$user['id'], 'service_created', 'Service ' . $name . ', office ID ' . $officeId);
        echo json_encode(['success' => true, 'message' => 'Service added. Reload the page to update the list.']);
        exit;
    }
    if ($action === 'save-schedule') {
        $officeId = filter_input(INPUT_POST, 'office_id', FILTER_VALIDATE_INT);
        $weekday = filter_input(INPUT_POST, 'weekday', FILTER_VALIDATE_INT);
        $isOpen = filter_input(INPUT_POST, 'is_open', FILTER_VALIDATE_INT);
        $opensAt = trim((string)($_POST['opens_at'] ?? ''));
        $closesAt = trim((string)($_POST['closes_at'] ?? ''));
        if (!$officeId || !is_int($weekday) || $weekday < 0 || $weekday > 6 || !in_array($isOpen, [0, 1], true)) {
            http_response_code(422);
            exit(json_encode(['error' => 'Provide a valid office, weekday, and open/closed selection.']));
        }
        if ($isOpen && (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $opensAt) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $closesAt) || $opensAt >= $closesAt)) {
            http_response_code(422);
            exit(json_encode(['error' => 'For an open day, provide valid times and ensure closing is after opening.']));
        }
        $stmt = $pdo->prepare(
            'INSERT INTO office_schedules (office_id,weekday,opens_at,closes_at,is_open) VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE opens_at=VALUES(opens_at),closes_at=VALUES(closes_at),is_open=VALUES(is_open)'
        );
        $stmt->execute([$officeId, $weekday, $isOpen ? $opensAt : null, $isOpen ? $closesAt : null, $isOpen]);
        log_activity($pdo, (int)$user['id'], 'office_schedule_changed', 'Office ID ' . $officeId . ', weekday ' . $weekday);
        echo json_encode(['success' => true, 'message' => 'Office schedule saved.']);
        exit;
    }
    if ($action === 'toggle-user') {
        $targetId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        if (!$targetId || $targetId === (int)$user['id']) {
            http_response_code(422);
            exit(json_encode(['error' => 'This account cannot be changed.']));
        }
        $stmt = $pdo->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = ?');
        $stmt->execute([$targetId]);
        if ($stmt->rowCount() !== 1) {
            http_response_code(404);
            exit(json_encode(['error' => 'Account not found.']));
        }
        log_activity($pdo, (int)$user['id'], 'user_access_changed', 'User ID ' . $targetId);
        echo json_encode(['success' => true, 'message' => 'Account access updated. Reload to see the new state.']);
        exit;
    }
    if ($action === 'toggle-office') {
        $officeId = filter_input(INPUT_POST, 'office_id', FILTER_VALIDATE_INT);
        $stmt = $pdo->prepare('UPDATE offices SET is_active = 1 - is_active WHERE id = ?');
        $stmt->execute([$officeId]);
        if ($stmt->rowCount() !== 1) {
            http_response_code(404);
            exit(json_encode(['error' => 'Office not found.']));
        }
        log_activity($pdo, (int)$user['id'], 'office_access_changed', 'Office ID ' . $officeId);
        echo json_encode(['success' => true, 'message' => 'Office status updated. Reload to see the new state.']);
        exit;
    }
    if ($action === 'toggle-service') {
        $serviceId = filter_input(INPUT_POST, 'service_id', FILTER_VALIDATE_INT);
        $stmt = $pdo->prepare('UPDATE services SET is_active = 1 - is_active WHERE id = ?');
        $stmt->execute([$serviceId]);
        if ($stmt->rowCount() !== 1) {
            http_response_code(404);
            exit(json_encode(['error' => 'Service not found.']));
        }
        log_activity($pdo, (int)$user['id'], 'service_access_changed', 'Service ID ' . $serviceId);
        echo json_encode(['success' => true, 'message' => 'Service status updated.']);
        exit;
    }
    http_response_code(422);
    echo json_encode(['error' => 'Unknown administration action.']);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($exception instanceof PDOException && $exception->getCode() === '23000') {
        http_response_code(409);
        echo json_encode(['error' => 'That email or service already exists.']);
    } else {
        error_log('Admin action failed: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'The administration change could not be saved.']);
    }
}
