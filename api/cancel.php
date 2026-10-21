<?php
require_once __DIR__ . '/../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
$user = require_role('student');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method not allowed.']));
}
verify_csrf();
$ticketId = filter_input(INPUT_POST, 'ticket_id', FILTER_VALIDATE_INT);
if (!$ticketId) {
    http_response_code(422);
    exit(json_encode(['error' => 'Invalid ticket.']));
}
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT id FROM queue_tickets WHERE id = ? AND user_id = ? AND status = 'Waiting' FOR UPDATE");
    $stmt->execute([$ticketId, $user['id']]);
    if (!$stmt->fetch()) {
        $pdo->rollBack();
        http_response_code(409);
        exit(json_encode(['error' => 'Only your waiting tickets can be cancelled.']));
    }
    $update = $pdo->prepare("UPDATE queue_tickets SET status = 'Cancelled' WHERE id = ?");
    $update->execute([$ticketId]);
    $notify = $pdo->prepare('INSERT INTO notifications (user_id, ticket_id, message) VALUES (?, ?, ?)');
    $notify->execute([$user['id'], $ticketId, 'Your queue ticket was cancelled.']);
    log_activity($pdo, (int)$user['id'], 'queue_cancelled', 'Ticket ID ' . $ticketId);
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Ticket cancelled.']);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Queue cancellation failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Could not cancel this ticket.']);
}
