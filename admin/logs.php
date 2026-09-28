<?php
/**
 * 后台：用户操作日志
 * =================
 * 权限：view_logs（查看用户日志）——系统管理员默认拥有；
 *       普通管理员/用户需经系统管理员在权限矩阵或二级授权中授予。
 * 展示：用户在门户与后台的操作（登录/退出、增删改、重置密码、导入、设置保存等），
 *       含操作人、模块、详情、IP 与成功/失败状态。
 * 可见范围（服务端强制）：
 *   - 系统管理员：全部日志；
 *   - 普通授权人：本人的日志 + 级别低于自己且在其可管理组范围内用户的日志；
 *     已删除用户（user_id 为空）的日志对其不可见。
 */
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require_admin();

if (!is_super() && !has_perm('view_logs')) {
    header('Location: /index.html'); exit;
}

/* ============================================================
   日志删除（高危操作）：仅系统管理员可执行
   - 单条删除：GET /admin/logs.html?del=<id>
   - 批量删除：POST action=delete_selected，log_ids[] 为选中 ID
   删除后写一条审计日志（该条不会随本次删除被清掉），
   并跳回当前筛选/分页视图。
   ============================================================ */
if (is_super() && isset($_GET['del']) && (int)$_GET['del'] > 0) {
    $delId = (int)$_GET['del'];
    $q = $pdo->prepare("SELECT username, display_name, action, created_at FROM user_logs WHERE id=?");
    $q->execute([$delId]);
    $delRow = $q->fetch();
    if ($delRow) {
        $pdo->prepare("DELETE FROM user_logs WHERE id=?")->execute([$delId]);
        $delWho = (string)$delRow['display_name'] !== '' ? $delRow['display_name']
                : ((string)$delRow['username'] !== '' ? $delRow['username'] : '未知用户');
        write_log($pdo, 'delete', '用户日志',
                  "删除日志：ID {$delId}（{$delWho} · {$delRow['action']} · {$delRow['created_at']}）");
    }
    header('Location: ' . logs_page_url()); exit;
}

if (is_super() && ($_POST['action'] ?? '') === 'delete_selected') {
    $rawIds = is_array($_POST['log_ids'] ?? null) ? $_POST['log_ids'] : [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $rawIds), fn($v) => $v > 0)));
    if ($ids) {
        $in  = implode(',', $ids);
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM user_logs WHERE id IN ($in)")->fetchColumn();
        $pdo->exec("DELETE FROM user_logs WHERE id IN ($in)");
        write_log($pdo, 'delete', '用户日志',
                  "批量删除日志：{$cnt} 条（ID：" . implode(',', $ids) . '）');
    }
    header('Location: ' . logs_page_url()); exit;
}

// 操作类型 → 中文名（筛选下拉与列表徽标共用；未列入者直接显示英文，无此情况）
$ACTION_LABELS = [
    // 登录与账号
    'login'             => '登录',
    'logout'            => '退出登录',
    'change_password'   => '修改密码',
    'reset_password'    => '重置密码',
    'revoke_device'     => '强制下线设备',
    'revoke_own_device' => '下线本人设备',
    'toggle_entry'      => '设置入口开关',
    // 内容与导航
    'create'            => '新增',
    'update'            => '修改',
    'delete'            => '删除',
    'import'            => '批量导入',
    'mynav_create'      => '新增个人导航',
    'mynav_update'      => '修改个人导航',
    'mynav_delete'      => '删除个人导航',
    'theme_switch'      => '切换皮肤',
    // 参数与权限
    'save_settings'     => '保存网站参数',
    'save_about'        => '保存关于我们',
    'save_matrix'       => '保存权限矩阵',
    'save_grants'       => '保存用户授权',
];

