<?php
/**
 * 跳转入口：校验导航项有效后直接 302 到目标网址。
 * （已移除预置 Cookie / 账号密码代登录等登录凭据功能）
 */
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: /index.html');
    exit;
}

$stmt = $pdo->prepare("SELECT url FROM nav_items WHERE id=? AND status=1");
$stmt->execute([$id]);
$url = (string)$stmt->fetchColumn();

if ($url === '') {
    header('Location: /index.html');
    exit;
}

// 累计点击次数（原子自增，避免并发覆盖）
$pdo->prepare("UPDATE nav_items SET click_count = click_count + 1 WHERE id=?")->execute([$id]);

header('Location: ' . $url);
exit;
