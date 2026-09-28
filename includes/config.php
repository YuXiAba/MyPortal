<?php
/**
 * 错误显示策略（生产环境安全）
 * ===========================================
 * 不把 PHP 警告/错误直接输出到页面（否则会出现在页面顶部、
 * 破坏布局并暴露服务器绝对路径），改为写入服务器错误日志。
 * 即使个别文件因上传不及时残留旧代码，也不会再在页面顶部
 * 出现 Warning；开发调试时在 php.ini 中打开 display_errors 即可。
 */
ini_set('display_errors', '0');
ini_set('log_errors', '1');

/** 站点基础信息 */
$SITE_NAME = '我的门户';

/** 载入后台模块注册中心（可扩展性：新模块只需在 modules.php 中注册） */
require_once __DIR__ . '/modules.php';

/** 载入富内容 / 图标渲染器（支持 text/html/md，图标支持 emoji/URL） */
require_once __DIR__ . '/format.php';

/** 用户组权限清单：key => 中文名（超级管理员自动拥有全部权限） */
$PERMS = [
    'access_admin'     => '后台访问权限',
    'manage_navgroups' => '导航分组管理',
    'manage_nav'       => '导航项管理',
    'manage_groups'    => '用户组管理',
    'manage_users'     => '用户管理',
    'create_users'     => '新建用户',
    'reset_password'   => '重置用户密码',
    'import_users'     => '批量导入用户',
    'manage_user_navs' => '用户导航管理',
    'manage_engines'   => '搜索引擎管理',
    'manage_settings'  => '网站参数设置',
    'manage_about'     => '关于我们设置',
    'view_logs'        => '查看用户日志',
    'view_search_logs' => '搜索记录查看',
    'manage_nav_clicks'=> '导航点击管理',
    'manage_themes'    => '主题皮肤管理',
    'manage_theme_meta' => '皮肤元数据管理',
    'manage_user_themes' => '用户主题管理',
    'manage_user_entry' => '用户设置入口管理',
    'can_delegate'     => '允许二级授权',
];

/**
 * 权限分类：权限矩阵与二级授权页按此分组展示。
 * 清单内的「每一项」权限都可经矩阵分配或二级授权——包括后台访问与
 * 「允许二级授权」本身（持有 can_delegate 者可把它再授予更低级别用户）。
 * 新增权限时：在 $PERMS 注册 key，并把 key 放入下列任一分类。
 */
$PERM_CATEGORIES = [
    'system' => [
        'label' => '系统基础', 'icon' => '🔐',
        'perms' => ['access_admin', 'manage_themes', 'manage_theme_meta', 'can_delegate'],
    ],
    'nav' => [
        'label' => '门户导航', 'icon' => '🧭',
        'perms' => ['manage_navgroups', 'manage_nav', 'manage_user_navs', 'manage_engines'],
    ],
    'user' => [
        'label' => '用户管理', 'icon' => '👥',
        'perms' => ['manage_groups', 'manage_users', 'create_users', 'reset_password', 'import_users', 'manage_user_themes', 'manage_user_entry'],
    ],
    'record' => [
        'label' => '内容与记录', 'icon' => '📋',
        'perms' => ['manage_settings', 'manage_about', 'view_logs', 'view_search_logs', 'manage_nav_clicks'],
    ],
];

/**
 * 返回「带校验」的权限分类：保证每个 PERM key 恰好属于一个分类。
 * 若有新权限忘记分类，自动归入「其他权限」兜底分类（不致在矩阵中消失）。
 */
function perm_categories(): array {
    $cats = $GLOBALS['PERM_CATEGORIES'];
    $seen = [];
    foreach ($cats as $ck => $c) {
        // 只保留清单内仍存在的 key，并记录归属
        $cats[$ck]['perms'] = array_values(array_filter(
            $c['perms'],
            function ($pk) use (&$seen) {
                if (!isset($GLOBALS['PERMS'][$pk]) || isset($seen[$pk])) return false;
                $seen[$pk] = true;
                return true;
            }
        ));
    }
    $missing = array_diff(array_keys($GLOBALS['PERMS']), array_keys($seen));
    if ($missing) {
        $cats['other'] = ['label' => '其他权限', 'icon' => '✨', 'perms' => array_values($missing)];
    }
    return $cats;
}

