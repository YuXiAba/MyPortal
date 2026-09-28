<?php
/**
 * 后台：用户自定义导航管理（/admin/usernavs.html）
 * ===================================================
 * 用户在前台「我的导航」自助添加的个人导航（仅本人可见）。
 * 权限 manage_user_navs（可在权限矩阵分配，也可经二级授权授予）：
 *   - 超级管理员：管理所有用户的自定义导航；
 *   - 普通管理员：仅可管理级别更低 / 自管组同组、且在可管理范围内的用户。
 */
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require_admin();
require_perm('manage_user_navs');

$error = '';
$kw = trim($_GET['q'] ?? '');

/* ===================== POST：新增 / 编辑 ===================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'create' && isset($_POST['uid'])) {
        $uid   = (int)$_POST['uid'];
        $title = trim($_POST['title'] ?? '');
        $url   = normalize_nav_url($_POST['url'] ?? '');
        $icon  = trim($_POST['icon'] ?? '');
        $sort  = (int)($_POST['sort_order'] ?? 0);
        if (!can_manage_user($pdo, $uid)) {
            $error = '无权为该用户添加导航：目标不在您的可管理范围内。';
        } elseif ($title === '' || $url === '') {
            $error = '标题和 URL 不能为空。';
        } else {
            $pdo->prepare("INSERT INTO user_nav_items (user_id, title, url, icon, sort_order) VALUES (?,?,?,?,?)")
                ->execute([$uid, $title, $url, $icon, $sort]);
            write_log($pdo, 'create', '用户导航管理', "为用户 ID:{$uid} 添加自定义导航：{$title}");
            header('Location: /admin/usernavs.html?saved=1#user-' . $uid); exit;
        }
    }

    if ($act === 'update' && isset($_POST['id'])) {
        $id    = (int)$_POST['id'];
        $title = trim($_POST['title'] ?? '');
        $url   = normalize_nav_url($_POST['url'] ?? '');
        $icon  = trim($_POST['icon'] ?? '');
        $sort  = (int)($_POST['sort_order'] ?? 0);
        $ownSt = $pdo->prepare("SELECT user_id FROM user_nav_items WHERE id=?");
        $ownSt->execute([$id]);
        $ownerId = (int)$ownSt->fetchColumn();
        if ($ownerId === 0) {
            $error = '该导航不存在或已被删除。';
        } elseif (!can_manage_user($pdo, $ownerId)) {
            $error = '无权修改该导航：所属用户不在您的可管理范围内。';
        } elseif ($title === '' || $url === '') {
            $error = '标题和 URL 不能为空。';
        } else {
            $pdo->prepare("UPDATE user_nav_items SET title=?, url=?, icon=?, sort_order=? WHERE id=?")
                ->execute([$title, $url, $icon, $sort, $id]);
            write_log($pdo, 'update', '用户导航管理', "修改用户 ID:{$ownerId} 的自定义导航：{$title}");
            header('Location: /admin/usernavs.html?saved=1#user-' . $ownerId); exit;
        }
    }
}

/* ===================== GET：删除 ===================== */
if (isset($_GET['del'])) {
    $delId = (int)$_GET['del'];
    $dSt = $pdo->prepare("SELECT user_id, title FROM user_nav_items WHERE id=?");
    $dSt->execute([$delId]);
    $dRow = $dSt->fetch();
    if (!$dRow) {
        $error = '该导航不存在或已被删除。';
    } elseif (!can_manage_user($pdo, (int)$dRow['user_id'])) {
        $error = '无权删除该导航：所属用户不在您的可管理范围内。';
    } else {
        $pdo->prepare("DELETE FROM user_nav_items WHERE id=?")->execute([$delId]);
        write_log($pdo, 'delete', '用户导航管理',
                  "删除用户 ID:{$dRow['user_id']} 的自定义导航：{$dRow['title']}");
        header('Location: /admin/usernavs.html?saved=1#user-' . (int)$dRow['user_id']); exit;
    }
}

