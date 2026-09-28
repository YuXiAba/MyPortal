<?php
/**
 * 认证辅助：会话管理、登录态、权限判断
 * 使用前请先 require 'db.php'。
 */

// 登录状态有效期：超级管理员可在「网站参数」中设置（秒）
// 0 = 会话 Cookie（浏览器关闭即失效）；>0 = 持久 Cookie，到期需重新登录
if (isset($GLOBALS['SESSION_LIFETIME']) && (int)$GLOBALS['SESSION_LIFETIME'] > 0) {
    $life = (int)$GLOBALS['SESSION_LIFETIME'];
    session_set_cookie_params([
        'lifetime' => $life,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
session_start();

/**
 * 服务端登录有效期校验（滑动过期：距上次活动超过设定时长则要求重新登录）。
 * 登录成功后写入 $_SESSION['_last_activity']。
 */
function enforce_session_lifetime(): void {
    if (!isset($_SESSION['user'])) return;
    $life = (int)($GLOBALS['SESSION_LIFETIME'] ?? 0);
    if ($life <= 0) return;
    $now = time();
    if (isset($_SESSION['_last_activity']) && ($now - (int)$_SESSION['_last_activity']) > $life) {
        $_SESSION = [];
        session_destroy();
        header('Location: /login.html?expired=1');
        exit;
    }
    $_SESSION['_last_activity'] = $now;
}
enforce_session_lifetime();
// 登录设备会话校验：当前设备被吊销则踢出；老会话自动补登；每 30 秒心跳
enforce_device_session();

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

/**
 * 获取访客 IP（兼容 IPv4/IPv6）。
 * 站点普遍部署在 Nginx 反代之后，REMOTE_ADDR 会是 127.0.0.1，
 * 因此依次尝试 X-Forwarded-For（首个地址）→ X-Real-IP → REMOTE_ADDR，
 * 每个候选都经 FILTER_VALIDATE_IP 校验，防止伪造头注入非法值。
 * 注意：仅在反代本身会覆写这些头（不信任客户端直连传入值）的部署下才可信。
 */
function client_ip(): string {
    $candidates = [];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // 多级代理时为逗号分隔列表，最左侧为原始客户端
        $first = trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        $candidates[] = $first;
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $candidates[] = trim((string)$_SERVER['HTTP_X_REAL_IP']);
    }
    if (!empty($_SERVER['REMOTE_ADDR'])) {
        $candidates[] = trim((string)$_SERVER['REMOTE_ADDR']);
    }
    foreach ($candidates as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            return $ip;
        }
    }
    return '';
}

function is_logged_in(): bool {
    return isset($_SESSION['user']);
}

/**
 * 写入用户操作日志（user_logs）。
 * - 操作人默认取当前登录用户；未登录场景（如登录失败）可用 $actorName/$actorDisplay
 *   传入目标用户名与显示名快照（账号不存在时显示名传"非注册用户"）；
 * - 记录模块/操作/详情/IP/状态（success|fail）；
 * - 任何异常都被吞掉：日志记录失败绝不能影响主业务流程。
 */
function write_log(PDO $pdo, string $action, string $module, string $detail = '',
                   string $status = 'success', ?string $actorName = null,
                   ?string $actorDisplay = null): void {
    try {
        $u = current_user();
        if ($actorName === null) {
            $actorName = $u['username'] ?? '';
        }
        if ($actorDisplay === null) {
            $actorDisplay = $u['display_name'] ?? '';
        }
        if (function_exists('mb_substr')) {
            $detail = mb_substr($detail, 0, 1000);
        } else {
            $detail = substr($detail, 0, 1000);
        }
        $st = $pdo->prepare("INSERT INTO user_logs (user_id, username, display_name, action, module, detail, ip, status, created_at)
                             VALUES (?,?,?,?,?,?,?,?,NOW())");
        $st->execute([
            $u['id'] ?? null,
            $actorName,
            $actorDisplay,
            $action,
            $module,
            $detail,
            client_ip() ?: null,
            $status,
        ]);
    } catch (Throwable $e) {
        // 静默忽略，保证主流程不受影响
    }
}

/**
 * 是否可进入后台：不再由用户组"管理员"标记决定，
 * 而是看是否拥有「后台访问权限 access_admin」（超级管理员自动拥有）。
 */
function is_admin(): bool {
    return is_super() || has_perm('access_admin');
}

/** 是否超级管理员（拥有全部权限，可改网站参数与各用户组权限） */
function is_super(): bool {
    $u = current_user();
    return $u !== null && !empty($u['is_super']);
}

/**
 * 判断当前用户是否拥有某项权限。
 * 超级管理员自动拥有全部；否则看其用户组 permissions 列表。
 */
function has_perm(string $key): bool {
    if (is_super()) return true;
    $u = current_user();
    if ($u === null) return false;
    $perms = $u['permissions'] ?? [];
    return is_array($perms) && in_array($key, $perms, true);
}

/** 无权限则 302 到首页 */
function require_perm(string $key): void {
    if (!has_perm($key)) {
        header('Location: /index.html');
        exit;
    }
}

/* ============================================================
   多用户组支持：关联表 user_group_members
   ============================================================ */

/** 用户所属的全部用户组 ID */
function user_group_ids(PDO $pdo, int $userId): array {
    $st = $pdo->prepare("SELECT group_id FROM user_group_members WHERE user_id=? ORDER BY group_id");
    $st->execute([$userId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * 重建用户缓存的"主用户组"（所属组中级别最高者， ties 取 ID 最小）；
 * 无任何组时置 NULL。users.group_id 仅作列表 JOIN 缓存，真实归属以关联表为准。
 */
function sync_primary_group(PDO $pdo, int $userId): void {
    $gids = user_group_ids($pdo, $userId);
    $primary = null;
    if ($gids) {
        $in = implode(',', array_map('intval', $gids));
        $row = $pdo->query("SELECT id FROM user_groups WHERE id IN ($in) ORDER BY level ASC, id ASC LIMIT 1")->fetch();
        $primary = $row ? (int)$row['id'] : null;
    }
    $pdo->prepare("UPDATE users SET group_id=? WHERE id=?")->execute([$primary, $userId]);
}

/**
 * 聚合用户在全部所属组上的身份信息：
 * - is_super / is_admin：任一组命中即为真；
 * - level：取最高级别（数字最小），无组为 99；
 * - group_perms_csv：各组权限并集；
 * - groups：所属组明细行，group_names：组名列表。
 */
function load_user_identity(PDO $pdo, int $userId): ?array {
    $st = $pdo->prepare("SELECT * FROM users WHERE id=?");
    $st->execute([$userId]);
    $u = $st->fetch();
    if (!$u) return null;

    $gids = user_group_ids($pdo, $userId);
    $groups = [];
    if ($gids) {
        $in = implode(',', array_map('intval', $gids));
        $groups = $pdo->query("SELECT * FROM user_groups WHERE id IN ($in) ORDER BY level ASC, id ASC")->fetchAll();
    }

    $isSuper = false; $isAdmin = false; $level = 99;
    $permKeys = []; $names = [];
    foreach ($groups as $g) {
        if ((int)$g['is_super'] === 1) $isSuper = true;
        if ((int)$g['is_admin'] === 1) $isAdmin = true;
        $level = min($level, (int)$g['level']);
        $names[] = (string)$g['name'];
        foreach (array_filter(array_map('trim', explode(',', (string)$g['permissions']))) as $p) {
            $permKeys[$p] = true;
        }
    }
    return [
        'user'            => $u,
        'groups'          => $groups,
        'group_ids'       => $gids,
        'group_names'     => $names,
        'is_super'        => $isSuper,
        'is_admin'        => $isAdmin,
        'level'           => $groups ? $level : 99,
        'group_perms_csv' => implode(',', array_keys($permKeys)),
    ];
}

/** 由聚合身份组装会话用户数据（登录与会话自愈共用） */
function build_session_user(PDO $pdo, array $ident): array {
    $u = $ident['user'];
    return [
        'id'          => (int)$u['id'],
        'username'    => (string)$u['username'],
        'display_name'=> (string)$u['display_name'],
        'group_id'    => $u['group_id'] !== null ? (int)$u['group_id'] : null,
        'group_ids'   => $ident['group_ids'],
        'group_name'  => implode('、', $ident['group_names']),
        'is_admin'    => $ident['is_admin'],
        'is_super'    => $ident['is_super'],
        'level'       => $ident['level'],
        // 「我的设置」入口开关（每用户；老数据缺列时按开启处理）
        'settings_entry' => (int)($u['settings_entry'] ?? 1) === 1,
        'permissions' => effective_permissions($pdo, (int)$u['id'], $ident['group_perms_csv'], $ident['is_super']),
    ];
}

/**
 * 判断指定用户是否属于"超级管理员组"（任一所属组 is_super=1）。
 * 用于禁止普通管理员对系统管理员账号进行任何操作。
 */
function user_is_super(PDO $pdo, int $userId): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM user_group_members m
                         JOIN user_groups g ON m.group_id = g.id
                         WHERE m.user_id = ? AND g.is_super = 1");
    $st->execute([$userId]);
    return (int)$st->fetchColumn() > 0;
}

/**
 * 读取指定用户的管理级别（所属用户组中的最高级别；数字越小级别越高）。
 * 无用户组的用户按最低级别 99 处理。
 */
function user_level(PDO $pdo, int $userId): int {
    $st = $pdo->prepare("SELECT MIN(g.level) FROM user_group_members m
                         JOIN user_groups g ON m.group_id = g.id WHERE m.user_id = ?");
    $st->execute([$userId]);
    $lvl = $st->fetchColumn();
    return $lvl !== null && $lvl !== false ? (int)$lvl : 99;
}

/** 当前登录用户的级别（会话中记录；兜底 99） */
function current_level(): int {
    $u = current_user();
    return $u !== null ? (int)($u['level'] ?? 99) : 99;
}

/**
 * 计算用户的有效权限集合 = 用户组权限 ∪ 用户级权限（user_permissions，二级授权）。
 * 超级管理员直接返回全部权限 key。
 */
function effective_permissions(PDO $pdo, ?int $userId, string $groupPermsCsv = '', bool $isSuper = false): array {
    if ($isSuper) {
        return array_keys($GLOBALS['PERMS']);
    }
    $perms = array_filter(array_map('trim', explode(',', $groupPermsCsv)));
    if ($userId !== null) {
        $st = $pdo->prepare("SELECT perm_key FROM user_permissions WHERE user_id = ?");
        $st->execute([$userId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $k) {
            $perms[] = (string)$k;
        }
    }
    return array_values(array_unique(array_filter($perms)));
}

/**
 * 返回当前登录管理员可管理的用户组 ID 数组。
 * - 超级管理员：返回 null（表示不受限制，可管理全部用户组）
 * - 普通管理员：返回其所属组 manageable_groups 字段解析出的 ID 列表
 * - 其他（无管理权）：返回空数组
 */
function manageable_group_ids(PDO $pdo): ?array {
    $u = current_user();
    if ($u === null) return [];
    if (!empty($u['is_super'])) return null; // 不受限
    // 拥有后台访问权限、且至少属于一个用户组（scope 定义在各所属组上，取并集）
    if (!has_perm('access_admin')) return [];
    $gids = !empty($u['group_ids']) ? $u['group_ids']
          : (!empty($u['group_id']) ? [(int)$u['group_id']] : []);
    if (empty($gids)) return [];
    $in = implode(',', array_map('intval', $gids));
    $rows = $pdo->query("SELECT manageable_groups FROM user_groups WHERE id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
    $ids = [];
    foreach ($rows as $csv) {
        foreach (resolve_ids((string)$csv) as $id) $ids[$id] = true;
    }
    return array_keys($ids);
}

/**
 * 共享子查询：目标用户（外层别名 u）的组名聚合 / 最高级别。
 * 各后台列表统一引用，避免同一 SQL 在多个文件重复书写。
 */
function group_names_sql(): string {
    return "(SELECT GROUP_CONCAT(g.name ORDER BY g.level ASC SEPARATOR '、')
               FROM user_group_members m JOIN user_groups g ON m.group_id=g.id
              WHERE m.user_id=u.id)";
}

/** 目标用户最高级别（数字越小越高；无组视为 99） */
function target_level_sql(): string {
    return "(SELECT COALESCE(MIN(g.level), 99)
               FROM user_group_members m JOIN user_groups g ON m.group_id=g.id
              WHERE m.user_id=u.id)";
}

/**
 * 当前管理员可管理的用户列表：
 * 级别更低 + 在可管理用户组范围内；外加自管组中的同组其他成员（同级例外）。
 */
function manageable_users(PDO $pdo): array {
    $myLevel = current_level();
    $lvlSql = target_level_sql();
    list($peerClause, $peerArgs) = self_managed_peer_clause((int)current_user()['id'], $myLevel);
    $sql = "SELECT u.id, u.username, u.display_name
            FROM users u
            WHERE ($lvlSql > ? OR $peerClause)";
    $args = array_merge([$myLevel], $peerArgs);
    $scope = manageable_group_ids($pdo);
    if ($scope !== null) {
        if (empty($scope)) return [];
        $in = implode(',', array_map('intval', $scope));
        // 自管组的同组同伴天然在范围内（本组已列入可管理组）
        $sql .= " AND EXISTS (SELECT 1 FROM user_group_members m2
                              WHERE m2.user_id = u.id AND m2.group_id IN ($in))";
    }
    $sql .= " ORDER BY $lvlSql, u.id";
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/**
 * 「自管组同级例外」SQL 片段（各列表查询共用）。
 *
 * 一个普通组（is_admin=0、is_super=0）把"本组"勾选进自己的可管理用户组
 * （FIND_IN_SET 自身 id）时即为自管组：组内成员可互相管理，但不能操作自己。
 * 安全约束：目标若还挂在比我更高（数字更小）级别的组上，例外不生效。
 *
 * 约定：外层查询目标表别名为 u。
 * 返回 [sql片段, 绑定参数]（顺序：我的 ID、我的级别、我的 ID）。
 */
function self_managed_peer_clause(int $meId, int $myLevel): array {
    $lvlSql = target_level_sql();
    $sql = "u.id <> ? AND $lvlSql >= ? AND EXISTS (
                SELECT 1
                FROM user_group_members am
                JOIN user_group_members pm ON pm.group_id = am.group_id
                JOIN user_groups smg       ON smg.id = am.group_id
                WHERE am.user_id = ?
                  AND pm.user_id = u.id
                  AND smg.is_admin = 0 AND smg.is_super = 0
                  AND FIND_IN_SET(smg.id, smg.manageable_groups)
            )";
    return [$sql, [$meId, $myLevel, $meId]];
}

/**
 * 当前管理员是否可操作指定用户：
 * 1. 自己永远可操作本人（改名等，删除自己由调用方拦截）；
 * 2. 自管组例外：与目标同属一个"把本组列入可管理范围"的普通组、
 *    且目标不比我级别更高时，即使同级也允许互改；
 * 3. 级别规则（核心）：只能管理级别"低于"自己的用户（目标 level 更大），
 *    同级管理员（含系统管理员之间、普通管理员之间）禁止相互修改；
 * 4. 普通管理员还必须落在其"可管理用户组"范围内；
 *    超级管理员（级别1）不受可管理组限制，但同样不能操作同级的其他系统管理员。
 */
function can_manage_user(PDO $pdo, int $targetUserId): bool {
    $u = current_user();
    if ($u === null) return false;
    if ((int)$u['id'] === $targetUserId) return true;

    // 例外：自管组中的同组其他成员（本组须把自己列入可管理范围）
    $myGids = user_group_ids($pdo, (int)$u['id']);
    if ($myGids) {
        $in = implode(',', array_map('intval', $myGids));
        $ps = $pdo->prepare("SELECT COUNT(*)
                               FROM user_group_members pm
                               JOIN user_groups smg ON smg.id = pm.group_id
                              WHERE pm.user_id = ?
                                AND pm.group_id IN ($in)
                                AND smg.is_admin = 0 AND smg.is_super = 0
                                AND FIND_IN_SET(smg.id, smg.manageable_groups)");
        $ps->execute([$targetUserId]);
        // 目标若还挂在比我更高的级别上，则不适用同级例外
        if ((int)$ps->fetchColumn() > 0 && user_level($pdo, $targetUserId) >= current_level()) {
            return true;
        }
    }

    // 级别判定：同级或更高级别一律禁止
    if (user_level($pdo, $targetUserId) <= current_level()) {
        return false;
    }

    if (!empty($u['is_super'])) return true; // 超管管理所有更低级别用户

    $scope = manageable_group_ids($pdo);
    if ($scope === null) return true;
    if (empty($scope)) return false;
    // 多组：目标用户只要在范围内的任一用户组中即可
    $tgids = user_group_ids($pdo, $targetUserId);
    if (empty($tgids)) return false;
    return !empty(array_intersect($tgids, $scope));
}

/* ============================================================
   二级授权（权限委派）
   ============================================================ */

/**
 * 当前用户可对外授予的权限 key 列表：
 * - 超级管理员：全部权限；
 * - 其他用户：仅本人持有的权限（不能把自己没有的权限授予别人）。
 */
function grantable_perms(): array {
    if (is_super()) return array_keys($GLOBALS['PERMS']);
    $u = current_user();
    $perms = is_array($u['permissions'] ?? null) ? $u['permissions'] : [];
    // 只保留仍在权限清单内的 key
    return array_values(array_intersect(array_keys($GLOBALS['PERMS']), $perms));
}

/**
 * 是否可向指定用户进行二级授权：
 * 须持有「允许二级授权(can_delegate)」权限，且目标是可管理的更低级别用户（不能授权给自己）。
 */
function can_delegate_to(PDO $pdo, int $targetUserId): bool {
    $u = current_user();
    if ($u === null || (int)$u['id'] === $targetUserId) return false;
    if (!has_perm('can_delegate')) return false;
    // 授权对象必须级别"严格更低"：自管组的同级例外不适用于权限授予
    if (user_level($pdo, $targetUserId) <= current_level()) return false;
    return can_manage_user($pdo, $targetUserId);
}

/**
 * 是否可撤销指定用户的某项用户级授权：
 * - 超级管理员：可撤销任意用户级授权；
 * - 其他授权人：仅可撤销"由本人授予"的授权。
 */
function can_revoke_grant(PDO $pdo, int $targetUserId, string $permKey): bool {
    $u = current_user();
    if ($u === null) return false;
    if (!has_perm('can_delegate')) return false;
    if (!empty($u['is_super'])) {
        return can_manage_user($pdo, $targetUserId);
    }
    $st = $pdo->prepare("SELECT granted_by FROM user_permissions WHERE user_id=? AND perm_key=?");
    $st->execute([$targetUserId, $permKey]);
    return (int)$st->fetchColumn() === (int)$u['id'];
}

/**
 * 当前管理员新增/调整用户时，允许选择的目标用户组。
 * 超级管理员：全部用户组（含超级管理员组，可直接把用户提为超管）；
 * 普通管理员：仅其可管理用户组（且过滤掉同级/更高级别组）。
 */
function allowed_target_groups(PDO $pdo): array {
    $scope = manageable_group_ids($pdo);
    if ($scope === null) {
        return $pdo->query("SELECT * FROM user_groups ORDER BY level, id")->fetchAll();
    }
    if (empty($scope)) return [];
    $in = implode(',', array_map('intval', $scope));
    $st = $pdo->prepare("SELECT * FROM user_groups WHERE id IN ($in) AND level > ? ORDER BY level, id");
    $st->execute([current_level()]);
    return $st->fetchAll();
}

/**
 * 当前管理员"可改动归属"的用户组：可管理范围内 且 级别严格更低。
 * 同级组（含自管组本组）不可改动——防止编辑同组同伴时把其从本组移除。
 * 系统管理员可改动全部组。
 */
function mutable_group_ids(PDO $pdo): array {
    if (is_super()) {
        return array_map('intval',
            $pdo->query("SELECT id FROM user_groups")->fetchAll(PDO::FETCH_COLUMN));
    }
    $scope = manageable_group_ids($pdo) ?? [];
    if (!$scope) return [];
    $in = implode(',', array_map('intval', $scope));
    $rows = $pdo->query("SELECT id FROM user_groups
                         WHERE id IN ($in) AND level > " . current_level())
                ->fetchAll(PDO::FETCH_COLUMN);
    return array_map('intval', $rows);
}

/**
 * 导航内容（nav_groups / nav_items 均有 group_ids、user_ids 两列）的
 * 「管理范围」SQL 片段。
 *
 * 命中任一即在范围内：
 *   a) 公开：两列均空，所有人可见；
 *   b) 可见用户组命中其“可管理用户组”（FIND_IN_SET）；
 *   c) 单独可见用户命中其可管理用户；
 *   d) 直接授权：当前用户所属的某用户组，被超管列入该内容的“可管理用户组”
 *      （$manageableExpr 指定存放该 CSV 的列表达式；导航项取所属导航分组的值）。
 * 超级管理员不受限制（返回恒真片段）。
 *
 * @param string $alias           外层行别名（如 g / n / t）
 * @param string $manageableExpr  可管理用户组 CSV 的列/子查询表达式；空串表示不判断 d)
 * @return array [0 => SQL片段, 1 => 绑定参数]
 */
function nav_scope_clause(PDO $pdo, string $alias, string $manageableExpr = ''): array {
    if (is_super()) return ['1=1', []];

    $parts = ["($alias.group_ids='' AND $alias.user_ids='')"];
    $args  = [];
    foreach ((array)manageable_group_ids($pdo) as $gid) {
        $parts[] = "FIND_IN_SET(?, $alias.group_ids)";
        $args[]  = (int)$gid;
    }
    foreach (manageable_users($pdo) as $mu) {
        $parts[] = "FIND_IN_SET(?, $alias.user_ids)";
        $args[]  = (int)$mu['id'];
    }
    // d) 直接授权：本用户所属组是否在该内容的可管理用户组清单中
    if ($manageableExpr !== '') {
        $meId = (int)current_user()['id'];
        foreach (user_group_ids($pdo, $meId) as $myGid) {
            $parts[] = "FIND_IN_SET(?, $manageableExpr)";
            $args[]  = $myGid;
        }
    }
    return ['(' . implode(' OR ', $parts) . ')', $args];
}

/** 指定导航分组 / 导航项当前是否在当前管理员的管理范围内（更新、删除前校验） */
function nav_row_in_scope(PDO $pdo, string $table, int $rowId): bool {
    if (!in_array($table, ['nav_groups', 'nav_items'], true)) return false;
    // 可管理 CSV 表达式：分组直接取本列；导航项取所属导航分组的列
    if ($table === 'nav_groups') {
        $manageableExpr = 't.manageable_group_ids';
    } else {
        $manageableExpr = "(SELECT pg.manageable_group_ids FROM nav_groups pg
                            JOIN nav_items ti ON ti.group_id=pg.id
                           WHERE ti.id=t.id)";
    }
    list($clause, $args) = nav_scope_clause($pdo, 't', $manageableExpr);
    $st = $pdo->prepare("SELECT EXISTS(SELECT 1 FROM `$table` t
                          WHERE t.id=? AND $clause)");
    $st->execute(array_merge([$rowId], $args));
    return (int)$st->fetchColumn() === 1;
}

/**
 * 更新授权列（group_ids / user_ids）时的范围合并：
 * - 范围外的「现有授权」冻结保留（无权撤销，也不能因本次提交丢失）；
 * - 范围内授权以本次提交为准（可增可减）。
 *
 * @param array $posted  本次提交解析出的 ID
 * @param array $current 现有 ID
 * @param array $allowed 可管理 ID（范围）
 * @return string 可落库 CSV
 */
function scoped_grant_csv(array $posted, array $current, array $allowed): string {
    $frozen   = array_diff($current, $allowed);
    $editable = array_intersect($posted, $allowed);
    $final    = array_unique(array_merge($frozen, $editable));
    sort($final);
    return implode(',', array_map('intval', $final));
}

/**
 * 用户组属性统一归一化（所有组写入的唯一规则入口）。
 * 入参为"期望"的类型/权限/范围/级别；返回可直接落库的列值：
 * - super：恒 1 级、全权自动（不落地）、不设范围；
 * - admin：强制保留 access_admin，级别钳制 2~98；
 * - normal：权限与范围均以提交为准、二者不再隐式联动
 *   （组长模式已移除；需要后台访问请显式勾选 access_admin），级别钳制 3~98。
 */
function normalize_group_attributes(string $type, array $permKeys, array $scopeIds,
                                    int $postedLevel, ?array $current): array {
    $permKeys = array_values(array_unique(array_map('strval', $permKeys)));
    $scopeIds  = array_values(array_unique(array_map('intval', $scopeIds)));

    if ($type === 'super') {
        return [
            'is_admin' => 1, 'is_super' => 1, 'level' => 1,
            'permissions' => '', 'manageable_groups' => '',
        ];
    }

    if ($type === 'admin') {
        if (!in_array('access_admin', $permKeys, true)) $permKeys[] = 'access_admin';
        $default = $current ? (int)$current['level'] : 2;
        $level = $postedLevel > 0 ? $postedLevel : $default;
        return [
            'is_admin' => 1, 'is_super' => 0,
            'level' => max(2, min(98, $level)),
            'permissions' => implode(',', $permKeys),
            'manageable_groups' => implode(',', $scopeIds),
        ];
    }

    // normal
    $default = $current ? (int)$current['level'] : 3;
    $level = $postedLevel > 0 ? $postedLevel : $default;
    return [
        'is_admin' => 0, 'is_super' => 0,
        'level' => max(3, min(98, $level)),
        'permissions' => implode(',', $permKeys),
        'manageable_groups' => implode(',', $scopeIds),
    ];
}

/** 读取网站参数（合并默认值） */
function site_settings(?PDO $pdo = null): array {
    if (isset($GLOBALS['__site_settings_cache'])) return $GLOBALS['__site_settings_cache'];
    $cache = site_defaults();
    if ($pdo) {
        foreach ($pdo->query("SELECT setting_key, setting_value FROM settings") as $r) {
            $cache[$r['setting_key']] = $r['setting_value'];
        }
    }
    return $GLOBALS['__site_settings_cache'] = $cache;
}

/** 清除网站参数缓存（后台保存设置后调用，保证同一请求内后续读取为新值） */
function site_settings_reset(): void {
    unset($GLOBALS['__site_settings_cache']);
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: /login.html');
        exit;
    }
    enforce_login_switch();
}

/** 检查登录总开关并踢出非超级管理员的当前会话 */
function enforce_login_switch(): void {
    if (!is_logged_in()) return;
    global $pdo;
    $s = site_settings($pdo);
    if (($s['login_disabled'] ?? '0') === '1' && !is_super()) {
        // 吊销当前设备行，避免其继续占用「最大设备数」名额
        $devId = current_device_id();
        if ($devId > 0) {
            try { revoke_device_row($pdo, $devId); } catch (Throwable $e) {}
        }
        session_destroy();
        header('Location: /login.html');
        exit;
    }
}

/**
 * 后台页面守卫：必须登录 + 拥有「后台访问权限 access_admin」。
 * 系统管理员自动通过；普通用户即便直接输入后台 URL 也会被送回首页。
 */
function require_admin(): void {
    if (!is_logged_in()) {
        header('Location: /login.html');
        exit;
    }
    if (!has_perm('access_admin')) {
        header('Location: /index.html');
        exit;
    }
}

/**
 * 把逗号分隔的 ID 列表解析为 int 数组
 */
function resolve_ids(string $csv): array {
    return array_filter(array_map('intval', explode(',', trim($csv))));
}

/**
 * 判断一项授权（group_ids 面向用户组、user_ids 面向单独用户）对当前用户是否可见。
 * 用户可属多个用户组：$userGroupIds 为其全部组 ID，任一组命中即可见。
 * 规则：二者均为空 = 所有人可见（公开）；否则满足"用户组命中"或"单独用户命中"任一即可见。
 */
function is_visible_to(string $groupIdsCsv, string $userIdsCsv, array $userGroupIds, ?int $userId): bool {
    $gids = resolve_ids($groupIdsCsv);
    $uids = resolve_ids($userIdsCsv);
    if (empty($gids) && empty($uids)) {
        return true; // 公开
    }
    if ($userId !== null && in_array($userId, $uids, true)) {
        return true; // 单独用户授权命中
    }
    return !empty(array_intersect($userGroupIds, $gids)); // 任一所属用户组命中
}

/**
 * 返回当前用户可见的导航，按分组组织：
 *   [group_id => ['group' => 分组行, 'items' => [导航项...]]]
 * 分组可见且其中至少有一个可见导航项时才返回。
 * 授权维度：既看用户组（group_ids，含多组），也看单独用户（user_ids）。
 */
function visible_nav(PDO $pdo, ?array $user): array {
    $userId = $user['id'] ?? null;
    $userGroupIds = !empty($user['group_ids']) ? array_map('intval', $user['group_ids'])
                   : (!empty($user['group_id']) ? [(int)$user['group_id']] : []);

    $groups = $pdo->query("SELECT * FROM nav_groups WHERE status=1 ORDER BY sort_order ASC, id ASC")->fetchAll();
    $items  = $pdo->query("SELECT * FROM nav_items WHERE status=1 ORDER BY sort_order ASC, id ASC")->fetchAll();

    $itemsByGroup = [];
    foreach ($items as $it) {
        $itemsByGroup[(int)$it['group_id']][] = $it;
    }

    $nav = [];
    foreach ($groups as $g) {
        if (!is_visible_to($g['group_ids'], $g['user_ids'], $userGroupIds, $userId)) {
            continue; // 分组不可见
        }
        $list = [];
        foreach (($itemsByGroup[(int)$g['id']] ?? []) as $it) {
            if (is_visible_to($it['group_ids'], $it['user_ids'], $userGroupIds, $userId)) {
                $list[] = $it;
            }
        }
        if ($list) {
            $nav[(int)$g['id']] = ['group' => $g, 'items' => $list];
        }
    }
    return $nav;
}

/* ============================================================
   会话自愈：每次请求从数据库刷新当前用户的级别 / 权限
   1) 修复旧会话缺少 level 被当成 99 级、导致"看不到任何用户"的问题，
      无需用户手动重新登录；
   2) 系统管理员对权限、级别、用户组的调整即时生效；
   3) 用户已被删除时自动清除登录态。
   ============================================================ */
function sync_session_user(PDO $pdo): void {
    $uid = (int)($_SESSION['user']['id'] ?? 0);
    if ($uid <= 0) return;

    // 多组聚合身份（权限并集 / 最高级别 / 任一管理员组）
    $ident = load_user_identity($pdo, $uid);
    if ($ident === null) {
        // 用户已被删除
        $_SESSION = [];
        session_destroy();
        header('Location: /login.html');
        exit;
    }
    $_SESSION['user'] = array_merge($_SESSION['user'], build_session_user($pdo, $ident));
}

// auth.php 总是在 db.php 之后加载：已登录时每次请求自动同步
if (is_logged_in() && ($GLOBALS['pdo'] ?? null) instanceof PDO) {
    sync_session_user($GLOBALS['pdo']);
}

/* ============================================================
   登录设备（user_sessions）
   - 每次登录按 PHP session_id 登记一行：设备名（UA 解析）、IP、
     登录时间、最后活跃时间；
   - 最大设备数（max_login_devices，0=不限）在登录时校验；
   - 被管理员或本人吊销（revoked=1）后，该设备下一次请求即被踢出；
   - 老会话（升级前已登录）在首次请求时自动补登，不受上限影响。
   ============================================================ */

/** 解析 User-Agent：['os','browser','name']；全部规则失败也返回可读兜底 */
function parse_device_label(string $ua): array {
    // —— 操作系统 / 设备类型 ——
    $os = '未知设备';
    if (preg_match('/iPad/i', $ua)) {
        $os = 'iPad';
    } elseif (preg_match('/iPhone|iPod/i', $ua)) {
        $os = 'iPhone';
    } elseif (preg_match('/Android/i', $ua)) {
        $os = preg_match('/Mobile/i', $ua) ? 'Android 手机' : 'Android 平板';
    } elseif (preg_match('/Windows NT/i', $ua)) {
        $os = 'Windows';
    } elseif (preg_match('/Mac OS X/i', $ua)) {
        $os = 'macOS';   // iPadOS 桌面 UA 也可能落到这里
    } elseif (preg_match('/Windows/i', $ua)) {
        $os = 'Windows';
    } elseif (preg_match('/Linux/i', $ua)) {
        $os = 'Linux';
    }

    // —— 浏览器 / 内置 WebView ——（顺序敏感：微信/Edge 等也包含 Chrome/Safari 标记）
    $browser = '';
    if (preg_match('/MicroMessenger/i', $ua)) {
        $browser = '微信内置浏览器';
    } elseif (preg_match('/QQBrowser/i', $ua)) {
        $browser = 'QQ 浏览器';
    } elseif (preg_match('/Edg(?:e|A|iOS)?\//i', $ua)) {
        $browser = 'Edge';
    } elseif (preg_match('/OPR\/|Opera/i', $ua)) {
        $browser = 'Opera';
    } elseif (preg_match('/Firefox\//i', $ua)) {
        $browser = 'Firefox';
    } elseif (preg_match('/Chrome\//i', $ua)) {
        $browser = 'Chrome';
    } elseif (preg_match('/Version\/[0-9.]+.*Safari/i', $ua)) {
        $browser = 'Safari';
    } elseif (preg_match('/MSIE|Trident/i', $ua)) {
        $browser = 'IE';
    }

    return [
        'os'      => $os,
        'browser' => $browser,
        'name'    => $os . ($browser !== '' ? ' · ' . $browser : ''),
    ];
}

/** 清除会话 Cookie 并跳登录页（设备被吊销 / 被踢下线共用） */
function device_kick_redirect(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: /login.html?kicked=1');
    exit;
}

/**
 * 登记当前登录设备（登录成功后调用）。
 * 同一 PHP session 再次登录时重置该行（revoked 清零、时间刷新）。
 */
function register_user_session(PDO $pdo, int $userId): int {
    $sid = session_id();
    if ($sid === '' || $userId <= 0) return 0;
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (strlen($ua) > 500) $ua = substr($ua, 0, 500);
    $dev = parse_device_label($ua);
    $ip  = client_ip();

    $pdo->prepare("INSERT INTO user_sessions
                     (user_id, session_id, ip, user_agent, device_name, created_at, last_access_at)
                   VALUES (?,?,?,?,?,NOW(),NOW())
                   ON DUPLICATE KEY UPDATE
                     user_id=VALUES(user_id), revoked=0,
                     ip=VALUES(ip), user_agent=VALUES(user_agent), device_name=VALUES(device_name),
                     created_at=NOW(), last_access_at=NOW()")
        ->execute([$userId, $sid, $ip !== '' ? $ip : null, $ua, $dev['name']]);

    $id = (int)$pdo->lastInsertId();   // 命中更新时 lastInsertId 可能为 0
    if ($id <= 0) {
        $q = $pdo->prepare("SELECT id FROM user_sessions WHERE session_id=?");
        $q->execute([$sid]);
        $id = (int)$q->fetchColumn();
    }
    $_SESSION['_device_id'] = $id;
    $_SESSION['_dev_hb'] = time();
    return $id;
}

/** 每次请求校验当前设备会话：被吊销则踢出；缺失则补登；定时心跳与清理 */
function enforce_device_session(): void {
    $u = current_user();
    if (!$u) return;
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!($pdo instanceof PDO)) return;

    try {
        $sid = session_id();
        $q = $pdo->prepare("SELECT * FROM user_sessions WHERE session_id=?");
        $q->execute([$sid]);
        $row = $q->fetch();

        if (!$row) {
            // 升级前已存在的老会话：自动补登，不做设备数限制
            register_user_session($pdo, (int)$u['id']);
        } else {
            if ((int)$row['user_id'] !== (int)$u['id'] || (int)$row['revoked'] === 1) {
                device_kick_redirect();
            }
            $_SESSION['_device_id'] = (int)$row['id'];

            // 心跳：每 30 秒最多写一次，刷新最后活跃时间/IP
            $lastHb = (int)($_SESSION['_dev_hb'] ?? 0);
            if (time() - $lastHb >= 30) {
                $ip = client_ip();
                $pdo->prepare("UPDATE user_sessions SET last_access_at=NOW(), ip=? WHERE id=?")
                    ->execute([$ip !== '' ? $ip : null, (int)$row['id']]);
                $_SESSION['_dev_hb'] = time();
            }
        }

        // 约 2% 请求顺带做一次垃圾清理
        if (mt_rand(1, 50) === 1) gc_user_sessions($pdo);
    } catch (Throwable $e) {
        // 任何异常都不影响页面正常访问
    }
}

/** 当前登录设备行 ID（无则 0） */
function current_device_id(): int {
    return (int)($_SESSION['_device_id'] ?? 0);
}

/** 活跃判定时间点：有限会话按登录有效期；浏览器会话按 30 天兜底 */
function device_active_cutoff(PDO $pdo): string {
    $life = (int)(site_settings($pdo)['session_lifetime'] ?? 0);
    if ($life > 0) return date('Y-m-d H:i:s', time() - $life);
    return date('Y-m-d H:i:s', time() - 30 * 86400);
}

/**
 * 登录前检查：是否还能新增一台设备。
 * 返回 ['ok','max','count']；max=0 表示不限制。
 */
function can_open_new_session(PDO $pdo, int $userId): array {
    $max = (int)(site_settings($pdo)['max_login_devices'] ?? 0);
    if ($max <= 0) return ['ok' => true, 'max' => 0, 'count' => 0];

    $q = $pdo->prepare("SELECT COUNT(*) FROM user_sessions
                         WHERE user_id=? AND revoked=0 AND last_access_at > ?");
    $q->execute([$userId, device_active_cutoff($pdo)]);
    $count = (int)$q->fetchColumn();
    return ['ok' => $count < $max, 'max' => $max, 'count' => $count];
}

/** 某用户全部设备行（含已吊销，按最后活跃倒序） */
function user_device_sessions(PDO $pdo, int $userId): array {
    $q = $pdo->prepare("SELECT * FROM user_sessions WHERE user_id=? ORDER BY last_access_at DESC, id DESC");
    $q->execute([$userId]);
    return $q->fetchAll();
}

/** 吊销指定设备行（调用方负责归属/权限校验） */
function revoke_device_row(PDO $pdo, int $rowId): void {
    $pdo->prepare("UPDATE user_sessions SET revoked=1 WHERE id=?")->execute([$rowId]);
}

/** 清理：已吊销超 7 天删除；长期不活跃（90 天）的非吊销行删除 */
function gc_user_sessions(PDO $pdo): void {
    try {
        $pdo->exec("DELETE FROM user_sessions
                     WHERE revoked=1 AND last_access_at < (NOW() - INTERVAL 7 DAY)");
        $pdo->exec("DELETE FROM user_sessions
                     WHERE revoked=0 AND last_access_at < (NOW() - INTERVAL 90 DAY)");
    } catch (Throwable $e) {
        // 忽略
    }
}
