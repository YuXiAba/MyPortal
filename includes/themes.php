<?php
/**
 * 主题皮肤注册中心
 * ============================================
 * 皮肤 = 一组覆盖 :root 默认值的 CSS 变量（可含暗色表面调整）。
 *
 * 两类皮肤：
 *   1) 内置皮肤：在本文件 builtin_themes() 注册；
 *   2) 自定义皮肤：把 <id>.css 放入 assets/themes/，文件头部注释写元信息
 *      （字段见 parse_theme_meta()），系统自动识别，无需改 PHP。
 *
 * 可见性：settings.themes_enabled（JSON map id=>1/0）；未显式记录 = 可见。
 * 默认皮肤：settings.theme_default。
 * 用户选择：users.theme（登录用户持久化）+ cookie theme（访客）。
 *
 * 元数据覆盖：theme_overrides 表（manage_theme_meta 权限）——后台可修改皮肤
 * 名称/版本/作者/说明/暗色类型与「可见用户组」，无需改动皮肤文件；
 * 覆盖字段为空时回退到内置注册值或 CSS 注释头。
 */

/** 内置皮肤清单（default 皮肤的变量在 style.css :root 中，无独立文件） */
function builtin_themes(): array {
    return [
        [
            'id' => 'default', 'name' => '经典蓝', 'file' => '',
            'author' => '系统内置', 'version' => '1.0', 'dark' => false,
            'desc' => '默认浅色风格，清爽专业的门户蓝。',
            'swatches' => ['#2563eb', '#1d4ed8', '#eef2ff'],
        ],
        [
            'id' => 'violet', 'name' => '梦幻紫', 'file' => 'violet.css',
            'author' => '系统内置', 'version' => '1.0', 'dark' => false,
            'desc' => '优雅神秘的紫罗兰，创意与设计感之选。',
            'swatches' => ['#7c3aed', '#6d28d9', '#f1ebff'],
        ],
        [
            'id' => 'emerald', 'name' => '翡翠绿', 'file' => 'emerald.css',
            'author' => '系统内置', 'version' => '1.0', 'dark' => false,
            'desc' => '自然清新的翡翠绿，护眼而有生机。',
            'swatches' => ['#059669', '#047857', '#e6f7f1'],
        ],
        [
            'id' => 'rose', 'name' => '玫瑰红', 'file' => 'rose.css',
            'author' => '系统内置', 'version' => '1.0', 'dark' => false,
            'desc' => '热情鲜明的玫瑰红，醒目而有活力。',
            'swatches' => ['#e11d48', '#be123c', '#ffe9ee'],
        ],
        [
            'id' => 'amber', 'name' => '琥珀橙', 'file' => 'amber.css',
            'author' => '系统内置', 'version' => '1.0', 'dark' => false,
            'desc' => '温暖明亮的琥珀橙，积极而醒目。',
            'swatches' => ['#d97706', '#b45309', '#fff3e0'],
        ],
        [
            'id' => 'cyan', 'name' => '青碧', 'file' => 'cyan.css',
            'author' => '系统内置', 'version' => '1.0', 'dark' => false,
            'desc' => '清澈通透的青碧色，科技与沉静并存。',
            'swatches' => ['#0891b2', '#0e7490', '#e2f7fb'],
        ],
        [
            'id' => 'dark', 'name' => '暗夜黑', 'file' => 'dark.css',
            'author' => '系统内置', 'version' => '1.0', 'dark' => true,
            'desc' => '深色暗色模式，夜间浏览护眼，沉浸专注。',
            'swatches' => ['#4f8cff', '#111c2e', '#0b1220'],
        ],
    ];
}

/**
 * 扫描 assets/themes/*.css，解析文件头注释元信息。
 * 注释格式（每行一个字段，大小写不敏感）：
 *   Theme Id: my-skin
 *   Theme Name: 我的皮肤
 *   Author: 作者名
 *   Version: 1.0
 *   Description: 皮肤说明
 *   Dark: no          （yes/1/true = 暗色皮肤）
 */
function scan_custom_themes(): array {
    $dir = dirname(__DIR__) . '/assets/themes';
    if (!is_dir($dir)) return [];
    $out = [];
    foreach (glob($dir . '/*.css') ?: [] as $path) {
        $src = (string)file_get_contents($path);
        $meta = parse_theme_meta($src);
        $id = (string)($meta['id'] ?? '');
        // 无 Theme Id 或与内置/已扫描冲突时跳过；ID 仅允许安全字符
        if ($id === '' || !preg_match('/^[a-zA-Z0-9_-]{2,40}$/', $id)) continue;
        if (isset($out[$id])) continue;
        $out[$id] = [
            'id'       => $id,
            'name'     => (string)($meta['name'] ?? $id),
            'file'     => basename($path),
            'author'   => (string)($meta['author'] ?? '未知作者'),
            'version'  => (string)($meta['version'] ?? '1.0'),
            'desc'     => (string)($meta['desc'] ?? ''),
            'dark'     => !empty($meta['dark']),
            'builtin'  => false,
            'swatches' => [],
        ];
    }
    return array_values($out);
}

