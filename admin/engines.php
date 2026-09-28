<?php
/**
 * 后台：搜索引擎管理（增删改查）
 * 权限：manage_engines（系统管理员默认拥有，也可授予普通管理员组）
 * 字段：名称 / URL 模板（必须含 %s 占位符）/ 图标（emoji 或图片 URL）/ 排序 / 状态
 */
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require_admin();
require_perm('manage_engines');

$error = '';

// 新增
if (isset($_POST['action']) && $_POST['action'] === 'create') {
    $name  = trim($_POST['name'] ?? '');
    $tpl   = trim($_POST['url_template'] ?? '');
    $icon  = trim($_POST['icon'] ?? '');
    $sort  = (int)($_POST['sort_order'] ?? 0);
    if ($name === '' || $tpl === '') {
        $error = '名称和 URL 模板不能为空。';
    } elseif (strpos($tpl, '%s') === false) {
        $error = 'URL 模板必须包含 %s 占位符，用于替换搜索关键词。';
    } else {
        $pdo->prepare("INSERT INTO search_engines (name, url_template, icon, sort_order, status) VALUES (?,?,?,?,?)")
            ->execute([$name, $tpl, $icon, $sort, isset($_POST['status']) ? 1 : 0]);
        write_log($pdo, 'create', '搜索引擎管理', "新增搜索引擎：{$name}");
        header('Location: /admin/engines.html?saved=1'); exit;
    }
}

// 修改
if (isset($_POST['action']) && $_POST['action'] === 'update' && isset($_POST['id'])) {
    $id    = (int)$_POST['id'];
    $name  = trim($_POST['name'] ?? '');
    $tpl   = trim($_POST['url_template'] ?? '');
    $icon  = trim($_POST['icon'] ?? '');
    $sort  = (int)($_POST['sort_order'] ?? 0);
    if ($name === '' || $tpl === '') {
        $error = '名称和 URL 模板不能为空。';
    } elseif (strpos($tpl, '%s') === false) {
        $error = 'URL 模板必须包含 %s 占位符。';
    } else {
        $pdo->prepare("UPDATE search_engines SET name=?, url_template=?, icon=?, sort_order=?, status=? WHERE id=?")
            ->execute([$name, $tpl, $icon, $sort, isset($_POST['status']) ? 1 : 0, $id]);
        write_log($pdo, 'update', '搜索引擎管理', "修改搜索引擎：{$name}（ID：{$id}）");
        header('Location: /admin/engines.html?saved=1'); exit;
    }
}

// 删除
if (isset($_GET['del']) && (int)$_GET['del'] > 0) {
    $delRow = $pdo->prepare("SELECT name FROM search_engines WHERE id=?");
    $delRow->execute([(int)$_GET['del']]);
    $delName = (string)$delRow->fetchColumn();
    $pdo->prepare("DELETE FROM search_engines WHERE id=?")->execute([(int)$_GET['del']]);
    write_log($pdo, 'delete', '搜索引擎管理', "删除搜索引擎：{$delName}（ID：" . (int)$_GET['del'] . '）');
    header('Location: /admin/engines.html?saved=1'); exit;
}

$engines = $pdo->query("SELECT * FROM search_engines ORDER BY sort_order ASC, id ASC")->fetchAll();

$pageTitle = '搜索引擎管理';
require __DIR__ . '/../includes/header.php';
?>
<div class="admin-layout">
  <?php render_admin_sidebar('engines'); ?>

  <section class="admin-main">
    <h2>搜索引擎管理</h2>
    <p class="page-sub">维护首页搜索框可选的搜索引擎与跳转模板。</p>
    <?php if (isset($_GET['saved'])): ?><p class="form-success">已保存，前台搜索框下拉已更新。</p><?php endif; ?>
    <?php if ($error): ?><p class="form-error"><?= e($error) ?></p><?php endif; ?>
    <p class="admin-tip"><strong>URL 模板</strong>用 <code>%s</code> 表示关键词位置，例如 <code>https://www.bing.com/search?q=%s</code>；图标支持 emoji 或图片 URL。停用的引擎不会出现在前台下拉中。</p>

    <details class="create-box"<?= !empty($error) ? ' open' : '' ?>>
      <summary>➕ 添加搜索引擎</summary>
      <div class="create-body">
    <form method="post" class="admin-form" action="/admin/engines.html">
      <input type="hidden" name="action" value="create">
      <div class="form-row">
        <input type="text" name="name" placeholder="引擎名称，如 必应" required>
        <input type="text" name="url_template" placeholder="URL 模板，含 %s" required>
        <input type="text" name="icon" placeholder="图标 emoji/图片 URL">
        <input type="number" name="sort_order" value="0" title="排序" style="max-width:90px">
        <label class="chk"><input type="checkbox" name="status" checked> 启用</label>
        <button type="submit" class="btn-primary">➕ 添加引擎</button>
      </div>
    </form>
      </div>
    </details>

    <table class="data-table">
      <thead><tr><th>图标</th><th>名称</th><th>URL 模板</th><th>排序</th><th>状态</th><th>操作</th></tr></thead>
      <tbody>
        <?php if (empty($engines)): ?>
          <tr><td colspan="6" class="empty-tip">还没有搜索引擎，在上方添加。</td></tr>
        <?php endif; ?>
        <?php foreach ($engines as $e): ?>
          <tr>
            <td class="icon-cell"><?= icon_html((string)$e['icon']) ?></td>
            <td><?= e($e['name']) ?></td>
            <td class="mono"><?= e($e['url_template']) ?></td>
            <td><?= (int)$e['sort_order'] ?></td>
            <td><?= (int)$e['status'] === 1 ? '✅ 启用' : '🚫 停用' ?></td>
            <td class="ops">
              <button class="btn-mini" onclick="toggleEdit(<?= (int)$e['id'] ?>)">编辑</button>
              <a class="btn-mini danger" href="/admin/engines.html?del=<?= (int)$e['id'] ?>"
                 onclick="return confirm('确定删除搜索引擎「<?= e($e['name']) ?>」？')">删除</a>
            </td>
          </tr>
          <tr id="edit-<?= (int)$e['id'] ?>" class="edit-row" style="display:none">
            <td colspan="6">
              <form method="post" class="admin-form inline-edit" action="/admin/engines.html">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
                <div class="form-row">
                  <input type="text" name="name" value="<?= e($e['name']) ?>" required>
                  <input type="text" name="url_template" value="<?= e($e['url_template']) ?>" required>
                  <input type="text" name="icon" value="<?= e($e['icon']) ?>" placeholder="emoji 或图片 URL">
                  <input type="number" name="sort_order" value="<?= (int)$e['sort_order'] ?>" style="max-width:90px">
                  <label class="chk"><input type="checkbox" name="status" <?= (int)$e['status']===1?'checked':'' ?>> 启用</label>
                  <button type="submit" class="btn-mini">保存</button>
                </div>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>
</div>

<script>
function toggleEdit(id) {
  var row = document.getElementById('edit-' + id);
  row.style.display = row.style.display === 'none' ? '' : 'none';
}
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
