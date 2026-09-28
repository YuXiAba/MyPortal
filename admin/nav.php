<?php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require_admin();
require_perm('manage_nav');

$isSuperAdmin = is_super();
$navGroups = $pdo->query("SELECT * FROM nav_groups ORDER BY sort_order, id")->fetchAll();
$userGroups = $pdo->query("SELECT * FROM user_groups ORDER BY id")->fetchAll();
// 「单独可见用户」仅列出当前管理员可管理的用户（级别更低 + 可管理组范围内）
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
// 本人所属的用户组（可将本组设为可见组，否则自己反而看不到所管理的导航项）
$myGroupIds = $isSuperAdmin
    ? array_map(fn($g) => (int)$g['id'], $userGroups)
    : user_group_ids($pdo, (int)current_user()['id']);
$visibleGroupAllowed = array_values(array_unique(array_merge($scopeGroupIds, $myGroupIds)));
$scopeUserIds = array_map(fn($u) => (int)$u['id'], $users);
// 当前管理员可操作的导航分组 ID（导航项只能归属这些分组）
list($ngScopeSql, $ngScopeArgs) = nav_scope_clause($pdo, 'g', 'g.manageable_group_ids');
$ngScopeSt = $pdo->prepare("SELECT g.id FROM nav_groups g WHERE $ngScopeSql");
$ngScopeSt->execute($ngScopeArgs);
$scopeNavGroupIds = array_map('intval', $ngScopeSt->fetchAll(PDO::FETCH_COLUMN));

// 从提交收集授权 ID，过滤非法值
function collect_ids(array $post, string $key): array {
    return isset($post[$key]) && is_array($post[$key])
        ? array_values(array_filter(array_map('intval', $post[$key]), fn($v) => $v > 0))
        : [];
}

$PER_PAGE_OPTIONS = [10, 20, 50, 100];

/** 生成分页链接，保留分组筛选；POST 回跳时从隐藏域读取页码 */
function navs_page_url(array $override = []): string {
    $filterGroup = (int)($_REQUEST['group'] ?? 0);
    $perPage = (int)($_REQUEST['per_page'] ?? 20);
    $page    = max(1, (int)($_REQUEST['p'] ?? 1));
    $params = ['p' => $page];
    if ($filterGroup > 0) $params['group'] = $filterGroup;
    if ($perPage !== 20) $params['per_page'] = $perPage;
    $final = array_filter(array_merge($params, $override), fn($v) => $v !== null);
    return '/admin/navs.html?' . http_build_query($final);
}

