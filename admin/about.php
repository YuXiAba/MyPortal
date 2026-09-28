<?php
/**
 * 后台：关于我们设置（/admin/about.html）
 * =======================================
 * 可编辑内容（存入 settings 表）：
 *   ① 简介标语 about_slogan
 *   ② 详细介绍 about_content + about_content_type（text/html/md；
 *      html 仅系统管理员可写，二级授权人只可用纯文本/Markdown）
 *   ③ 功能特性 about_features（JSON：图标 / 标题 / 说明，可增删行）
 *
 * 权限：manage_about —— 系统管理员默认拥有；可在权限管理中
 *       授予用户组或经二级授权授予单个用户。
 */
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require_admin();
require_perm('manage_about');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $slogan = trim((string)($_POST['about_slogan'] ?? ''));
    $content = trim((string)($_POST['about_content'] ?? ''));
    $contentType = (string)($_POST['about_content_type'] ?? 'text');
    if (!in_array($contentType, ['text', 'md', 'html'], true)) {
        $contentType = 'text';
    }
    // 非系统管理员：禁止写入原生 HTML（受信内容仅超管可写）
    if (!is_super() && $contentType === 'html') {
        $contentType = 'text';
    }

    // 功能特性行：图标 / 标题 / 说明；标题与图标都为空的行自动忽略
    $features = [];
    $fIcons = $_POST['f_icon'] ?? [];
    $fTitles = $_POST['f_title'] ?? [];
    $fDescs = $_POST['f_desc'] ?? [];
    if (is_array($fTitles)) {
        foreach ($fTitles as $i => $title) {
            $icon  = trim((string)($fIcons[$i] ?? ''));
            $title = trim((string)$title);
            $desc  = trim((string)($fDescs[$i] ?? ''));
            if ($icon === '' && $title === '' && $desc === '') continue;
            $features[] = ['icon' => $icon, 'title' => $title !== '' ? $title : '未命名', 'desc' => $desc];
        }
    }

    // 页面启用开关
    $enabled = isset($_POST['about_enabled']) ? '1' : '0';

    $upsert = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?)
                             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    $upsert->execute(['about_enabled', $enabled]);
    $upsert->execute(['about_slogan', $slogan]);
    $upsert->execute(['about_content', $content]);
    $upsert->execute(['about_content_type', $contentType]);
    $upsert->execute(['about_features', json_encode(array_values($features), JSON_UNESCAPED_UNICODE)]);

    write_log($pdo, 'save_about', '关于我们设置',
              '保存关于我们内容（页面：' . ($enabled==='1'?'启用':'关闭')
              . '，特性 ' . count($features) . ' 项）');
    header('Location: /admin/about.html?saved=1'); exit;
}

$s = site_settings($pdo);
$features = json_decode((string)$s['about_features'], true);
$features = is_array($features) ? $features : [];
$contentType = (string)$s['about_content_type'];
if (!in_array($contentType, ['text', 'md', 'html'], true)) $contentType = 'text';

