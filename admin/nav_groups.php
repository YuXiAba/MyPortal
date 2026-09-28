<?php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require_admin();
require_perm('manage_navgroups');

$isSuperAdmin = is_super();
$userGroups = $pdo->query("SELECT * FROM user_groups ORDER BY id")->fetchAll();
// 可管理用户（超管 = 全部；普通管理员 = 级别更低且在范围内 + 自管组同伴）
$users = manageable_users($pdo);
// 编辑表单需展示范围外「冻结」授权，故另取全部用户
$allUsers = $pdo->query("SELECT id, username, display_name FROM users ORDER BY id")->fetchAll();
// 用户 → 所属用户组映射（供「单独可见用户」面板按组筛选）
$userGroupMembership = [];
foreach ($pdo->query("SELECT user_id, group_id FROM user_group_members")->fetchAll() as $__um) {
    $userGroupMembership[(int)$__um['user_id']][] = (int)$__um['group_id'];
}
// 当前管理员可分配可见性的用户组 / 用户 ID（超管 = 全部）
$scopeGroupIds = $isSuperAdmin
    ? array_map(fn($g) => (int)$g['id'], $userGroups)
    : (array)manageable_group_ids($pdo);
$scopeUserIds = array_map(fn($u) => (int)$u['id'], $users);
// 本人所属的用户组（可将本组设为可见组，否则自己反而看不到所管理的分组）
$myGroupIds = $isSuperAdmin
    ? array_map(fn($g) => (int)$g['id'], $userGroups)
    : user_group_ids($pdo, (int)current_user()['id']);
// 可见用户组候选 = 可管理组 ∪ 本人所属组（超管 = 全部）
$visibleGroupAllowed = array_values(array_unique(array_merge($scopeGroupIds, $myGroupIds)));
// 可设置「可管理用户组」的普通管理员：须持有「允许二级授权」；
// 仅可指定本人可管理（级别严格更低）的用户组（防越权把管理权交给同级/更高级）
$canSetMgr = $isSuperAdmin || has_perm('can_delegate');
$mgrAllowed = $isSuperAdmin
    ? array_map(fn($g) => (int)$g['id'], $userGroups)
    : mutable_group_ids($pdo);

// 从提交中收集授权 ID（用户组 / 单独用户），过滤非法值
function posted_ids(array $post, string $key): array {
    return isset($post[$key]) && is_array($post[$key])
        ? array_values(array_filter(array_map('intval', $post[$key]), fn($v) => $v > 0))
        : [];
}

$PER_PAGE_OPTIONS = [10, 20, 50, 100];

/** 生成分页链接（POST 回跳时从隐藏域读取页码；GET 时从查询串读取） */
function navgroups_page_url(array $override = []): string {
    $perPage = (int)($_REQUEST['per_page'] ?? 20);
    $page    = max(1, (int)($_REQUEST['p'] ?? 1));
    $params = ['p' => $page];
    if ($perPage !== 20) $params['per_page'] = $perPage;
    $final = array_filter(array_merge($params, $override), fn($v) => $v !== null);
    return '/admin/navgroups.html?' . http_build_query($final);
}

