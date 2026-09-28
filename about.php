<?php
/**
 * 前台：关于我们（/about.html）
 * 公开页面，访客即可访问；页面内容在后台「关于我们设置」
 * （/admin/about.html，manage_about 权限）中维护。
 */
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';

$s = site_settings($pdo);
$siteName = (string)($s['site_name'] ?: $SITE_NAME);
$slogan   = trim((string)$s['about_slogan']);

// 详细介绍（text/html/md）
$contentHtml = render_rich((string)$s['about_content'], (string)$s['about_content_type']);

// 功能特性（后台维护的 JSON；数据异常时回退为内置默认）
$features = json_decode((string)$s['about_features'], true);
if (!is_array($features)) $features = [];

// 快速入口（全站统一伪静态地址）
$aboutLinks = [
    ['/index.html',  '🏠', '返回首页'],
    ['/login.html',  '🔑', '登录账号'],
    ['/mynav.html',  '🧩', '我的导航（需登录）'],
];

$canEdit = is_logged_in() && has_perm('manage_about') && has_perm('access_admin');
$isEnabled = (string)$s['about_enabled'] === '1';

$pageTitle = '关于我们';
require __DIR__ . '/includes/header.php';

// 页面已关闭：非编辑权限的访客只看到关闭提示（HTTP 404）
if (!$isEnabled && !$canEdit):
  http_response_code(404);
?>
<section class="about-wrap about-closed">
  <div class="about-closed-icon">🚧</div>
  <h1 class="about-closed-title">关于我们暂未开放</h1>
  <p class="about-closed-desc">该页面当前已关闭，稍后再来看看吧。</p>
  <div class="about-actions">
    <a class="btn-primary" href="/index.html">返回首页</a>
  </div>
</section>
<?php
  require __DIR__ . '/includes/footer.php';
  exit;
endif;
?>
<section class="about-wrap">
  <?php if (!$isEnabled): ?>
    <p class="about-offline-banner">🚧 页面当前为<strong>关闭状态</strong>，普通访客看到的是「暂未开放」提示；此预览仅对拥有编辑权限的管理员可见。 <a href="/admin/about.html">去启用</a></p>
  <?php endif; ?>
  <div class="about-hero">
    <span class="about-logo"><?= icon_html((string)($s['site_logo'] ?? '🏠')) ?></span>
    <h1 class="about-name"><?= e($siteName) ?></h1>
    <?php if ($slogan !== ''): ?><p class="about-slogan"><?= e($slogan) ?></p><?php endif; ?>
    <div class="about-actions">
      <a class="btn-primary" href="/index.html">进入首页</a>
      <?php if (!is_logged_in()): ?>
        <a class="btn-mini" href="/login.html">登录</a>
      <?php endif; ?>
      <?php if ($canEdit): ?>
        <a class="btn-mini" href="/admin/about.html">📖 编辑本页</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($contentHtml !== ''): ?>
    <div class="about-content rich-content">
      <?= $contentHtml ?>
    </div>
  <?php endif; ?>

  <?php if ($features): ?>
    <h2 class="section-title about-block-title">功能特性</h2>
    <div class="nav-grid about-feature-grid">
      <?php foreach ($features as $f): ?>
        <div class="nav-card about-feature-card">
          <span class="about-feature-icon"><?= icon_html((string)($f['icon'] ?? '')) ?></span>
          <span class="about-feature-name"><?= e((string)($f['title'] ?? '')) ?></span>
          <p class="about-feature-desc"><?= e((string)($f['desc'] ?? '')) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <h2 class="section-title about-block-title">快速入口</h2>
  <div class="about-links">
    <?php foreach ($aboutLinks as $l): ?>
      <a class="about-link-item" href="<?= e($l[0]) ?>">
        <span class="about-link-icon"><?= $l[1] ?></span>
        <span><?= e($l[2]) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
