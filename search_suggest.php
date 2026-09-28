<?php
/**
 * 搜索联想端点（GET ?q=xxx，返回 JSON）
 * ======================================
 * - 登录用户：优先本人历史搜索词（最近优先），不足补全站热门词；
 * - 未登录访客：全站热门词（按搜索次数排序）。
 * q 为空时：登录用户返回本人最近搜索，访客返回全站热门（聚焦即可见）。
 * 返回：{"items": ["关键词", ...]}，最多 8 条。
 */
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$q = trim((string)($_GET['q'] ?? ''));
// LIKE 通配符转义
$like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';

$limit = 8;
$items = [];
$me = current_user();

if ($me !== null) {
    // ① 本人历史（按最近一次搜索排序）
    $mySt = $pdo->prepare("SELECT keyword
                             FROM search_logs
                            WHERE user_id = ? AND keyword LIKE ?
                            GROUP BY keyword
                            ORDER BY MAX(id) DESC
                            LIMIT " . (int)$limit);
    $mySt->execute([(int)$me['id'], $like]);
    foreach ($mySt->fetchAll(PDO::FETCH_COLUMN) as $kw) $items[] = (string)$kw;
}

// ② 不足部分用全站热门补齐（排除已选词）
if (count($items) < $limit) {
    $sql = "SELECT keyword
              FROM search_logs
             WHERE keyword LIKE ?";
    $args = [$like];
    if ($items) {
        $place = implode(',', array_fill(0, count($items), '?'));
        $sql .= " AND keyword NOT IN ($place)";
        $args = array_merge($args, $items);
    }
    $sql .= " GROUP BY keyword
              ORDER BY COUNT(*) DESC, MAX(id) DESC
              LIMIT " . (int)($limit - count($items));
    $hotSt = $pdo->prepare($sql);
    $hotSt->execute($args);
    foreach ($hotSt->fetchAll(PDO::FETCH_COLUMN) as $kw) $items[] = (string)$kw;
}

echo json_encode(['items' => array_values(array_unique($items))],
                 JSON_UNESCAPED_UNICODE);