// 筛选下拉的分组顺序（全部 key 均须在 $ACTION_LABELS 中）
$ACTION_GROUPS = [
    '登录与账号' => ['login', 'logout', 'change_password', 'reset_password',
                    'revoke_device', 'revoke_own_device', 'toggle_entry'],
    '内容与导航' => ['create', 'update', 'delete', 'import',
                    'mynav_create', 'mynav_update', 'mynav_delete', 'theme_switch'],
    '参数与权限' => ['save_settings', 'save_about', 'save_matrix', 'save_grants'],
];

// ---- 筛选条件 ----
$fAction = $_GET['action'] ?? '';
$fStatus = $_GET['status'] ?? '';
$kw      = trim($_GET['q'] ?? '');
if (!isset($ACTION_LABELS[$fAction])) $fAction = '';
if (!in_array($fStatus, ['success', 'fail'], true)) $fStatus = '';

$where = [];
$args  = [];
if ($fAction !== '') { $where[] = 'l.action = ?'; $args[] = $fAction; }
if ($fStatus !== '') { $where[] = 'l.status = ?'; $args[] = $fStatus; }
if ($kw !== '') {
    $where[] = '(l.username LIKE ? OR l.display_name LIKE ? OR l.detail LIKE ?)';
    $args[] = "%$kw%"; $args[] = "%$kw%"; $args[] = "%$kw%";
}

// 可见范围
$me = (int)current_user()['id'];
if (!is_super()) {
    $myLevel = current_level();
    $scope = manageable_group_ids($pdo);
    $scopeSql = "l.user_id = ? OR EXISTS (
                    SELECT 1 FROM users tu
                    WHERE tu.id = l.user_id
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
    // 范围条件是最后拼入 WHERE 的，参数须追加在末尾，保持占位符顺序一致
    $args = array_merge($args, $scopeArgs);
}
$whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

// ---- 分页 ----
$PER_PAGE_OPTIONS = [20, 50, 100, 200];
$perPage = (int)($_GET['per_page'] ?? 20);
if (!in_array($perPage, $PER_PAGE_OPTIONS, true)) $perPage = 20;

$countSt = $pdo->prepare("SELECT COUNT(*) FROM user_logs l" . $whereSql);
$countSt->execute($args);
$total = (int)$countSt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$page = max(1, (int)($_GET['p'] ?? 1));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$listSt = $pdo->prepare("SELECT l.* FROM user_logs l" . $whereSql .
                        " ORDER BY l.id DESC LIMIT $perPage OFFSET $offset");
$listSt->execute($args);
$logs = $listSt->fetchAll();

/** 分页链接：保留全部筛选条件 */
function logs_page_url(array $override = []): string {
    $params = [
        'action'   => (string)($_GET['action'] ?? ''),
        'status'   => (string)($_GET['status'] ?? ''),
        'q'        => trim($_GET['q'] ?? ''),
        'per_page' => (int)($_GET['per_page'] ?? 20),
        'p'        => (int)($_GET['p'] ?? 1),
    ];
    $params = array_merge($params, $override);
    return '/admin/logs.html?' . http_build_query($params);
}

