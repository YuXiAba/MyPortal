<?php
/**
 * 伪静态路由器 — 供 PHP 内置服务器使用
 * 用法：  php -S localhost:8000 router.php
 * 作用：  在无 Apache/Nginx 的开发环境下，也能访问伪静态地址
 *         （如 /jump/12.html、/login.html、/admin/navs.html 等）。
 *         路由规则与 .htaccess / nginx-portal.conf 保持一致。
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

// 前台
$frontRoutes = [
    '/index.html'    => '/index.php',
    '/login.html'    => '/login.php',
    '/logout.html'   => '/logout.php',
    '/password.html' => '/my_password.php',
    '/settings.html' => '/settings.php',
    '/mynav.html'    => '/my_nav.php',
    '/about.html'    => '/about.php',
    '/theme.html'    => '/theme.php',
];
if (isset($frontRoutes[$uri])) {
    require __DIR__ . $frontRoutes[$uri];
    exit;
}

// 导航跳转（自动登录）：/jump/<id>.html
if (preg_match('#^/jump/(\d+)\.html$#', $uri, $m)) {
    $_GET['id'] = $m[1];
    require __DIR__ . '/jump.php';
    exit;
}

// 后台
if (preg_match('#^/admin/(index|navgroups|navs|groups|permissions|users|usernavs|engines|import|logs|searchlogs|settings|themes|about)\.html$#', $uri, $m)) {
    $map = [
        'index'     => 'index',
        'navgroups' => 'nav_groups',
        'navs'      => 'nav',
        'groups'    => 'groups',
        'permissions' => 'permissions',
        'users'     => 'users',
        'usernavs'  => 'user_navs',
        'engines'   => 'engines',
        'import'    => 'import',
        'logs'      => 'logs',
        'searchlogs' => 'search_logs',
        'settings'  => 'settings',
        'themes'    => 'themes',
        'about'     => 'about',
    ];
    $_SERVER['SCRIPT_NAME'] = '/admin/' . $map[$m[1]] . '.php';
    require __DIR__ . '/admin/' . $map[$m[1]] . '.php';
    exit;
}

// 其余请求（静态资源 / 原始 .php）交给内置服务器处理
return false;
