<?php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
// 本页在 require header.php 之前即用到皮肤函数（主题下拉/名称解析），需提前加载
require_once __DIR__ . '/../includes/themes.php';
require_admin();

// 进入条件：拥有「用户管理」「新建用户」「重置用户密码」或「用户主题管理」任一权限
if (!has_perm('manage_users') && !has_perm('create_users')
    && !has_perm('reset_password') && !has_perm('manage_user_themes')) {
    header('Location: /index.html'); exit;
}

/** 校验目标用户是否允许当前管理员操作；不允许时输出错误并中断本次动作 */
function guard_manage(PDO $pdo, int $targetId): bool {
    global $error;
    // 级别 + 范围（含自管组同级例外）统一由 can_manage_user 判定，
    // 避免另写一道级别检查把同组互改提前挡掉。
    if (!can_manage_user($pdo, $targetId)) {
        $error = '无权操作该用户：只能管理级别低于自己的用户，或自管组中的同组其他成员。';
        return false;
    }
    return true;
}

/** 收集"分配到的用户组"ID，仅保留真实存在的组 */
function collect_assigned_group_ids(array $post): array {
    $sel = isset($post['group_ids']) && is_array($post['group_ids'])
         ? array_map('intval', $post['group_ids']) : [];
    return array_values(array_unique(array_filter($sel)));
}

/** 校验拟分配的用户组全部在当前管理员可改动范围内（系统管理员不限） */
function guard_groups(PDO $pdo, array $groupIds): bool {
    global $error;
    if (is_super()) return true;
    $mutable = mutable_group_ids($pdo);
    foreach ($groupIds as $gid) {
        if (!in_array($gid, $mutable, true)) {
            $error = '无权将用户分配到该用户组：超出您的可管理范围（或为同级组）。';
            return false;
        }
    }
    return true;
}

/**
 * 写入用户的多组归属：
 * - 系统管理员：直接以提交集合为准替换；
 * - 普通管理员：只能改动"范围内且级别更低"的组；其余既有归属
 *   （范围外、同级——包括自管组本组——一律原样保留）。
 * 写入后重算"主用户组"缓存。
 */
function replace_user_groups(PDO $pdo, int $userId, array $postedIds): void {
    $final = $postedIds;
    if (!is_super()) {
        $mutable  = mutable_group_ids($pdo);
        $existing = user_group_ids($pdo, $userId);
        // 不可改动（同级/范围外）的既有归属全部保留
        $keep = array_diff($existing, $mutable);
        // 提交中超出可改动范围的一律剔除
        $postedMutable = array_intersect($postedIds, $mutable);
        $final = array_values(array_unique(array_merge($keep, $postedMutable)));
    }
    $pdo->prepare("DELETE FROM user_group_members WHERE user_id=?")->execute([$userId]);
    if ($final) {
        $ins = $pdo->prepare("INSERT IGNORE INTO user_group_members (user_id, group_id) VALUES (?,?)");
        foreach ($final as $gid) $ins->execute([$userId, $gid]);
    }
    sync_primary_group($pdo, $userId);
}

/** 保存成功后跳回来源页签 */
function saved_redirect(string $retType): void {
    $url = '/admin/users.html?saved=1';
    if ($retType === 'admins') $url .= '&type=admins';
    header('Location: ' . $url); exit;
}

/**
 * 输出某用户的「主题管理」展开行（查/增/改/删均在此表单完成）：
 * - 查：显示该用户当前主题（空=跟随站点默认）；
 * - 增/改：选择可见皮肤并保存；
 * - 删：选「跟随站点默认」保存即清除其个人选择。
 * 可分配皮肤以「对用户可见」清单为准，与 /theme.html 保持一致。
 */
function render_user_theme_row(PDO $pdo, array $u, string $retType, int $colSpan = 7): void {
    $cur = (string)($u['theme'] ?? '');
    $curTheme = $cur !== '' ? get_theme($cur) : null;
    // 下拉展示「对该目标用户可见」的皮肤（按其用户组身份判断），
    // 避免管理员给用户指定一个该用户无权使用的皮肤
    $themes = available_themes($pdo, ['id' => (int)$u['id']]);
    echo '<tr id="utheme-' . (int)$u['id'] . '" class="edit-row" style="display:none">';
    echo '<td colspan="' . $colSpan . '">';
    echo '<form method="post" class="admin-form inline-edit" action="/admin/users.html">';
    echo '<input type="hidden" name="action" value="set_user_theme">';
    echo '<input type="hidden" name="id" value="' . (int)$u['id'] . '">';
    echo '<input type="hidden" name="ret_type" value="' . e($retType) . '">';
    echo '<span class="perm-label">当前主题：'
        . ($curTheme !== null ? e($curTheme['name']) : '跟随站点默认') . '</span>';
    echo '<label class="inline-label">设置主题 <select name="theme">';
    echo '<option value="">跟随站点默认（清除个人选择）</option>';
    foreach ($themes as $t) {
        echo '<option value="' . e($t['id']) . '"'
            . ($t['id'] === $cur ? ' selected' : '') . '>' . e($t['name']) . '</option>';
    }
    echo '</select></label>';
    echo '<button type="submit" class="btn-mini">保存</button>';
    echo '<span class="grp-hint">选皮肤保存=增/改；选「跟随默认」=删除其个人选择。</span>';
    echo '</form>';
    echo '</td></tr>';
}

