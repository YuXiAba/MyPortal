<?php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/probe.php';
require_admin();

$navCount    = (int)$pdo->query("SELECT COUNT(*) FROM nav_items")->fetchColumn();
$groupCount  = (int)$pdo->query("SELECT COUNT(*) FROM user_groups")->fetchColumn();
$userCount   = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalClicks = (int)$pdo->query("SELECT COALESCE(SUM(click_count),0) FROM nav_items")->fetchColumn();

/* ---------- 各用户组人数（归属以关联表为准） ---------- */
$groupStats = $pdo->query("SELECT g.id, g.name, g.level, g.is_admin, g.is_super,
                                  (SELECT COUNT(DISTINCT m.user_id) FROM user_group_members m
                                    WHERE m.group_id=g.id) AS user_count
                           FROM user_groups g ORDER BY g.level, g.id")->fetchAll();
$ungroupedCount = (int)$pdo->query("SELECT COUNT(*) FROM users u
                                    WHERE NOT EXISTS (SELECT 1 FROM user_group_members m WHERE m.user_id=u.id)")
                           ->fetchColumn();

/* ---------- 各导航项点击次数（分页 + 筛选；manage_nav_clicks 权限） ---------- */
$canManageClicks = has_perm('manage_nav_clicks');

// 清零操作（单个：GET；批量：POST）——仅持有 manage_nav_clicks 者
if ($canManageClicks && isset($_GET['reset_click'])) {
    $rcId = (int)$_GET['reset_click'];
    $rcName = (string)$pdo->query("SELECT title FROM nav_items WHERE id=$rcId")->fetchColumn();
    if ($rcName !== '') {
        $pdo->prepare("UPDATE nav_items SET click_count=0 WHERE id=?")->execute([$rcId]);
        write_log($pdo, 'update', '导航点击', "清零导航项点击数：{$rcName}（ID：{$rcId}）");
    }
    header('Location: /admin/index.html?pane=clicks&resetdone=1');
    exit;
}
if ($canManageClicks && ($_POST['action'] ?? '') === 'reset_clicks') {
    $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
    $ids = array_values(array_unique(array_filter($ids)));
    if ($ids) {
        $in = implode(',', $ids);
        $cnt = (int)$pdo->exec("UPDATE nav_items SET click_count=0 WHERE id IN ($in)");
        write_log($pdo, 'update', '导航点击', "批量清零导航项点击数：{$cnt} 项（ID：$in）");
    }
    header('Location: /admin/index.html?pane=clicks&resetdone=1');
    exit;
}

// 筛选条件
$clickKw  = trim($_GET['click_q'] ?? '');
$clickGid = (int)($_GET['click_gid'] ?? 0);
$clickWhere = [];
$clickArgs  = [];
if ($clickKw !== '') {
    $clickWhere[] = "(ni.title LIKE ? OR ni.url LIKE ?)";
    $clickArgs[] = "%$clickKw%";
    $clickArgs[] = "%$clickKw%";
}
if ($clickGid > 0) {
    $clickWhere[] = "ni.group_id=?";
    $clickArgs[] = $clickGid;
}
$clickWhereSql = $clickWhere ? ' WHERE ' . implode(' AND ', $clickWhere) : '';

$clickCountSt = $pdo->prepare("SELECT COUNT(*) FROM nav_items ni" . $clickWhereSql);
$clickCountSt->execute($clickArgs);
$clickTotal = (int)$clickCountSt->fetchColumn();

$CLICK_PER_PAGE_OPTIONS = [10, 20, 50, 100];
$clickPerPage = (int)($_GET['click_pp'] ?? 20);
if (!in_array($clickPerPage, $CLICK_PER_PAGE_OPTIONS, true)) $clickPerPage = 20;
$clickTotalPages = max(1, (int)ceil($clickTotal / $clickPerPage));
$clickPage = (int)($_GET['click_p'] ?? 1);
if ($clickPage < 1) $clickPage = 1;
if ($clickPage > $clickTotalPages) $clickPage = $clickTotalPages;
$clickOffset = ($clickPage - 1) * $clickPerPage;

$clickSt = $pdo->prepare("SELECT ni.id, ni.title, ni.url, ni.icon, ni.click_count, ni.status,
                                 ng.id AS gid, ng.name AS gname
                          FROM nav_items ni LEFT JOIN nav_groups ng ON ni.group_id = ng.id
                          $clickWhereSql
                          ORDER BY ng.sort_order, ng.id, ni.click_count DESC, ni.sort_order
                          LIMIT $clickPerPage OFFSET $clickOffset");
$clickSt->execute($clickArgs);
$clickRows = $clickSt->fetchAll();

/** 导航点击分页链接（保留筛选，pane=clicks 用于自动展开页签） */
function clicks_page_url(array $override = []): string {
    $params = array_filter([
        'pane'      => 'clicks',
        'click_q'   => trim($_GET['click_q'] ?? ''),
        'click_gid' => (int)($_GET['click_gid'] ?? 0),
        'click_pp'  => (int)($_GET['click_pp'] ?? 20),
        'click_p'   => (int)($_GET['click_p'] ?? 1),
    ], fn($v, $k) => !(in_array($k, ['click_q'], true) && $v === '')
                   && !(in_array($k, ['click_gid'], true) && (int)$v === 0), ARRAY_FILTER_USE_BOTH);
    $params = array_merge($params, $override);
    return '/admin/index.html?' . http_build_query($params);
}

// 页面加载时默认展开的页签（用于分页链接回来保持在点击页）
$initialPane = (string)($_GET['pane'] ?? 'overview');
if (!in_array($initialPane, ['overview','groups','logins','clicks'], true)) $initialPane = 'overview';
if ($initialPane === 'clicks' && !$canManageClicks) $initialPane = 'overview';

/* ---------- 用户最近登录（IP / 时间） ----------
 * 普通管理员只能看到级别更低、且在其管理范围内的用户；
 * 超级管理员可查看全部。多组口径：级别取所属组最高，范围走关联表。
 */
$myLevel = current_level();
$scope = manageable_group_ids($pdo);

$lvlSql = target_level_sql();
// 级别更低的用户，OR 自管组中的同组其他成员（同级例外）
list($peerClause, $peerArgs) = self_managed_peer_clause((int)current_user()['id'], $myLevel);
$loginSql = "SELECT u.id, u.username, u.display_name, u.last_login_ip, u.last_login_at,
                    " . group_names_sql() . " AS group_name, $lvlSql AS group_level
             FROM users u
             WHERE ($lvlSql > ? OR $peerClause)";
$loginArgs = array_merge([$myLevel], $peerArgs);
if ($scope !== null) {
    if (empty($scope)) {
        $loginSql .= " AND 1=0";
    } else {
        $in = implode(',', array_map('intval', $scope));
        $loginSql .= " AND EXISTS (SELECT 1 FROM user_group_members m
                                    WHERE m.user_id=u.id AND m.group_id IN ($in))";
    }
}
$loginSql .= " ORDER BY (u.last_login_at IS NULL), u.last_login_at DESC, u.id";
$loginSt = $pdo->prepare($loginSql);
$loginSt->execute($loginArgs);
$loginUsers = $loginSt->fetchAll();

// 本站探针
$probe = site_probe($pdo);

$pageTitle = '后台管理';
require __DIR__ . '/../includes/header.php';
?>
<div class="admin-layout">
  <?php render_admin_sidebar('dashboard'); ?>

  <section class="admin-main">
    <h2>概览</h2>
    <p class="page-sub">门户运行数据、用户组人数、登录与导航点击统计。</p>

    <!-- 页签：样式与用户管理页一致，点击切换下方对应面板 -->
    <div class="um-tabs" id="dashTabs">
      <button type="button" class="um-tab active" data-pane="overview">📊 数据总览</button>
      <button type="button" class="um-tab" data-pane="groups">👥 用户组人数（<?= $groupCount ?>）</button>
      <button type="button" class="um-tab" data-pane="logins">🕐 用户最近登录（<?= count($loginUsers) ?>）</button>
      <?php if ($canManageClicks): ?>
      <button type="button" class="um-tab" data-pane="clicks">🧭 导航点击（<?= $clickTotal ?>）</button>
      <?php endif; ?>
    </div>

    <!-- ① 数据总览 -->
    <div class="dash-pane active" data-pane="overview">
      <div class="stat-grid">
        <div class="stat-card"><span class="stat-num"><?= $navCount ?></span><span class="stat-label">导航项</span></div>
        <div class="stat-card"><span class="stat-num"><?= $totalClicks ?></span><span class="stat-label">导航总点击</span></div>
        <div class="stat-card"><span class="stat-num"><?= $groupCount ?></span><span class="stat-label">用户组</span></div>
        <div class="stat-card"><span class="stat-num"><?= $userCount ?></span><span class="stat-label">用户</span></div>
      </div>

      <!-- 本站探针 -->
      <details class="probe-card" open>
        <summary>🩺 本站探针（服务器运行环境）</summary>
        <div class="probe-grid">
          <?php foreach ($probe as $group => $items): ?>
            <div class="probe-group">
              <h4><?= e($group) ?></h4>
              <?php foreach ($items as $label => $value):
                $vs = (string)$value; ?>
                <div class="probe-line"<?= $vs !== '' ? ' title="' . e($vs) . '"' : '' ?>>
                  <span class="probe-k"><?= e($label) ?></span>
                  <span class="probe-v"><?= e($vs) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </details>
    </div>

    <!-- ② 各用户组人数 -->
    <div class="dash-pane" data-pane="groups">
      <table class="data-table">
        <thead><tr><th>用户组</th><th>级别</th><th>人数</th></tr></thead>
        <tbody>
          <?php foreach ($groupStats as $gs):
            $typeIcon = (int)$gs['is_super']===1 ? '⭐' : ((int)$gs['is_admin']===1 ? '🛡️' : '👥');
          ?>
            <tr>
              <td><?= $typeIcon ?> <?= e($gs['name']) ?></td>
              <td><span class="level-badge"><?= (int)$gs['level'] ?></span></td>
              <td><strong><?= (int)$gs['user_count'] ?></strong></td>
            </tr>
          <?php endforeach; ?>
          <tr>
            <td><span class="grp-hint">无分组</span></td>
            <td>—</td>
            <td><?= $ungroupedCount ?></td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- ③ 用户最近登录 -->
    <div class="dash-pane" data-pane="logins">
      <?php if (empty($loginUsers)): ?>
        <p class="empty-tip">当前没有可查看登录记录的用户。</p>
      <?php else: ?>
      <table class="data-table">
        <thead><tr><th>用户名</th><th>显示名</th><th>用户组</th><th>最近登录 IP</th><th>最近登录时间</th></tr></thead>
        <tbody>
          <?php foreach ($loginUsers as $lu): ?>
            <tr>
              <td><?= e($lu['username']) ?></td>
              <td><?= e($lu['display_name']) ?></td>
              <td><?= e($lu['group_name'] ?: '无分组') ?></td>
              <td class="mono ip-cell"><?= $lu['last_login_ip'] !== null ? e($lu['last_login_ip']) : '<span class="grp-hint">从未登录</span>' ?></td>
              <td class="nowrap"><?= $lu['last_login_at'] !== null ? e($lu['last_login_at']) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <!-- ④ 导航点击次数（分页/筛选/清零：manage_nav_clicks 权限） -->
    <?php if ($canManageClicks): ?>
    <div class="dash-pane" data-pane="clicks">
      <?php if (isset($_GET['resetdone'])): ?><p class="form-success">已清零。</p><?php endif; ?>

      <!-- 筛选工具栏 -->
      <form method="get" class="search-inline" action="/admin/index.html">
        <input type="hidden" name="pane" value="clicks">
        <input type="text" name="click_q" value="<?= e($clickKw) ?>" placeholder="搜索导航项标题 / URL">
        <select name="click_gid">
          <option value="0">全部分组</option>
          <?php foreach ($pdo->query("SELECT id,name FROM nav_groups ORDER BY sort_order,id") as $ng): ?>
            <option value="<?= (int)$ng['id'] ?>" <?= $clickGid===(int)$ng['id']?'selected':'' ?>><?= e($ng['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="click_pp" title="每页显示条数">
          <?php foreach ($CLICK_PER_PAGE_OPTIONS as $opt): ?>
            <option value="<?= $opt ?>" <?= $clickPerPage===$opt?'selected':'' ?>>每页 <?= $opt ?> 条</option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-primary">筛选</button>
      </form>

      <?php if (empty($clickRows)): ?>
        <p class="empty-tip">没有符合条件的导航项。</p>
      <?php else: ?>
      <form method="post" action="/admin/index.html?pane=clicks"
            onsubmit="return confirm('确定将所选导航项的点击数清零？');">
        <input type="hidden" name="action" value="reset_clicks">
        <table class="data-table">
          <thead><tr>
            <th class="pm-check-cell"><input type="checkbox" onclick="toggleAll(this)"></th>
            <th>所属分组</th><th>导航项</th><th>状态</th><th>点击次数</th><th>操作</th>
          </tr></thead>
          <tbody>
            <?php foreach ($clickRows as $cr): ?>
              <tr>
                <td class="pm-check-cell"><input type="checkbox" name="ids[]" value="<?= (int)$cr['id'] ?>"></td>
                <td><?= e($cr['gname'] ?: '未分组') ?></td>
                <td>
                  <?= icon_html((string)$cr['icon'] ?? '', 'nav-ic') ?>
                  <?= e($cr['title']) ?>
                </td>
                <td class="nowrap"><?= (int)$cr['status']===1 ? '<span class="ok">启用</span>' : '<span class="bad">停用</span>' ?></td>
                <td><strong class="click-num"><?= (int)$cr['click_count'] ?></strong></td>
                <td>
                  <a class="btn-mini danger"
                     href="<?= e(clicks_page_url(['reset_click' => (int)$cr['id']])) ?>"
                     onclick="return confirm('确定清零「 <?= e($cr['title']) ?> 」的点击数？');">清零</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div class="click-bottom-bar">
          <button type="submit" class="btn-primary">批量清零所选</button>
        </div>
      </form>

      <!-- 分页（全站统一样式） -->
      <div class="pagination-bar">
        <div class="page-info">
          共 <strong><?= $clickTotal ?></strong> 项，
          第 <?= $clickPage ?> / <?= $clickTotalPages ?> 页
        </div>
        <div class="page-controls">
          <a class="btn-mini <?= $clickPage<=1?'disabled':'' ?>"
             href="<?= e(clicks_page_url(['click_p'=>1])) ?>">« 首页</a>
          <a class="btn-mini <?= $clickPage<=1?'disabled':'' ?>"
             href="<?= e(clicks_page_url(['click_p'=>max(1,$clickPage-1)])) ?>">‹ 上一页</a>
          <?php
          $startP = max(1, $clickPage - 2);
          $endP = min($clickTotalPages, $startP + 4);
          $startP = max(1, $endP - 4);
          for ($pi = $startP; $pi <= $endP; $pi++): ?>
            <a class="page-num <?= $pi===$clickPage?'current':'' ?>"
               href="<?= e(clicks_page_url(['click_p' => $pi])) ?>"><?= $pi ?></a>
          <?php endfor; ?>
          <a class="btn-mini <?= $clickPage>=$clickTotalPages?'disabled':'' ?>"
             href="<?= e(clicks_page_url(['click_p'=>min($clickTotalPages,$clickPage+1)])) ?>">下一页 ›</a>
          <a class="btn-mini <?= $clickPage>=$clickTotalPages?'disabled':'' ?>"
             href="<?= e(clicks_page_url(['click_p'=>$clickTotalPages])) ?>">末页 »</a>
        </div>
      </div>

      <p class="grp-hint">仅统计通过门户「/jump」入口产生的点击；用户个人自定义导航的直链不计入。</p>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <p class="admin-tip">当前登录：<?= e(current_user()['display_name'] ?: current_user()['username']) ?>（<?= e(current_user()['group_name'] ?: '无分组') ?>）</p>
  </section>
</div>

<script>
(function () {
  var tabs = document.getElementById('dashTabs');
  // 初始页签：分页链接回来时保持在对应面板
  var initial = <?= json_encode($initialPane) ?>;
  function activate(key) {
    tabs.querySelectorAll('.um-tab').forEach(function (t) {
      t.classList.toggle('active', t.getAttribute('data-pane') === key);
    });
    document.querySelectorAll('.dash-pane').forEach(function (p) {
      p.classList.toggle('active', p.getAttribute('data-pane') === key);
    });
  }
  if (initial !== 'overview') activate(initial);
  tabs.addEventListener('click', function (ev) {
    var btn = ev.target.closest('.um-tab');
    if (!btn) return;
    activate(btn.getAttribute('data-pane'));
  });
})();
/* 全选 / 取消全选（批量清零） */
function toggleAll(master) {
  document.querySelectorAll('input[name="ids[]"]').forEach(function (cb) { cb.checked = master.checked; });
}
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
