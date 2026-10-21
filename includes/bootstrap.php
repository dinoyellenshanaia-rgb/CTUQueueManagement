<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/../config/db.php';

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $submitted = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($submitted) || !hash_equals(csrf_token(), $submitted)) {
        http_response_code(403);
        exit('Invalid request token. Reload the page and try again.');
    }
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_api_request(): bool
{
    return strpos((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/api/') !== false;
}

function require_login(): array
{
    global $pdo;
    $user = current_user();
    if (!$user) {
        if (is_api_request()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            exit(json_encode(['error' => 'Please log in to continue.']));
        }
        header('Location: /CTUQueueManagement/login.php');
        exit;
    }
    $statement = $pdo->prepare('SELECT id, full_name, email, role, is_active FROM users WHERE id = ?');
    $statement->execute([$user['id']]);
    $account = $statement->fetch();
    if (!$account || !(int)$account['is_active']) {
        $_SESSION = [];
        session_regenerate_id(true);
        http_response_code(403);
        if (is_api_request()) {
            header('Content-Type: application/json; charset=utf-8');
            exit(json_encode(['error' => 'This account is unavailable.']));
        }
        exit('This account is unavailable. Please contact an administrator.');
    }
    $_SESSION['user'] = [
        'id' => (int)$account['id'],
        'full_name' => $account['full_name'],
        'email' => $account['email'],
        'role' => $account['role'],
    ];
    return $_SESSION['user'];
}

function require_role(string ...$roles): array
{
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        if (is_api_request()) {
            header('Content-Type: application/json; charset=utf-8');
            exit(json_encode(['error' => 'You are not authorized to access this resource.']));
        }
        exit('You are not authorized to access this page.');
    }
    return $user;
}

function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function log_activity(PDO $pdo, ?int $userId, string $action, ?string $details = null): void
{
    $statement = $pdo->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (?, ?, ?)');
    $statement->execute([$userId, $action, $details]);
}

function office_staff_ids(PDO $pdo, int $userId): array
{
    $statement = $pdo->prepare('SELECT office_id FROM staff_offices WHERE user_id = ?');
    $statement->execute([$userId]);
    return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
}

function ticket_label(array $ticket): string
{
    return sprintf('%s-%03d', strtoupper(substr((string)$ticket['office_name'], 0, 2)), (int)$ticket['queue_number']);
}
