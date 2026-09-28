<?php
/**
 * 我的导航（用户自助管理个人自定义导航，仅本人可见）
 * 布局：顶部添加面板 + 下方与首页一致的卡片网格（所见即所得）；
 *       卡片悬浮显示编辑/删除，编辑在弹窗中完成。
 */
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require_login();

$me = (int)current_user()['id'];
$error = '';

// 新增
if (isset($_POST['action']) && $_POST['action'] === 'create') {
    $title = trim($_POST['title'] ?? '');
    $url   = normalize_nav_url($_POST['url'] ?? '');
    $icon  = trim($_POST['icon'] ?? '');
    $sort  = (int)($_POST['sort_order'] ?? 0);
    if ($title === '' || $url === '') {
        $error = '标题和 URL 不能为空。';
    } else {
        $pdo->prepare("INSERT INTO user_nav_items (user_id, title, url, icon, sort_order) VALUES (?,?,?,?,?)")
            ->execute([$me, $title, $url, $icon, $sort]);
        write_log($pdo, 'mynav_create', '我的导航', "新增自定义导航：{$title}");
        header('Location: /mynav.html?saved=1'); exit;
    }
}

// 修改
if (isset($_POST['action']) && $_POST['action'] === 'update' && isset($_POST['id'])) {
    $id    = (int)$_POST['id'];
    $title = trim($_POST['title'] ?? '');
    $url   = normalize_nav_url($_POST['url'] ?? '');
    $icon  = trim($_POST['icon'] ?? '');
    $sort  = (int)($_POST['sort_order'] ?? 0);
    if ($title === '' || $url === '') {
        $error = '标题和 URL 不能为空。';
    } else {
        $pdo->prepare("UPDATE user_nav_items SET title=?, url=?, icon=?, sort_order=? WHERE id=? AND user_id=?")
            ->execute([$title, $url, $icon, $sort, $id, $me]);
        write_log($pdo, 'mynav_update', '我的导航', "修改自定义导航：{$title}");
        header('Location: /mynav.html?saved=1'); exit;
    }
}

// 删除
if (isset($_GET['del']) && (int)$_GET['del'] > 0) {
    $oldSt = $pdo->prepare("SELECT title FROM user_nav_items WHERE id=? AND user_id=?");
    $oldSt->execute([(int)$_GET['del'], $me]);
    $oldTitle = (string)$oldSt->fetchColumn();
    $pdo->prepare("DELETE FROM user_nav_items WHERE id=? AND user_id=?")
        ->execute([(int)$_GET['del'], $me]);
    write_log($pdo, 'mynav_delete', '我的导航', "删除自定义导航：{$oldTitle}");
    header('Location: /mynav.html?saved=1'); exit;
}

$st = $pdo->prepare("SELECT * FROM user_nav_items WHERE user_id=? ORDER BY sort_order ASC, id ASC");
$st->execute([$me]);
$items = $st->fetchAll();

