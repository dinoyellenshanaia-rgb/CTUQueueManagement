<?php
require_once __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
$user = require_role('student');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method not allowed.']));
}
verify_csrf();
$officeId = filter_input(INPUT_POST, 'office_id', FILTER_VALIDATE_INT);
$serviceId = filter_input(INPUT_POST, 'service_id', FILTER_VALIDATE_INT);
if (!$officeId || !$serviceId) {
    http_response_code(422);
    exit(json_encode(['error' => 'Select a valid office and service.']));
}

try {
    $pdo->beginTransaction();
    $officeStmt = $pdo->prepare('SELECT id, name, accepting_queue FROM offices WHERE id = ? AND is_active = 1 FOR UPDATE');
    $officeStmt->execute([$officeId]);
    $office = $officeStmt->fetch();
    if (!$office || !(int)$office['accepting_queue']) {
        $pdo->rollBack();
        http_response_code(409);
        exit(json_encode(['error' => 'This office is not accepting queue tickets right now.']));
    }
    $scheduleStmt = $pdo->prepare(
        'SELECT is_open, opens_at, closes_at, CURRENT_TIME() AS server_time FROM office_schedules
         WHERE office_id = ? AND weekday = DAYOFWEEK(CURRENT_DATE()) - 1'
    );
    $scheduleStmt->execute([$officeId]);
    $schedule = $scheduleStmt->fetch();
    if ($schedule && (!(int)$schedule['is_open'] || $schedule['opens_at'] === null ||
        $schedule['closes_at'] === null || $schedule['server_time'] < $schedule['opens_at'] ||
        $schedule['server_time'] >= $schedule['closes_at'])) {
        $pdo->rollBack();
        http_response_code(409);
        exit(json_encode(['error' => 'This office is outside its configured service schedule.']));
    }
    $serviceStmt = $pdo->prepare('SELECT id FROM services WHERE id = ? AND office_id = ? AND is_active = 1');
    $serviceStmt->execute([$serviceId, $officeId]);
    if (!$serviceStmt->fetch()) {
        $pdo->rollBack();
        http_response_code(422);
        exit(json_encode(['error' => 'That service is unavailable for this office.']));
    }
    $activeStmt = $pdo->prepare("SELECT id FROM queue_tickets WHERE user_id = ? AND office_id = ? AND service_date = CURRENT_DATE() AND status IN ('Waiting','Called','Serving') LIMIT 1 FOR UPDATE");
    $activeStmt->execute([$user['id'], $officeId]);
    if ($activeStmt->fetch()) {
        $pdo->rollBack();
        http_response_code(409);
        exit(json_encode(['error' => 'You already have an active ticket for this office.']));
    }
    $counterUpsert = $pdo->prepare(
        'INSERT INTO queue_counters (office_id, service_date, last_number) VALUES (?, CURRENT_DATE(), 0)
         ON DUPLICATE KEY UPDATE last_number = last_number'
    );
    $counterUpsert->execute([$officeId]);
    $counterStmt = $pdo->prepare('SELECT last_number FROM queue_counters WHERE office_id = ? AND service_date = CURRENT_DATE() FOR UPDATE');
    $counterStmt->execute([$officeId]);
    $nextNumber = (int)$counterStmt->fetchColumn() + 1;
    $counterUpdate = $pdo->prepare('UPDATE queue_counters SET last_number = ? WHERE office_id = ? AND service_date = CURRENT_DATE()');
    $counterUpdate->execute([$nextNumber, $officeId]);
    $insert = $pdo->prepare(
        "INSERT INTO queue_tickets (office_id, service_id, user_id, service_date, queue_number, status)
         VALUES (?, ?, ?, CURRENT_DATE(), ?, 'Waiting')"
    );
    $insert->execute([$officeId, $serviceId, $user['id'], $nextNumber]);
    $ticketId = (int)$pdo->lastInsertId();
    $notify = $pdo->prepare('INSERT INTO notifications (user_id, ticket_id, message) VALUES (?, ?, ?)');
    $notify->execute([$user['id'], $ticketId, 'You joined the ' . $office['name'] . ' queue. Your number is ' . strtoupper(substr($office['name'], 0, 2)) . '-' . str_pad((string)$nextNumber, 3, '0', STR_PAD_LEFT) . '.']);
    log_activity($pdo, (int)$user['id'], 'queue_joined', 'Office ID ' . $officeId . ', ticket ' . $ticketId);
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'You joined the queue successfully.']);
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $databaseError = (string)($exception->errorInfo[2] ?? '');
    if ($exception->getCode() === '23000' && strpos($databaseError, 'uq_user_active_office_day') !== false) {
        http_response_code(409);
        echo json_encode(['error' => 'You already have an active ticket for this office.']);
    } else {
        error_log('Queue join failed: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Could not join the queue. Please try again.']);
    }
}