/**
 * 输出某用户的「登录设备」弹窗：设备名 / IP / 登录时间 / 最后活跃 / 状态，
 * 非当前设备且未吊销时可「强制下线」（revoke_device 动作在本页上方处理）。
 * 设备清单取全局预取的 $deviceMap，缺失时即时查询。
 */
function render_device_modal(PDO $pdo, array $u, string $retType): void {
    $uid = (int)$u['id'];
    $sessions = $GLOBALS['deviceMap'][$uid] ?? null;
    if ($sessions === null) $sessions = user_device_sessions($pdo, $uid);
    $curSid = session_id();

    echo '<div class="modal" id="modal-dev-' . $uid . '" data-modal>';
    echo '<div class="modal-overlay" data-modal-close></div>';
    echo '<div class="modal-box" style="max-width:680px">';
    echo '<div class="modal-head"><h3 class="modal-title">📱 登录设备：'
        . e($u['display_name'] ?: $u['username']) . '</h3>';
    echo '<button type="button" class="modal-close" data-modal-close aria-label="关闭">✕</button></div>';
    echo '<div class="modal-body">';
    if (!$sessions) {
        echo '<p class="empty-tip">暂无登录记录。</p>';
    } else {
        echo '<table class="data-table" style="font-size:12.5px">';
        echo '<thead><tr><th>设备</th><th>IP</th><th>登录时间</th><th>最后活跃</th><th>状态</th><th>操作</th></tr></thead><tbody>';
        foreach ($sessions as $s) {
            $isCurrent = $s['session_id'] === $curSid;
            echo '<tr>';
            echo '<td title="' . e($s['user_agent']) . '">' . e($s['device_name'])
                . ($isCurrent ? ' <span class="grp-hint">本机</span>' : '') . '</td>';
            echo '<td>' . e((string)$s['ip']) . '</td>';
            echo '<td>' . e((string)$s['created_at']) . '</td>';
            echo '<td>' . e((string)$s['last_access_at']) . '</td>';
            if ((int)$s['revoked'] === 1) {
                echo '<td>⚫ 已下线</td>';
            } elseif ($isCurrent) {
                echo '<td>🟢 当前设备</td>';
            } else {
                echo '<td>🔵 登录中</td>';
            }
            echo '<td class="ops">';
            if (!$isCurrent && (int)$s['revoked'] === 0) {
                echo '<form method="post" action="/admin/users.html" style="display:inline"';
                echo ' onsubmit="return confirm(\'确定强制下线该设备？对方下次操作将被要求重新登录。\');">';
                echo '<input type="hidden" name="action" value="revoke_device">';
                echo '<input type="hidden" name="device_id" value="' . (int)$s['id'] . '">';
                echo '<input type="hidden" name="ret_type" value="' . e($retType) . '">';
                echo '<button type="submit" class="btn-mini danger">强制下线</button>';
                echo '</form>';
            } else {
                echo '<span class="grp-hint">—</span>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
    echo '<div class="modal-foot"><button type="button" class="btn-mini" data-modal-close>关闭</button></div>';
    echo '</div></div>';
}

// 重置用户密码（独立权限：reset_password；可经二级授权获得）
if (isset($_POST['action']) && $_POST['action'] === 'reset_pass' && isset($_POST['id'])) {
    $uid = (int)$_POST['id'];
    $np  = $_POST['new_password'] ?? '';
    $ret = $_POST['ret_type'] === 'admins' ? 'admins' : 'users';
    if (!has_perm('reset_password')) {
        $error = '无权重置密码：需要「重置用户密码」权限。';
    } elseif (!guard_manage($pdo, $uid)) {
        // 拦截
    } elseif (u_len($np) < 4) {
        $error = '新密码至少 4 位。';
    } else {
        $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([md5($np), $uid]);
        $qName = $pdo->prepare("SELECT username FROM users WHERE id=?"); $qName->execute([$uid]);
        write_log($pdo, 'reset_password', '用户管理', '重置用户密码：' . (string)$qName->fetchColumn());
        saved_redirect($ret);
    }
}

// 设置/清除用户主题（独立权限 manage_user_themes；可经二级授权获得）
if (isset($_POST['action']) && $_POST['action'] === 'set_user_theme' && isset($_POST['id'])) {
    $id    = (int)$_POST['id'];
    $ret   = $_POST['ret_type'] === 'admins' ? 'admins' : 'users';
    $theme = trim((string)($_POST['theme'] ?? ''));
    if (!has_perm('manage_user_themes')) {
        $error = '无权管理用户主题：需要「用户主题管理」权限。';
    } elseif (!guard_manage($pdo, $id)) {
        // 拦截：只能管理级别更低的用户或自管组同组其他成员
    } else {
        // 只允许写入「对该目标用户可见」的皮肤（按目标用户组身份判断）；
        // 空串=清除个人选择（跟随站点默认）
        $allowed = array_keys(available_themes($pdo, ['id' => $id]));
        if ($theme !== '' && !in_array($theme, $allowed, true)) {
            $error = '该皮肤不可用（不存在或已被站点隐藏）。';
        } else {
            $pdo->prepare("UPDATE users SET theme=? WHERE id=?")->execute([$theme, $id]);
            $qName = $pdo->prepare("SELECT username FROM users WHERE id=?");
            $qName->execute([$id]);
            $uname = (string)$qName->fetchColumn();
            write_log($pdo, 'update', '用户管理',
                $theme === ''
                    ? "清除用户主题（跟随默认）：{$uname}"
                    : "设置用户主题：{$uname} → {$theme}");
            saved_redirect($ret);
        }
    }
}

// 强制下线用户的登录设备（需 manage_users 权限，且目标用户在管理范围内）
if (isset($_POST['action']) && $_POST['action'] === 'revoke_device') {
    $devId = (int)($_POST['device_id'] ?? 0);
    $ret   = ($_POST['ret_type'] ?? '') === 'admins' ? 'admins' : 'users';

    if (!has_perm('manage_users')) {
        $error = '无权下线登录设备：需要「用户管理」权限。';
    } elseif ($devId <= 0) {
        $error = '参数错误：未指定要下线的设备。';
    } else {
        $dSt = $pdo->prepare("SELECT * FROM user_sessions WHERE id=?");
        $dSt->execute([$devId]);
        $devRow = $dSt->fetch();
        if (!$devRow) {
            $error = '该登录设备不存在或已被清理。';
        } elseif ($devRow['session_id'] === session_id()) {
            $error = '不能在用户管理中下线当前设备（如需退出请点顶栏「退出」）。';
        } elseif (!guard_manage($pdo, (int)$devRow['user_id'])) {
            // 拦截：目标用户超出当前管理员管理范围
        } elseif ((int)$devRow['revoked'] === 1) {
            saved_redirect($ret);  // 已吊销：幂等成功
        } else {
            revoke_device_row($pdo, $devId);
            $qName = $pdo->prepare("SELECT username FROM users WHERE id=?");
            $qName->execute([(int)$devRow['user_id']]);
            $targetName = (string)$qName->fetchColumn();
            write_log($pdo, 'revoke_device', '用户管理',
                "强制下线登录设备：{$targetName}（{$devRow['device_name']}，IP：{$devRow['ip']}）");
            saved_redirect($ret);
        }
    }
}

// 单个新增用户（需独立权限 create_users；可同时勾选多个用户组）
if (isset($_POST['action']) && $_POST['action'] === 'create') {
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $display  = trim($_POST['display_name'] ?? '');
    $groupIds = collect_assigned_group_ids($_POST);

    if (!has_perm('create_users')) {
        $error = '无权新增用户：需要「新建用户」权限。';
    } elseif (!guard_groups($pdo, $groupIds)) {
        // 拦截
    } elseif ($username === '' || $password === '') {
        $error = '用户名和密码不能为空。';
    } else {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $chk->execute([$username]);
        if ((int)$chk->fetchColumn() > 0) {
            $error = "用户名「 $username 」已存在。";
        } else {
            // 过滤掉系统中不存在的组 ID
            if ($groupIds) {
                $in = implode(',', array_map('intval', $groupIds));
                $existRows = $pdo->query("SELECT id FROM user_groups WHERE id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
                $groupIds = array_map('intval', $existRows);
            }
            $pdo->prepare("INSERT INTO users (username, password, display_name) VALUES (?,?,?)")
                ->execute([$username, md5($password), $display !== '' ? $display : $username]);
            $newId = (int)$pdo->lastInsertId();
            replace_user_groups($pdo, $newId, $groupIds);
            write_log($pdo, 'create', '用户管理', "新增用户：{$username}" . ($groupIds ? '（用户组 ID：' . implode(',', $groupIds) . '）' : '（无分组）'));
            saved_redirect('users');
        }
    }
}

// 修改所属用户组（需 manage_users；支持同时勾选多个组）
if (isset($_POST['action']) && $_POST['action'] === 'change_group' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    $ret = $_POST['ret_type'] === 'admins' ? 'admins' : 'users';
    $groupIds = collect_assigned_group_ids($_POST);
    if (!has_perm('manage_users')) {
        $error = '无权修改用户组：需要「用户管理」权限。';
    } elseif (!guard_manage($pdo, $id)) {
        // 拦截
    } elseif (!guard_groups($pdo, $groupIds)) {
        // 拦截
    } else {
        // 剔除不存在的组 ID
        if ($groupIds) {
            $in = implode(',', array_map('intval', $groupIds));
            $existRows = $pdo->query("SELECT id FROM user_groups WHERE id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
            $groupIds = array_map('intval', $existRows);
        }
        replace_user_groups($pdo, $id, $groupIds);
        $qName = $pdo->prepare("SELECT username FROM users WHERE id=?"); $qName->execute([$id]);
        write_log($pdo, 'update', '用户管理', '修改所属用户组：' . (string)$qName->fetchColumn()
                  . ' → ' . ($groupIds ? '组 ID ' . implode(',', $groupIds) : '无分组'));
        saved_redirect($ret);
    }
}

// 修改显示名（需 manage_users）
if (isset($_POST['action']) && $_POST['action'] === 'change_name' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    $ret = $_POST['ret_type'] === 'admins' ? 'admins' : 'users';
    $name = trim($_POST['display_name'] ?? '');
    if (!has_perm('manage_users')) {
        $error = '无权修改显示名：需要「用户管理」权限。';
    } elseif (guard_manage($pdo, $id)) {
        $pdo->prepare("UPDATE users SET display_name=? WHERE id=?")->execute([$name, $id]);
        write_log($pdo, 'update', '用户管理', "修改显示名：用户 ID {$id} → {$name}");
        saved_redirect($ret);
    }
}

// 删除用户（需 manage_users；不能删除自己）
if (isset($_GET['del']) && (int)$_GET['del'] > 0) {
    $delId = (int)$_GET['del'];
    if (!has_perm('manage_users')) {
        $error = '无权删除用户：需要「用户管理」权限。';
    } elseif ($delId === (int)current_user()['id']) {
        $error = '不能删除当前登录账号。';
    } elseif (!guard_manage($pdo, $delId)) {
        // 拦截
    } else {
        $qName = $pdo->prepare("SELECT username FROM users WHERE id=?"); $qName->execute([$delId]);
        $delUsername = (string)$qName->fetchColumn();
        // 搜索记录保留审计：解除与该用户的关联（身份快照列不变）
        $pdo->prepare("UPDATE search_logs SET user_id=NULL WHERE user_id=?")->execute([$delId]);
        $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$delId]);
        write_log($pdo, 'delete', '用户管理', "删除用户：{$delUsername}（ID：{$delId}）");
        $ret = ($_GET['type'] ?? '') === 'admins' ? 'admins' : 'users';
        saved_redirect($ret);
    }
}

// 当前页签
$type = ($_GET['type'] ?? 'users') === 'admins' ? 'admins' : 'users';

// 用户组筛选：0=全部；-1=无分组；>0=指定组
$fGid = (int)($_GET['gid'] ?? 0);

// 当前管理员可选的目标用户组（用于新增/改组勾选；多组统一从关联表判断）
$groups = allowed_target_groups($pdo);

// 用户多组归属映射（用于编辑表单勾选状态）：user_id => [group_id,...]
$membershipMap = [];
$mmRows = $pdo->query("SELECT user_id, group_id FROM user_group_members")->fetchAll();
foreach ($mmRows as $mmr) {
    $membershipMap[(int)$mmr['user_id']][] = (int)$mmr['group_id'];
}

// 预取全部登录设备行，按用户分组（供「登录设备」弹窗一次渲染，免逐行查询）
$deviceMap = [];
try {
    foreach ($pdo->query("SELECT * FROM user_sessions ORDER BY last_access_at DESC, id DESC")->fetchAll() as $__dv) {
        $deviceMap[(int)$__dv['user_id']][] = $__dv;
    }
} catch (Throwable $e) {
    $deviceMap = [];
}

// 组名聚合 / 最高级别子查询（共享片段）
$gNamesSql = group_names_sql();
$lvlSql    = target_level_sql();

/* ===================== ① 管理员列表 ===================== */
// 管理员 = 任一所属组为管理员组/超管组（is_admin=1）。
// 所有管理员可相互查看；同级/更高级别只读。
$adminWhere = "EXISTS (SELECT 1 FROM user_group_members m JOIN user_groups g ON m.group_id=g.id
                       WHERE m.user_id=u.id AND g.is_admin=1)";
$adminArgs = [];
if ($fGid > 0) {
    $adminWhere .= " AND EXISTS (SELECT 1 FROM user_group_members fm WHERE fm.user_id=u.id AND fm.group_id=?)";
    $adminArgs[] = $fGid;
} elseif ($fGid === -1) {
    $adminWhere .= " AND NOT EXISTS (SELECT 1 FROM user_group_members fm WHERE fm.user_id=u.id)";
}
$adminSt = $pdo->prepare("SELECT u.*,
                                  $gNamesSql AS group_name,
                                  $lvlSql AS group_level
                           FROM users u
                           WHERE $adminWhere
                           ORDER BY group_level ASC, u.id ASC");
$adminSt->execute($adminArgs);
$adminUsers = $adminSt->fetchAll();

/* ===================== ② 普通用户列表（搜索 + 分页） ===================== */
$kw = trim($_GET['q'] ?? '');
$myLevel = current_level();
$scope = manageable_group_ids($pdo);
$baseSql = "FROM users u";
$where = [];
$args = [];
if ($kw !== '') {
    $where[] = "(u.username LIKE ? OR u.display_name LIKE ?)";
    $args[] = "%$kw%"; $args[] = "%$kw%";
}
// 普通用户：不存在任何管理员组归属
$where[] = "NOT EXISTS (SELECT 1 FROM user_group_members m JOIN user_groups g ON m.group_id=g.id
                        WHERE m.user_id=u.id AND g.is_admin=1)";
// 级别过滤：只能看到级别更低者；例外：自管组中的同组其他成员
list($peerClause, $peerArgs) = self_managed_peer_clause((int)current_user()['id'], $myLevel);
$where[] = "($lvlSql > ? OR $peerClause)";
$args[] = $myLevel;
$args = array_merge($args, $peerArgs);
if ($scope !== null) {
    if (empty($scope)) {
        $where[] = "1=0"; // 未分配任何可管理组：看不到任何用户
    } else {
        $in = implode(',', array_map('intval', $scope));
        $where[] = "EXISTS (SELECT 1 FROM user_group_members m
                            WHERE m.user_id=u.id AND m.group_id IN ($in))";
    }
}
// 用户组筛选
if ($fGid > 0) {
    $where[] = "EXISTS (SELECT 1 FROM user_group_members gm WHERE gm.user_id=u.id AND gm.group_id=?)";
    $args[] = $fGid;
} elseif ($fGid === -1) {
    $where[] = "NOT EXISTS (SELECT 1 FROM user_group_members gm WHERE gm.user_id=u.id)";
}
$whereSql = " WHERE " . implode(" AND ", $where);

// ---- 分页：每页显示个数由用户自定义 ----
$PER_PAGE_OPTIONS = [10, 20, 50, 100];
$perPage = (int)($_GET['per_page'] ?? 20);
if (!in_array($perPage, $PER_PAGE_OPTIONS, true)) $perPage = 20;

// 「上次登录时间」列显示开关（网站参数设置；manage_settings 权限可改，可二级授权）
$showLastLogin = (site_settings($pdo)['users_show_last_login'] ?? '1') === '1';
$colSpan = $showLastLogin ? 8 : 7;

$countSt = $pdo->prepare("SELECT COUNT(*) " . $baseSql . $whereSql);
$countSt->execute($args);
$total = (int)$countSt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$page = (int)($_GET['p'] ?? 1);
if ($page < 1) $page = 1;
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$listSt = $pdo->prepare("SELECT u.*, $gNamesSql AS group_name, $lvlSql AS group_level
                         " . $baseSql . $whereSql . " ORDER BY u.id LIMIT $perPage OFFSET $offset");
$listSt->execute($args);
$users = $listSt->fetchAll();

/** 生成分页/每页链接，保留搜索条件与页签 */
function users_page_url(array $override = []): string {
    $params = array_filter([
        'type'     => 'users',
        'q'        => trim($_GET['q'] ?? ''),
        'gid'      => (int)($_GET['gid'] ?? 0),
        'per_page' => (int)($_GET['per_page'] ?? 20),
        'p'        => (int)($_GET['p'] ?? 1),
    ], fn($v, $k) => ($k === 'q' ? $v !== '' : ($k === 'gid' ? $v !== 0 : true)),
        ARRAY_FILTER_USE_BOTH);
    $params = array_merge($params, $override);
    return '/admin/users.html?' . http_build_query($params);
}

/** 用户组筛选下拉选项（当前管理员可管理范围内的组） */
function group_filter_select(string $fieldName, int $selected): string {
    global $groups;
    $html = '<select name="' . $fieldName . '">'
          . '<option value="0">全部用户组</option>'
          . '<option value="-1" ' . ($selected===-1?'selected':'') . '>无分组</option>';
    foreach ($groups as $g) {
        $html .= '<option value="' . (int)$g['id'] . '" ' . ($selected===(int)$g['id']?'selected':'') . '>'
               . e($g['name']) . ((int)$g['is_admin']===1?'（管理员）':'') . '</option>';
    }
    return $html . '</select>';
}

$selfId    = (int)current_user()['id'];
$canManage = has_perm('manage_users');
$canCreate = has_perm('create_users');
$canReset  = has_perm('reset_password');
$canImport = has_perm('import_users');
$canUserThemes = has_perm('manage_user_themes');

$pageTitle = '用户管理';
require __DIR__ . '/../includes/header.php';
?>
<div class="admin-layout">
  <?php render_admin_sidebar('users'); ?>

  <section class="admin-main">
    <h2>用户管理</h2>
    <p class="page-sub">管理后台管理员与普通用户账号、所属用户组与密码。</p>
    <?php if (isset($_GET['saved'])): ?><p class="form-success">已保存。</p><?php endif; ?>
    <?php if (!empty($error)): ?><p class="form-error"><?= e($error) ?></p><?php endif; ?>

    <!-- 页签切换 -->
    <div class="um-tabs">
      <a class="um-tab <?= $type==='admins'?'active':'' ?>" href="/admin/users.html?type=admins">🛡️ 管理员（<?= count($adminUsers) ?>）</a>
      <a class="um-tab <?= $type==='users'?'active':'' ?>" href="/admin/users.html?type=users">👥 普通用户（<?= $total ?>）</a>
    </div>

    <?php if ($type === 'admins'): ?>
    <!-- ========== 管理员页签：可相互查看，同级/更高级别只读 ========== -->
    <p class="admin-tip">
      管理员之间可相互查看信息；但<strong>同级管理员禁止相互增删改</strong>（含系统管理员之间），
      级别更高的管理员同样只读；仅系统管理员可对级别更低的管理员进行操作。
    </p>

    <!-- 用户组筛选 -->
    <form method="get" class="search-inline" action="/admin/users.html">
      <input type="hidden" name="type" value="admins">
      <?= group_filter_select('gid', $fGid) ?>
      <button type="submit" class="btn-mini">筛选</button>
      <?php if ($fGid !== 0): ?><a href="/admin/users.html?type=admins" class="btn-mini">清除</a><?php endif; ?>
    </form>

    <table class="data-table">
      <thead><tr><th>ID</th><th>用户名</th><th>显示名</th><th>用户组</th><th>级别</th><th>注册时间</th><?php if ($showLastLogin): ?><th>上次登录</th><?php endif; ?><th>操作</th></tr></thead>
      <tbody>
      <?php if (empty($adminUsers)): ?>
        <tr><td colspan="<?= $colSpan ?>" class="empty-tip">当前没有管理员。</td></tr>
      <?php endif; ?>
      <?php $admModalUsers = []; foreach ($adminUsers as $u):
        $isMe  = (int)$u['id'] === $selfId;
        // 可编辑条件：非本人 + 级别更低 + 管理范围内（普通管理员对所有管理员均为只读）
        $canEdit = !$isMe && can_manage_user($pdo, (int)$u['id']);
        // 该用户当前主题（查）：即使皮肤已被隐藏也保留显示
        $uTheme   = (string)($u['theme'] ?? '');
        $uThemeT  = $uTheme !== '' ? get_theme($uTheme) : null;
        $themeLbl = $uThemeT !== null ? $uThemeT['name'] : ($uTheme !== '' ? $uTheme : '');
      ?>
        <tr>
          <td><?= (int)$u['id'] ?></td>
          <td><?= e($u['username']) ?><?= $isMe ? ' <span class="grp-hint">（我）</span>' : '' ?></td>
          <td><?= e($u['display_name']) ?></td>
          <td><?= e($u['group_name'] ?: '无分组') ?></td>
          <td><span class="level-badge"><?= (int)($u['group_level'] ?? 99) ?></span></td>
          <td><?= e($u['created_at']) ?></td>
          <?php if ($showLastLogin): ?>
          <td title="<?= !empty($u['last_login_ip']) ? '登录 IP：' . e($u['last_login_ip']) : '' ?>">
            <?= !empty($u['last_login_at']) ? e($u['last_login_at']) : '<span class="grp-hint">从未登录</span>' ?>
          </td>
          <?php endif; ?>
          <td class="ops">
            <?php if ($canEdit): ?>
              <?php if ($canManage): ?>
                <button class="btn-mini" onclick="toggleEdit(<?= (int)$u['id'] ?>)">编辑</button>
              <?php endif; ?>
              <?php if ($canReset): ?>
                <button class="btn-mini" onclick="togglePwd(<?= (int)$u['id'] ?>)">🔑 重置密码</button>
              <?php endif; ?>
              <?php if ($canUserThemes): ?>
                <button class="btn-mini" title="管理该用户的主题"
                        onclick="toggleUTheme(<?= (int)$u['id'] ?>)">🎨<?= $themeLbl !== '' ? ' ' . e($themeLbl) : ' 主题' ?></button>
              <?php endif; ?>
              <?php if ($canManage): ?>
                <button class="btn-mini" title="查看/下线该用户的登录设备"
                        data-modal-open="modal-dev-<?= (int)$u['id'] ?>">📱 设备</button>
              <?php endif; ?>
              <?php if ($canManage): ?>
                <a class="btn-mini danger" href="/admin/users.html?del=<?= (int)$u['id'] ?>&type=admins"
                   onclick="return confirm('确定删除管理员「<?= e($u['username']) ?>」？')">删除</a>
              <?php endif; ?>
            <?php else: ?>
              <span class="grp-hint">🔒 仅查看</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php if ($canEdit && $canManage): ?>
        <tr id="edit-<?= (int)$u['id'] ?>" class="edit-row" style="display:none">
          <td colspan="<?= $colSpan ?>">
            <form method="post" class="admin-form inline-edit" action="/admin/users.html">
              <input type="hidden" name="action" value="change_name">
              <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <input type="hidden" name="ret_type" value="admins">
              <label class="inline-label">显示名
                <input type="text" name="display_name" value="<?= e($u['display_name']) ?>">
              </label>
              <button type="submit" class="btn-mini">保存</button>
            </form>
            <form method="post" class="admin-form inline-edit" action="/admin/users.html">
              <input type="hidden" name="action" value="change_group">
              <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <input type="hidden" name="ret_type" value="admins">
              <div class="perm-box">
                <span class="perm-label">所属用户组（可多选；不在管理范围内的组不显示，会自动保留）：</span>
                <div>
                  <?php if (empty($groups)): ?>
                    <span class="grp-hint">当前没有可分配的用户组（留空保存即为无分组）。</span>
                  <?php endif; ?>
                  <?php
                    $curGids = $membershipMap[(int)$u['id']] ?? [];
                    foreach ($groups as $g): ?>
                    <label class="chk"><input type="checkbox" name="group_ids[]" value="<?= (int)$g['id'] ?>" <?= in_array((int)$g['id'], $curGids, true)?'checked':'' ?>>
                      <?= e($g['name']) ?><?= (int)$g['is_admin']===1?'（管理员）':'' ?>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>
              <button type="submit" class="btn-mini">保存</button>
            </form>
          </td>
        </tr>
        <?php endif; ?>
        <?php if ($canEdit && $canReset): ?>
        <tr id="pwd-<?= (int)$u['id'] ?>" class="edit-row" style="display:none">
          <td colspan="<?= $colSpan ?>">
            <form method="post" class="admin-form inline-edit" action="/admin/users.html">
              <input type="hidden" name="action" value="reset_pass">
              <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <input type="hidden" name="ret_type" value="admins">
              <label class="inline-label">新密码
                <input type="password" name="new_password" placeholder="至少 4 位" minlength="4" required autocomplete="new-password">
              </label>
              <button type="submit" class="btn-mini">重置密码</button>
              <span class="grp-hint">立即生效，无需原密码（md5 保存）</span>
            </form>
          </td>
        </tr>
        <?php endif; ?>
        <?php if ($canEdit && $canUserThemes) render_user_theme_row($pdo, $u, 'admins', $colSpan); ?>
        <?php if ($canEdit && $canManage) $admModalUsers[] = $u; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php foreach ($admModalUsers as $mu) render_device_modal($pdo, $mu, 'admins'); ?>

    <?php else: ?>
    <!-- ========== 普通用户页签 ========== -->
    <?php if ($canImport || $canCreate): ?>
    <details class="create-box"<?= (!empty($error) && $type === 'users') ? ' open' : '' ?>>
      <summary>➕ 新增 / 导入用户</summary>
      <div class="create-body">
        <?php if ($canImport): ?>
        <div class="form-row" style="margin-bottom:12px">
          <a href="/admin/import.html" class="btn-primary">📥 批量导入用户</a>
          <span class="grp-hint">支持粘贴文本批量导入，或上传 CSV 文件。</span>
        </div>
        <?php endif; ?>

        <?php if ($canCreate): ?>
        <form method="post" class="admin-form" action="/admin/users.html">
          <input type="hidden" name="action" value="create">
          <div class="form-row">
            <input type="text" name="username" placeholder="用户名（必填）" required>
            <input type="password" name="password" placeholder="密码（必填，md5 保存）" required>
            <input type="text" name="display_name" placeholder="显示名（可选）">
            <button type="submit" class="btn-primary">新增用户</button>
          </div>
          <div class="perm-box">
            <span class="perm-label">所属用户组（可多选；不勾选则为无分组）：</span>
            <div>
              <?php if (empty($groups)): ?><span class="grp-hint">当前没有可分配的用户组。</span><?php endif; ?>
              <?php foreach ($groups as $g): ?>
                <label class="chk"><input type="checkbox" name="group_ids[]" value="<?= (int)$g['id'] ?>">
                  <?= e($g['name']) ?><?= (int)$g['is_admin']===1?'（管理员）':'' ?>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        </form>
        <?php endif; ?>
      </div>
    </details>
    <?php endif; ?>

    <form method="get" class="search-inline" action="/admin/users.html">
      <input type="hidden" name="type" value="users">
      <input type="text" name="q" placeholder="按用户名或显示名搜索" value="<?= e($kw) ?>">
      <?= group_filter_select('gid', $fGid) ?>
      <button type="submit" class="btn-mini">搜索</button>
      <?php if ($kw !== '' || $fGid !== 0): ?>
        <a href="/admin/users.html?type=users" class="btn-mini">清除</a>
      <?php endif; ?>
    </form>

    <table class="data-table">
      <thead><tr><th>ID</th><th>用户名</th><th>显示名</th><th>用户组</th><th>级别</th><th>注册时间</th><?php if ($showLastLogin): ?><th>上次登录</th><?php endif; ?><th>操作</th></tr></thead>
      <tbody>
      <?php if (empty($users)): ?>
        <tr><td colspan="<?= $colSpan ?>" class="empty-tip"><?= $kw !== '' ? '没有匹配搜索条件的用户。' : '当前没有可查看的用户。' ?></td></tr>
      <?php endif; ?>
      <?php $usrModalUsers = []; foreach ($users as $u):
        // 该用户当前主题（查）：即使皮肤已被隐藏也保留显示
        $uTheme   = (string)($u['theme'] ?? '');
        $uThemeT  = $uTheme !== '' ? get_theme($uTheme) : null;
        $themeLbl = $uThemeT !== null ? $uThemeT['name'] : ($uTheme !== '' ? $uTheme : '');
      ?>
        <tr>
          <td><?= (int)$u['id'] ?></td>
          <td><?= e($u['username']) ?></td>
          <td><?= e($u['display_name']) ?></td>
          <td><?= e($u['group_name'] ?: '无分组') ?></td>
          <td><span class="level-badge"><?= (int)($u['group_level'] ?? 99) ?></span></td>
          <td><?= e($u['created_at']) ?></td>
          <?php if ($showLastLogin): ?>
          <td title="<?= !empty($u['last_login_ip']) ? '登录 IP：' . e($u['last_login_ip']) : '' ?>">
            <?= !empty($u['last_login_at']) ? e($u['last_login_at']) : '<span class="grp-hint">从未登录</span>' ?>
          </td>
          <?php endif; ?>
          <td class="ops">
            <?php if ($canManage): ?>
              <button class="btn-mini" onclick="toggleEdit(<?= (int)$u['id'] ?>)">编辑</button>
            <?php endif; ?>
            <?php if ($canReset): ?>
              <button class="btn-mini" onclick="togglePwd(<?= (int)$u['id'] ?>)">🔑 重置密码</button>
            <?php endif; ?>
            <?php if ($canUserThemes): ?>
              <button class="btn-mini" title="管理该用户的主题"
                      onclick="toggleUTheme(<?= (int)$u['id'] ?>)">🎨<?= $themeLbl !== '' ? ' ' . e($themeLbl) : ' 主题' ?></button>
            <?php endif; ?>
            <?php if ($canManage): ?>
              <button class="btn-mini" title="查看/下线该用户的登录设备"
                      data-modal-open="modal-dev-<?= (int)$u['id'] ?>">📱 设备</button>
            <?php endif; ?>
            <?php if ($canManage && (int)$u['id'] !== $selfId): ?>
              <a class="btn-mini danger" href="/admin/users.html?del=<?= (int)$u['id'] ?>"
                 onclick="return confirm('确定删除用户「<?= e($u['username']) ?>」？')">删除</a>
            <?php endif; ?>
            <?php if (!$canManage && !$canReset && !$canUserThemes): ?>
              <span class="grp-hint">🔒 仅查看</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php if ($canManage): ?>
        <tr id="edit-<?= (int)$u['id'] ?>" class="edit-row" style="display:none">
          <td colspan="<?= $colSpan ?>">
            <form method="post" class="admin-form inline-edit" action="/admin/users.html">
              <input type="hidden" name="action" value="change_name">
              <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <label class="inline-label">显示名
                <input type="text" name="display_name" value="<?= e($u['display_name']) ?>">
              </label>
              <button type="submit" class="btn-mini">保存</button>
            </form>
            <form method="post" class="admin-form inline-edit" action="/admin/users.html">
              <input type="hidden" name="action" value="change_group">
              <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <div class="perm-box">
                <span class="perm-label">所属用户组（可多选；不在管理范围内的组不显示，会自动保留）：</span>
                <div>
                  <?php if (empty($groups)): ?>
                    <span class="grp-hint">当前没有可分配的用户组（留空保存即为无分组）。</span>
                  <?php endif; ?>
                  <?php $curGids = $membershipMap[(int)$u['id']] ?? []; ?>
                  <?php foreach ($groups as $g): ?>
                    <label class="chk"><input type="checkbox" name="group_ids[]" value="<?= (int)$g['id'] ?>" <?= in_array((int)$g['id'], $curGids, true)?'checked':'' ?>>
                      <?= e($g['name']) ?><?= (int)$g['is_admin']===1?'（管理员）':'' ?>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>
              <button type="submit" class="btn-mini">保存</button>
            </form>
          </td>
        </tr>
        <?php endif; ?>
        <?php if ($canReset): ?>
        <tr id="pwd-<?= (int)$u['id'] ?>" class="edit-row" style="display:none">
          <td colspan="<?= $colSpan ?>">
            <form method="post" class="admin-form inline-edit" action="/admin/users.html">
              <input type="hidden" name="action" value="reset_pass">
              <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <label class="inline-label">新密码
                <input type="password" name="new_password" placeholder="至少 4 位" minlength="4" required autocomplete="new-password">
              </label>
              <button type="submit" class="btn-mini">重置密码</button>
              <span class="grp-hint">立即生效，无需原密码（md5 保存）</span>
            </form>
          </td>
        </tr>
        <?php endif; ?>
        <?php if ($canUserThemes) render_user_theme_row($pdo, $u, 'users', $colSpan); ?>
        <?php if ($canManage) $usrModalUsers[] = $u; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php foreach ($usrModalUsers as $mu) render_device_modal($pdo, $mu, 'users'); ?>

    <!-- 分页栏：每页个数可自定义，翻页保留搜索条件 -->
    <div class="pagination-bar">
      <div class="page-info">
        共 <strong><?= $total ?></strong> 个用户，
        第 <?= $page ?> / <?= $totalPages ?> 页
        （显示第 <?= $total === 0 ? 0 : ($offset + 1) ?>–<?= min($offset + $perPage, $total) ?> 条）
      </div>
      <div class="page-controls">
        <label class="inline-label">每页
          <select onchange="location.href=this.value" style="width:80px">
            <?php foreach ($PER_PAGE_OPTIONS as $opt): ?>
              <option value="<?= e(users_page_url(['per_page' => $opt, 'p' => 1])) ?>"
                 <?= $opt===$perPage?'selected':'' ?>><?= $opt ?> 条</option>
            <?php endforeach; ?>
          </select>
        </label>

        <a class="btn-mini <?= $page<=1?'disabled':'' ?>" href="<?= e(users_page_url(['p' => 1])) ?>">« 首页</a>
        <a class="btn-mini <?= $page<=1?'disabled':'' ?>" href="<?= e(users_page_url(['p' => max(1,$page-1)])) ?>">‹ 上一页</a>

        <?php
        // 页码窗口：当前页前后各 2 页
        $startP = max(1, $page - 2);
        $endP = min($totalPages, $startP + 4);
        $startP = max(1, $endP - 4);
        for ($i = $startP; $i <= $endP; $i++): ?>
          <a class="page-num <?= $i===$page?'current':'' ?>" href="<?= e(users_page_url(['p' => $i])) ?>"><?= $i ?></a>
        <?php endfor; ?>

        <a class="btn-mini <?= $page>=$totalPages?'disabled':'' ?>" href="<?= e(users_page_url(['p' => min($totalPages,$page+1)])) ?>">下一页 ›</a>
        <a class="btn-mini <?= $page>=$totalPages?'disabled':'' ?>" href="<?= e(users_page_url(['p' => $totalPages])) ?>">末页 »</a>
      </div>
    </div>
    <?php endif; ?>
  </section>
</div>

<script>
function toggleEdit(id) {
  var row = document.getElementById('edit-' + id);
  row.style.display = row.style.display === 'none' ? '' : 'none';
}
function togglePwd(id) {
  var row = document.getElementById('pwd-' + id);
  row.style.display = row.style.display === 'none' ? '' : 'none';
}
function toggleUTheme(id) {
  var row = document.getElementById('utheme-' + id);
  row.style.display = row.style.display === 'none' ? '' : 'none';
}
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
