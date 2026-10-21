<?php
require_once __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
$user = require_role('staff');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method not allowed.']));
}
verify_csrf();
$action = (string)($_POST['action'] ?? '');
$officeId = filter_input(INPUT_POST, 'office_id', FILTER_VALIDATE_INT);
$ticketId = filter_input(INPUT_POST, 'ticket_id', FILTER_VALIDATE_INT);
$rawServiceId = $_POST['service_id'] ?? null;
$serviceId = $rawServiceId === null || $rawServiceId === ''
    ? null
    : (is_string($rawServiceId) ? filter_var($rawServiceId, FILTER_VALIDATE_INT) : false);
if (!$officeId || !in_array((int)$officeId, office_staff_ids($pdo, (int)$user['id']), true)) {
    http_response_code(403);
    exit(json_encode(['error' => 'Not assigned to this office.']));
}
try {
    $pdo->beginTransaction();
    if ($action === 'pause' || $action === 'resume') {
        $value = $action === 'resume' ? 1 : 0;
        $update = $pdo->prepare('UPDATE offices SET accepting_queue = ? WHERE id = ?');
        $update->execute([$value, $officeId]);
        log_activity($pdo, (int)$user['id'], 'queue_' . $action, 'Office ID ' . $officeId);
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => $value ? 'Queue acceptance resumed.' : 'Queue acceptance paused.']);
        exit;
    }
    if ((!$ticketId && $action !== 'call') || !in_array($action, ['call', 'serving', 'complete', 'no-show', 'skip'], true)) {
        $pdo->rollBack();
        http_response_code(422);
        exit(json_encode(['error' => 'Invalid queue action.']));
    }
    if ($rawServiceId !== null && $rawServiceId !== '' && (!$serviceId || $serviceId < 1)) {
        $pdo->rollBack();
        http_response_code(422);
        exit(json_encode(['error' => 'Select a valid service.']));
    }
    if ($serviceId !== null && $action !== 'call') {
        $pdo->rollBack();
        http_response_code(422);
        exit(json_encode(['error' => 'A service filter can only be used when calling the next ticket.']));
    }
    $ticketStmt = $pdo->prepare('SELECT id, user_id, queue_number, status FROM queue_tickets WHERE id = ? AND office_id = ? AND service_date = CURRENT_DATE() FOR UPDATE');
    if ($action === 'call') {
        $callSql = "SELECT id, user_id, queue_number, status FROM queue_tickets
                    WHERE office_id = ? AND service_date = CURRENT_DATE() AND status = 'Waiting'";
        $callParams = [$officeId];
        if ($serviceId !== null) {
            $serviceCheck = $pdo->prepare('SELECT id FROM services WHERE id = ? AND office_id = ?');
            $serviceCheck->execute([$serviceId, $officeId]);
            if (!$serviceCheck->fetch()) {
                $pdo->rollBack();
                http_response_code(422);
                exit(json_encode(['error' => 'Select a service offered by this office.']));
            }
            $callSql .= ' AND service_id = ?';
            $callParams[] = $serviceId;
        }
        $ticketStmt = $pdo->prepare($callSql . ' ORDER BY queue_number LIMIT 1 FOR UPDATE');
        $ticketStmt->execute($callParams);
    } else {
        $ticketStmt->execute([$ticketId, $officeId]);
    }
    $ticket = $ticketStmt->fetch();
    if (!$ticket) {
        $pdo->rollBack();
        http_response_code(409);
        exit(json_encode(['error' => 'No matching queue ticket is available for that action.']));
    }
    $transitions = [
        'call' => ['Waiting', 'Called'],
        'serving' => ['Called', 'Serving'],
        'complete' => ['Serving', 'Completed'],
        'no-show' => ['Called', 'No-show'],
        'skip' => ['Called', 'Waiting'],
    ];
    [$from, $to] = $transitions[$action];
    if ($ticket['status'] !== $from) {
        $pdo->rollBack();
        http_response_code(409);
        exit(json_encode(['error' => 'That ticket is not in the required status for this action.']));
    }
    if ($action === 'call') {
        $alreadyCalled = $pdo->prepare("SELECT id FROM queue_tickets WHERE office_id = ? AND service_date = CURRENT_DATE() AND status IN ('Called','Serving') LIMIT 1 FOR UPDATE");
        $alreadyCalled->execute([$officeId]);
        if ($alreadyCalled->fetch()) {
            $pdo->rollBack();
            http_response_code(409);
            exit(json_encode(['error' => 'Complete or serve the current called ticket before calling another.']));
        }
    }
    if ($action === 'skip') {
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
        $update = $pdo->prepare("UPDATE queue_tickets SET status = 'Waiting', queue_number = ?, called_at = NULL WHERE id = ?");
        $update->execute([$nextNumber, $ticket['id']]);
    } else {
        $setTime = $action === 'call' ? ', called_at = NOW()' : ($action === 'complete' ? ', completed_at = NOW()' : '');
        $update = $pdo->prepare('UPDATE queue_tickets SET status = ?' . $setTime . ' WHERE id = ?');
        $update->execute([$to, $ticket['id']]);
    }
    $messages = [
        'Called' => 'Your queue number has been called. Please proceed to the office.',
        'Serving' => 'You are now being served.',
        'Completed' => 'Your service has been completed. Thank you.',
        'No-show' => 'Your ticket was marked no-show. Please contact the office if this is incorrect.',
    ];
    if ($action === 'skip') {
        $messages['Waiting'] = 'Your ticket was skipped and moved to the end of the queue. Your queue number has changed.';
    }
    $notify = $pdo->prepare('INSERT INTO notifications (user_id, ticket_id, message) VALUES (?, ?, ?)');
    $notify->execute([$ticket['user_id'], $ticket['id'], $messages[$to]]);
    log_activity($pdo, (int)$user['id'], $action === 'skip' ? 'ticket_skipped' : 'ticket_' . strtolower(str_replace('-', '_', $to)), 'Ticket ID ' . $ticket['id']);
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Queue updated to ' . $to . '.']);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Staff queue action failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Could not update the queue. Please try again.']);
}