/** 网站参数默认值 */
function site_defaults(): array {
    return [
        // —— 分类一：基础信息 ——
        'site_name'        => $GLOBALS['SITE_NAME'] ?? '我的门户',
        'site_logo'        => '🏠',            // emoji 或图片 URL
        // —— 分类二：顶部导航（按钮显隐 + 自定义链接 JSON，链接可设访客可见） ——
        'top_show_admin'   => '1',
        'top_show_password'=> '1',
        'top_show_theme'   => '1',
        'top_show_logout'  => '1',
        'top_custom_links' => '[]',
        // —— 主题皮肤 ——
        'theme_default'    => 'default',     // 站点默认皮肤 ID
        'themes_enabled'   => '',            // 皮肤可见性 JSON（空=全部内置皮肤可见）
        // —— 界面显示开关 ——
        'home_show_weather'     => '1',      // 首页显示当地天气（免 key；浏览器定位，拒绝时 IP 粗定位）
        'users_show_last_login' => '1',      // 后台用户列表显示「上次登录」列
        // —— 分类三：底部版权（支持 text/html/md） ——
        'site_footer'      => '',
        'site_footer_type' => 'text',
        // —— 分类四：自定义代码 ——
        'custom_css'       => '',
        'custom_js'        => '',
        // —— 分类五：登录与会话 ——
        // 登录总开关：'1' = 关闭所有人登录，超级管理员除外
        'login_disabled'   => '0',
        // 登录状态有效期（秒）：0 = 浏览器关闭即失效；如 7200=2小时 / 86400=7天
        'session_lifetime' => '0',
        // 每个用户最大同时登录设备数：0 = 不限制；超限的新登录将被拒绝
        'max_login_devices' => '0',
        // —— 分类六：关于我们（manage_about 权限可编辑，支持二级授权） ——
        // 页面开关：'1' = 启用（公开访问）；'0' = 关闭（访客看到关闭提示，仅管理员可预览/编辑）
        'about_enabled'     => '1',
        'about_slogan'      => '一个轻量的 PHP 导航门户 —— 聚合搜索、分类导航与分级权限，让常用站点触手可及。',
        'about_content'     => '',                      // 详细介绍（text/html/md）
        'about_content_type'=> 'text',
        'about_features'    => json_encode([            // 功能特性卡片 JSON
            ['icon' => '🔍', 'title' => '多搜索引擎', 'desc' => '内置多个常用搜索引擎，首页一键切换，快速检索全网内容。'],
            ['icon' => '🧭', 'title' => '分类导航',   'desc' => '导航按分组整齐排列，支持图标、排序与启用状态，找站点不再翻收藏夹。'],
            ['icon' => '👥', 'title' => '多用户组',   'desc' => '一个用户可同时归属多个用户组，级别与权限按各组自动聚合。'],
            ['icon' => '🔐', 'title' => '双维度授权', 'desc' => '既能按用户组授权，也可对单个用户授权；粒度可到导航分组或单个导航项。'],
            ['icon' => '⭐', 'title' => '我的导航',   'desc' => '登录后可在「我的导航」中自助添加个人专属链接，仅本人可见。'],
            ['icon' => '📝', 'title' => '操作日志',   'desc' => '登录与后台操作全程留痕，支持筛选、搜索与删除，安全可追溯。'],
        ], JSON_UNESCAPED_UNICODE),
    ];
}

function e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** 字符串长度（优先 mbstring，缺失时回退 strlen，避免依赖可选扩展） */
function u_len(string $s): int {
    return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
}