/** 解析 CSS 源码首个注释块中的元信息 */
function parse_theme_meta(string $src): array {
    if (!preg_match('/\A\s*\/\*+(.+?)\*+\//s', $src, $m)) return [];
    $meta = [];
    foreach (preg_split('/\r?\n/', $m[1]) as $line) {
        $line = trim($line, " \t*");
        if ($line === '' || strpos($line, ':') === false) continue;
        list($k, $v) = explode(':', $line, 2);
        $k = strtolower(trim($k));
        $v = trim($v);
        switch ($k) {
            case 'theme id': $meta['id'] = $v; break;
            case 'theme name': $meta['name'] = $v; break;
            case 'author': $meta['author'] = $v; break;
            case 'version': $meta['version'] = $v; break;
            case 'description': $meta['desc'] = $v; break;
            case 'dark':
                $meta['dark'] = in_array(strtolower($v), ['yes', '1', 'true', 'dark'], true);
                break;
        }
    }
    return $meta;
}

/**
 * 全部皮肤（内置 + 自定义），内置在前；以 id 为键。
 * 自定义皮肤 id 与内置冲突时以内置为准。
 * 结果会合并 theme_overrides 中的后台覆盖值（名称/版本/作者/说明/暗色/可见组）。
 */
function all_themes(): array {
    if (!isset($GLOBALS['__all_themes_reset'])) $GLOBALS['__all_themes_reset'] = false;
    if (!$GLOBALS['__all_themes_reset'] && isset($GLOBALS['__all_themes_cache'])
        && is_array($GLOBALS['__all_themes_cache'])) {
        return $GLOBALS['__all_themes_cache'];
    }
    $themes = [];
    foreach (builtin_themes() as $t) {
        $t['builtin'] = true;
        $themes[$t['id']] = $t;
    }
    foreach (scan_custom_themes() as $t) {
        if (!isset($themes[$t['id']])) $themes[$t['id']] = $t;
    }
    $pdo = $GLOBALS['pdo'] ?? null;
    if ($pdo instanceof PDO) {
        try { $themes = merge_theme_overrides($pdo, $themes); }
        catch (Throwable $e) { /* 覆盖表读取失败时降级为原始元数据，不致致命 */ }
    }
    $GLOBALS['__all_themes_cache'] = $themes;
    $GLOBALS['__all_themes_reset'] = false;
    return $themes;
}

/** 清除皮肤清单与覆盖表的缓存（元数据/可见性写入后同进程重读时使用） */
function all_themes_reset(): void {
    $GLOBALS['__all_themes_reset'] = true;
    $GLOBALS['__all_themes_cache'] = null;
}

/** 读取全部皮肤元数据覆盖（theme_id => 行）；表缺失时返回空 */
function theme_overrides_map(PDO $pdo): array {
    static $ovCache = null;
    if (!empty($GLOBALS['__all_themes_reset'])) { $ovCache = null; }
    if ($ovCache !== null) return $ovCache;
    $out = [];
    try {
        foreach ($pdo->query("SELECT * FROM theme_overrides") as $r) {
            $out[(string)$r['theme_id']] = $r;
        }
    } catch (Throwable $e) { return []; }
    return $ovCache = $out;
}

/** 把覆盖行的 visible_groups CSV 解析为整型 ID 数组 */
function override_visible_group_ids($row): array {
    if (empty($row['visible_groups'])) return [];
    $ids = array_filter(array_map('intval', explode(',', (string)$row['visible_groups'])));
    return array_values(array_unique($ids));
}

/** 将 theme_overrides 覆盖合并进皮肤集合（空字段不覆盖；dark 用 -1 表示不覆盖） */
function merge_theme_overrides(PDO $pdo, array $themes): array {
    $ovMap = theme_overrides_map($pdo);
    foreach ($themes as $id => $t) {
        $t['visible_groups'] = [];
        $t['overridden'] = false;
        if (!isset($ovMap[$id])) { $themes[$id] = $t; continue; }
        $ov = $ovMap[$id];
        $changed = false;
        foreach (['name','author','version'] as $k) {
            if ((string)$ov[$k] !== '') { $t[$k] = (string)$ov[$k]; $changed = true; }
        }
        if ((string)$ov['description'] !== '') { $t['desc'] = (string)$ov['description']; $changed = true; }
        $dark = (int)$ov['dark'];
        if ($dark === 0 || $dark === 1) { $t['dark'] = $dark === 1; $changed = true; }
        $vg = override_visible_group_ids($ov);
        if ($vg) { $t['visible_groups'] = $vg; $changed = true; }
        $t['overridden'] = $changed;
        $themes[$id] = $t;
    }
    return $themes;
}