if (isset($_POST['action']) && $_POST['action'] === 'create') {
    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        $error = '分组名称不能为空。';
    } else {
        $postedGids = posted_ids($_POST, 'group_ids');
        $postedUids = posted_ids($_POST, 'user_ids');
        // 仅可授予「可管理组 ∪ 本人所属组」/ 可管理用户（防构造请求越权）
        $gidsCsv = implode(',', array_intersect($postedGids, $visibleGroupAllowed));
        $uidsCsv = implode(',', array_intersect($postedUids, $scopeUserIds));
        // 「可管理用户组」：超管或持「允许二级授权」的管理员可设置，
        // 但只能指定本人可管理（级别更低）的组；无权者提交一律忽略
        $mgrCsv = '';
        if ($canSetMgr) {
            $mgrCsv = implode(',', array_intersect(
                posted_ids($_POST, 'manageable_group_ids'), $mgrAllowed));
        }
        $pdo->prepare("INSERT INTO nav_groups (name, icon, sort_order, status, group_ids, user_ids, manageable_group_ids)
                       VALUES (?,?,?,?,?,?,?)")
            ->execute([
                $name,
                trim($_POST['icon'] ?? ''),
                (int)($_POST['sort_order'] ?? 0),
                isset($_POST['status']) ? 1 : 0,
                $gidsCsv,
                $uidsCsv,
                $mgrCsv,
            ]);
        write_log($pdo, 'create', '导航分组管理', "新增导航分组：{$name}");
        header('Location: ' . navgroups_page_url(['saved' => 1])); exit;
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'batch_create') {
    $lines = preg_split('/\r\n|\r|\n/', (string)($_POST['names'] ?? ''));
    $names = [];
    foreach ($lines as $ln) {
        $ln = trim($ln);
        if ($ln !== '') $names[] = $ln;
    }
    $names = array_values(array_unique($names));
    if (!$names) {
        $error = '请至少输入一个分组名称（每行一个）。';
    } else {
        $postedGids = posted_ids($_POST, 'group_ids');
        $postedUids = posted_ids($_POST, 'user_ids');
        $gidsCsv = implode(',', array_intersect($postedGids, $visibleGroupAllowed));
        $uidsCsv = implode(',', array_intersect($postedUids, $scopeUserIds));
        $mgrCsv = '';
        if ($canSetMgr) {
            $mgrCsv = implode(',', array_intersect(
                posted_ids($_POST, 'manageable_group_ids'), $mgrAllowed));
        }
        $existSt = $pdo->prepare("SELECT COUNT(*) FROM nav_groups WHERE name=?");
        $insSt = $pdo->prepare("INSERT INTO nav_groups (name, icon, sort_order, status, group_ids, user_ids, manageable_group_ids)
                                VALUES (?,?,?,?,?,?,?)");
        $doneN = 0;
        $skipN = 0;
        foreach ($names as $nm) {
            $existSt->execute([$nm]);
            if ((int)$existSt->fetchColumn() > 0) { $skipN++; continue; }
            $insSt->execute([$nm, '', 0, 1, $gidsCsv, $uidsCsv, $mgrCsv]);
            write_log($pdo, 'create', '导航分组管理', "批量新增导航分组：{$nm}");
            $doneN++;
        }
        header('Location: ' . navgroups_page_url(['batchdone' => $doneN, 'batchskip' => $skipN])); exit;
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'update' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    $name = trim($_POST['name'] ?? '');
    $curSt = $pdo->prepare("SELECT * FROM nav_groups WHERE id=?");
    $curSt->execute([$id]);
    $cur = $curSt->fetch();
    if (!$cur) {
        $error = '导航分组不存在。';
    } elseif (!nav_row_in_scope($pdo, 'nav_groups', $id)) {
        $error = '无权操作该导航分组：仅可查看、修改、添加、删除可管理用户组有权访问的内容。';
    } elseif ($name === '') {
        $error = '分组名称不能为空。';
    } else {
        $postedGids = posted_ids($_POST, 'group_ids');
        $postedUids = posted_ids($_POST, 'user_ids');
        // 范围外现有授权冻结保留，范围内（含本人所属组）以提交为准
        $gidsCsv = scoped_grant_csv($postedGids, resolve_ids($cur['group_ids']), $visibleGroupAllowed);
        $uidsCsv = scoped_grant_csv($postedUids, resolve_ids($cur['user_ids']), $scopeUserIds);
        // 「可管理用户组」：默认保留原值；超管/持二级授权的管理员可改
        // （候选外的现有值冻结保留，防越权同时不破坏既有配置）
        $mgrCsv = (string)$cur['manageable_group_ids'];
        if ($canSetMgr) {
            $mgrCsv = scoped_grant_csv(
                posted_ids($_POST, 'manageable_group_ids'),
                resolve_ids($cur['manageable_group_ids']),
                $mgrAllowed);
        }
        $pdo->prepare("UPDATE nav_groups SET name=?, icon=?, sort_order=?, status=?, group_ids=?, user_ids=?, manageable_group_ids=? WHERE id=?")
            ->execute([
                $name,
                trim($_POST['icon'] ?? ''),
                (int)($_POST['sort_order'] ?? 0),
                isset($_POST['status']) ? 1 : 0,
                $gidsCsv,
                $uidsCsv,
                $mgrCsv,
                $id,
            ]);
        write_log($pdo, 'update', '导航分组管理', "修改导航分组：{$name}（ID：{$id}）");
        header('Location: ' . navgroups_page_url(['saved' => 1])); exit;
    }
}

if (isset($_GET['del']) && (int)$_GET['del'] > 0) {
    $delId = (int)$_GET['del'];
    $delRow = $pdo->prepare("SELECT name FROM nav_groups WHERE id=?");
    $delRow->execute([$delId]);
    $delName = (string)$delRow->fetchColumn();
    if (nav_row_in_scope($pdo, 'nav_groups', $delId)) {
        $pdo->prepare("DELETE FROM nav_groups WHERE id=?")->execute([$delId]);
        write_log($pdo, 'delete', '导航分组管理', "删除导航分组：{$delName}（ID：{$delId}）");
        header('Location: ' . navgroups_page_url(['saved' => 1])); exit;
    }
    $error = '无权删除该导航分组：仅可管理可管理用户组有权访问的内容。';
}

// ---- 分页：总数 + 当前页数据 ----
$perPage = (int)($_GET['per_page'] ?? 20);
if (!in_array($perPage, $PER_PAGE_OPTIONS, true)) $perPage = 20;
list($navScopeSql, $navScopeArgs) = nav_scope_clause($pdo, 'g', 'g.manageable_group_ids');

$countSt = $pdo->prepare("SELECT COUNT(*) FROM nav_groups g WHERE $navScopeSql");
$countSt->execute($navScopeArgs);
$total = (int)$countSt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$page = max(1, (int)($_GET['p'] ?? 1));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$listSt = $pdo->prepare("SELECT g.*, (SELECT COUNT(*) FROM nav_items n WHERE n.group_id=g.id) AS item_count
                          FROM nav_groups g WHERE $navScopeSql
                         ORDER BY g.sort_order, g.id
                         LIMIT $perPage OFFSET $offset");
$listSt->execute($navScopeArgs);
$groups = $listSt->fetchAll();
$pageTitle = '导航分组管理';
require __DIR__ . '/../includes/header.php';
?>
<div class="admin-layout">
  <?php render_admin_sidebar('navgroups'); ?>

  <section class="admin-main">
    <div class="page-head-bar">
      <div>
        <h2>导航分组管理</h2>
        <p class="page-sub">维护前台导航的分组结构、可见范围与可管理用户组。</p>
      </div>
      <div class="ph-actions">
        <button type="button" class="btn-primary" data-modal-open="modal-ng-create">➕ 新增分组</button>
        <button type="button" class="btn-mini" data-modal-open="modal-ng-batch">📚 批量添加</button>
      </div>
    </div>

    <?php if (isset($_GET['saved'])): ?><p class="form-success">已保存。</p><?php endif; ?>
    <?php if (isset($_GET['batchdone']) || isset($_GET['batchskip'])): ?>
      <p class="form-success">
        批量添加完成：成功 <?= (int)($_GET['batchdone'] ?? 0) ?> 个
        <?php if ((int)($_GET['batchskip'] ?? 0) > 0): ?>
          ，<?= (int)$_GET['batchskip'] ?> 个因同名已存在被跳过
        <?php endif; ?>。
      </p>
    <?php endif; ?>
    <?php if (!empty($error)): ?><p class="form-error"><?= e($error) ?></p><?php endif; ?>

    <details class="rule-card">
      <summary>📜 管理范围规则</summary>
      <ul>
        <?php if ($isSuperAdmin): ?>
          <li>系统管理员可查看、新增、修改、删除<strong>全部导航分组</strong>，为任意用户组 / 用户分配可见性，并可设置<strong>可管理用户组</strong>。</li>
          <li>被设为“可管理用户组”的成员，可对本分组及其下导航项增删改查（无需系统管理员身份）。</li>
        <?php else: ?>
          <li>您仅可查看、新增、修改、删除<strong>可管理用户组有权访问</strong>的分组（公开分组除外）；若您所属用户组被设为某分组的可管理用户组，同样可管理该分组。</li>
          <li>可见性可授予您<strong>可管理的用户组 / 用户</strong>，也可勾选<strong>本人所属组（本组）</strong>；范围外的现有授权以灰色冻结显示，不会被改动。</li>
          <li>持有「允许二级授权」时，您还可设置“可管理用户组”，但仅可指定您可管理的更低级别用户组。</li>
        <?php endif; ?>
        <li>本页面权限可由系统管理员在权限矩阵分配，也可经二级授权授予。</li>
      </ul>
    </details>

    <?php if (empty($groups)): ?>
      <p class="empty-tip">当前页没有导航分组。</p>
    <?php endif; ?>

    <?php foreach ($groups as $g):
        $gGids = resolve_ids($g['group_ids']);
        $gUids = resolve_ids($g['user_ids']);
        $gMgr  = resolve_ids($g['manageable_group_ids']);
        // 组 id → 名称（用于 pill 的 title 全文）
        $name_of_gid = function ($id) use ($userGroups) {
            foreach ($userGroups as $ug) if ((int)$ug['id'] === $id) return $ug['name'];
            return '#' . $id;
        };
        $name_of_uid = function ($id) use ($allUsers) {
            foreach ($allUsers as $u) if ((int)$u['id']===$id) return $u['display_name']?:$u['username'];
            return '#'.$id;
        };
        $isPublic = empty($gGids) && empty($gUids);
        $gidTitle = implode('、', array_map($name_of_gid, $gGids));
        $uidTitle = implode('、', array_map($name_of_uid, $gUids));
        $mgrTitle = implode('、', array_map($name_of_gid, $gMgr));
    ?>
      <div class="admin-form ng-card">
        <div class="form-row">
          <strong class="icon-cell"><?= icon_html((string)$g['icon']) ?> <?= e($g['name']) ?></strong>
          <span class="grp-hint"><?= (int)$g['item_count'] ?> 项 · 排序 <?= (int)$g['sort_order'] ?> · <?= (int)$g['status']===1?'✅ 启用':'🚫 停用' ?></span>
          <?php if ($isPublic): ?>
            <span class="scope-pill is-public">🌐 公开</span>
          <?php else: ?>
            <span class="scope-pill" title="可见组：<?= e($gidTitle ?: '无') ?>&#10;可见用户：<?= e($uidTitle ?: '无') ?>">
              👁️ <?= $gGids ? count($gGids).' 组' : '' ?><?= ($gGids && $gUids) ? ' · ' : '' ?><?= $gUids ? count($gUids).' 人' : '' ?>
            </span>
          <?php endif; ?>
          <?php if ($gMgr): ?>
            <span class="scope-pill is-manage" title="可管理用户组：<?= e($mgrTitle) ?>">🛠️ <?= count($gMgr) ?> 组可管理</span>
          <?php endif; ?>
          <button type="button" class="btn-mini" data-modal-open="modal-ng-edit-<?= (int)$g['id'] ?>">编辑</button>
          <a class="btn-mini danger" href="<?= e(navgroups_page_url(['del' => (int)$g['id']])) ?>"
             onclick="return confirm('确定删除分组「<?= e($g['name']) ?>」？该分组下导航项将一并删除。')">删除</a>
        </div>
      </div>

      <!-- 编辑弹窗 -->
      <div class="modal" id="modal-ng-edit-<?= (int)$g['id'] ?>" data-modal>
        <div class="modal-overlay" data-modal-close></div>
        <div class="modal-box">
          <div class="modal-head">
            <h3 class="modal-title">编辑导航分组：<?= e($g['name']) ?></h3>
            <button type="button" class="modal-close" data-modal-close aria-label="关闭">✕</button>
          </div>
          <form method="post" action="/admin/navgroups.html">
            <div class="modal-body">
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
              <input type="hidden" name="p" value="<?= $page ?>">
              <input type="hidden" name="per_page" value="<?= $perPage ?>">

              <div class="modal-section">
                <div class="modal-section-head">📋 基础信息</div>
                <div class="form-row">
                  <input type="text" name="name" value="<?= e($g['name']) ?>" required>
                  <input type="text" name="icon" value="<?= e($g['icon']) ?>" placeholder="emoji 或图片 URL">
                  <input type="number" name="sort_order" value="<?= (int)$g['sort_order'] ?>">
                  <label class="chk"><input type="checkbox" name="status" <?= (int)$g['status']===1?'checked':'' ?>> 启用</label>
                </div>
              </div>

              <div class="modal-section">
                <div class="modal-section-head">👀 谁可以看到
                  <span class="ms-hint">都不勾选 = 对所有人公开；勾选后仅选中对象可见</span>
                </div>
                <span class="perm-label">可见用户组</span>
                <input type="text" class="picker-filter" data-picker-filter="pk-ng-g-<?= (int)$g['id'] ?>" placeholder="输入名称搜索用户组…">
                <div class="picker-box" id="pk-ng-g-<?= (int)$g['id'] ?>">
                  <?php foreach ($userGroups as $ug):
                      $gidNow = (int)$ug['id'];
                      $gChecked = in_array($gidNow, $gGids, true);
                      $gEditable = in_array($gidNow, $visibleGroupAllowed, true);
                      // 范围外且未勾选的组不显示；已勾选的范围外组冻结保留
                      if (!$gEditable && !$gChecked) continue; ?>
                    <label class="chk"><input type="checkbox" name="group_ids[]" value="<?= $gidNow ?>"
                      <?= $gChecked ? 'checked' : '' ?> <?= $gEditable ? '' : 'disabled' ?>>
                      <span><?= e($ug['name']) ?><?php if (in_array($gidNow, $myGroupIds, true)): ?> <span class="own-grp-tag">（本组）</span><?php endif; ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>

                <div style="height:10px"></div>
                <span class="perm-label">单独可见用户</span>
                <div class="picker-toolbar">
                  <input type="text" class="picker-filter" data-picker-filter="pk-ng-u-<?= (int)$g['id'] ?>" placeholder="输入名称搜索用户…">
                  <select class="picker-group" data-picker-group="pk-ng-u-<?= (int)$g['id'] ?>">
                    <option value="">全部组</option>
                    <?php foreach ($userGroups as $ug):
                        if (!in_array((int)$ug['id'], $visibleGroupAllowed, true)) continue; ?>
                      <option value="<?= (int)$ug['id'] ?>"><?= e($ug['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="picker-box" id="pk-ng-u-<?= (int)$g['id'] ?>">
                  <?php foreach ($allUsers as $u):
                      $uidNow = (int)$u['id'];
                      $uChecked = in_array($uidNow, $gUids, true);
                      $uEditable = $isSuperAdmin || in_array($uidNow, $scopeUserIds, true);
                      if (!$uEditable && !$uChecked) continue; ?>
                    <label class="chk" title="登录账号：<?= e($u['username']) ?>"
                           data-gids="<?= isset($userGroupMembership[$uidNow]) ? implode(',', $userGroupMembership[$uidNow]) : '' ?>"><input type="checkbox" name="user_ids[]" value="<?= $uidNow ?>"
                      <?= $uChecked ? 'checked' : '' ?> <?= $uEditable ? '' : 'disabled' ?>>
                      <span><?= e($u['display_name'] ?: $u['username']) ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
                <?php if (!$isSuperAdmin): ?>
                  <p class="perm-tip">灰色勾选为范围外的现有授权，已冻结保留；您仅可修改可管理用户组 / 用户的可见性。</p>
                <?php endif; ?>
              </div>

              <div class="modal-section">
                <div class="modal-section-head">🛠️ 谁可以管理
                  <span class="ms-hint">勾选组的成员可在后台管理本分组及其下全部导航项</span>
                </div>
                <?php if ($canSetMgr): ?>
                  <input type="text" class="picker-filter" data-picker-filter="pk-ng-m-<?= (int)$g['id'] ?>" placeholder="输入名称搜索用户组…">
                  <div class="picker-box" id="pk-ng-m-<?= (int)$g['id'] ?>">
                    <?php
                    // 候选 = 本人可指定的组（超管=全部）∪ 范围外的现有值（冻结保留）
                    $editMgrIds = array_values(array_unique(array_merge($mgrAllowed, $gMgr)));
                    if (empty($editMgrIds)): ?>
                      <span class="picker-empty">未设置（不勾选则仅系统管理员可管理）。</span>
                    <?php endif; ?>
                    <?php foreach ($userGroups as $ug):
                        $mNow = (int)$ug['id'];
                        if (!in_array($mNow, $editMgrIds, true)) continue;
                        $mEditable = in_array($mNow, $mgrAllowed, true);
                    ?>
                      <label class="chk"><input type="checkbox" name="manageable_group_ids[]" value="<?= $mNow ?>"
                        <?= in_array($mNow, $gMgr, true) ? 'checked' : '' ?> <?= $mEditable ? '' : 'disabled' ?>>
                        <span><?= e($ug['name']) ?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                  <p class="perm-tip">不勾选则仅系统管理员可管理。<?= $isSuperAdmin ? '' : '经二级授权，仅可指定您可管理的更低级别用户组；灰色为范围外现有授权，已冻结。' ?></p>
                <?php else: ?>
                  <div class="picker-box">
                    <?php if (!$gMgr): ?>
                      <span class="picker-empty">未设置（仅系统管理员可管理）。</span>
                    <?php else: foreach ($userGroups as $ug):
                        if (!in_array((int)$ug['id'], $gMgr, true)) continue; ?>
                      <label class="chk"><input type="checkbox" checked disabled>
                        <span><?= e($ug['name']) ?></span>
                      </label>
                    <?php endforeach; endif; ?>
                  </div>
                  <p class="perm-tip">仅系统管理员或持有「允许二级授权」的管理员可修改此项。</p>
                <?php endif; ?>
              </div>
            </div>
            <div class="modal-foot">
              <span class="modal-hint grp-hint"></span>
              <button type="button" class="btn-mini" data-modal-close>取消</button>
              <button type="submit" class="btn-primary">保存</button>
            </div>
          </form>
        </div>
      </div>
    <?php endforeach; ?>

    <!-- 新增弹窗 -->
    <div class="modal" id="modal-ng-create" data-modal>
      <div class="modal-overlay" data-modal-close></div>
      <div class="modal-box">
        <div class="modal-head">
          <h3 class="modal-title">新增导航分组</h3>
          <button type="button" class="modal-close" data-modal-close aria-label="关闭">✕</button>
        </div>
        <form method="post" action="/admin/navgroups.html">
          <div class="modal-body">
            <input type="hidden" name="action" value="create">
            <div class="modal-section">
              <div class="modal-section-head">📋 基础信息</div>
              <div class="form-row">
                <input type="text" name="name" placeholder="分组名称（必填），如 搜索引擎" required data-modal-autofocus>
                <input type="text" name="icon" placeholder="图标（emoji 或图片 URL，可选）">
                <input type="number" name="sort_order" placeholder="排序" value="0">
                <label class="chk"><input type="checkbox" name="status" checked> 启用</label>
              </div>
            </div>
            <div class="modal-section">
              <div class="modal-section-head">👀 谁可以看到
                <span class="ms-hint">都不勾选 = 对所有人公开；勾选后仅选中对象可见</span>
              </div>
              <span class="perm-label">可见用户组</span>
              <input type="text" class="picker-filter" data-picker-filter="pk-ng-create-g" placeholder="输入名称搜索用户组…">
              <div class="picker-box" id="pk-ng-create-g">
                <?php foreach ($userGroups as $ug):
                    if (!in_array((int)$ug['id'], $visibleGroupAllowed, true)) continue; ?>
                  <label class="chk"><input type="checkbox" name="group_ids[]" value="<?= (int)$ug['id'] ?>">
                    <span><?= e($ug['name']) ?><?php if (in_array((int)$ug['id'], $myGroupIds, true)): ?> <span class="own-grp-tag">（本组）</span><?php endif; ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
              <div style="height:10px"></div>
              <span class="perm-label">单独可见用户</span>
              <div class="picker-toolbar">
                <input type="text" class="picker-filter" data-picker-filter="pk-ng-create-u" placeholder="输入名称搜索用户…">
                <select class="picker-group" data-picker-group="pk-ng-create-u">
                  <option value="">全部组</option>
                  <?php foreach ($userGroups as $ug):
                      if (!in_array((int)$ug['id'], $visibleGroupAllowed, true)) continue; ?>
                    <option value="<?= (int)$ug['id'] ?>"><?= e($ug['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="picker-box" id="pk-ng-create-u">
                <?php foreach ($users as $u): ?>
                  <label class="chk" title="登录账号：<?= e($u['username']) ?>"
                         data-gids="<?= isset($userGroupMembership[(int)$u['id']]) ? implode(',', $userGroupMembership[(int)$u['id']]) : '' ?>"><input type="checkbox" name="user_ids[]" value="<?= (int)$u['id'] ?>">
                    <span><?= e($u['display_name'] ?: $u['username']) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="modal-section">
              <div class="modal-section-head">🛠️ 谁可以管理
                <span class="ms-hint">勾选组的成员可在后台管理本分组及其下全部导航项</span>
              </div>
              <div class="picker-box">
                <?php if ($canSetMgr): ?>
                  <?php if (empty($mgrAllowed)): ?>
                    <span class="picker-empty">您当前没有可指定的更低级别用户组。</span>
                  <?php else: foreach ($userGroups as $ug):
                      if (!in_array((int)$ug['id'], $mgrAllowed, true)) continue; ?>
                    <label class="chk"><input type="checkbox" name="manageable_group_ids[]" value="<?= (int)$ug['id'] ?>">
                      <span><?= e($ug['name']) ?></span>
                    </label>
                  <?php endforeach; endif; ?>
                  <p class="perm-tip">不勾选则仅系统管理员可管理。<?= $isSuperAdmin ? '' : '（经二级授权，仅可指定您可管理的更低级别用户组）' ?></p>
                <?php else: ?>
                  <span class="picker-empty">仅系统管理员或持有「允许二级授权」的管理员可设置。</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <div class="modal-foot">
            <button type="button" class="btn-mini" data-modal-close>取消</button>
            <button type="submit" class="btn-primary">新增分组</button>
          </div>
        </form>
      </div>
    </div>

    <!-- 批量添加弹窗 -->
    <div class="modal" id="modal-ng-batch" data-modal>
      <div class="modal-overlay" data-modal-close></div>
      <div class="modal-box">
        <div class="modal-head">
          <h3 class="modal-title">批量添加导航分组</h3>
          <button type="button" class="modal-close" data-modal-close aria-label="关闭">✕</button>
        </div>
        <form method="post" action="/admin/navgroups.html">
          <div class="modal-body">
            <input type="hidden" name="action" value="batch_create">
            <div class="modal-section">
              <div class="modal-section-head">📚 分组名称
                <span class="ms-hint">每行一个；空行忽略、批内去重、同名跳过</span>
              </div>
              <textarea name="names" rows="6" style="width:100%"
                        placeholder="每行一个分组名称，例如：&#10;搜索引擎&#10;常用工具&#10;开发文档" required data-modal-autofocus></textarea>
            </div>
            <div class="modal-section">
              <div class="modal-section-head">👀 谁可以看到 <span class="ms-hint">对本批所有分组生效</span></div>
              <span class="perm-label">可见用户组</span>
              <input type="text" class="picker-filter" data-picker-filter="pk-ng-batch-g" placeholder="输入名称搜索用户组…">
              <div class="picker-box" id="pk-ng-batch-g">
                <?php foreach ($userGroups as $ug):
                    if (!in_array((int)$ug['id'], $visibleGroupAllowed, true)) continue; ?>
                  <label class="chk"><input type="checkbox" name="group_ids[]" value="<?= (int)$ug['id'] ?>">
                    <span><?= e($ug['name']) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
              <div style="height:10px"></div>
              <span class="perm-label">单独可见用户</span>
              <div class="picker-toolbar">
                <input type="text" class="picker-filter" data-picker-filter="pk-ng-batch-u" placeholder="输入名称搜索用户…">
                <select class="picker-group" data-picker-group="pk-ng-batch-u">
                  <option value="">全部组</option>
                  <?php foreach ($userGroups as $ug):
                      if (!in_array((int)$ug['id'], $visibleGroupAllowed, true)) continue; ?>
                    <option value="<?= (int)$ug['id'] ?>"><?= e($ug['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="picker-box" id="pk-ng-batch-u">
                <?php foreach ($users as $u): ?>
                  <label class="chk" title="登录账号：<?= e($u['username']) ?>"
                         data-gids="<?= isset($userGroupMembership[(int)$u['id']]) ? implode(',', $userGroupMembership[(int)$u['id']]) : '' ?>"><input type="checkbox" name="user_ids[]" value="<?= (int)$u['id'] ?>">
                    <span><?= e($u['display_name'] ?: $u['username']) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="modal-section">
              <div class="modal-section-head">🛠️ 谁可以管理</div>
              <div class="picker-box">
                <?php if ($canSetMgr): ?>
                  <?php foreach ($userGroups as $ug):
                      if (!in_array((int)$ug['id'], $mgrAllowed, true)) continue; ?>
                    <label class="chk"><input type="checkbox" name="manageable_group_ids[]" value="<?= (int)$ug['id'] ?>">
                      <span><?= e($ug['name']) ?></span>
                    </label>
                  <?php endforeach; ?>
                <?php else: ?>
                  <span class="picker-empty">仅系统管理员或持有「允许二级授权」的管理员可设置。</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <div class="modal-foot">
            <button type="button" class="btn-mini" data-modal-close>取消</button>
            <button type="submit" class="btn-primary">批量添加</button>
          </div>
        </form>
      </div>
    </div>

    <!-- 分页栏：与用户管理页样式一致，翻页保留每页条数 -->
    <div class="pagination-bar">
      <div class="page-info">
        共 <strong><?= $total ?></strong> 个分组，
        第 <?= $page ?> / <?= $totalPages ?> 页
        （显示第 <?= $total === 0 ? 0 : ($offset + 1) ?>–<?= min($offset + $perPage, $total) ?> 条）
      </div>
      <div class="page-controls">
        <label class="inline-label">每页
          <select onchange="location.href=this.value" style="width:80px">
            <?php foreach ($PER_PAGE_OPTIONS as $opt): ?>
              <option value="<?= e(navgroups_page_url(['per_page' => $opt, 'p' => 1, 'saved' => null])) ?>"
                 <?= $opt===$perPage?'selected':'' ?>><?= $opt ?> 条</option>
            <?php endforeach; ?>
          </select>
        </label>

        <a class="btn-mini <?= $page<=1?'disabled':'' ?>" href="<?= e(navgroups_page_url(['p' => 1])) ?>">« 首页</a>
        <a class="btn-mini <?= $page<=1?'disabled':'' ?>" href="<?= e(navgroups_page_url(['p' => max(1,$page-1)])) ?>">‹ 上一页</a>

        <?php
        $startP = max(1, $page - 2);
        $endP = min($totalPages, $startP + 4);
        $startP = max(1, $endP - 4);
        for ($i = $startP; $i <= $endP; $i++): ?>
          <a class="page-num <?= $i===$page?'current':'' ?>" href="<?= e(navgroups_page_url(['p' => $i])) ?>"><?= $i ?></a>
        <?php endfor; ?>

        <a class="btn-mini <?= $page>=$totalPages?'disabled':'' ?>" href="<?= e(navgroups_page_url(['p' => min($totalPages,$page+1)])) ?>">下一页 ›</a>
        <a class="btn-mini <?= $page>=$totalPages?'disabled':'' ?>" href="<?= e(navgroups_page_url(['p' => $totalPages])) ?>">末页 »</a>
      </div>
    </div>
  </section>
</div>

<?php
// 提交出错时自动重开对应弹窗，保证错误信息与表单内容可见
if (!empty($error)) {
    $reopenId = null;
    $act = $_POST['action'] ?? '';
    if ($act === 'create') $reopenId = 'modal-ng-create';
    elseif ($act === 'batch_create') $reopenId = 'modal-ng-batch';
    elseif ($act === 'update') $reopenId = 'modal-ng-edit-' . (int)($_POST['id'] ?? 0);
    if ($reopenId !== null) {
        echo '<script>window.addEventListener("DOMContentLoaded",function(){openModal(' . json_encode($reopenId) . ');});</script>';
    }
}
require __DIR__ . '/../includes/footer.php';
