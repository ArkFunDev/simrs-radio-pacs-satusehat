<?php
/**
 * Simple session guard. Include right after session_start().
 * Redirects to login.php (or returns 401 JSON for AJAX calls).
 */
if (!isset($_SESSION['user_radiologi'])) {
    $isAjax = ($_SERVER['REQUEST_METHOD'] === 'POST');
    if ($isAjax) {
        http_response_code(401);
        header('Content-Type: application/json');
        die(json_encode(['status' => 'error', 'message' => 'Unauthorized']));
    }
    header('Location: login.php');
    exit();
}