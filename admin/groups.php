<?php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require_admin();
require_perm('manage_groups');

$error = '';

// 删除规则：
// - 超级管理员组（is_super=1）：系统专属，任何情况不可删除；
// - 管理员组（is_admin=1）：仅系统管理员可删除；
// - 普通组：有 manage_groups 权限即可删除。
// 删除后从关联表移除归属并重算受影响用户的"主用户组"缓存。
if (isset($_GET['del']) && (int)$_GET['del'] > 0) {
    $delId = (int)$_GET['del'];
    $chk = $pdo->prepare("SELECT name, is_admin, is_super FROM user_groups WHERE id=?");
    $chk->execute([$delId]);
    $g = $chk->fetch();
    if (!$g) {
        $error = '用户组不存在。';
    } elseif ((int)$g['is_super'] === 1) {
        $error = '不能删除超级管理员组（级别 1 为系统专属，需始终保留）。';
    } elseif ((int)$g['is_admin'] === 1 && !is_super()) {
        $error = '仅系统管理员可删除管理员用户组。';
    } else {
        $aff = $pdo->prepare("SELECT DISTINCT user_id FROM user_group_members WHERE group_id=?");
        $aff->execute([$delId]);
        $affectedUsers = array_map('intval', $aff->fetchAll(PDO::FETCH_COLUMN));
        // 关联表行由外键 ON DELETE CASCADE 自动清理；先清缓存主组
        $pdo->prepare("UPDATE users SET group_id=NULL WHERE group_id=?")->execute([$delId]);
        $pdo->prepare("DELETE FROM user_groups WHERE id=?")->execute([$delId]);
        foreach ($affectedUsers as $auId) {
            sync_primary_group($pdo, $auId);
        }
        write_log($pdo, 'delete', '用户组管理', "删除用户组：{$g['name']}（ID：{$delId}）");
        header('Location: /admin/groups.html?saved=1'); exit;
    }
}