// 查询用户及其自定义导航条数；按级别过滤 + 普通管理员按可管理用户组过滤
$scope = manageable_group_ids($pdo);
$where = [];
$args = [];
if ($kw !== '') {
    $where[] = "(u.username LIKE ? OR u.display_name LIKE ?)";
    $args[] = "%$kw%"; $args[] = "%$kw%";
}
// 多组口径：组名聚合、级别取所属组最高
$lvlSql = target_level_sql();

// 级别隔离：同级及更高级别不可见（无组视为 99）；例外：自管组同组同伴
list($peerClause, $peerArgs) = self_managed_peer_clause((int)current_user()['id'], current_level());
$where[] = "($lvlSql > ? OR $peerClause)";
$args[] = current_level();
$args = array_merge($args, $peerArgs);
if ($scope !== null) {
    if (empty($scope)) {
        $where[] = "1=0";
    } else {
        $in = implode(',', array_map('intval', $scope));
        $where[] = "EXISTS (SELECT 1 FROM user_group_members m
                            WHERE m.user_id=u.id AND m.group_id IN ($in))";
    }
}
$sql = "SELECT u.id, u.username, u.display_name,
               " . group_names_sql() . " AS group_name,
               (SELECT COUNT(*) FROM user_nav_items n WHERE n.user_id = u.id) AS nav_count
        FROM users u"
     . ($where ? " WHERE " . implode(" AND ", $where) : "")
     . " ORDER BY u.id";
$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$users = $stmt->fetchAll();

// 读取有自定义导航的用户的明细
$navsByUser = [];
if ($users) {
    $ids = array_map(fn($u) => (int)$u['id'], $users);
    $in = implode(',', $ids);
    $rows = $pdo->query("SELECT * FROM user_nav_items WHERE user_id IN ($in) ORDER BY user_id, sort_order ASC, id ASC")->fetchAll();
    foreach ($rows as $r) {
        $navsByUser[(int)$r['user_id']][] = $r;
    }
}

// 统计"可见范围内"的条数（级别过滤后）
$totalNavs = array_sum(array_map(fn($u) => (int)$u['nav_count'], $users));