$pageTitle = '用户日志';
require __DIR__ . '/../includes/header.php';
?>
<div class="admin-layout">
  <?php render_admin_sidebar('logs'); ?>

  <section class="admin-main">
    <h2>用户操作日志</h2>
    <p class="page-sub">查看全站登录、退出与后台操作记录。</p>
    <?php if (isset($_GET['saved'])): ?><p class="form-success">已保存。</p><?php endif; ?>
    <p class="admin-tip">
      记录全站登录/退出与后台操作，包含操作 IP 与成功/失败状态。
      <?php if (is_super()): ?>您是系统管理员，可查看<strong>全部用户</strong>的日志，并可<strong>删除单条或批量删除</strong>日志（删除操作同样留痕）。
      <?php else: ?>您当前仅可查看<strong>本人及管理范围内用户</strong>的日志；日志删除仅限系统管理员。<?php endif; ?>
    </p>

    <!-- 筛选栏 -->
    <form method="get" class="search-inline log-filter" action="/admin/logs.html">
      <input type="text" name="q" placeholder="按显示名/用户名或详情关键词搜索" value="<?= e($kw) ?>">
      <label class="inline-label">操作
        <select name="action">
          <option value="">全部操作</option>
          <?php foreach ($ACTION_GROUPS as $gLabel => $gKeys): ?>
          <optgroup label="<?= e($gLabel) ?>">
            <?php foreach ($gKeys as $key): ?>
            <option value="<?= e($key) ?>" <?= $fAction===$key?'selected':'' ?>><?= e($ACTION_LABELS[$key]) ?></option>
            <?php endforeach; ?>
          </optgroup>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="inline-label">状态
        <select name="status">
          <option value="">全部状态</option>
          <option value="success" <?= $fStatus==='success'?'selected':'' ?>>成功</option>
          <option value="fail" <?= $fStatus==='fail'?'selected':'' ?>>失败</option>
        </select>
      </label>
      <button type="submit" class="btn-mini">筛选</button>
      <a href="/admin/logs.html" class="btn-mini">重置</a>
    </form>

    <?php if (is_super()): ?>
    <!-- 系统管理员：批量删除表单（勾选列 + 行内删除） -->
    <form method="post" action="/admin/logs.html" id="logBatchForm"
          onsubmit="return confirm('确定删除选中的日志？此操作不可恢复。')">
      <input type="hidden" name="action" value="delete_selected">
      <div class="log-batch-bar">
        <button type="submit" class="btn-mini danger">🗑️ 删除选中</button>
        <span class="grp-hint">勾选后批量删除；选中范围仅当前页，翻页不保留。</span>
      </div>
      <table class="data-table log-table">
        <thead><tr>
          <th class="cb-cell"><input type="checkbox" id="logSelectAll" title="全选/取消全选本页"></th>
          <th>时间</th><th>用户</th><th>模块</th><th>操作</th><th>详情</th><th>IP</th><th>状态</th><th>删除</th>
        </tr></thead>
        <tbody>
        <?php if (empty($logs)): ?>
          <tr><td colspan="9" class="empty-tip">没有符合条件的日志记录。</td></tr>
        <?php endif; ?>
        <?php foreach ($logs as $l):
          $isMe = (int)$l['user_id'] === $me;
          $actLabel = $ACTION_LABELS[$l['action']] ?? $l['action'];
          $delUrl = logs_page_url(['del' => (int)$l['id']]);
        ?>
          <tr>
            <td class="cb-cell"><input type="checkbox" name="log_ids[]" class="log-row-cb" value="<?= (int)$l['id'] ?>"></td>
            <td class="log-time"><?= e($l['created_at']) ?></td>
            <td>
              <?php
                $disp  = (string)$l['display_name'];
                $uname = (string)$l['username'];
                if ($disp === '非注册用户'):
              ?>
                <span class="non-user">非注册用户</span>
              <?php elseif ($disp !== '' && $disp !== $uname): ?>
                <?= e($disp) ?>
                <span class="grp-hint">（<?= e($uname !== '' ? $uname : '—') ?>）</span>
                <?php if ($isMe): ?><span class="grp-hint">（我）</span><?php endif; ?>
              <?php else: ?>
                <?= e($uname !== '' ? $uname : '（未知用户）') ?>
                <?php if ($isMe): ?><span class="grp-hint">（我）</span><?php endif; ?>
              <?php endif; ?>
            </td>
            <td class="muted-cell"><?= e($l['module']) ?></td>
            <td><span class="log-act log-act-<?= e($l['action']) ?>"><?= e($actLabel) ?></span></td>
            <td class="log-detail"><?= e($l['detail']) ?></td>
            <td class="mono ip-cell"><?= $l['ip'] !== null ? e($l['ip']) : '—' ?></td>
            <td><?= $l['status']==='success'
                  ? '<span class="log-status ok">● 成功</span>'
                  : '<span class="log-status bad">● 失败</span>' ?></td>
            <td class="ops">
              <a class="btn-mini danger" href="<?= e($delUrl) ?>"
                 onclick="return confirm('确定删除该条日志？此操作不可恢复。')">删除</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </form>
    <?php else: ?>
    <table class="data-table log-table">
      <thead><tr>
        <th>时间</th><th>用户</th><th>模块</th><th>操作</th><th>详情</th><th>IP</th><th>状态</th>
      </tr></thead>
      <tbody>
      <?php if (empty($logs)): ?>
        <tr><td colspan="7" class="empty-tip">没有符合条件的日志记录。</td></tr>
      <?php endif; ?>
      <?php foreach ($logs as $l):
        $isMe = (int)$l['user_id'] === $me;
        $actLabel = $ACTION_LABELS[$l['action']] ?? $l['action'];
      ?>
        <tr>
          <td class="log-time"><?= e($l['created_at']) ?></td>
          <td>
            <?php
              $disp  = (string)$l['display_name'];
              $uname = (string)$l['username'];
              if ($disp === '非注册用户'):
            ?>
              <span class="non-user">非注册用户</span>
            <?php elseif ($disp !== '' && $disp !== $uname): ?>
              <?= e($disp) ?>
              <span class="grp-hint">（<?= e($uname !== '' ? $uname : '—') ?>）</span>
              <?php if ($isMe): ?><span class="grp-hint">（我）</span><?php endif; ?>
            <?php else: ?>
              <?= e($uname !== '' ? $uname : '（未知用户）') ?>
              <?php if ($isMe): ?><span class="grp-hint">（我）</span><?php endif; ?>
            <?php endif; ?>
          </td>
          <td class="muted-cell"><?= e($l['module']) ?></td>
          <td><span class="log-act log-act-<?= e($l['action']) ?>"><?= e($actLabel) ?></span></td>
          <td class="log-detail"><?= e($l['detail']) ?></td>
          <td class="mono ip-cell"><?= $l['ip'] !== null ? e($l['ip']) : '—' ?></td>
          <td><?= $l['status']==='success'
                ? '<span class="log-status ok">● 成功</span>'
                : '<span class="log-status bad">● 失败</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <!-- 分页栏 -->
    <div class="pagination-bar">
      <div class="page-info">
        共 <strong><?= $total ?></strong> 条日志，
        第 <?= $page ?> / <?= $totalPages ?> 页
        （第 <?= $total===0 ? 0 : ($offset+1) ?>–<?= min($offset+$perPage,$total) ?> 条）
      </div>
      <div class="page-controls">
        <label class="inline-label">每页
          <select onchange="location.href=this.value" style="width:88px">
            <?php foreach ($PER_PAGE_OPTIONS as $opt): ?>
              <option value="<?= e(logs_page_url(['per_page'=>$opt,'p'=>1])) ?>"
                 <?= $opt===$perPage?'selected':'' ?>><?= $opt ?> 条</option>
            <?php endforeach; ?>
          </select>
        </label>
        <a class="btn-mini <?= $page<=1?'disabled':'' ?>" href="<?= e(logs_page_url(['p'=>1])) ?>">« 首页</a>
        <a class="btn-mini <?= $page<=1?'disabled':'' ?>" href="<?= e(logs_page_url(['p'=>max(1,$page-1)])) ?>">‹ 上一页</a>
        <?php
        $startP = max(1, $page - 2);
        $endP = min($totalPages, $startP + 4);
        $startP = max(1, $endP - 4);
        for ($i = $startP; $i <= $endP; $i++): ?>
          <a class="page-num <?= $i===$page?'current':'' ?>" href="<?= e(logs_page_url(['p'=>$i])) ?>"><?= $i ?></a>
        <?php endfor; ?>
        <a class="btn-mini <?= $page>=$totalPages?'disabled':'' ?>" href="<?= e(logs_page_url(['p'=>min($totalPages,$page+1)])) ?>">下一页 ›</a>
        <a class="btn-mini <?= $page>=$totalPages?'disabled':'' ?>" href="<?= e(logs_page_url(['p'=>$totalPages])) ?>">末页 »</a>
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
