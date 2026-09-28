<?php
// 登录总开关：关闭后踢出所有非超级管理员的现有会话
if (function_exists('enforce_login_switch')) {
    enforce_login_switch();
}
$__s = site_settings($pdo);
// 顶部自定义链接（后台「网站参数」中维护，JSON；vis: guest=访客可见 / login=仅登录可见）
$__customLinks = json_decode((string)($__s['top_custom_links'] ?? '[]'), true);
$__customLinks = is_array($__customLinks) ? array_filter($__customLinks, function ($l) {
    return is_array($l) && trim((string)($l['text'] ?? '') . (string)($l['url'] ?? '')) !== '';
}) : [];
// 样式版本号：取文件修改时间，CSS 一更新 URL 即变化，绕过浏览器/CDN 缓存
$__cssFile = __DIR__ . '/../assets/style.css';
$__cssVer  = is_file($__cssFile) ? filemtime($__cssFile) : '1';

// 主题皮肤：解析当前皮肤、可见皮肤清单与皮肤样式 URL
require_once __DIR__ . '/themes.php';
$__themeId       = current_theme_id($pdo);
$__theme         = get_theme($__themeId);
$__themeCssUrl   = $__theme ? theme_css_url($__theme) : '';
$__switchThemes  = available_themes($pdo);
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="<?= e($__themeId) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle ?? $SITE_NAME) ?></title>
<link rel="stylesheet" href="/assets/style.css?v=<?= e((string)$__cssVer) ?>">
<?php if ($__themeCssUrl !== ''): ?>
<link rel="stylesheet" href="<?= e($__themeCssUrl) ?>">
<?php endif; ?>
<?php if (trim((string)($__s['custom_css'] ?? '')) !== ''): ?>
<style><?= $__s['custom_css'] ?></style>
<?php endif; ?>
</head>
<body>
<header class="topbar">
  <div class="topbar-inner">
    <a class="brand" href="/index.html">
      <?= icon_html((string)$__s['site_logo'], 'brand-logo-icon') ?>
      <?= e($__s['site_name'] ?: $SITE_NAME) ?>
    </a>
    <nav class="topnav">
      <?php if (is_admin() && ($__s['top_show_admin'] ?? '1') === '1'): ?>
        <a class="nav-link" href="/admin/index.html">⚙️ 后台管理</a>
      <?php endif; ?>
      <?php foreach ($__customLinks as $l):
        // 访客可见度：仅登录可见的链接对未登录用户隐藏
        $vis = (string)($l['vis'] ?? 'guest');
        if ($vis === 'login' && !is_logged_in()) continue;
      ?>
        <a class="nav-link custom-nav-link" href="<?= e($l['url'] ?? '#') ?>"
           <?php if (preg_match('#^https?://#i', (string)($l['url'] ?? ''))): ?>target="_blank" rel="noopener"<?php endif; ?>>
          <?= icon_html((string)($l['icon'] ?? ''), 'custom-link-icon') ?><?= e($l['text'] ?? '') ?>
        </a>
      <?php endforeach; ?>
      <?php if (is_logged_in()): ?>
        <?php $u = current_user(); ?>
        <span class="user-badge">👤 <?= e($u['display_name'] ?: $u['username']) ?></span>
        <?php if (!empty($u['settings_entry'])): ?>
          <a class="nav-link" href="/settings.html">⚙️ 设置</a>
        <?php endif; ?>
        <?php if (($__s['top_show_password'] ?? '1') === '1'): ?>
          <a class="nav-link" href="/password.html">🔒 修改密码</a>
        <?php endif; ?>
        <?php if (($__s['top_show_logout'] ?? '1') === '1'): ?>
          <a class="nav-link" href="/logout.html">退出</a>
        <?php endif; ?>
      <?php else: ?>
        <a class="nav-link" href="/login.html">登录</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<main class="container">
