<?php
/**
 * 搜索埋点端点（POST，由首页搜索框在提交时通过 sendBeacon 调用）
 * =============================================================
 * 记录一条搜索到 search_logs：登录用户带身份快照，未登录访客 user_id 为空。
 * 响应 204（埋点方不关心返回内容）。
 * 同一会话内同一关键词 + 引擎 3 秒内重复提交只记一次，抑制误触/连点。
 */
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';

http_response_code(204);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;

$keyword = trim((string)($_POST['keyword'] ?? ''));
if ($keyword === '') return;
// 与表字段对齐，超长截断（优先按字符截断，避免多字节切断）
if (u_len($keyword) > 191) {
    $keyword = function_exists('mb_substr') ? mb_substr($keyword, 0, 191)
                                            : substr($keyword, 0, 191);
}
$engineId = (int)($_POST['engine_id'] ?? 0);

// 会话级短时去重（同一词 + 同一引擎）
$dedupKey = $keyword . '|' . $engineId;
if (($_SESSION['_search_last'] ?? null) === $dedupKey
    && (time() - (int)($_SESSION['_search_last_t'] ?? 0)) < 3) {
    return;
}
$_SESSION['_search_last']   = $dedupKey;
$_SESSION['_search_last_t'] = time();

// 引擎名称快照（查不到时用空名，不影响记录）
$eSt = $pdo->prepare("SELECT name FROM search_engines WHERE id=?");
$eSt->execute([$engineId]);
$engineName = (string)($eSt->fetchColumn() ?: '');

$u = current_user();
$pdo->prepare("INSERT INTO search_logs
                   (user_id, username, display_name, keyword, engine_id, engine_name, ip, created_at)
               VALUES (?,?,?,?,?,?,?,NOW())")
    ->execute([
        $u['id'] ?? null,
        $u['username'] ?? '',
        $u['display_name'] ?? '',
        $keyword,
        $engineId,
        $engineName,
        client_ip(),
    ]);
