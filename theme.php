<?php
/**
 * 主题皮肤切换（公开页：登录用户写入账号，访客写入 Cookie）
 */
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/themes.php';

/** 仅允许跳回本站路径，防止开放重定向 */
function safe_back(string $url): string {
    if ($url === '' || $url[0] !== '/' || strpos($url, '//') === 0 || strpos($url, '/\\') === 0) {
        return '/index.html';
    }
    return $url;
}

// 应用皮肤
if (isset($_GET['set'])) {
    $id = (string)$_GET['set'];
    $themes = available_themes($pdo);
    if (isset($themes[$id])) {
        if (is_logged_in()) {
            save_user_theme($pdo, (int)current_user()['id'], $id);
        }
        // 访客 / 已登录用户都写 Cookie（账号选择优先于 Cookie）
        setcookie('theme', $id, time() + 31536000, '/', '', false, false);
        $_COOKIE['theme'] = $id;
        write_log($pdo, 'theme_switch', '主题皮肤', '切换皮肤：' . $themes[$id]['name']);
    }
    header('Location: ' . safe_back((string)($_GET['back'] ?? $_SERVER['HTTP_REFERER'] ?? '')));
    exit;
}

// 跟随站点默认（清除个人选择）
if (isset($_GET['reset'])) {
    if (is_logged_in()) {
        save_user_theme($pdo, (int)current_user()['id'], '');
    }
    setcookie('theme', '', time() - 3600, '/');
    unset($_COOKIE['theme']);
    write_log($pdo, 'theme_switch', '主题皮肤', '恢复跟随站点默认皮肤');
    header('Location: ' . safe_back((string)($_GET['back'] ?? '/theme.html')));
    exit;
}

$themes    = available_themes($pdo);
$currentId = current_theme_id($pdo);

$pageTitle = '主题皮肤';
require __DIR__ . '/includes/header.php';
?>
<h1 class="page-title" style="margin:6px 0 4px">🎨 主题皮肤</h1>
<p class="page-sub" style="margin-bottom:18px">选择喜欢的界面配色，登录用户的选择保存在账号中，换设备登录同样生效。</p>

<div class="theme-grid">
  <?php foreach ($themes as $t):
    $isCurrent = $t['id'] === $currentId;
    $applyUrl = '/theme.html?set=' . urlencode($t['id']) . '&back=' . urlencode('/theme.html');
  ?>
    <div class="theme-card<?= $isCurrent ? ' current' : '' ?>">
      <!-- 皮肤预览：品牌色 + 表面色块 -->
      <div class="theme-preview<?= !empty($t['dark']) ? ' dark-preview' : '' ?>">
        <?php if (!empty($t['swatches'])): foreach (array_slice($t['swatches'], 0, 3) as $i => $c): ?>
          <span class="tp-swatch tp-s<?= $i ?>" style="background:<?= e($c) ?>"></span>
        <?php endforeach; else: ?>
          <span class="tp-swatch tp-s0" style="background:var(--primary)"></span>
          <span class="tp-swatch tp-s1" style="background:var(--primary-dark)"></span>
          <span class="tp-swatch tp-s2" style="background:var(--primary-soft)"></span>
        <?php endif; ?>
      </div>
      <div class="theme-card-body">
        <div class="theme-name-row">
          <strong><?= e($t['name']) ?></strong>
          <?php if (!empty($t['dark'])): ?><span class="theme-dark-tag">暗色</span><?php endif; ?>
          <?php if ($isCurrent): ?><span class="theme-current-tag">✓ 使用中</span><?php endif; ?>
        </div>
        <p class="theme-desc"><?= e($t['desc']) ?></p>
        <p class="theme-meta">v<?= e($t['version']) ?> · <?= e($t['author']) ?>
          <?= !empty($t['builtin']) ? '· 内置' : '· 自定义' ?></p>
        <?php if (!$isCurrent): ?>
          <a class="btn-primary theme-apply-btn" href="<?= e($applyUrl) ?>">使用此皮肤</a>
        <?php else: ?>
          <a class="btn-mini theme-apply-btn" href="/theme.html?reset=1">恢复跟随站点默认</a>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
