<?php
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';

// 会话销毁前记录退出日志（含 IP）
$logoutUser = current_user();
if ($logoutUser) {
    // 吊销当前设备行（正常退出，释放设备名额）
    $devId = current_device_id();
    if ($devId > 0) {
        try { revoke_device_row($pdo, $devId); } catch (Throwable $e) {}
    }
    write_log($pdo, 'logout', '登录认证', '退出登录');
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

header('Location: /login.html');
exit;