// 新增 / 编辑
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');

    // 读取当前行（编辑场景），用于级别默认值与非超管保持原有属性
    $curRow = null;
    if (isset($_POST['action']) && $_POST['action'] === 'update' && isset($_POST['id'])) {
        $q = $pdo->prepare("SELECT * FROM user_groups WHERE id=?");
        $q->execute([(int)$_POST['id']]);
        $curRow = $q->fetch();
    }

    // 全部真实普通组 ID（可管理范围只接受普通组，含本组）
    $normalIds = array_map('intval', $pdo->query(
        "SELECT id FROM user_groups WHERE is_admin=0 AND is_super=0")->fetchAll(PDO::FETCH_COLUMN));

    if (is_super()) {
        // ---- 组类型：仅系统管理员可设置 ----
        $postedType = $_POST['group_type'] ?? 'normal';
        $type = in_array($postedType, ['normal', 'admin', 'super'], true) ? $postedType : 'normal';

        // 权限：只接受权限清单内的 key
        $postedPerms = is_array($_POST['perms'] ?? null) ? $_POST['perms'] : [];
        $permKeys = array_values(array_intersect(
            array_keys($GLOBALS['PERMS']), array_map('strval', $postedPerms)));

        // 可管理范围：只接受真实普通组（允许本组）
        $postedScope = array_map('intval', (array)($_POST['manageable_groups'] ?? []));
        $scopeIds = array_values(array_intersect($postedScope, $normalIds));

        $attrs = normalize_group_attributes($type, $permKeys, $scopeIds,
                                            (int)($_POST['level'] ?? 0), $curRow);
    } else {
        // 非系统管理员：只能改名；类型/权限/范围/级别一律按现值归一（防构造请求越权）
        if ($curRow) {
            $type = (int)$curRow['is_super']===1 ? 'super'
                  : ((int)$curRow['is_admin']===1 ? 'admin' : 'normal');
            $curPerms = array_filter(array_map('trim', explode(',', (string)$curRow['permissions'])));
            $curScope = resolve_ids((string)($curRow['manageable_groups'] ?? ''));
        } else {
            $type = 'normal'; $curPerms = []; $curScope = []; // 新组=无权限普通组
        }
        $attrs = normalize_group_attributes($type, $curPerms, $curScope, 0, $curRow);
    }
    $isAdmin    = (int)$attrs['is_admin'];
    $isSuper    = (int)$attrs['is_super'];
    $perms      = (string)$attrs['permissions'];
    $manageable = (string)$attrs['manageable_groups'];
    $newLevel   = (int)$attrs['level'];

    if ($name === '') {
        $error = '用户组名称不能为空。';
    } elseif (isset($_POST['action']) && $_POST['action'] === 'create') {
        $pdo->prepare("INSERT INTO user_groups (name, is_admin, is_super, permissions, manageable_groups, level)
                       VALUES (?,?,?,?,?,?)")
            ->execute([$name, $isAdmin, $isSuper, $perms, $manageable, $newLevel]);
        $typeText = ['super' => '超级管理员组', 'admin' => '管理员组', 'normal' => '普通组'][$type];
        write_log($pdo, 'create', '用户组管理', "新增用户组：{$name}（{$typeText}）");
        header('Location: /admin/groups.html?saved=1'); exit;
    } elseif (isset($_POST['action']) && $_POST['action'] === 'update' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        if (!$curRow) {
            $error = '用户组不存在。';
        } elseif ((int)$curRow['is_super']===1 && $type !== 'super') {
            // 保护：系统中必须始终保留至少一个超级管理员组
            $cnt = (int)$pdo->query("SELECT COUNT(*) FROM user_groups WHERE is_super=1")->fetchColumn();
            if ($cnt <= 1) {
                $error = '不能降级最后一个超级管理员组：系统必须始终保留至少一个系统管理员组。';
            }
        }
        if ($error === '') {
            $pdo->prepare("UPDATE user_groups SET name=?, is_admin=?, is_super=?, permissions=?, manageable_groups=?, level=?
                           WHERE id=?")
                ->execute([$name, $isAdmin, $isSuper, $perms, $manageable, $newLevel, $id]);
            // 该组成员的"主用户组"缓存可能因级别/类型变化而改变，全部重算
            $mu = $pdo->prepare("SELECT user_id FROM user_group_members WHERE group_id=?");
            $mu->execute([$id]);
            foreach ($mu->fetchAll(PDO::FETCH_COLUMN) as $muId) {
                sync_primary_group($pdo, (int)$muId);
            }
            $typeText = ['super' => '超级管理员组', 'admin' => '管理员组', 'normal' => '普通组'][$type];
            write_log($pdo, 'update', '用户组管理', "修改用户组：{$name}（类型：{$typeText}，ID：{$id}）");
            header('Location: /admin/groups.html?saved=1'); exit;
        }
    }
}

// 列表：用户数按多组关联表统计
$groups = $pdo->query("SELECT g.*,
                              (SELECT COUNT(DISTINCT m.user_id) FROM user_group_members m WHERE m.group_id=g.id) AS user_count
                       FROM user_groups g ORDER BY g.level ASC, g.id ASC")->fetchAll();
// 可供分配"被管理"的用户组：仅普通组（管理员组/超管组不参与被管理分配）
$normalGroups = $pdo->query("SELECT id, name FROM user_groups WHERE is_admin=0 AND is_super=0 ORDER BY id")->fetchAll();

$pageTitle = '用户组管理';
require __DIR__ . '/../includes/header.php';
?>
<div class="admin-layout">
  <?php render_admin_sidebar('groups'); ?>

  <section class="admin-main">
    <div class="page-head-bar">
      <div>
        <h2>用户组管理</h2>
        <p class="page-sub">管理用户组的类型、级别、权限集合与可管理用户组范围。</p>
      </div>
      <div class="ph-actions">
        <button type="button" class="btn-primary" data-modal-open="modal-g-create">➕ 新增用户组</button>
      </div>
    </div>

    <?php if (isset($_GET['saved'])): ?><p class="form-success">已保存。</p><?php endif; ?>
    <?php if (!empty($error)): ?><p class="form-error"><?= e($error) ?></p><?php endif; ?>
    <?php if (is_super()): ?>
      <details class="rule-card">
        <summary>📜 配置规则</summary>
        <ul>
          <li>用户组分三类：<strong>👥 普通组 / 🛡️ 管理员组 / ⭐ 超级管理员组</strong>；超级管理员组自动拥有全部权限、级别恒为 1，系统中至少保留一个。</li>
          <li>「可管理用户组」定义该组成员<strong>能管理哪些组的用户</strong>，仅可勾选普通组；需要进入后台还须显式勾选「后台访问权限」等权限。</li>
          <li><strong>勾选「本组」= 自管组</strong>：组内成员可互相管理，但不能操作自己，也不能把同伴移出本组。</li>
        </ul>
      </details>
    <?php else: ?>
      <p class="admin-tip">仅系统管理员可修改用户组类型、权限与可管理范围，你仅可修改组名称。</p>
    <?php endif; ?>

    <table class="data-table">
      <thead><tr><th>ID</th><th>名称</th><th>类型</th><th>级别</th><th>权限</th><th>可管理用户组</th><th>用户数</th><th>操作</th></tr></thead>
      <tbody>
      <?php $groupModals = []; foreach ($groups as $g):
        $gPerms = array_filter(array_map('trim', explode(',', (string)$g['permissions'])));
        $gManageable = resolve_ids((string)($g['manageable_groups'] ?? ''));
        $isSuperGroup = (int)$g['is_super'] === 1;
        $gIsAdmin = !$isSuperGroup && (int)$g['is_admin'] === 1;
        $typeLabel = $isSuperGroup ? '⭐ 超级管理员组'
                   : ($gIsAdmin ? '🛡️ 管理员组' : '👥 普通组');
      ?>
        <tr>
          <td><?= (int)$g['id'] ?></td>
          <td><?= e($g['name']) ?></td>
          <td><?= $typeLabel ?></td>
          <td><span class="level-badge"><?= (int)$g['level'] ?></span></td>
          <td>
            <?php if ($isSuperGroup): ?>
              <span class="pill">全部权限</span>
            <?php elseif ($gPerms): ?>
              <div class="badge-row">
                <?php foreach ($gPerms as $pk): ?>
                  <span class="pill perm-pill" title="<?= e($pk) ?>"><?= e($GLOBALS['PERMS'][$pk] ?? $pk) ?></span>
                <?php endforeach; ?>
              </div>
            <?php else: ?><span class="grp-hint">—</span><?php endif; ?>
          </td>
          <td>
            <?php if ($isSuperGroup): ?>
              <span class="pill">全部组</span>
            <?php elseif (empty($gManageable)): ?>
              <span class="grp-hint">未分配</span>
            <?php else: ?>
              <div class="badge-row">
                <?php foreach ($normalGroups as $ng):
                  if (!in_array((int)$ng['id'], $gManageable, true)) continue;
                  $isSelf = (int)$ng['id'] === (int)$g['id'];
                ?>
                  <span class="pill <?= $isSelf ? 'pill-self' : '' ?>"><?= e($ng['name']) ?><?= $isSelf ? '（本组）' : '' ?></span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </td>
          <td><?= (int)$g['user_count'] ?></td>
          <td class="ops">
            <button type="button" class="btn-mini" data-modal-open="modal-g-edit-<?= (int)$g['id'] ?>">编辑</button>
            <?php if (!$isSuperGroup && (is_super() || !$gIsAdmin)): ?>
              <a class="btn-mini danger" href="/admin/groups.html?del=<?= (int)$g['id'] ?>"
                 onclick="return confirm('确定删除「<?= e($g['name']) ?>」？将移除该组全部成员归属。')">删除</a>
            <?php endif; ?>
          </td>
        </tr>
        <?php ob_start(); ?>
        <!-- 编辑弹窗 -->
        <div class="modal" id="modal-g-edit-<?= (int)$g['id'] ?>" data-modal>
          <div class="modal-overlay" data-modal-close></div>
          <div class="modal-box">
            <div class="modal-head">
              <h3 class="modal-title">编辑用户组：<?= e($g['name']) ?></h3>
              <button type="button" class="modal-close" data-modal-close aria-label="关闭">✕</button>
            </div>
            <form method="post" action="/admin/groups.html">
              <div class="modal-body">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">

                <div class="modal-section">
                  <div class="modal-section-head">📋 基础信息</div>
                  <div class="form-row">
                    <input type="text" name="name" value="<?= e($g['name']) ?>" required data-modal-autofocus>
                    <?php if (is_super()): ?>
                      <label class="inline-label">类型
                        <select name="group_type" class="edit-type">
                          <option value="normal" <?= !$isSuperGroup&&!$gIsAdmin?'selected':'' ?>>👥 普通组</option>
                          <option value="admin"  <?= $gIsAdmin?'selected':'' ?>>🛡️ 管理员组</option>
                          <option value="super"  <?= $isSuperGroup?'selected':'' ?>>⭐ 超级管理员组</option>
                        </select>
                      </label>
                      <label class="inline-label edit-level">级别
                        <input type="number" name="level" value="<?= (int)$g['level'] ?>" min="<?= $gIsAdmin ? 2 : 3 ?>" max="98" style="width:80px">
                      </label>
                    <?php endif; ?>
                  </div>
                </div>

                <?php if ($isSuperGroup): ?>
                  <div class="modal-section">
                    <p class="grp-hint">超级管理员组自动拥有全部权限、级别恒为 1，可管理所有用户组，且不能被降级/删除；系统中至少保留一个超级管理员组。</p>
                  </div>
                <?php elseif (is_super()): ?>
                  <div class="modal-section">
                    <div class="modal-section-head">🔑 权限集合
                      <span class="ms-hint">该组成员可执行的后台操作；管理员组自动包含「后台访问权限」</span>
                    </div>
                    <?php foreach (perm_categories() as $catId => $cat): ?>
                      <span class="perm-label"><?= e($cat['icon']) ?> <?= e($cat['label']) ?></span>
                      <input type="text" class="picker-filter"
                             data-picker-filter="pk-g-<?= e($catId) ?>-<?= (int)$g['id'] ?>"
                             placeholder="搜索「<?= e($cat['label']) ?>」权限…">
                      <div class="picker-box" id="pk-g-<?= e($catId) ?>-<?= (int)$g['id'] ?>">
                        <?php foreach ($cat['perms'] as $k): ?>
                          <label class="chk"><input type="checkbox" name="perms[]" value="<?= e($k) ?>"
                            <?= in_array($k, $gPerms, true)?'checked':'' ?>>
                            <span><?= e($GLOBALS['PERMS'][$k]) ?></span>
                          </label>
                        <?php endforeach; ?>
                      </div>
                      <div style="height:8px"></div>
                    <?php endforeach; ?>
                  </div>

                  <div class="modal-section">
                    <div class="modal-section-head">🛠️ 可管理用户组
                      <span class="ms-hint">该组成员只能管理勾选组内的用户；可勾选本组</span>
                    </div>
                    <input type="text" class="picker-filter"
                           data-picker-filter="pk-gm-<?= (int)$g['id'] ?>" placeholder="输入名称搜索用户组…">
                    <div class="picker-box" id="pk-gm-<?= (int)$g['id'] ?>">
                      <?php if (empty($normalGroups)): ?>
                        <span class="picker-empty">暂无普通用户组可分配。</span>
                      <?php else: foreach ($normalGroups as $ng):
                        $isSelf = (int)$ng['id'] === (int)$g['id'];
                      ?>
                        <label class="chk">
                          <input type="checkbox" name="manageable_groups[]" value="<?= (int)$ng['id'] ?>"
                            <?= in_array((int)$ng['id'], $gManageable, true)?'checked':'' ?>>
                          <span><?= e($ng['name']) ?><?= $isSelf ? '（本组）' : '' ?></span>
                        </label>
                      <?php endforeach; endif; ?>
                    </div>
                    <p class="perm-tip">仅可分配普通用户组（不能管理管理员组/超管组）。<strong>勾选本组即自管组：同组可互改、不能操作自己、不能把同伴移出本组</strong>；成员还须拥有后台访问权限及相应操作权限。未勾选任何组时管理范围为空。</p>
                  </div>
                <?php else: ?>
                  <div class="modal-section">
                    <p class="grp-hint">类型、权限与可管理范围仅系统管理员可修改。</p>
                  </div>
                <?php endif; ?>
              </div>
              <div class="modal-foot">
                <button type="button" class="btn-mini" data-modal-close>取消</button>
                <button type="submit" class="btn-primary">保存</button>
              </div>
            </form>
          </div>
        </div>
        <?php $groupModals[] = ob_get_clean(); ?>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php foreach ($groupModals as $modalHtml) echo $modalHtml; ?>

    <!-- 新增弹窗 -->
    <div class="modal" id="modal-g-create" data-modal>
      <div class="modal-overlay" data-modal-close></div>
      <div class="modal-box">
        <div class="modal-head">
          <h3 class="modal-title">新增用户组</h3>
          <button type="button" class="modal-close" data-modal-close aria-label="关闭">✕</button>
        </div>
        <form method="post" action="/admin/groups.html">
          <div class="modal-body">
            <input type="hidden" name="action" value="create">
            <div class="modal-section">
              <div class="modal-section-head">📋 基础信息</div>
              <div class="form-row">
                <input type="text" name="name" placeholder="用户组名称（必填）" required data-modal-autofocus>
                <?php if (is_super()): ?>
                  <label class="inline-label">类型
                    <select name="group_type" id="newType">
                      <option value="normal">👥 普通组</option>
                      <option value="admin">🛡️ 管理员组</option>
                      <option value="super">⭐ 超级管理员组</option>
                    </select>
                  </label>
                <?php endif; ?>
              </div>
            </div>
            <?php if (is_super()): ?>
              <div class="modal-section">
                <div class="modal-section-head">🔑 权限集合</div>
                <?php foreach (perm_categories() as $catId => $cat): ?>
                  <span class="perm-label"><?= e($cat['icon']) ?> <?= e($cat['label']) ?></span>
                  <input type="text" class="picker-filter"
                         data-picker-filter="pk-gnew-<?= e($catId) ?>"
                         placeholder="搜索「<?= e($cat['label']) ?>」权限…">
                  <div class="picker-box" id="pk-gnew-<?= e($catId) ?>">
                    <?php foreach ($cat['perms'] as $k): ?>
                      <label class="chk"><input type="checkbox" name="perms[]" value="<?= e($k) ?>">
                        <span><?= e($GLOBALS['PERMS'][$k]) ?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                  <div style="height:8px"></div>
                <?php endforeach; ?>
                <p class="perm-tip">管理员组自动包含「后台访问权限」；超级管理员组自动拥有全部权限，无需勾选。普通组需要后台访问时请显式勾选权限，保存后再编辑配置「可管理用户组」。</p>
              </div>
            <?php else: ?>
              <div class="modal-section">
                <p class="grp-hint">将创建无权限的普通用户组；类型、权限与可管理范围需由系统管理员后续配置。</p>
              </div>
            <?php endif; ?>
          </div>
          <div class="modal-foot">
            <button type="button" class="btn-mini" data-modal-close>取消</button>
            <button type="submit" class="btn-primary">新增用户组</button>
          </div>
        </form>
      </div>
    </div>
  </section>
</div>

<script>
// 编辑弹窗：组类型切换时，级别输入框下限随之变化（管理员 2 / 普通组 3）
document.addEventListener('change', function (e) {
  var sel = e.target;
  if (!sel.classList || !sel.classList.contains('edit-type')) return;
  var form = sel.closest('form');
  if (!form) return;
  var levelWrap = form.querySelector('.edit-level');
  if (!levelWrap) return;
  var levelInput = levelWrap.querySelector('input');
  levelInput.min = sel.value === 'admin' ? 2 : 3;
  if (parseInt(levelInput.value, 10) < parseInt(levelInput.min, 10)) {
    levelInput.value = levelInput.min;
  }
});
</script>
<?php
// 提交出错时自动重开对应弹窗，保证错误信息与表单内容可见
if (!empty($error) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $reopenId = null;
    $act = $_POST['action'] ?? '';
    if ($act === 'create') $reopenId = 'modal-g-create';
    elseif ($act === 'update') $reopenId = 'modal-g-edit-' . (int)($_POST['id'] ?? 0);
    if ($reopenId !== null) {
        echo '<script>window.addEventListener("DOMContentLoaded",function(){openModal(' . json_encode($reopenId) . ');});</script>';
    }
}
require __DIR__ . '/../includes/footer.php';
