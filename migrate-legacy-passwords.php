<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config/db.php';

try {
    $columns = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('password_hash', $columns, true)) {
        throw new RuntimeException('Run database-migration.sql before this command.');
    }

    if (!in_array('password', $columns, true)) {
        fwrite(STDOUT, "Legacy passwords are already migrated.\n");
        exit(0);
    }

    $pdo->beginTransaction();
    $users = $pdo->query('SELECT id, password, password_hash FROM users FOR UPDATE')->fetchAll();
    $update = $pdo->prepare('UPDATE users SET password_hash = ?, password = ? WHERE id = ?');

    foreach ($users as $user) {
        $existingHash = (string)($user['password_hash'] ?? '');
        $legacyPassword = (string)($user['password'] ?? '');
        $storedValue = $existingHash !== '' ? $existingHash : $legacyPassword;
        if ($storedValue === '') {
            throw new RuntimeException('A user account has no password value; resolve it before continuing.');
        }

        $hashInfo = password_get_info($storedValue);
        $hash = $hashInfo['algoName'] !== 'unknown'
            ? $storedValue
            : password_hash($storedValue, PASSWORD_DEFAULT);

        $update->execute([$hash, '', (int)$user['id']]);
    }
    $pdo->commit();

    $pdo->exec(
        'ALTER TABLE users
         MODIFY COLUMN password_hash VARCHAR(255) NOT NULL,
         DROP COLUMN password'
    );
    fwrite(STDOUT, "Legacy account passwords are now stored as password hashes.\n");
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Legacy password migration failed: ' . $exception->getMessage());
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
