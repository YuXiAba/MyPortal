<?php
/**
 * 后台：搜索记录（/admin/searchlogs.html）
 * ========================================
 * 权限：view_search_logs（搜索记录查看）——系统管理员默认拥有；
 *       可在权限矩阵授予用户组，也可经二级授权授予单个用户。
 * 展示：首页搜索框的每次检索（关键词、引擎、IP、时间、搜索人），
 *       含未登录访客记录；顶部附热门词统计。
 * 可见范围（服务端强制，与用户日志一致）：
 *   - 系统管理员：全部记录；
 *   - 普通授权人：本人记录 + 级别低于自己且在可管理组范围内用户的记录，
 *     未登录访客与已删除用户的记录不可见。
 * 删除（单条 / 批量）仅系统管理员可执行。
 */
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require_admin();

if (!is_super() && !has_perm('view_search_logs')) {
    header('Location: /index.html'); exit;
}

/* ---------- 删除：仅系统管理员 ---------- */
if (is_super() && isset($_GET['del']) && (int)$_GET['del'] > 0) {
    $delId = (int)$_GET['del'];
    $q = $pdo->prepare("SELECT keyword, username, created_at FROM search_logs WHERE id=?");
    $q->execute([$delId]);
    if ($row = $q->fetch()) {
        $pdo->prepare("DELETE FROM search_logs WHERE id=?")->execute([$delId]);
        write_log($pdo, 'delete', '搜索记录',
                  "删除搜索记录：ID {$delId}（{$row['keyword']} · {$row['created_at']}）");
    }
    header('Location: ' . sl_page_url()); exit;
}

if (is_super() && ($_POST['action'] ?? '') === 'delete_selected') {
    $rawIds = is_array($_POST['log_ids'] ?? null) ? $_POST['log_ids'] : [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $rawIds), fn($v) => $v > 0)));
    if ($ids) {
        $in = implode(',', $ids);
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM search_logs WHERE id IN ($in)")->fetchColumn();
        $pdo->exec("DELETE FROM search_logs WHERE id IN ($in)");
        write_log($pdo, 'delete', '搜索记录', "批量删除搜索记录：{$cnt} 条");
    }
    header('Location: ' . sl_page_url()); exit;
}

/* ---------- 筛选 ---------- */
$kw        = trim($_GET['q'] ?? '');
$fUser     = trim($_GET['u'] ?? '');
$fEngine   = (int)($_GET['engine'] ?? 0);
$engines   = $pdo->query("SELECT id, name FROM search_engines ORDER BY sort_order ASC, id ASC")->fetchAll();

$where = [];
$args  = [];
if ($kw !== '') {
    $where[] = 'sl.keyword LIKE ?';
    $args[] = "%$kw%";
}
if ($fUser !== '') {
    $where[] = '(sl.username LIKE ? OR sl.display_name LIKE ?)';
    $args[] = "%$fUser%"; $args[] = "%$fUser%";
}
if ($fEngine > 0) {
    $where[] = 'sl.engine_id = ?';
    $args[] = $fEngine;
}

/* ---------- 可见范围（非超管） ---------- */
$me = (int)current_user()['id'];
if (!is_super()) {
    $myLevel = current_level();
    $scope = manageable_group_ids($pdo);
    $scopeSql = "sl.user_id = ? OR EXISTS (
                    SELECT 1 FROM users tu
                    WHERE tu.id = sl.user_id
                      AND COALESCE((SELECT MIN(tg.level) FROM user_group_members tm
                                      JOIN user_groups tg ON tm.group_id=tg.id
                                     WHERE tm.user_id=tu.id), 99) > ?";
    $scopeArgs = [$me, $myLevel];
    if ($scope !== null) {
        if (empty($scope)) {
            $scopeSql .= " AND 1=0"; // 无管理范围：只能看本人
        } else {
            $in = implode(',', array_map('intval', $scope));
            $scopeSql .= " AND EXISTS (SELECT 1 FROM user_group_members tm
                                         WHERE tm.user_id=tu.id AND tm.group_id IN ($in))";
        }
    }
    $scopeSql .= ")";
    $where[] = "($scopeSql)";
    $args = array_merge($args, $scopeArgs);
}
$whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