$pageTitle = '用户自定义导航';
require __DIR__ . '/../includes/header.php';
?>
<div class="admin-layout">
  <?php render_admin_sidebar('usernavs'); ?>

  <section class="admin-main">
    <h2>用户自定义导航</h2>
    <p class="page-sub">查看与管理用户在前台自助添加的个人导航。</p>
    <p class="admin-tip">
      用户在前台「<a href="/mynav.html">我的导航</a>」中自助添加的个人导航（仅本人可见）。
      <?= is_super()
          ? '你是超级管理员，可管理全部用户的自定义导航（可见范围内共 ' . $totalNavs . ' 条）。'
          : '你仅可管理被分配范围（级别更低或自管组同组）内用户的自定义导航（可见范围内共 ' . $totalNavs . ' 条）。' ?>
    </p>

    <?php if (isset($_GET['saved'])): ?><p class="form-success">✅ 已保存。</p><?php endif; ?>
    <?php if ($error): ?><p class="form-error"><?= e($error) ?></p><?php endif; ?>

    <form method="get" class="search-inline" action="/admin/usernavs.html">
      <input type="text" name="q" placeholder="按用户名或显示名搜索" value="<?= e($kw) ?>">
      <button type="submit" class="btn-mini">搜索</button>
      <?php if ($kw !== ''): ?><a href="/admin/usernavs.html" class="btn-mini">清除</a><?php endif; ?>
    </form>

    <?php if (empty($users)): ?>
      <p class="empty-tip">没有可管理的用户。</p>
    <?php endif; ?>

    <?php foreach ($users as $u):
        $uid  = (int)$u['id'];
        $items = $navsByUser[$uid] ?? [];
        // 写权限按级别/范围逐用户判定（可见不一定可改）
        $canEdit = can_manage_user($pdo, $uid);
    ?>
      <div class="user-nav-card" id="user-<?= $uid ?>">
        <div class="user-nav-head">
          <span class="user-nav-name">👤 <?= e($u['display_name'] ?: $u['username']) ?>
            <span class="user-nav-sub">（<?= e($u['username']) ?><?= $u['group_name'] ? ' · ' . e($u['group_name']) : '' ?>）</span>
          </span>
          <span class="user-nav-count <?= empty($items) ? 'is-zero' : '' ?>"><?= count($items) ?> 条</span>
        </div>
        <?php if (empty($items)): ?>
          <p class="empty-tip user-nav-empty">该用户暂无自定义导航。</p>
        <?php else: ?>
          <div class="user-nav-grid">
            <?php foreach ($items as $it):
                $iid = (int)$it['id'];
                $modalId = "editModal-{$uid}-{$iid}";
            ?>
              <div class="user-nav-item" title="<?= e($it['url']) ?>">
                <span class="nav-icon"><?= icon_html((string)$it['icon']) ?></span>
                <div class="user-nav-meta">
                  <span class="user-nav-title"><?= e($it['title']) ?></span>
                  <a class="user-nav-url mono" href="<?= e($it['url']) ?>" target="_blank" rel="noopener"><?= e($it['url']) ?></a>
                </div>
                <?php if ($canEdit): ?>
                  <span class="user-nav-item-ops">
                    <button type="button" class="mynav-op-btn" title="编辑" data-edit="<?= $modalId ?>">✏️</button>
                    <a class="mynav-op-btn mynav-op-del" title="删除"
                       href="/admin/usernavs.html?del=<?= $iid ?>"
                       onclick="return confirm('确定删除该用户的「<?= e($it['title']) ?>」？')">🗑️</a>
                  </span>
                <?php endif; ?>
                <span class="user-nav-sort">排序 <?= (int)$it['sort_order'] ?></span>
              </div>

              <?php if ($canEdit): ?>
                <!-- 编辑弹窗 -->
                <div class="mynav-modal-mask" id="<?= $modalId ?>">
                  <div class="mynav-modal">
                    <div class="mynav-modal-head">
                      <span>✏️ 编辑导航（<?= e($u['display_name'] ?: $u['username']) ?>）</span>
                      <button type="button" class="mynav-modal-close" data-close>&times;</button>
                    </div>
                    <form method="post" action="/admin/usernavs.html#user-<?= $uid ?>">
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
                        <button type="button" class="btn-mini" data-close>取消</button>
                        <button type="submit" class="btn-primary">💾 保存修改</button>
                      </div>
                    </form>
                  </div>
                </div>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if ($canEdit): ?>
          <!-- 为该用户添加导航 -->
          <form method="post" class="mynav-add-panel user-nav-add" action="/admin/usernavs.html#user-<?= $uid ?>">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="uid" value="<?= $uid ?>">
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
              <button type="submit" class="btn-primary mynav-add-btn">➕ 添加</button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </section>
</div>

<script>
(function () {
  function showModal(id) {
    var m = document.getElementById(id);
    if (!m) return;
    m.classList.add('show');
    document.body.style.overflow = 'hidden';
    var t = m.querySelector('input[name="title"]');
    if (t) t.focus();
  }
  function hideModal(m) {
    if (!m) return;
    m.classList.remove('show');
    document.body.style.overflow = '';
  }
  // 事件委托：打开 / 关闭弹窗、点击遮罩关闭
  document.addEventListener('click', function (e) {
    var openBtn = e.target.closest('[data-edit]');
    if (openBtn) { showModal(openBtn.getAttribute('data-edit')); return; }
    var closeBtn = e.target.closest('[data-close]');
    if (closeBtn) { hideModal(closeBtn.closest('.mynav-modal-mask')); return; }
    if (e.target.classList && e.target.classList.contains('mynav-modal-mask')) {
      hideModal(e.target);
    }
  });
  // ESC 关闭
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('.mynav-modal-mask.show').forEach(hideModal);
    }
  });
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
