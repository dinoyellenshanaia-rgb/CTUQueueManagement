<?php
require_once __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
$user = require_login();

try {
    if ($user['role'] === 'student') {
        $stmt = $pdo->prepare(
            "SELECT t.id, t.office_id, t.service_id, t.service_date, t.queue_number, t.status, t.created_at,
                    o.name AS office_name, s.name AS service_name,
                    (SELECT COUNT(*) FROM queue_tickets ahead
                     WHERE ahead.office_id = t.office_id AND ahead.service_date = t.service_date
                       AND ahead.status IN ('Waiting','Called','Serving') AND ahead.queue_number < t.queue_number) AS people_ahead,
                    (SELECT COALESCE(
                        (SELECT serving.queue_number FROM queue_tickets serving
                         WHERE serving.office_id = t.office_id AND serving.service_date = t.service_date
                           AND serving.status = 'Serving' ORDER BY serving.queue_number LIMIT 1),
                        (SELECT called.queue_number FROM queue_tickets called
                         WHERE called.office_id = t.office_id AND called.service_date = t.service_date
                           AND called.status = 'Called' ORDER BY called.queue_number LIMIT 1)
                    )) AS current_number
             FROM queue_tickets t
             JOIN offices o ON o.id = t.office_id
             JOIN services s ON s.id = t.service_id
             WHERE t.user_id = ? AND t.status IN ('Waiting','Called','Serving')
             ORDER BY t.created_at DESC"
        );
        $stmt->execute([$user['id']]);
        $tickets = $stmt->fetchAll();
        foreach ($tickets as &$ticket) {
            $estimate = $pdo->prepare(
                "SELECT COUNT(*) AS samples, AVG(TIMESTAMPDIFF(MINUTE, called_at, completed_at)) AS avg_minutes
                 FROM queue_tickets WHERE office_id = ? AND service_date = ? AND status = 'Completed'
                   AND called_at IS NOT NULL AND completed_at > called_at"
            );
            $estimate->execute([$ticket['office_id'], $ticket['service_date']]);
            $sample = $estimate->fetch();
            $ticket['estimated_minutes'] = ((int)$sample['samples'] >= 3 && $sample['avg_minutes'] !== null)
                ? (int)ceil((int)$ticket['people_ahead'] * (float)$sample['avg_minutes'])
                : null;
            $ticket['queue_label'] = ticket_label($ticket);
        }
        unset($ticket);
        $noticeStmt = $pdo->prepare('SELECT message, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 8');
        $noticeStmt->execute([$user['id']]);
        echo json_encode(['tickets' => $tickets, 'notifications' => $noticeStmt->fetchAll()], JSON_THROW_ON_ERROR);
        exit;
    }

    if ($user['role'] !== 'staff') {
        http_response_code(403);
        echo json_encode(['error' => 'Not authorized.']);
        exit;
    }

    $officeIds = office_staff_ids($pdo, (int)$user['id']);
    $officeId = filter_input(INPUT_GET, 'office', FILTER_VALIDATE_INT) ?: ($officeIds[0] ?? 0);
    if (!in_array((int)$officeId, $officeIds, true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Not assigned to this office.']);
        exit;
    }
    $queueDate = $_GET['date'] ?? date('Y-m-d');
    $dateValue = is_string($queueDate) ? DateTimeImmutable::createFromFormat('!Y-m-d', $queueDate) : false;
    if (!$dateValue || $dateValue->format('Y-m-d') !== $queueDate || $queueDate > date('Y-m-d')) {
        http_response_code(422);
        echo json_encode(['error' => 'Choose a valid queue date that is today or earlier.']);
        exit;
    }
    $rawServiceId = $_GET['service'] ?? null;
    $serviceId = $rawServiceId === null || $rawServiceId === ''
        ? null
        : (is_string($rawServiceId) ? filter_var($rawServiceId, FILTER_VALIDATE_INT) : false);
    if ($rawServiceId !== null && $rawServiceId !== '' && (!$serviceId || $serviceId < 1)) {
        http_response_code(422);
        echo json_encode(['error' => 'Select a valid service.']);
        exit;
    }
    if ($serviceId !== null) {
        $serviceCheck = $pdo->prepare('SELECT id FROM services WHERE id = ? AND office_id = ?');
        $serviceCheck->execute([$serviceId, $officeId]);
        if (!$serviceCheck->fetch()) {
            http_response_code(422);
            echo json_encode(['error' => 'Select a service offered by this office.']);
            exit;
        }
    }
    $officeStmt = $pdo->prepare('SELECT id, name, accepting_queue FROM offices WHERE id = ?');
    $officeStmt->execute([$officeId]);
    $office = $officeStmt->fetch();
    $queueSql =
        "SELECT t.id, t.queue_number, t.status, t.created_at, u.full_name, s.name AS service_name
         FROM queue_tickets t
         JOIN users u ON u.id = t.user_id
         JOIN services s ON s.id = t.service_id
         WHERE t.office_id = ? AND t.service_date = ?
           AND t.status IN ('Waiting','Called','Serving')";
    $queueParams = [$officeId, $queueDate];
    if ($serviceId !== null) {
        $queueSql .= ' AND t.service_id = ?';
        $queueParams[] = $serviceId;
    }
    $stmt = $pdo->prepare($queueSql . ' ORDER BY t.queue_number');
    $stmt->execute($queueParams);
    echo json_encode([
        'office' => $office,
        'date' => $queueDate,
        'service_id' => $serviceId,
        'tickets' => $stmt->fetchAll(),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    error_log('Queue polling failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Queue information is temporarily unavailable.']);
}