/**
 * 写入（或更新）某皮肤的元数据覆盖。
 * $data: name/author/version/description（trim 后字符串）、dark(-1 不覆盖/0/1)、
 * visible_groups（整型 ID 数组，空数组=不限用户组）。
 */
function save_theme_override(PDO $pdo, string $themeId, array $data): void {
    $vg = isset($data['visible_groups']) && is_array($data['visible_groups'])
        ? array_values(array_unique(array_filter(array_map('intval', $data['visible_groups'])))) : [];
    $dark = isset($data['dark']) ? (int)$data['dark'] : -1;
    if (!in_array($dark, [-1, 0, 1], true)) $dark = -1;
    $up = $pdo->prepare("INSERT INTO theme_overrides
        (theme_id, name, author, version, description, dark, visible_groups)
        VALUES (?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE name=VALUES(name), author=VALUES(author),
            version=VALUES(version), description=VALUES(description),
            dark=VALUES(dark), visible_groups=VALUES(visible_groups)");
    $up->execute([
        $themeId,
        (string)($data['name'] ?? ''),
        (string)($data['author'] ?? ''),
        (string)($data['version'] ?? ''),
        (string)($data['description'] ?? ''),
        $dark,
        implode(',', $vg),
    ]);
}

/** 皮肤可见性映射 id => bool（未显式记录 = 可见） */
function theme_enabled_map(PDO $pdo): array {
    $s = site_settings($pdo);
    $raw = json_decode((string)($s['themes_enabled'] ?? ''), true);
    $map = is_array($raw) ? $raw : [];
    $out = [];
    foreach (all_themes() as $id => $t) {
        $out[$id] = !isset($map[$id]) || (int)$map[$id] === 1;
    }
    return $out;
}

/**
 * 用户可见、可切换的皮肤（default 皮肤始终可见）。
 * 过滤规则（每个非 default 皮肤须同时满足）：
 *   1) 全局开关为「显示」（theme_enabled_map）；
 *   2) 未设置可见用户组，或视角用户属于其中任一可见组。
 * $viewUser 为 null 时以当前登录用户为视角；游客没有任何用户组，
 * 因此设置了可见用户组的皮肤对游客不可见。
 */
function available_themes(PDO $pdo, $viewUser = null): array {
    if ($viewUser === null) $viewUser = current_user();
    $gids = [];
    if (is_array($viewUser) && !empty($viewUser['id'])) {
        $gids = user_group_ids($pdo, (int)$viewUser['id']);
    }
    $map = theme_enabled_map($pdo);
    $out = [];
    foreach (all_themes() as $id => $t) {
        if ($id === 'default') { $out[$id] = $t; continue; }
        if (empty($map[$id])) continue;
        $vg = isset($t['visible_groups']) && is_array($t['visible_groups']) ? $t['visible_groups'] : [];
        if ($vg && !array_intersect($gids, $vg)) continue;
        $out[$id] = $t;
    }
    return $out;
}

/** 按 ID 获取皮肤（不存在返回 null） */
function get_theme(string $id): ?array {
    $all = all_themes();
    return $all[$id] ?? null;
}

/**
 * 当前生效皮肤 ID：
 * 登录用户 users.theme → cookie theme → 站点默认 → default；
 * 任一步命中「当前不可见」的皮肤则继续向下回退。
 */
function current_theme_id(PDO $pdo): string {
    $available = available_themes($pdo);
    $s = site_settings($pdo);

    $u = current_user();
    if ($u !== null) {
        $st = $pdo->prepare("SELECT theme FROM users WHERE id=?");
        $st->execute([(int)$u['id']]);
        $userTheme = (string)$st->fetchColumn();
        if ($userTheme !== '' && isset($available[$userTheme])) return $userTheme;
    }
    $cookieTheme = (string)($_COOKIE['theme'] ?? '');
    if ($cookieTheme !== '' && isset($available[$cookieTheme])) return $cookieTheme;

    $default = (string)($s['theme_default'] ?? 'default');
    if (isset($available[$default])) return $default;
    return 'default';
}

/** 皮肤样式文件 URL（default 皮肤无独立文件，返回空串）；带 mtime 破缓存 */
function theme_css_url(array $theme): string {
    if (empty($theme['file'])) return '';
    $path = dirname(__DIR__) . '/assets/themes/' . $theme['file'];
    if (!is_file($path)) return '';
    return '/assets/themes/' . rawurlencode($theme['file']) . '?v=' . filemtime($path);
}

/** 保存登录用户的皮肤选择（'' = 跟随站点默认） */
function save_user_theme(PDO $pdo, int $userId, string $themeId): void {
    $pdo->prepare("UPDATE users SET theme=? WHERE id=?")->execute([$themeId, $userId]);
}