// ---- 新增 ----
if (isset($_POST['action']) && $_POST['action'] === 'create') {
    $title = trim($_POST['title'] ?? '');
    $url   = trim($_POST['url'] ?? '');
    $group = (int)($_POST['nav_group_id'] ?? 0);
    if ($title !== '' && $url !== '') {
        // 所属导航分组必须在管理范围内
        if ($group <= 0 || !nav_row_in_scope($pdo, 'nav_groups', $group)) {
            $error = '无权在该导航分组下添加导航项：仅可管理可管理用户组有权访问的分组。';
        } else {
            $postedGids = collect_ids($_POST, 'group_ids');
            $postedUids = collect_ids($_POST, 'user_ids');
            // 普通管理员：仅可授予其可管理用户组 / 用户（防构造请求越权）
            $gidsCsv = $isSuperAdmin
                ? implode(',', $postedGids)
                : implode(',', array_intersect($postedGids, $visibleGroupAllowed));
            $uidsCsv = $isSuperAdmin
                ? implode(',', $postedUids)
                : implode(',', array_intersect($postedUids, $scopeUserIds));
            $pdo->prepare("INSERT INTO nav_items (group_id, title, url, icon, sort_order, status, group_ids, user_ids)
                           VALUES (?,?,?,?,?,?,?,?)")
                ->execute([
                    $group,
                    $title,
                    $url,
                    trim($_POST['icon'] ?? ''),
                    (int)($_POST['sort_order'] ?? 0),
                    isset($_POST['status']) ? 1 : 0,
                    $gidsCsv,
                    $uidsCsv,
                ]);
            write_log($pdo, 'create', '导航项管理', "新增导航项：{$title}");
            header('Location: ' . navs_page_url(['saved' => 1])); exit;
        }
    } else {
        $error = '标题和 URL 不能为空。';
    }
}

// ---- 批量新增：每行「标题 | URL | 图标(可选)」 ----
if (isset($_POST['action']) && $_POST['action'] === 'batch_create') {
    $group = (int)($_POST['nav_group_id'] ?? 0);
    if ($group <= 0 || !nav_row_in_scope($pdo, 'nav_groups', $group)) {
        $error = '无权在该导航分组下批量添加：仅可管理可管理用户组有权访问的分组。';
    } else {
        $lines = preg_split('/\r\n|\r|\n/', (string)($_POST['items'] ?? ''));
        $rows = [];
        foreach ($lines as $ln) {
            $ln = trim($ln);
            if ($ln === '') continue;
            $parts = array_map('trim', explode('|', $ln, 3));
            $t = $parts[0] ?? '';
            $u = $parts[1] ?? '';
            $ic = $parts[2] ?? '';
            if ($t === '' || $u === '') continue;
            $rows[$t . "\0" . $u . "\0" . $ic] = [$t, $u, $ic]; // 批内去重
        }
        if (!$rows) {
            $error = '请按「标题 | URL | 图标（可选）」格式输入至少一行。';
        } else {
            $postedGids = collect_ids($_POST, 'group_ids');
            $postedUids = collect_ids($_POST, 'user_ids');
            $gidsCsv = $isSuperAdmin
                ? implode(',', $postedGids)
                : implode(',', array_intersect($postedGids, $visibleGroupAllowed));
            $uidsCsv = $isSuperAdmin
                ? implode(',', $postedUids)
                : implode(',', array_intersect($postedUids, $scopeUserIds));
            $insSt = $pdo->prepare("INSERT INTO nav_items (group_id, title, url, icon, sort_order, status, group_ids, user_ids)
                                    VALUES (?,?,?,?,?,?,?,?)");
            $doneN = 0;
            foreach ($rows as $r) {
                $insSt->execute([$group, $r[0], $r[1], $r[2], 0, 1, $gidsCsv, $uidsCsv]);
                write_log($pdo, 'create', '导航项管理', "批量新增导航项：{$r[0]}");
                $doneN++;
            }
            header('Location: ' . navs_page_url(['batchdone' => $doneN, 'group' => $group])); exit;
        }
    }
}

// ---- 编辑 ----
if (isset($_POST['action']) && $_POST['action'] === 'update' && isset($_POST['id'])) {
    $id    = (int)$_POST['id'];
    $title = trim($_POST['title'] ?? '');
    $url   = trim($_POST['url'] ?? '');
    $group = (int)($_POST['nav_group_id'] ?? 0);
    $curSt = $pdo->prepare("SELECT * FROM nav_items WHERE id=?");
    $curSt->execute([$id]);
    $cur = $curSt->fetch();
    if (!$cur) {
        $error = '导航项不存在。';
    } elseif (!nav_row_in_scope($pdo, 'nav_items', $id)) {
        $error = '无权操作该导航项：仅可查看、修改、添加、删除可管理用户组有权访问的内容。';
    } elseif ($title === '' || $url === '') {
        $error = '标题和 URL 不能为空。';
    } else {
        // 所属分组：范围内可改；提交范围外分组仅允许与现值相同（未移动）
        $parentOk = nav_row_in_scope($pdo, 'nav_groups', $group)
                 || ((int)$cur['group_id'] === $group);
        if (!$parentOk) {
            $error = '无权将导航项移动到该导航分组。';
        } else {
            $postedGids = collect_ids($_POST, 'group_ids');
            $postedUids = collect_ids($_POST, 'user_ids');
            // 普通管理员：范围外现有授权冻结保留，范围内以提交为准
            $gidsCsv = $isSuperAdmin
                ? implode(',', $postedGids)
                : scoped_grant_csv($postedGids, resolve_ids($cur['group_ids']), $visibleGroupAllowed);
            $uidsCsv = $isSuperAdmin
                ? implode(',', $postedUids)
                : scoped_grant_csv($postedUids, resolve_ids($cur['user_ids']), $scopeUserIds);
            $pdo->prepare("UPDATE nav_items SET group_id=?, title=?, url=?, icon=?, sort_order=?, status=?, group_ids=?, user_ids=?
                           WHERE id=?")
                ->execute([
                    $group > 0 ? $group : null,
                    $title,
                    $url,
                    trim($_POST['icon'] ?? ''),
                    (int)($_POST['sort_order'] ?? 0),
                    isset($_POST['status']) ? 1 : 0,
                    $gidsCsv,
                    $uidsCsv,
                    $id,
                ]);
            write_log($pdo, 'update', '导航项管理', "修改导航项：{$title}（ID：{$id}）");
            header('Location: ' . navs_page_url(['saved' => 1])); exit;
        }
    }
}

// ---- 删除 ----
if (isset($_GET['del']) && (int)$_GET['del'] > 0) {
    $delId = (int)$_GET['del'];
    $delRow = $pdo->prepare("SELECT title FROM nav_items WHERE id=?");
    $delRow->execute([$delId]);
    $delTitle = (string)$delRow->fetchColumn();
    if (nav_row_in_scope($pdo, 'nav_items', $delId)) {
        $pdo->prepare("DELETE FROM nav_items WHERE id=?")->execute([$delId]);
        write_log($pdo, 'delete', '导航项管理', "删除导航项：{$delTitle}（ID：{$delId}）");
        header('Location: ' . navs_page_url(['saved' => 1])); exit;
    }
    $error = '无权删除该导航项：仅可管理可管理用户组有权访问的内容。';
}

// ---- 分页（按分组筛选；每组内按 sort_order, id 排序） ----
$filterGroup = isset($_GET['group']) && (int)$_GET['group'] > 0 ? (int)$_GET['group'] : 0;
// 筛选分组若不在管理范围内则忽略（防构造请求窥视范围外条目）
if ($filterGroup > 0 && !nav_row_in_scope($pdo, 'nav_groups', $filterGroup)) $filterGroup = 0;
$perPage = (int)($_GET['per_page'] ?? 20);
if (!in_array($perPage, $PER_PAGE_OPTIONS, true)) $perPage = 20;

// 导航项继承所属分组的「可管理用户组」直接授权
list($itemScopeSql, $itemScopeArgs) = nav_scope_clause($pdo, 'n',
    '(SELECT pg.manageable_group_ids FROM nav_groups pg WHERE pg.id=n.group_id)');

if ($filterGroup > 0) {
    $cntSt = $pdo->prepare("SELECT COUNT(*) FROM nav_items n
                             WHERE n.group_id=? AND $itemScopeSql");
    $cntSt->execute(array_merge([$filterGroup], $itemScopeArgs));
    $total = (int)$cntSt->fetchColumn();

    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = max(1, (int)($_GET['p'] ?? 1));
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $perPage;

    $st = $pdo->prepare("SELECT * FROM nav_items n
                          WHERE n.group_id=? AND $itemScopeSql
                          ORDER BY n.sort_order, n.id
                          LIMIT $perPage OFFSET $offset");
    $st->execute(array_merge([$filterGroup], $itemScopeArgs));
    $items = $st->fetchAll();
} else {
    $cntSt = $pdo->prepare("SELECT COUNT(*) FROM nav_items n WHERE $itemScopeSql");
    $cntSt->execute($itemScopeArgs);
    $total = (int)$cntSt->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = max(1, (int)($_GET['p'] ?? 1));
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $perPage;

    $st = $pdo->prepare("SELECT * FROM nav_items n WHERE $itemScopeSql
                          ORDER BY n.sort_order, n.id
                          LIMIT $perPage OFFSET $offset");
    $st->execute($itemScopeArgs);
    $items = $st->fetchAll();
}

// 组 id / 用户 id → 名称（用于 pill 的 title 全文）
$name_of_gid = function ($id) use ($userGroups) {
    foreach ($userGroups as $ug) if ((int)$ug['id'] === $id) return $ug['name'];
    return '#' . $id;
};
$name_of_uid = function ($id) use ($allUsers) {
    foreach ($allUsers as $u) if ((int)$u['id'] === $id) return $u['display_name'] ?: $u['username'];
    return '#' . $id;
};

$pageTitle = '导航项管理';
require __DIR__ . '/../includes/header.php';
?>
<div class="admin-layout">
  <?php render_admin_sidebar('navs'); ?>

  <section class="admin-main">
    <div class="page-head-bar">
      <div>
        <h2>导航项管理</h2>
        <p class="page-sub">维护各分组下的导航链接、排序与可见范围。</p>
      </div>
      <div class="ph-actions">
        <button type="button" class="btn-primary" data-modal-open="modal-nv-create">➕ 新增导航项</button>
        <button type="button" class="btn-mini" data-modal-open="modal-nv-batch">📚 批量添加</button>
      </div>
    </div>

    <?php if (isset($_GET['saved'])): ?><p class="form-success">已保存。</p><?php endif; ?>
    <?php if (isset($_GET['batchdone'])): ?>
      <p class="form-success">批量添加完成：成功 <?= (int)$_GET['batchdone'] ?> 个导航项。</p>
    <?php endif; ?>
    <?php if (!empty($error)): ?><p class="form-error"><?= e($error) ?></p><?php endif; ?>

    <details class="rule-card">
      <summary>📜 管理范围规则</summary>
      <ul>
        <?php if ($isSuperAdmin): ?>
          <li>系统管理员可查看、新增、修改、删除<strong>全部导航项</strong>，并可在任意导航分组下操作、为任意用户组 / 用户分配可见性。</li>
        <?php else: ?>
          <li>您仅可查看、新增、修改、删除<strong>可管理用户组有权访问</strong>的导航项（公开项除外）；若您所属用户组在「导航分组管理」中被设为所属分组的可管理用户组，同样可管理该分组下的导航项。</li>
          <li>导航项只能归属您可管理的导航分组，可见性可授予可管理的用户组 / 用户，<strong>也包含您本人所属的用户组</strong>（标注「本组」）；范围外的现有授权以灰色冻结显示，不会被改动。</li>
        <?php endif; ?>
        <li>本页面权限可由系统管理员在权限矩阵分配，也可经二级授权授予。</li>
      </ul>
    </details>

    <form method="get" class="search-inline" action="/admin/navs.html">
      <label class="inline-label">按分组筛选
        <select name="group" onchange="this.form.submit()">
          <option value="">全部</option>
          <?php foreach ($navGroups as $ng):
              if (!in_array((int)$ng['id'], $scopeNavGroupIds, true)) continue; ?>
            <option value="<?= (int)$ng['id'] ?>" <?= $filterGroup===(int)$ng['id']?'selected':'' ?>><?= e($ng['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </form>

    <table class="data-table">
      <thead><tr><th>所属分组</th><th>图标</th><th>标题</th><th>URL</th><th>状态</th><th>可见范围</th><th>操作</th></tr></thead>
      <tbody>
      <?php if (empty($items)): ?>
        <tr><td colspan="7" class="empty-tip">没有符合条件的导航项。</td></tr>
      <?php endif; ?>
      <?php $itemModals = []; foreach ($items as $item):
        $gids = resolve_ids($item['group_ids']);
        $uids = resolve_ids($item['user_ids']);
        $itemGroup = null;
        foreach ($navGroups as $ng) if ((int)$ng['id'] === (int)$item['group_id']) { $itemGroup = $ng['name']; break; }
        $isPublic = empty($gids) && empty($uids);
        $gTitle = implode('、', array_map($name_of_gid, $gids));
        $uTitle = implode('、', array_map($name_of_uid, $uids));
      ?>
        <tr>
          <td><?= e($itemGroup ?: '未分组') ?></td>
          <td class="icon-cell"><?= icon_html((string)$item['icon']) ?></td>
          <td><?= e($item['title']) ?></td>
          <td class="mono"><?= e($item['url']) ?></td>
          <td><?= (int)$item['status'] === 1 ? '✅ 启用' : '🚫 停用' ?></td>
          <td>
            <?php if ($isPublic): ?>
              <span class="scope-pill is-public">🌐 公开</span>
            <?php else: ?>
              <span class="scope-pill" title="可见组：<?= e($gTitle ?: '无') ?>&#10;可见用户：<?= e($uTitle ?: '无') ?>">
                👁️ <?= $gids ? count($gids).' 组' : '' ?><?= ($gids && $uids) ? ' · ' : '' ?><?= $uids ? count($uids).' 人' : '' ?>
              </span>
            <?php endif; ?>
          </td>
          <td class="ops">
            <button type="button" class="btn-mini" data-modal-open="modal-nv-edit-<?= (int)$item['id'] ?>">编辑</button>
            <a class="btn-mini danger" href="<?= e(navs_page_url(['del' => (int)$item['id']])) ?>"
               onclick="return confirm('确定删除「<?= e($item['title']) ?>」？')">删除</a>
          </td>
        </tr>
        <?php ob_start(); ?>
        <!-- 编辑弹窗 -->
        <div class="modal" id="modal-nv-edit-<?= (int)$item['id'] ?>" data-modal>
          <div class="modal-overlay" data-modal-close></div>
          <div class="modal-box">
            <div class="modal-head">
              <h3 class="modal-title">编辑导航项：<?= e($item['title']) ?></h3>
              <button type="button" class="modal-close" data-modal-close aria-label="关闭">✕</button>
            </div>
            <form method="post" action="/admin/navs.html">
              <div class="modal-body">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                <input type="hidden" name="group" value="<?= $filterGroup ?>">
                <input type="hidden" name="p" value="<?= $page ?>">
                <input type="hidden" name="per_page" value="<?= $perPage ?>">

                <div class="modal-section">
                  <div class="modal-section-head">📋 基础信息</div>
                  <div class="form-row">
                    <select name="nav_group_id" style="max-width:200px">
                      <?php
                      $curParent = (int)$item['group_id'];
                      $curParentEditable = $curParent > 0
                          && in_array($curParent, $scopeNavGroupIds, true);
                      // 当前所属分组若在范围外：作为禁用项保留显示（不允许移动，但保存不改归属）
                      if ($curParent > 0 && !$curParentEditable) {
                          $curParentName = '#未知名';
                          foreach ($navGroups as $ng) {
                              if ((int)$ng['id'] === $curParent) { $curParentName = $ng['name']; break; }
                          }
                          echo '<option value="' . $curParent . '" selected disabled>'
                               . e($curParentName) . '（范围外，不可移动）</option>';
                      } else {
                          echo '<option value="">未分组</option>';
                      }
                      foreach ($navGroups as $ng):
                          if (!in_array((int)$ng['id'], $scopeNavGroupIds, true)) continue; ?>
                        <option value="<?= (int)$ng['id'] ?>" <?= $curParent===(int)$ng['id']?'selected':'' ?>><?= e($ng['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <input type="text" name="title" value="<?= e($item['title']) ?>" required>
                    <input type="text" name="url" value="<?= e($item['url']) ?>" required>
                    <input type="text" name="icon" value="<?= e($item['icon']) ?>" placeholder="emoji 或图片 URL">
                    <input type="number" name="sort_order" value="<?= (int)$item['sort_order'] ?>">
                    <label class="chk"><input type="checkbox" name="status" <?= (int)$item['status']===1?'checked':'' ?>> 启用</label>
                  </div>
                </div>

                <div class="modal-section">
                  <div class="modal-section-head">👀 谁可以看到
                    <span class="ms-hint">都不勾选 = 对所有人公开；勾选后仅选中对象可见</span>
                  </div>
                  <span class="perm-label">可见用户组</span>
                  <input type="text" class="picker-filter" data-picker-filter="pk-nv-g-<?= (int)$item['id'] ?>" placeholder="输入名称搜索用户组…">
                  <div class="picker-box" id="pk-nv-g-<?= (int)$item['id'] ?>">
                    <?php foreach ($userGroups as $ug):
                        $gidNow = (int)$ug['id'];
                        $gChecked = in_array($gidNow, $gids, true);
                        $gEditable = $isSuperAdmin || in_array($gidNow, $visibleGroupAllowed, true);
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
                    <input type="text" class="picker-filter" data-picker-filter="pk-nv-u-<?= (int)$item['id'] ?>" placeholder="输入名称搜索用户…">
                    <select class="picker-group" data-picker-group="pk-nv-u-<?= (int)$item['id'] ?>">
                      <option value="">全部组</option>
                      <?php foreach ($userGroups as $ug):
                          if (!in_array((int)$ug['id'], $visibleGroupAllowed, true)) continue; ?>
                        <option value="<?= (int)$ug['id'] ?>"><?= e($ug['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="picker-box" id="pk-nv-u-<?= (int)$item['id'] ?>">
                    <?php foreach ($allUsers as $u):
                        $uidNow = (int)$u['id'];
                        $uChecked = in_array($uidNow, $uids, true);
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
                    <p class="perm-tip">灰色勾选为范围外的现有授权，已冻结保留；您仅可修改可管理范围内的设置。</p>
                  <?php endif; ?>
                </div>
              </div>
              <div class="modal-foot">
                <button type="button" class="btn-mini" data-modal-close>取消</button>
                <button type="submit" class="btn-primary">保存</button>
              </div>
            </form>
          </div>
        </div>
        <?php $itemModals[] = ob_get_clean(); ?>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php foreach ($itemModals as $modalHtml) echo $modalHtml; ?>

    <!-- 新增弹窗 -->
    <div class="modal" id="modal-nv-create" data-modal>
      <div class="modal-overlay" data-modal-close></div>
      <div class="modal-box">
        <div class="modal-head">
          <h3 class="modal-title">新增导航项</h3>
          <button type="button" class="modal-close" data-modal-close aria-label="关闭">✕</button>
        </div>
        <form method="post" action="/admin/navs.html">
          <div class="modal-body">
            <input type="hidden" name="action" value="create">
            <div class="modal-section">
              <div class="modal-section-head">📋 基础信息</div>
              <div class="form-row">
                <select name="nav_group_id" required style="max-width:200px" data-modal-autofocus>
                  <option value="">选择所属分组…</option>
                  <?php foreach ($navGroups as $ng):
                      if (!in_array((int)$ng['id'], $scopeNavGroupIds, true)) continue; ?>
                    <option value="<?= (int)$ng['id'] ?>"><?= e($ng['name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <input type="text" name="title" placeholder="标题（必填）" required>
                <input type="text" name="url" placeholder="URL，如 https://example.com（必填）" required>
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
              <input type="text" class="picker-filter" data-picker-filter="pk-nv-create-g" placeholder="输入名称搜索用户组…">
              <div class="picker-box" id="pk-nv-create-g">
                <?php foreach ($userGroups as $ug):
                    if (!$isSuperAdmin && !in_array((int)$ug['id'], $visibleGroupAllowed, true)) continue; ?>
                  <label class="chk"><input type="checkbox" name="group_ids[]" value="<?= (int)$ug['id'] ?>">
                    <span><?= e($ug['name']) ?><?php if (in_array((int)$ug['id'], $myGroupIds, true)): ?> <span class="own-grp-tag">（本组）</span><?php endif; ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
              <div style="height:10px"></div>
              <span class="perm-label">单独可见用户</span>
              <div class="picker-toolbar">
                <input type="text" class="picker-filter" data-picker-filter="pk-nv-create-u" placeholder="输入名称搜索用户…">
                <select class="picker-group" data-picker-group="pk-nv-create-u">
                  <option value="">全部组</option>
                  <?php foreach ($userGroups as $ug):
                      if (!in_array((int)$ug['id'], $visibleGroupAllowed, true)) continue; ?>
                    <option value="<?= (int)$ug['id'] ?>"><?= e($ug['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="picker-box" id="pk-nv-create-u">
                <?php foreach ($users as $u): ?>
                  <label class="chk" title="登录账号：<?= e($u['username']) ?>"
                         data-gids="<?= isset($userGroupMembership[(int)$u['id']]) ? implode(',', $userGroupMembership[(int)$u['id']]) : '' ?>"><input type="checkbox" name="user_ids[]" value="<?= (int)$u['id'] ?>">
                    <span><?= e($u['display_name'] ?: $u['username']) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
          <div class="modal-foot">
            <button type="button" class="btn-mini" data-modal-close>取消</button>
            <button type="submit" class="btn-primary">新增导航项</button>
          </div>
        </form>
      </div>
    </div>

    <!-- 批量添加弹窗 -->
    <div class="modal" id="modal-nv-batch" data-modal>
      <div class="modal-overlay" data-modal-close></div>
      <div class="modal-box">
        <div class="modal-head">
          <h3 class="modal-title">批量添加导航项</h3>
          <button type="button" class="modal-close" data-modal-close aria-label="关闭">✕</button>
        </div>
        <form method="post" action="/admin/navs.html">
          <div class="modal-body">
            <input type="hidden" name="action" value="batch_create">
            <div class="modal-section">
              <div class="modal-section-head">📚 所属分组与条目
                <span class="ms-hint">每行「标题 | URL | 图标(可选)」；空行/缺字段忽略、批内去重</span>
              </div>
              <div class="form-row">
                <select name="nav_group_id" required style="max-width:200px" data-modal-autofocus>
                  <option value="">选择所属分组…</option>
                  <?php foreach ($navGroups as $ng):
                      if (!in_array((int)$ng['id'], $scopeNavGroupIds, true)) continue; ?>
                    <option value="<?= (int)$ng['id'] ?>"><?= e($ng['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div style="height:8px"></div>
              <textarea name="items" rows="7" style="width:100%" required
                placeholder="每行一个，格式：标题 | URL | 图标(可选)，例如：&#10;百度 | https://www.baidu.com | 🔍&#10;GitHub | https://github.com"></textarea>
            </div>
            <div class="modal-section">
              <div class="modal-section-head">👀 谁可以看到 <span class="ms-hint">对本批所有项生效</span></div>
              <span class="perm-label">可见用户组</span>
              <input type="text" class="picker-filter" data-picker-filter="pk-nv-batch-g" placeholder="输入名称搜索用户组…">
              <div class="picker-box" id="pk-nv-batch-g">
                <?php foreach ($userGroups as $ug):
                    if (!$isSuperAdmin && !in_array((int)$ug['id'], $visibleGroupAllowed, true)) continue; ?>
                  <label class="chk"><input type="checkbox" name="group_ids[]" value="<?= (int)$ug['id'] ?>">
                    <span><?= e($ug['name']) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
              <div style="height:10px"></div>
              <span class="perm-label">单独可见用户</span>
              <div class="picker-toolbar">
                <input type="text" class="picker-filter" data-picker-filter="pk-nv-batch-u" placeholder="输入名称搜索用户…">
                <select class="picker-group" data-picker-group="pk-nv-batch-u">
                  <option value="">全部组</option>
                  <?php foreach ($userGroups as $ug):
                      if (!in_array((int)$ug['id'], $visibleGroupAllowed, true)) continue; ?>
                    <option value="<?= (int)$ug['id'] ?>"><?= e($ug['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="picker-box" id="pk-nv-batch-u">
                <?php foreach ($users as $u): ?>
                  <label class="chk" title="登录账号：<?= e($u['username']) ?>"
                         data-gids="<?= isset($userGroupMembership[(int)$u['id']]) ? implode(',', $userGroupMembership[(int)$u['id']]) : '' ?>"><input type="checkbox" name="user_ids[]" value="<?= (int)$u['id'] ?>">
                    <span><?= e($u['display_name'] ?: $u['username']) ?></span>
                  </label>
                <?php endforeach; ?>
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

    <!-- 分页栏：与用户管理页样式一致，翻页保留分组筛选与每页条数 -->
    <div class="pagination-bar">
      <div class="page-info">
        共 <strong><?= $total ?></strong> 个导航项<?= $filterGroup>0 ? '（当前筛选分组）' : '' ?>，
        第 <?= $page ?> / <?= $totalPages ?> 页
        （显示第 <?= $total === 0 ? 0 : ($offset + 1) ?>–<?= min($offset + $perPage, $total) ?> 条）
      </div>
      <div class="page-controls">
        <label class="inline-label">每页
          <select onchange="location.href=this.value" style="width:80px">
            <?php foreach ($PER_PAGE_OPTIONS as $opt): ?>
              <option value="<?= e(navs_page_url(['per_page' => $opt, 'p' => 1, 'saved' => null])) ?>"
                 <?= $opt===$perPage?'selected':'' ?>><?= $opt ?> 条</option>
            <?php endforeach; ?>
          </select>
        </label>

        <a class="btn-mini <?= $page<=1?'disabled':'' ?>" href="<?= e(navs_page_url(['p' => 1])) ?>">« 首页</a>
        <a class="btn-mini <?= $page<=1?'disabled':'' ?>" href="<?= e(navs_page_url(['p' => max(1,$page-1)])) ?>">‹ 上一页</a>

        <?php
        $startP = max(1, $page - 2);
        $endP = min($totalPages, $startP + 4);
        $startP = max(1, $endP - 4);
        for ($i = $startP; $i <= $endP; $i++): ?>
          <a class="page-num <?= $i===$page?'current':'' ?>" href="<?= e(navs_page_url(['p' => $i])) ?>"><?= $i ?></a>
        <?php endfor; ?>

        <a class="btn-mini <?= $page>=$totalPages?'disabled':'' ?>" href="<?= e(navs_page_url(['p' => min($totalPages,$page+1)])) ?>">下一页 ›</a>
        <a class="btn-mini <?= $page>=$totalPages?'disabled':'' ?>" href="<?= e(navs_page_url(['p' => $totalPages])) ?>">末页 »</a>
      </div>
    </div>
  </section>
</div>

<?php
// 提交出错时自动重开对应弹窗，保证错误信息与表单内容可见
if (!empty($error)) {
    $reopenId = null;
    $act = $_POST['action'] ?? '';
    if ($act === 'create') $reopenId = 'modal-nv-create';
    elseif ($act === 'batch_create') $reopenId = 'modal-nv-batch';
    elseif ($act === 'update') $reopenId = 'modal-nv-edit-' . (int)($_POST['id'] ?? 0);
    if ($reopenId !== null) {
        echo '<script>window.addEventListener("DOMContentLoaded",function(){openModal(' . json_encode($reopenId) . ');});</script>';
    }
}
require __DIR__ . '/../includes/footer.php';