/* ---------- 分页 ---------- */
$PER_PAGE_OPTIONS = [20, 50, 100, 200];
$perPage = (int)($_GET['per_page'] ?? 20);
if (!in_array($perPage, $PER_PAGE_OPTIONS, true)) $perPage = 20;

$countSt = $pdo->prepare("SELECT COUNT(*) FROM search_logs sl" . $whereSql);
$countSt->execute($args);
$total = (int)$countSt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$page = max(1, (int)($_GET['p'] ?? 1));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$listSt = $pdo->prepare("SELECT sl.* FROM search_logs sl" . $whereSql .
                        " ORDER BY sl.id DESC LIMIT $perPage OFFSET $offset");
$listSt->execute($args);
$logs = $listSt->fetchAll();

// 预取本页记录中仍存在的用户 ID（避免逐行查询的 N+1）
$pageUserIds = array_values(array_unique(array_filter(
    array_map(fn($l) => $l['user_id'] !== null ? (int)$l['user_id'] : 0, $logs))));
$existingUserIds = [];
if ($pageUserIds) {
    $inU = implode(',', $pageUserIds);
    $existingUserIds = array_map('intval',
        $pdo->query("SELECT id FROM users WHERE id IN ($inU)")->fetchAll(PDO::FETCH_COLUMN));
}

