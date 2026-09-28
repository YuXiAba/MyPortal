<?php
/**
 * 后台：权限管理
 *
 * 两大功能：
 * ① 权限矩阵：以"权限 × 用户组"矩阵查看/编辑组级权限（仅系统管理员可改）。
 * ② 二级授权：把权限直接授予单个用户（用户级权限）。
 *    - 系统管理员：可授予/撤销任意权限；
 *    - 普通授权人（持有 can_delegate）：只能授予"本人持有"的权限，
 *      且目标必须是级别低于自己、在自己管理范围内的用户；只能撤销本人授予的权限。
 */
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require_admin();

// 进入条件：系统管理员，或被授予"允许二级授权"
if (!is_super() && !has_perm('can_delegate')) {
    header('Location: /index.html'); exit;
}

// 页签：matrix=权限矩阵（默认，超管常用）；delegate=用户级授权（非超管默认）
$tab = (string)($_GET['tab'] ?? (is_super() ? 'matrix' : 'delegate'));
if (!in_array($tab, ['matrix', 'delegate'], true)) $tab = 'matrix';

$msg = '';
$error = '';

/* ===================== POST 处理 ===================== */

// ① 保存组级权限矩阵（仅系统管理员）
// 逐组处理（含本次全部未提交、按空集处理的组），一律经过
// normalize_group_attributes() 重算，保证类型/级别/access_admin 不变量。
if (isset($_POST['action']) && $_POST['action'] === 'save_matrix') {
    if (!is_super()) {
        $error = '仅系统管理员可修改用户组权限。';
    } else {
        $allowed = array_keys($GLOBALS['PERMS']);
        $matrix  = $_POST['matrix'] ?? [];
        $upd = $pdo->prepare("UPDATE user_groups SET permissions=? WHERE id=?");
        foreach ($pdo->query("SELECT * FROM user_groups ORDER BY id") as $grow) {
            $gid = (int)$grow['id'];
            $gType = (int)$grow['is_super']===1 ? 'super'
                   : ((int)$grow['is_admin']===1 ? 'admin' : 'normal');
            // 矩阵只改权限：类型/级别/范围沿用现值；未提交=全部取消
            $postedKeys = array_values(array_intersect(
                $allowed,
                array_map('strval', (array)($matrix[$gid] ?? []))
            ));
            $scopeIds = resolve_ids((string)$grow['manageable_groups']);
            $attrs = normalize_group_attributes($gType, $postedKeys, $scopeIds,
                                                (int)$grow['level'], $grow);
            $upd->execute([$attrs['permissions'], $gid]);
        }
        write_log($pdo, 'save_matrix', '权限管理', '保存用户组权限矩阵');
        header('Location: /admin/permissions.html?saved=matrix&tab=matrix'); exit;
    }
}

// ② 保存用户级授权（二级授权）
if (isset($_POST['action']) && $_POST['action'] === 'save_grants' && isset($_POST['uid'])) {
    $targetUid = (int)$_POST['uid'];
    if (!can_delegate_to($pdo, $targetUid)) {
        $error = '无权向该用户授权：目标必须是级别低于您、且在您管理范围内的用户。';
    } else {
        $grantable = grantable_perms();                 // 本人可授予的权限
        $desired   = array_values(array_intersect(
            $grantable,
            array_map('strval', (array)($_POST['perms'] ?? []))
        ));

        // 目标现有的用户级授权（含授权人信息）
        $st = $pdo->prepare("SELECT perm_key, granted_by FROM user_permissions WHERE user_id=?");
        $st->execute([$targetUid]);
        $current = [];
        foreach ($st->fetchAll() as $r) {
            $current[$r['perm_key']] = (int)$r['granted_by'];
        }

        $me = (int)current_user()['id'];

        // 撤销：不在期望集合中、且本人有权撤销的。
        // 仅处理本人可授予范围内的权限——无权授予的权限在界面上不显示，
        // 保存时也不得受任何影响（包括本人过去授予、但现已不再持有的权限）。
        foreach ($current as $key => $grantedBy) {
            if (!in_array($key, $grantable, true)) continue;
            if (in_array($key, $desired, true)) continue;
            if (can_revoke_grant($pdo, $targetUid, $key)) {
                $pdo->prepare("DELETE FROM user_permissions WHERE user_id=? AND perm_key=?")
                    ->execute([$targetUid, $key]);
            }
        }
        // 新增：期望中但尚不存在、且本人可授予的
        $ins = $pdo->prepare("INSERT IGNORE INTO user_permissions (user_id, perm_key, granted_by) VALUES (?,?,?)");
        foreach ($desired as $key) {
            if (!array_key_exists($key, $current)) {
                $ins->execute([$targetUid, $key, $me]);
            }
        }
        write_log($pdo, 'save_grants', '权限管理', "保存用户级授权：目标用户 ID {$targetUid}");
        header('Location: /admin/permissions.html?saved=grants&tab=delegate&uid=' . $targetUid); exit;
    }
}

