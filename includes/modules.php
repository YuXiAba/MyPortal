<?php
/**
 * 后台模块注册中心
 * =================
 * 后期添加新模块时，只需两步：
 *   1) 在下方 $ADMIN_MODULES 数组中注册一行
 *      （key / 名称 / URL / 所需权限 / 图标 / 所属菜单组）
 *   2) 在 admin/ 目录下创建对应的 .php 页面文件
 * 侧边栏会自动根据当前登录管理员的权限过滤并渲染，无需再逐个修改每个后台页面的导航 HTML。
 *
 * 字段说明：
 *   key    模块唯一标识（用于 active 高亮，建议与文件名一致）
 *   label  侧边栏显示名称
 *   url    伪静态访问地址
 *   perm   所需权限 key（对应 config.php 的 $PERMS），可传字符串；
 *          也可传数组表示"拥有其中任一权限即可见"；null 表示任何管理员可见
 *   icon   图标 emoji（可空）
 *   group  所属侧边栏菜单组 key（见下方 $ADMIN_MENU_GROUPS）；
 *          空字符串 '' 表示不分组，作为顶级单链接渲染（如「概览」）。
 *          组以可折叠的二级菜单展示，含当前页面的组默认展开。
 */

$ADMIN_MODULES = [
    // 顶级（不分组）
    ['key' => 'dashboard',  'label' => '概览',         'url' => '/admin/index.html',     'perm' => null,               'icon' => '📊', 'group' => ''],

    // 门户导航
    ['key' => 'navgroups',  'label' => '导航分组管理', 'url' => '/admin/navgroups.html', 'perm' => 'manage_navgroups', 'icon' => '🗂️', 'group' => 'nav'],
    ['key' => 'navs',       'label' => '导航项管理',   'url' => '/admin/navs.html',      'perm' => 'manage_nav',       'icon' => '🧭', 'group' => 'nav'],
    ['key' => 'usernavs',   'label' => '用户自定义导航','url' => '/admin/usernavs.html', 'perm' => 'manage_user_navs', 'icon' => '⭐', 'group' => 'nav'],
    ['key' => 'engines',    'label' => '搜索引擎管理', 'url' => '/admin/engines.html',   'perm' => 'manage_engines',   'icon' => '🔍', 'group' => 'nav'],

    // 用户与权限（用户管理 / 用户组管理共用一个入口，组内切换）
    ['key' => 'users',      'label' => '用户管理',     'url' => '/admin/users.html',     'perm' => ['manage_users', 'create_users', 'reset_password'], 'icon' => '🧑‍🤝‍🧑', 'group' => 'user'],
    ['key' => 'groups',     'label' => '用户组管理',   'url' => '/admin/groups.html',    'perm' => 'manage_groups',    'icon' => '👥', 'group' => 'user'],
    ['key' => 'perms',      'label' => '权限管理',     'url' => '/admin/permissions.html','perm' => 'can_delegate',    'icon' => '🔐', 'group' => 'user'],

    // 记录与日志
    ['key' => 'logs',       'label' => '用户日志',     'url' => '/admin/logs.html',      'perm' => 'view_logs',        'icon' => '📝', 'group' => 'record'],
    ['key' => 'searchlogs', 'label' => '搜索记录',     'url' => '/admin/searchlogs.html','perm' => 'view_search_logs', 'icon' => '🔎', 'group' => 'record'],

    // 系统设置
    ['key' => 'settings',   'label' => '网站参数设置', 'url' => '/admin/settings.html',  'perm' => ['manage_settings', 'manage_user_entry'],  'icon' => '⚙️', 'group' => 'system'],
    ['key' => 'about',      'label' => '关于我们设置', 'url' => '/admin/about.html',     'perm' => 'manage_about',     'icon' => '📖', 'group' => 'system'],
    ['key' => 'themes',     'label' => '主题皮肤管理', 'url' => '/admin/themes.html',    'perm' => 'manage_themes',    'icon' => '🎨', 'group' => 'system'],
];

/**
 * 侧边栏菜单组定义（显示顺序即数组顺序）
 */
$ADMIN_MENU_GROUPS = [
    'nav'    => ['label' => '门户导航',   'icon' => '🧭'],
    'user'   => ['label' => '用户与权限', 'icon' => '👥'],
    'record' => ['label' => '记录与日志', 'icon' => '📋'],
    'system' => ['label' => '系统设置',   'icon' => '⚙️'],
];

/**
 * 判断模块对当前管理员是否可见（按 perm 配置：字符串=必须拥有；数组=任一即可）
 */
function module_visible(array $m): bool {
    if ($m['perm'] === null) return true;
    foreach ((array)$m['perm'] as $pkey) {
        if (has_perm($pkey)) return true;
    }
    return false;
}

/**
 * 渲染后台侧边栏（按当前管理员权限过滤；分组折叠展示）
 * @param string $activeKey 当前页面对应的模块 key（用于高亮与自动展开所在组）
 */
function render_admin_sidebar(string $activeKey): void {
    global $ADMIN_MODULES, $ADMIN_MENU_GROUPS;

    // 1) 按权限过滤
    $visible = [];
    foreach ($ADMIN_MODULES as $m) {
        if (module_visible($m)) $visible[] = $m;
    }

    // 2) 拆分为顶级项与按组聚合
    $topItems = [];
    $grouped  = []; // gkey => [modules]
    foreach ($visible as $m) {
        if ($m['group'] === '' || !isset($ADMIN_MENU_GROUPS[$m['group']])) {
            $topItems[] = $m;
        } else {
            $grouped[$m['group']][] = $m;
        }
    }

    $renderLink = function (array $m) use ($activeKey) {
        $cls = $m['key'] === $activeKey ? 'side-link active' : 'side-link';
        $icon = $m['icon'] !== '' ? $m['icon'] . ' ' : '';
        echo '<a href="' . e($m['url']) . '" class="' . $cls . '">' . e($icon . $m['label']) . '</a>';
    };

    echo '<aside class="admin-side"><h3>后台管理</h3>';

    // 3) 顶级项
    foreach ($topItems as $m) $renderLink($m);

    // 4) 分组（按 $ADMIN_MENU_GROUPS 的定义顺序；整组无可见项则跳过）
    foreach ($ADMIN_MENU_GROUPS as $gkey => $g) {
        if (empty($grouped[$gkey])) continue;
        $hasActive = false;
        foreach ($grouped[$gkey] as $m) {
            if ($m['key'] === $activeKey) { $hasActive = true; break; }
        }
        echo '<details class="side-group"' . ($hasActive ? ' open' : '') . '>';
        echo '<summary class="side-group-head">'
           . '<span class="sgh-icon">' . e($g['icon']) . '</span>'
           . '<span class="sgh-label">' . e($g['label']) . '</span>'
           . '<span class="sgh-arrow">▾</span></summary>';
        echo '<div class="side-sub">';
        foreach ($grouped[$gkey] as $m) $renderLink($m);
        echo '</div></details>';
    }

    echo '</aside>';
}