/* ---------- 热门词 Top 20（仅套用可见范围，不套关键词等筛选） ---------- */
$hotWhere = [];
$hotArgs  = [];
if (!is_super()) {
    // 复用上面的范围片段（sl 别名一致），重新构造便于独立传参
    $myLevel = current_level();
    $scope = manageable_group_ids($pdo);
    $hotSql0 = "sl.user_id = ? OR EXISTS (
                    SELECT 1 FROM users tu
                    WHERE tu.id = sl.user_id
                      AND COALESCE((SELECT MIN(tg.level) FROM user_group_members tm
                                      JOIN user_groups tg ON tm.group_id=tg.id
                                     WHERE tm.user_id=tu.id), 99) > ?";
    $hotArgs = [$me, $myLevel];
    if ($scope !== null) {
        if (empty($scope)) {
            $hotSql0 .= " AND 1=0";
        } else {
            $in = implode(',', array_map('intval', $scope));
            $hotSql0 .= " AND EXISTS (SELECT 1 FROM user_group_members tm
                                        WHERE tm.user_id=tu.id AND tm.group_id IN ($in))";
        }
    }
    // 闭合开头的 EXISTS 左括号（与主列表 $scopeSql 的处理保持一致，漏补会致 1064）
    $hotSql0 .= ")";
    $hotWhere[] = "($hotSql0)";
}
$hotWhereSql = empty($hotWhere) ? '' : ' WHERE ' . implode(' AND ', $hotWhere);
$hotSt = $pdo->prepare("SELECT keyword, COUNT(*) AS cnt
                          FROM search_logs sl $hotWhereSql
                         GROUP BY keyword
                         ORDER BY cnt DESC, MAX(id) DESC
                         LIMIT 20");
$hotSt->execute($hotArgs);
$hotWords = $hotSt->fetchAll();

/** 分页链接：保留筛选条件 */
function sl_page_url(array $override = []): string {
    $params = [
        'q'        => trim($_GET['q'] ?? ''),
        'u'        => trim($_GET['u'] ?? ''),
        'engine'   => (int)($_GET['engine'] ?? 0),
        'per_page' => (int)($_GET['per_page'] ?? 20),
        'p'        => (int)($_GET['p'] ?? 1),
    ];
    $params = array_merge($params, $override);
    return '/admin/searchlogs.html?' . http_build_query($params);
}

$pageTitle = '搜索记录';
require __DIR__ . '/../includes/header.php';
?>
<div class="admin-layout">
  <?php render_admin_sidebar('searchlogs'); ?>

  <section class="admin-main">
    <h2>搜索记录</h2>
    <p class="page-sub">查看用户在前台的搜索关键词、所用引擎与 IP。</p>
    <p class="admin-tip">
      记录首页搜索框的每次检索（含未登录访客）。
      <?php if (is_super()): ?>您是系统管理员，可查看<strong>全部搜索记录</strong>，并可删除单条或批量删除。
      <?php else: ?>您当前仅可查看<strong>本人及管理范围内用户</strong>的搜索记录。<?php endif; ?>
    </p>

    <!-- 筛选栏 -->
    <form method="get" class="search-inline log-filter" action="/admin/searchlogs.html">
      <input type="text" name="q" placeholder="按关键词搜索" value="<?= e($kw) ?>">
      <input type="text" name="u" placeholder="按用户名/显示名搜索" value="<?= e($fUser) ?>">
      <label class="inline-label">引擎
        <select name="engine">
          <option value="0">全部引擎</option>
          <?php foreach ($engines as $en): ?>
            <option value="<?= (int)$en['id'] ?>" <?= (int)$en['id']===$fEngine?'selected':'' ?>><?= e($en['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" class="btn-mini">筛选</button>
      <a href="/admin/searchlogs.html" class="btn-mini">重置</a>
    </form>

    <!-- 热门词 -->
    <?php if ($hotWords): ?>
      <div class="rule-card hot-words-card">
        <strong>🔥 热门搜索词（Top <?= count($hotWords) ?>）</strong>
        <div class="badge-row">
          <?php foreach ($hotWords as $hw): ?>
            <a class="pill perm-pill" href="<?= e(sl_page_url(['q' => (string)$hw['keyword'], 'p' => 1])) ?>"
               title="按该词筛选记录"><?= e($hw['keyword']) ?> × <?= (int)$hw['cnt'] ?></a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if (is_super()): ?>
    <form method="post" action="/admin/searchlogs.html"
          onsubmit="return confirm('确定删除选中的搜索记录？此操作不可恢复。')">
      <input type="hidden" name="action" value="delete_selected">
      <div class="log-batch-bar">
        <button type="submit" class="btn-mini danger">🗑️ 删除选中</button>
        <span class="grp-hint">勾选后批量删除；选中范围仅当前页，翻页不保留。</span>
      </div>
      <table class="data-table log-table">
        <thead><tr>
          <th class="cb-cell"><input type="checkbox" id="logSelectAll" title="全选/取消全选本页"></th>
          <th>时间</th><th>用户</th><th>关键词</th><th>引擎</th><th>IP</th><th>删除</th>
        </tr></thead>
        <tbody>
        <?php if (empty($logs)): ?>
          <tr><td colspan="7" class="empty-tip">没有符合条件的搜索记录。</td></tr>
        <?php endif; ?>
        <?php foreach ($logs as $l):
            $isMe = $l['user_id'] !== null && (int)$l['user_id'] === $me;
        ?>
          <tr>
            <td class="cb-cell"><input type="checkbox" name="log_ids[]" class="log-row-cb" value="<?= (int)$l['id'] ?>"></td>
            <td class="log-time"><?= e($l['created_at']) ?></td>
            <td>
              <?php if ($l['user_id'] === null): ?>
                <span class="non-user">未登录访客</span>
              <?php else:
                  $disp  = (string)$l['display_name'];
                  $uname = (string)$l['username'];
                  // 用户可能已被物理删除（存在性已批量预取）
                  if (!in_array((int)$l['user_id'], $existingUserIds, true)): ?>
                    <?= e($uname !== '' ? $uname : '（未知用户）') ?>
                    <span class="grp-hint">（已删除）</span>
                  <?php elseif ($disp !== '' && $disp !== $uname): ?>
                    <?= e($disp) ?><span class="grp-hint">（<?= e($uname) ?>）</span>
                    <?php if ($isMe): ?><span class="grp-hint">（我）</span><?php endif; ?>
                  <?php else: ?>
                    <?= e($uname !== '' ? $uname : '（未知用户）') ?>
                    <?php if ($isMe): ?><span class="grp-hint">（我）</span><?php endif; ?>
                  <?php endif; ?>
              <?php endif; ?>
            </td>
            <td class="kw-cell"><?= e($l['keyword']) ?></td>
            <td class="muted-cell"><?= e($l['engine_name'] !== '' ? $l['engine_name'] : '—') ?></td>
            <td class="mono ip-cell"><?= $l['ip'] !== null ? e($l['ip']) : '—' ?></td>
            <td class="ops">
              <a class="btn-mini danger" href="<?= e(sl_page_url(['del' => (int)$l['id']])) ?>"
                 onclick="return confirm('确定删除该条记录？')">删除</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </form>
    <?php else: ?>
    <table class="data-table log-table">
      <thead><tr>
        <th>时间</th><th>用户</th><th>关键词</th><th>引擎</th><th>IP</th>
      </tr></thead>
      <tbody>
      <?php if (empty($logs)): ?>
        <tr><td colspan="5" class="empty-tip">没有符合条件的搜索记录。</td></tr>
      <?php endif; ?>
      <?php foreach ($logs as $l):
          $isMe = $l['user_id'] !== null && (int)$l['user_id'] === $me;
      ?>
        <tr>
          <td class="log-time"><?= e($l['created_at']) ?></td>
          <td>
            <?php if ($l['user_id'] === null): ?>
              <span class="non-user">未登录访客</span>
            <?php else:
                $disp  = (string)$l['display_name'];
                $uname = (string)$l['username'];
                if ($disp !== '' && $disp !== $uname): ?>
                  <?= e($disp) ?><span class="grp-hint">（<?= e($uname) ?>）</span>
                  <?php if ($isMe): ?><span class="grp-hint">（我）</span><?php endif; ?>
                <?php else: ?>
                  <?= e($uname !== '' ? $uname : '（未知用户）') ?>
                  <?php if ($isMe): ?><span class="grp-hint">（我）</span><?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
          </td>
          <td class="kw-cell"><?= e($l['keyword']) ?></td>
          <td class="muted-cell"><?= e($l['engine_name'] !== '' ? $l['engine_name'] : '—') ?></td>
          <td class="mono ip-cell"><?= $l['ip'] !== null ? e($l['ip']) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <!-- 分页栏 -->
    <div class="pagination-bar">
      <div class="page-info">
        共 <strong><?= $total ?></strong> 条记录，
        第 <?= $page ?> / <?= $totalPages ?> 页
        （第 <?= $total===0 ? 0 : ($offset+1) ?>–<?= min($offset+$perPage,$total) ?> 条）
      </div>
      <div class="page-controls">
        <label class="inline-label">每页
          <select onchange="location.href=this.value" style="width:88px">
            <?php foreach ($PER_PAGE_OPTIONS as $opt): ?>
              <option value="<?= e(sl_page_url(['per_page'=>$opt,'p'=>1])) ?>"
                 <?= $opt===$perPage?'selected':'' ?>><?= $opt ?> 条</option>
            <?php endforeach; ?>
          </select>
        </label>
        <a class="btn-mini <?= $page<=1?'disabled':'' ?>" href="<?= e(sl_page_url(['p'=>1])) ?>">« 首页</a>
        <a class="btn-mini <?= $page<=1?'disabled':'' ?>" href="<?= e(sl_page_url(['p'=>max(1,$page-1)])) ?>">‹ 上一页</a>
        <?php
        $startP = max(1, $page - 2);
        $endP = min($totalPages, $startP + 4);
        $startP = max(1, $endP - 4);
        for ($i = $startP; $i <= $endP; $i++): ?>
          <a class="page-num <?= $i===$page?'current':'' ?>" href="<?= e(sl_page_url(['p'=>$i])) ?>"><?= $i ?></a>
        <?php endfor; ?>
        <a class="btn-mini <?= $page>=$totalPages?'disabled':'' ?>" href="<?= e(sl_page_url(['p'=>min($totalPages,$page+1)])) ?>">下一页 ›</a>
        <a class="btn-mini <?= $page>=$totalPages?'disabled':'' ?>" href="<?= e(sl_page_url(['p'=>$totalPages])) ?>">末页 »</a>
      </div>
    </div>
  </section>
</div>
<script>
(function () {
  var all = document.getElementById('logSelectAll');
  if (!all) return;
  all.addEventListener('change', function () {
    document.querySelectorAll('.log-row-cb').forEach(function (cb) { cb.checked = all.checked; });
  });
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