$pageTitle = '关于我们设置';
require __DIR__ . '/../includes/header.php';
?>
<div class="admin-layout">
  <?php render_admin_sidebar('about'); ?>

  <section class="admin-main">
    <h2>关于我们设置</h2>
    <p class="page-sub">编辑关于我们页的简介、详细介绍与功能特性卡片。</p>
    <?php if (isset($_GET['saved'])): ?><p class="form-success">已保存，<a href="/about.html" target="_blank">点此查看关于我们页面</a>。</p><?php endif; ?>
    <?php if (is_super()): ?>
      <p class="admin-tip">你是系统管理员。本页内容默认仅你可编辑；也可在「权限管理」中把「关于我们设置」权限授予其他管理员（用户组或单个用户二级授权）。</p>
    <?php else: ?>
      <p class="admin-tip">你已通过授权获得本页编辑权限。详细介绍仅支持纯文本 / Markdown；原生 HTML 仅限系统管理员。</p>
    <?php endif; ?>

    <form method="post" class="admin-form" action="/admin/about.html">
      <!-- ⓪ 页面启用开关 -->
      <div class="form-row">
        <label class="chk about-enable-chk">
          <input type="checkbox" name="about_enabled" value="1" <?= (string)$s['about_enabled']==='1'?'checked':'' ?>>
          <strong>启用关于我们页面</strong>
        </label>
      </div>
      <p class="grp-hint about-enable-hint">关闭后，访客访问 /about.html 只会看到「页面暂未开放」提示；你仍可在本页编辑内容，并可在前台预览关闭状态。</p>

      <!-- ① 简介标语 -->
      <div class="form-row">
        <label class="full-label">简介标语（显示在页面顶部站点名称下方）
          <input type="text" name="about_slogan" value="<?= e((string)$s['about_slogan']) ?>" placeholder="一句话介绍本站">
        </label>
      </div>

      <!-- ② 详细介绍 -->
      <div class="form-row">
        <label class="full-label">详细介绍（显示在功能特性上方，支持纯文本 / Markdown<?= is_super() ? ' / HTML' : '' ?>）
          <textarea name="about_content" rows="6" placeholder="可写多段介绍；Markdown 支持标题、列表、链接等语法"><?= e((string)$s['about_content']) ?></textarea>
        </label>
      </div>
      <div class="form-row">
        <label class="inline-label">内容语法
          <select name="about_content_type">
            <option value="text" <?= $contentType==='text'?'selected':'' ?>>纯文本</option>
            <option value="md"   <?= $contentType==='md'?'selected':'' ?>>Markdown</option>
            <?php if (is_super()): ?>
              <option value="html" <?= $contentType==='html'?'selected':'' ?>>HTML（仅系统管理员）</option>
            <?php endif; ?>
          </select>
        </label>
        <span class="grp-hint">留空则前台不显示详细介绍区块。</span>
      </div>

      <!-- ③ 功能特性 -->
      <h3 class="form-sub-title">功能特性卡片</h3>
      <div id="featRows">
        <?php foreach ($features as $f): ?>
          <div class="about-feat-row">
            <input type="text" name="f_icon[]" value="<?= e((string)$f['icon']) ?>" placeholder="图标" class="f-icon" title="emoji 或图片 URL">
            <input type="text" name="f_title[]" value="<?= e((string)$f['title']) ?>" placeholder="标题" class="f-title">
            <input type="text" name="f_desc[]" value="<?= e((string)$f['desc']) ?>" placeholder="说明" class="f-desc">
            <button type="button" class="btn-mini danger feat-del">删除</button>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="form-row">
        <button type="button" class="btn-mini" id="featAdd">➕ 添加一项</button>
        <span class="grp-hint">全部清空并保存，前台将不显示功能特性区块；图标可填 emoji 或图片 URL。</span>
      </div>

      <div class="form-row" style="margin-top:14px">
        <button type="submit" class="btn-primary">保存</button>
        <a class="btn-mini" href="/about.html" target="_blank">预览页面</a>
      </div>
    </form>
  </section>
</div>

<script>
(function () {
  var box = document.getElementById('featRows');
  function addRow(icon, title, desc) {
    var div = document.createElement('div');
    div.className = 'about-feat-row';
    div.innerHTML =
      '<input type="text" name="f_icon[]" placeholder="图标" class="f-icon" title="emoji 或图片 URL">' +
      '<input type="text" name="f_title[]" placeholder="标题" class="f-title">' +
      '<input type="text" name="f_desc[]" placeholder="说明" class="f-desc">' +
      '<button type="button" class="btn-mini danger feat-del">删除</button>';
    box.appendChild(div);
  }
  document.getElementById('featAdd').addEventListener('click', function () { addRow(); });
  box.addEventListener('click', function (ev) {
    if (ev.target.classList.contains('feat-del')) {
      ev.target.closest('.about-feat-row').remove();
    }
  });
  // 初始为空时给一行，便于直接填写
  if (!box.children.length) addRow();
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