/* ===================== 数据准备 ===================== */

$allGroups = $pdo->query("SELECT * FROM user_groups ORDER BY level, id")->fetchAll();
$groupPermMap = []; // gid => [perm keys]
foreach ($allGroups as $g) {
    $groupPermMap[(int)$g['id']] = array_filter(array_map('trim', explode(',', (string)$g['permissions'])));
}

// 可被查看的目标用户：级别低于自己（普通授权人另需在管理范围内），
// 外加自管组中的同组其他成员（同级可见，但不可对其授权）
$myLevel = current_level();
$scope = manageable_group_ids($pdo);
$lvlSql = target_level_sql();
list($peerClause, $peerArgs) = self_managed_peer_clause((int)current_user()['id'], $myLevel);
$sql = "SELECT u.id, u.username, u.display_name,
               " . group_names_sql() . " AS group_name, $lvlSql AS level
        FROM users u
        WHERE ($lvlSql > ? OR $peerClause)";
$args = array_merge([$myLevel], $peerArgs);
if ($scope !== null) {
    if (empty($scope)) {
        $sql .= " AND 1=0";
    } else {
        $in = implode(',', array_map('intval', $scope));
        $sql .= " AND EXISTS (SELECT 1 FROM user_group_members m
                              WHERE m.user_id=u.id AND m.group_id IN ($in))";
    }
}
$sql .= " ORDER BY level, u.id";
$st = $pdo->prepare($sql);
$st->execute($args);
$targetUsers = $st->fetchAll();

// 当前选中的目标用户
$selUid = (int)($_GET['uid'] ?? 0);
$selUser = null;
foreach ($targetUsers as $tu) {
    if ((int)$tu['id'] === $selUid) { $selUser = $tu; break; }
}
if ($selUser === null && !empty($targetUsers)) {
    $selUser = $targetUsers[0];
    $selUid = (int)$selUser['id'];
}

