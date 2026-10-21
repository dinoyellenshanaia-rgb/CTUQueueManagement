<?php
require_once __DIR__ . '/includes/bootstrap.php';
$user = require_login();
switch ($user['role']) {
    case 'student': header('Location: /CTUQueueManagement/student/index.php'); break;
    case 'staff': header('Location: /CTUQueueManagement/staff/index.php'); break;
    case 'admin': header('Location: /CTUQueueManagement/admin/index.php'); break;
    default: http_response_code(403); exit('Unsupported account role.');
}
exit;
