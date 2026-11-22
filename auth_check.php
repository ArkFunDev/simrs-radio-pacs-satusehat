<?php
/**
 * Session guard. Include right after config.php / session_start().
 *
 *  - Belum login  -> redirect ke login.php (atau JSON 401 untuk request AJAX/POST)
 *  - require_role([...]) -> batasi halaman ke peran tertentu:
 *        'radiologi'  : petugas/dokter radiologi
 *        'superadmin' : akun admin Khanza
 *        'user'       : petugas non-radiologi (IGD/Poli/Ranap)
 *        'dokter'     : dokter non-radiologi
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function auth_is_ajax(): bool {
    return $_SERVER['REQUEST_METHOD'] === 'POST'
        || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
}

function auth_deny(int $code, string $message, string $redirect = 'login.php'): void {
    if (auth_is_ajax()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode(['status' => 'error', 'ok' => false, 'message' => $message, 'msg' => $message]));
    }
    if ($code === 401) {
        header('Location: ' . $redirect);
        exit();
    }
    http_response_code($code);
    die(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
}

if (!isset($_SESSION['user_radiologi'])) {
    auth_deny(401, 'Unauthorized');
}

/*
 * Perlindungan CSRF dasar untuk SEMUA endpoint POST/AJAX: tolak request lintas-origin.
 * (Browser modern selalu mengirim Origin pada POST; Referer dipakai sebagai cadangan.)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $src = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
    if ($src !== '') {
        $srcPort = parse_url($src, PHP_URL_PORT);
        $srcHost = (string)parse_url($src, PHP_URL_HOST) . ($srcPort ? ':' . $srcPort : '');
        if (!hash_equals(strtolower($_SERVER['HTTP_HOST'] ?? ''), strtolower($srcHost))) {
            auth_deny(403, 'Permintaan lintas-origin ditolak.');
        }
    }
}

/** Batasi halaman ke peran tertentu. Dipanggil setelah auth_check.php di-include. */
function require_role(array $allowed): void {
    $role = $_SESSION['user_role'] ?? '';
    if (!in_array($role, $allowed, true)) {
        auth_deny(403, 'Anda tidak memiliki akses ke halaman ini.');
    }
}