// 选中用户的：组级权限 / 用户级授权明细
$selGroupPerms = [];
$selUserGrants = []; // perm_key => [granted_by name, created_at]
if ($selUser !== null) {
    // 组级权限 = 该用户全部所属组权限的并集（以关联表为准）
    foreach (user_group_ids($pdo, $selUid) as $mmGid) {
        if (isset($groupPermMap[$mmGid])) {
            $selGroupPerms = array_merge($selGroupPerms, $groupPermMap[$mmGid]);
        }
    }
    $selGroupPerms = array_values(array_unique($selGroupPerms));
    $grSt = $pdo->prepare("SELECT up.perm_key, up.created_at,
                                  gu.username AS granter_name, gu.display_name AS granter_display
                           FROM user_permissions up
                           LEFT JOIN users gu ON up.granted_by = gu.id
                           WHERE up.user_id=?");
    $grSt->execute([$selUid]);
    foreach ($grSt->fetchAll() as $r) {
        $selUserGrants[$r['perm_key']] = [
            'granter' => $r['granter_display'] ?: ($r['granter_name'] ?: '系统'),
            'at'      => $r['created_at'],
        ];
    }
}

$grantable = grantable_perms();
if (isset($_GET['saved']) && !isset($error)) {
    $msg = $_GET['saved'] === 'matrix' ? '权限矩阵已保存。' : '用户级授权已保存。';
}

$pageTitle = '权限管理';
require __DIR__ . '/../includes/header.php';
?>
<div class="admin-layout">
  <?php render_admin_sidebar('perms'); ?>

  <section class="admin-main">
    <h2>权限管理</h2>
    <p class="page-sub">按用户组分配权限矩阵，或将权限二级授权给单个用户。</p>
    <?php if ($msg): ?><p class="form-success">✅ <?= e($msg) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="form-error"><?= e($error) ?></p><?php endif; ?>

    <div class="perm-rules-card">
      <strong>📜 授权规则</strong>
      <ul>
        <li>系统管理员（级别 1）拥有<strong>全部权限</strong>，可编辑所有用户组的权限，并可对任意更低级别用户直接授权。</li>
        <li>权限按级别隔离：只能管理级别<strong>低于</strong>自己的用户；<strong>同级管理员禁止相互修改</strong>（自管组仅可同组互改、不可互相授权）。</li>
        <li>清单内<strong>每一项权限</strong>（含「后台访问权限」「允许二级授权」本身）均可在矩阵分配，也可二级授权给单个用户。</li>
        <li>「允许二级授权」本身是一项权限：获得该权限的用户可把<strong>自己持有的权限</strong>授予更低级别用户，但不能授予自己没有的权限，且只能撤销本人授予的权限；因此也可把「允许二级授权」再向下授予，形成多级授权链。</li>
        <li>用户最终权限 = 用户组权限 ＋ 用户级授权；系统每次请求自动同步，权限变更<strong>即时生效</strong>，无需重新登录。</li>
      </ul>
    </div>

    <!-- 页签切换（与用户管理页同款） -->
    <div class="um-tabs">
      <a class="um-tab <?= $tab==='matrix'?'active':'' ?>" href="/admin/permissions.html?tab=matrix">🔐 权限矩阵</a>
      <a class="um-tab <?= $tab==='delegate'?'active':'' ?>" href="/admin/permissions.html?tab=delegate">👤 用户级授权（二级授权）</a>
    </div>

    <?php if ($tab === 'matrix'): ?>
    <!-- ① 权限矩阵 -->
    <div class="perm-matrix-card">
      <div class="perm-card-head">
        <h3>权限矩阵（用户组权限）</h3>
        <?php if (!is_super()): ?><span class="grp-hint">只读：仅系统管理员可编辑</span><?php endif; ?>
      </div>

      <form method="post" action="/admin/permissions.html" id="matrixForm">
        <input type="hidden" name="action" value="save_matrix">
        <div class="perm-matrix-wrap">
        <table class="perm-matrix">
          <thead>
            <tr>
              <th class="pm-perm-col">权限</th>
              <?php foreach ($allGroups as $g): ?>
                <th>
                  <?= e($g['name']) ?>
                  <span class="pm-level">Lv<?= (int)$g['level'] ?></span>
                </th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach (perm_categories() as $cat):
                $pmColCount = count($allGroups) + 1;
            ?>
              <tr class="pm-cat-row">
                <td colspan="<?= $pmColCount ?>">
                  <span class="pm-cat-icon"><?= e($cat['icon']) ?></span>
                  <span class="pm-cat-label"><?= e($cat['label']) ?></span>
                </td>
              </tr>
              <?php foreach ($cat['perms'] as $pkey): $plabel = $GLOBALS['PERMS'][$pkey]; $iHoldIt = has_perm($pkey); ?>
                <tr class="<?= $iHoldIt ? 'pm-row-held' : '' ?>">
                  <td class="pm-perm-col">
                    <span class="pm-perm-name"><?= e($plabel) ?></span>
                    <span class="pm-perm-key"><?= e($pkey) ?></span>
                  </td>
                  <?php foreach ($allGroups as $g):
                      $gid = (int)$g['id'];
                      $isSG = (int)$g['is_super'] === 1;
                      $checked = $isSG || in_array($pkey, $groupPermMap[$gid], true);
                  ?>
                    <td class="pm-check-cell">
                      <?php if ($isSG): ?>
                        <span class="pm-all" title="系统管理员自动拥有全部权限">★</span>
                      <?php elseif (is_super()): ?>
                        <input type="checkbox" name="matrix[<?= $gid ?>][]" value="<?= e($pkey) ?>"
                               <?= $checked ? 'checked' : '' ?>>
                      <?php else: ?>
                        <?= $checked ? '✅' : '—' ?>
                      <?php endif; ?>
                    </td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <?php if (is_super()): ?>
          <div class="form-row" style="margin-top:12px">
            <button type="submit" class="btn-primary">💾 保存权限矩阵</button>
          </div>
        <?php endif; ?>
      </form>
    </div>
    <?php endif; ?>

    <?php if ($tab === 'delegate'): ?>
    <!-- ② 二级授权 -->
    <div class="perm-delegate-card">
      <div class="perm-card-head">
        <h3>用户级授权（二级授权）</h3>
      </div>

      <?php if (empty($targetUsers)): ?>
        <p class="empty-tip">当前没有可授权的更低级别用户。</p>
      <?php else: ?>
        <form method="get" class="search-inline" action="/admin/permissions.html">
          <input type="hidden" name="tab" value="delegate">
          <span class="grp-label">选择用户：</span>
          <select name="uid" onchange="this.form.submit()" style="max-width:340px">
            <?php foreach ($targetUsers as $tu):
                $canGrantThis = can_delegate_to($pdo, (int)$tu['id']);
            ?>
              <option value="<?= (int)$tu['id'] ?>" <?= (int)$tu['id']===$selUid?'selected':'' ?>>
                <?= e($tu['display_name'] ?: $tu['username']) ?>
                （<?= e($tu['username']) ?><?= $tu['group_name'] ? ' · ' . e($tu['group_name']) . ' Lv' . (int)$tu['level'] : ' · 无分组' ?>）
                <?= $canGrantThis ? '' : ' · 同级不可授权' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>

        <?php if ($selUser !== null && !can_delegate_to($pdo, $selUid)): ?>
          <p class="empty-tip">「<?= e($selUser['display_name'] ?: $selUser['username']) ?>」与您同级：
            二级授权只能面向级别<strong>低于</strong>自己的用户（自管组的同组互改不含权限授予）。</p>
        <?php elseif ($selUser !== null): ?>
          <form method="post" action="/admin/permissions.html">
            <input type="hidden" name="action" value="save_grants">
            <input type="hidden" name="uid" value="<?= $selUid ?>">

            <?php
            // 仅展示当前授权人"可授予"的权限（系统管理员=全部；普通授权人=本人持有的权限），
            // 无权授予的权限直接不显示；下方按权限分类逐组渲染。
            $visiblePerms = [];
            foreach ($grantable as $gk) {
                if (isset($GLOBALS['PERMS'][$gk])) $visiblePerms[$gk] = true;
            }
            ?>
            <?php if (empty($visiblePerms)): ?>
              <p class="empty-tip">您没有可授予他人的权限。</p>
            <?php else: ?>
              <?php foreach (perm_categories() as $catId => $cat):
                  // 该分类下当前授权人可授予的权限
                  $catPerms = array_values(array_intersect($cat['perms'], array_keys($visiblePerms)));
                  if (empty($catPerms)) continue;
              ?>
                <div class="delegate-cat">
                  <h4 class="delegate-cat-head">
                    <span><?= e($cat['icon']) ?></span> <?= e($cat['label']) ?>
                  </h4>
                  <input type="text" class="picker-filter" data-picker-filter="pk-del-<?= e($catId) ?>"
                         placeholder="搜索「<?= e($cat['label']) ?>」权限…">
                  <div class="picker-box" id="pk-del-<?= e($catId) ?>">
                    <?php foreach ($catPerms as $pkey):
                        $plabel    = $GLOBALS['PERMS'][$pkey];
                        $fromGroup = in_array($pkey, $selGroupPerms, true);
                        $granted   = isset($selUserGrants[$pkey]);
                        $gInfo     = $selUserGrants[$pkey] ?? null;
                    ?>
                      <label class="chk" title="<?= $fromGroup ? '已由用户组授予，不可在此修改'
                          : ($granted ? '用户级授权 · ' . e($gInfo['granter']) . ' · ' . e($gInfo['at'])
                          : '可授予') ?>">
                        <input type="checkbox" name="perms[]" value="<?= e($pkey) ?>"
                               <?= $fromGroup ? 'disabled checked' : '' ?>
                               <?= (!$fromGroup && $granted) ? 'checked' : '' ?>>
                        <span><?= e($plabel) ?>
                          <?php if ($fromGroup): ?><span class="mini-tag tag-group">组</span>
                          <?php elseif ($granted): ?><span class="mini-tag tag-user">👤</span>
                          <?php endif; ?>
                        </span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>

            <div class="form-row" style="margin-top:14px">
              <button type="submit" class="btn-primary">💾 保存对「<?= e($selUser['display_name'] ?: $selUser['username']) ?>」的授权</button>
              <span class="grp-hint">勾选=授予该用户级权限；取消勾选=撤销（仅能撤销您有权撤销的授权；组权限不可在此修改）。</span>
            </div>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </section>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