$pageTitle = '我的导航';
require __DIR__ . '/includes/header.php';
?>
<div class="mynav-page">
  <div class="mynav-head">
    <div>
      <h1 class="page-title">⭐ 我的导航</h1>
      <p class="admin-tip">添加的导航仅你自己可见，展示效果与下方预览完全一致。图标支持 emoji 或图片 URL；站内地址直接写 <code>admin</code> 即可，会自动识别为 <code>/admin</code>。</p>
    </div>
    <a href="/index.html" class="btn-mini">← 返回首页</a>
  </div>

  <?php if (isset($_GET['saved'])): ?><p class="form-success">✅ 已保存。</p><?php endif; ?>
  <?php if ($error): ?><p class="form-error"><?= e($error) ?></p><?php endif; ?>

  <!-- 添加面板 -->
  <form method="post" class="mynav-add-panel" action="/mynav.html">
    <input type="hidden" name="action" value="create">
    <div class="mynav-add-grid">
      <label class="mynav-field">
        <span class="mynav-field-label">标题 <i>*</i></span>
        <input type="text" name="title" placeholder="如：后台管理" required>
      </label>
      <label class="mynav-field">
        <span class="mynav-field-label">URL <i>*</i></span>
        <input type="text" name="url" placeholder="https://example.com 或 admin" required>
      </label>
      <label class="mynav-field">
        <span class="mynav-field-label">图标</span>
        <input type="text" name="icon" placeholder="emoji 或图片 URL">
      </label>
      <label class="mynav-field mynav-field-sort">
        <span class="mynav-field-label">排序</span>
        <input type="number" name="sort_order" value="0">
      </label>
      <button type="submit" class="btn-primary mynav-add-btn">➕ 添加导航</button>
    </div>
  </form>

  <!-- 预览区（与首页展示一致） -->
  <div class="mynav-preview-head">
    <h2 class="mynav-preview-title">导航预览</h2>
    <span class="mynav-count-tag">共 <?= count($items) ?> 条</span>
  </div>

  <?php if (empty($items)): ?>
    <div class="mynav-empty">
      <span class="mynav-empty-icon">🗂️</span>
      <p>还没有自定义导航，在上方添加第一条吧。</p>
    </div>
  <?php else: ?>
    <div class="nav-grid mynav-manage-grid">
      <?php foreach ($items as $it): $iid = (int)$it['id']; ?>
        <div class="mynav-manage-card">
          <div class="mynav-card-ops">
            <button type="button" class="mynav-op-btn" title="编辑" onclick="openEdit(<?= $iid ?>)">✏️</button>
            <a class="mynav-op-btn mynav-op-del" title="删除"
               href="/mynav.html?del=<?= $iid ?>"
               onclick="return confirm('确定删除「<?= e($it['title']) ?>」？')">🗑️</a>
          </div>
          <span class="nav-icon"><?= icon_html((string)$it['icon']) ?></span>
          <span class="nav-title"><?= e($it['title']) ?></span>
          <span class="mynav-card-url"><?= e($it['url']) ?></span>
        </div>

        <!-- 编辑弹窗 -->
        <div class="mynav-modal-mask" id="editModal-<?= $iid ?>">
          <div class="mynav-modal">
            <div class="mynav-modal-head">
              <span>✏️ 编辑导航</span>
              <button type="button" class="mynav-modal-close" onclick="closeEdit(<?= $iid ?>)">&times;</button>
            </div>
            <form method="post" action="/mynav.html">
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="id" value="<?= $iid ?>">
              <div class="mynav-modal-body">
                <label class="mynav-field">
                  <span class="mynav-field-label">标题 <i>*</i></span>
                  <input type="text" name="title" value="<?= e($it['title']) ?>" required>
                </label>
                <label class="mynav-field">
                  <span class="mynav-field-label">URL <i>*</i></span>
                  <input type="text" name="url" value="<?= e($it['url']) ?>" required>
                </label>
                <label class="mynav-field">
                  <span class="mynav-field-label">图标（emoji 或图片 URL）</span>
                  <input type="text" name="icon" value="<?= e($it['icon']) ?>">
                </label>
                <label class="mynav-field mynav-field-sort">
                  <span class="mynav-field-label">排序</span>
                  <input type="number" name="sort_order" value="<?= (int)$it['sort_order'] ?>">
                </label>
              </div>
              <div class="mynav-modal-foot">
                <button type="button" class="btn-mini" onclick="closeEdit(<?= $iid ?>)">取消</button>
                <button type="submit" class="btn-primary">💾 保存修改</button>
              </div>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<script>
function openEdit(id) {
  var m = document.getElementById('editModal-' + id);
  m.classList.add('show');
  document.body.style.overflow = 'hidden';
  m.querySelector('input[name="title"]').focus();
}
function closeEdit(id) {
  var m = document.getElementById('editModal-' + id);
  m.classList.remove('show');
  document.body.style.overflow = '';
}
// 点击遮罩关闭 / ESC 关闭
document.querySelectorAll('.mynav-modal-mask').forEach(function (m) {
  m.addEventListener('click', function (e) {
    if (e.target === m) {
      m.classList.remove('show');
      document.body.style.overflow = '';
    }
  });
});
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') {
    document.querySelectorAll('.mynav-modal-mask.show').forEach(function (m) {
      m.classList.remove('show');
    });
    document.body.style.overflow = '';
  }
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
