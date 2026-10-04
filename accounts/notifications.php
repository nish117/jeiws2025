<?php
/** AJAX endpoint for the top-bar notification dropdown. */
require __DIR__ . '/includes/bootstrap.php';

$user = current_user();
if (!$user) {
    http_response_code(401);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}
verify_csrf();

header('Content-Type: application/json');

switch ($_POST['action'] ?? '') {
    case 'mark_all_read':
        db()->prepare('UPDATE acc_notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')
            ->execute([$user['id']]);
        echo json_encode(['ok' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}
