<?php
/**
 * 前台：我的设置（登录用户中心）
 * 布局：左侧列表菜单 + 右侧内容面板
 *   🏠 主页    —— 基本个人信息 / 自定义导航速览 / 登录设备名称与快捷下线 /
 *                设备最大同时在线数量
 *   ⭐ 我的导航 —— 个人自定义导航的增删改查（仅本人可见）
 *   📱 登录设备 —— 全部登录设备详情，可强制下线其他设备
 *   🔒 修改密码 —— 验证当前密码后修改
 *   🎨 主题皮肤 —— 切换界面配色（受站点总开关与可见皮肤数量控制）
 */
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require_login();

// 入口开关：被管理员关闭后，直接访问也一并拦截
if (empty(current_user()['settings_entry'])) {
    header('Location: /index.html');
    exit;
}

$me = current_user();
$uid = (int)$me['id'];

// 取完整用户行（会话中仅缓存部分字段）
$uSt = $pdo->prepare("SELECT * FROM users WHERE id=?");
$uSt->execute([$uid]);
$meRow = $uSt->fetch();
if (!$meRow) {
    header('Location: /login.html');
    exit;
}

// 面板提示消息：['tab'=>面板, 'type'=>success/error, 'text'=>内容]
$panelMsg = null;
$activeTab = 'home';

/**
 * 处理一次 POST：成功时写日志并按需跳转；失败时填充面板消息。
 * 为避免与本页表单动作冲突，导航相关动作统一使用 nav_ 前缀。
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $retTab = (string)($_POST['ret_tab'] ?? 'home');
    if (!in_array($retTab, ['home', 'nav', 'devices', 'password', 'theme'], true)) {
        $retTab = 'home';
    }

    // —— 修改密码 ——
    if ($action === 'password') {
        $cur     = (string)($_POST['cur_password'] ?? '');
        $new     = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        if ($cur === '' || $new === '') {
            $panelMsg = ['tab' => 'password', 'type' => 'error', 'text' => '请输入当前密码和新密码。'];
        } elseif (u_len($new) < 4) {
            $panelMsg = ['tab' => 'password', 'type' => 'error', 'text' => '新密码至少 4 位。'];
        } elseif ($new !== $confirm) {
            $panelMsg = ['tab' => 'password', 'type' => 'error', 'text' => '两次输入的新密码不一致。'];
        } elseif (md5($cur) !== (string)$meRow['password']) {
            $panelMsg = ['tab' => 'password', 'type' => 'error', 'text' => '当前密码不正确。'];
        } else {
            $pdo->prepare("UPDATE users SET password=? WHERE id=?")
                ->execute([md5($new), $uid]);
            write_log($pdo, 'change_password', '登录认证', '用户自助修改密码');
            $panelMsg = ['tab' => 'password', 'type' => 'success', 'text' => '密码已修改成功，当前设备保持登录。'];
        }
    }

    // —— 强制下线自己的设备（主页快捷操作 / 设备面板共用） ——
    elseif ($action === 'revoke_own') {
        $devId = (int)($_POST['device_id'] ?? 0);
        $dSt = $pdo->prepare("SELECT * FROM user_sessions WHERE id=?");
        $dSt->execute([$devId]);
        $devRow = $dSt->fetch();

        if (!$devRow || (int)$devRow['user_id'] !== $uid) {
            $panelMsg = ['tab' => $retTab, 'type' => 'error', 'text' => '设备不存在或不属于当前账号。'];
        } elseif ($devRow['session_id'] === session_id()) {
            $panelMsg = ['tab' => $retTab, 'type' => 'error', 'text' => '不能下线当前设备（如需退出请点顶栏「退出」）。'];
        } elseif ((int)$devRow['revoked'] === 1) {
            $panelMsg = ['tab' => $retTab, 'type' => 'success', 'text' => '该设备已下线。'];
        } else {
            revoke_device_row($pdo, $devId);
            write_log($pdo, 'revoke_own_device', '登录认证',
                '用户自助下线设备：' . $devRow['device_name'] . '（IP：' . $devRow['ip'] . '）');
            $panelMsg = ['tab' => $retTab, 'type' => 'success',
                         'text' => '已下线设备：' . $devRow['device_name']];
        }
    }

    // —— 自定义导航：新增 ——
    elseif ($action === 'nav_create') {
        $title = trim($_POST['title'] ?? '');
        $url   = normalize_nav_url($_POST['url'] ?? '');
        $icon  = trim($_POST['icon'] ?? '');
        $sort  = (int)($_POST['sort_order'] ?? 0);
        if ($title === '' || $url === '') {
            $panelMsg = ['tab' => 'nav', 'type' => 'error', 'text' => '标题和 URL 不能为空。'];
        } else {
            $pdo->prepare("INSERT INTO user_nav_items (user_id, title, url, icon, sort_order) VALUES (?,?,?,?,?)")
                ->execute([$uid, $title, $url, $icon, $sort]);
            write_log($pdo, 'mynav_create', '我的导航', "新增自定义导航：{$title}");
            header('Location: /settings.html?navsaved=1#nav');
            exit;
        }
    }

    // —— 自定义导航：修改 ——
    elseif ($action === 'nav_update') {
        $id    = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $url   = normalize_nav_url($_POST['url'] ?? '');
        $icon  = trim($_POST['icon'] ?? '');
        $sort  = (int)($_POST['sort_order'] ?? 0);
        if ($title === '' || $url === '') {
            $panelMsg = ['tab' => 'nav', 'type' => 'error', 'text' => '标题和 URL 不能为空。'];
        } else {
            $up = $pdo->prepare("UPDATE user_nav_items SET title=?, url=?, icon=?, sort_order=?
                                  WHERE id=? AND user_id=?");
            $up->execute([$title, $url, $icon, $sort, $id, $uid]);
            write_log($pdo, 'mynav_update', '我的导航', "修改自定义导航：{$title}");
            header('Location: /settings.html?navsaved=1#nav');
            exit;
        }
    }

    // —— 自定义导航：删除 ——
    elseif ($action === 'nav_delete') {
        $id = (int)($_POST['id'] ?? 0);
        $dSt = $pdo->prepare("SELECT title FROM user_nav_items WHERE id=? AND user_id=?");
        $dSt->execute([$id, $uid]);
        $oldTitle = (string)$dSt->fetchColumn();
        $pdo->prepare("DELETE FROM user_nav_items WHERE id=? AND user_id=?")->execute([$id, $uid]);
        write_log($pdo, 'mynav_delete', '我的导航', "删除自定义导航：{$oldTitle}");
        header('Location: /settings.html?navsaved=1#nav');
        exit;
    }

    if ($panelMsg !== null) $activeTab = $panelMsg['tab'];
}

// 所属用户组名称
$myGroupNames = [];
$gids = user_group_ids($pdo, $uid);
if ($gids) {
    $in = implode(',', array_map('intval', $gids));
    $myGroupNames = $pdo->query("SELECT name FROM user_groups WHERE id IN ($in)")
        ->fetchAll(PDO::FETCH_COLUMN);
}

// 自定义导航
$nSt = $pdo->prepare("SELECT * FROM user_nav_items WHERE user_id=? ORDER BY sort_order ASC, id ASC");
$nSt->execute([$uid]);
$navItems = $nSt->fetchAll();

// 登录设备
$sessions = user_device_sessions($pdo, $uid);
$curSid   = session_id();
$activeDeviceCount = 0;
foreach ($sessions as $s) {
    if ((int)$s['revoked'] === 0) $activeDeviceCount++;
}

// 设备最大同时在线数量（网站参数；0 = 不限制）
$maxDevices = (int)(site_settings($pdo)['max_login_devices'] ?? 0);

$pageTitle = '我的设置';
require __DIR__ . '/includes/header.php';
$__weekMap = ['日', '一', '二', '三', '四', '五', '六'];
$showThemeMenu = ($__s['top_show_theme'] ?? '1') === '1' && count($__switchThemes) >= 2;
?>
<div class="uc-wrap" data-default-tab="<?= e($activeTab) ?>">

  <!-- ========== 左侧列表菜单 ========== -->
  <aside class="uc-menu">
    <div class="uc-menu-head">
      <span class="uc-menu-logo">⚙️</span>
      <div>
        <div class="uc-menu-title">我的设置</div>
        <div class="uc-menu-sub"><?= e($meRow['username']) ?></div>
      </div>
    </div>
    <nav class="uc-menu-list">
      <button type="button" class="uc-menu-item" data-tab="home">
        <span class="uci-icon">🏠</span><span class="uci-label">主页</span>
      </button>
      <button type="button" class="uc-menu-item" data-tab="nav">
        <span class="uci-icon">⭐</span><span class="uci-label">我的导航</span>
        <span class="uci-badge"><?= count($navItems) ?></span>
      </button>
      <button type="button" class="uc-menu-item" data-tab="devices">
        <span class="uci-icon">📱</span><span class="uci-label">登录设备</span>
        <span class="uci-badge uci-badge-live"><?= $activeDeviceCount ?></span>
      </button>
      <button type="button" class="uc-menu-item" data-tab="password">
        <span class="uci-icon">🔒</span><span class="uci-label">修改密码</span>
      </button>
      <?php if ($showThemeMenu): ?>
      <button type="button" class="uc-menu-item" data-tab="theme">
        <span class="uci-icon">🎨</span><span class="uci-label">主题皮肤</span>
      </button>
      <?php endif; ?>
    </nav>
  </aside>

  <!-- ========== 右侧内容面板 ========== -->
  <div class="uc-content">

    <!-- ① 主页 -->
    <section class="uc-panel" data-panel="home">
      <h2 class="uc-panel-title">主页</h2>

      <!-- 欢迎 + 实时时钟 -->
      <div class="uc-hero">
        <div>
          <h3 class="uc-hello">你好，<?= e($meRow['display_name'] ?: $meRow['username']) ?></h3>
          <div class="uc-date-line">
            <span id="msDate"><?= date('Y年m月d日') ?></span>
            <span class="uc-sep">·</span>
            <span id="msWeek">星期<?= $__weekMap[(int)date('w')] ?></span>
          </div>
        </div>
        <div class="ms-clock" id="msClock"><?= date('H:i:s') ?></div>
      </div>

      <!-- 基本个人信息 -->
      <div class="uc-block">
        <h4 class="uc-block-title">👤 基本信息</h4>
        <div class="uc-info-grid">
          <div class="uc-info-cell">
            <span class="uc-info-k">登录账号</span>
            <span class="uc-info-v"><?= e($meRow['username']) ?></span>
          </div>
          <div class="uc-info-cell">
            <span class="uc-info-k">显示名称</span>
            <span class="uc-info-v"><?= e($meRow['display_name'] ?: '（未设置，显示登录账号）') ?></span>
          </div>
          <div class="uc-info-cell">
            <span class="uc-info-k">用户组</span>
            <span class="uc-info-v"><?= $myGroupNames ? e(implode('、', $myGroupNames)) : '<span class="grp-hint">无分组</span>' ?></span>
          </div>
          <div class="uc-info-cell">
            <span class="uc-info-k">注册时间</span>
            <span class="uc-info-v"><?= e($meRow['created_at']) ?></span>
          </div>
          <div class="uc-info-cell">
            <span class="uc-info-k">上次登录</span>
            <span class="uc-info-v"><?= !empty($meRow['last_login_at'])
                ? e($meRow['last_login_at']) . (!empty($meRow['last_login_ip']) ? ' <span class="grp-hint">（IP：' . e($meRow['last_login_ip']) . '）</span>' : '')
                : '<span class="grp-hint">这是第一次登录</span>' ?></span>
          </div>
          <div class="uc-info-cell">
            <span class="uc-info-k">最大同时在线设备</span>
            <span class="uc-info-v"><?= $maxDevices > 0 ? $maxDevices . ' 台' : '<span class="grp-hint">不限制</span>' ?></span>
          </div>
        </div>
      </div>

      <!-- 自定义导航 + 登录设备 速览 -->
      <div class="uc-quick-grid">

        <!-- 自定义导航速览 -->
        <div class="uc-block uc-quick-block">
          <div class="uc-quick-head">
            <h4 class="uc-block-title">⭐ 我的自定义导航</h4>
            <button type="button" class="btn-mini" data-goto="nav">管理</button>
          </div>
          <?php if (!$navItems): ?>
            <p class="empty-tip">还没有自定义导航。</p>
          <?php else: ?>
            <ul class="uc-nav-mini">
              <?php foreach (array_slice($navItems, 0, 6) as $it): ?>
              <li>
                <span class="uc-nav-mini-ic"><?= icon_html((string)$it['icon']) ?></span>
                <span class="uc-nav-mini-name"><?= e($it['title']) ?></span>
              </li>
              <?php endforeach; ?>
            </ul>
            <?php if (count($navItems) > 6): ?>
              <p class="grp-hint">还有 <?= count($navItems) - 6 ?> 条，点击「管理」查看全部。</p>
            <?php endif; ?>
          <?php endif; ?>
        </div>

        <!-- 登录设备速览 -->
        <div class="uc-block uc-quick-block">
          <div class="uc-quick-head">
            <h4 class="uc-block-title">📱 登录设备</h4>
            <button type="button" class="btn-mini" data-goto="devices">详情</button>
          </div>
          <?php if (!$sessions): ?>
            <p class="empty-tip">暂无登录记录。</p>
          <?php else: ?>
            <ul class="uc-dev-mini">
              <?php foreach (array_slice($sessions, 0, 5) as $s):
                $isCurrent = $s['session_id'] === $curSid;
                $isOff = (int)$s['revoked'] === 1; ?>
              <li>
                <span class="uc-dev-dot <?= $isOff ? 'dot-off' : ($isCurrent ? 'dot-cur' : 'dot-live') ?>"></span>
                <span class="uc-dev-mini-name" title="<?= e($s['user_agent']) ?>"><?= e($s['device_name']) ?></span>
                <?php if ($isCurrent): ?>
                  <span class="grp-hint">当前</span>
                <?php elseif (!$isOff): ?>
                <form method="post" class="uc-dev-mini-ops"
                      onsubmit="return confirm('确定下线该设备？');">
                  <input type="hidden" name="action" value="revoke_own">
                  <input type="hidden" name="ret_tab" value="home">
                  <input type="hidden" name="device_id" value="<?= (int)$s['id'] ?>">
                  <button type="submit" class="btn-mini danger">下线</button>
                </form>
                <?php endif; ?>
              </li>
              <?php endforeach; ?>
            </ul>
            <?php if ($panelMsg !== null && $panelMsg['tab'] === 'home'): ?>
              <p class="<?= $panelMsg['type'] === 'error' ? 'form-error' : 'form-success' ?>"><?= e($panelMsg['text']) ?></p>
            <?php endif; ?>
          <?php endif; ?>
        </div>

      </div>
    </section>

    <!-- ② 我的导航 -->
    <section class="uc-panel" data-panel="nav">
      <h2 class="uc-panel-title">⭐ 我的导航</h2>
      <p class="page-sub">添加的导航仅你自己可见。图标支持 emoji 或图片 URL；站内地址直接写 <code>admin</code> 即可，会自动识别为 <code>/admin</code>。</p>

      <!-- 添加表单 -->
      <form method="post" class="uc-nav-add" action="/settings.html#nav">
        <input type="hidden" name="action" value="nav_create">
        <input type="hidden" name="ret_tab" value="nav">
        <div class="uc-nav-add-grid">
          <label class="uc-field uc-field-title">
            <span class="uc-field-label">标题 <i>*</i></span>
            <input type="text" name="title" placeholder="如：后台管理" required>
          </label>
          <label class="uc-field uc-field-url">
            <span class="uc-field-label">URL <i>*</i></span>
            <input type="text" name="url" placeholder="https://example.com 或 admin" required>
          </label>
          <label class="uc-field uc-field-icon">
            <span class="uc-field-label">图标</span>
            <input type="text" name="icon" placeholder="emoji 或图片 URL">
          </label>
          <label class="uc-field uc-field-sort">
            <span class="uc-field-label">排序</span>
            <input type="number" name="sort_order" value="0">
          </label>
          <button type="submit" class="btn-primary uc-nav-add-btn">➕ 添加</button>
        </div>
      </form>

      <?php if (isset($_GET['navsaved'])): ?>
        <p class="form-success">✅ 已保存。</p>
      <?php endif; ?>
      <?php if ($panelMsg !== null && $panelMsg['tab'] === 'nav'): ?>
        <p class="<?= $panelMsg['type'] === 'error' ? 'form-error' : 'form-success' ?>"><?= e($panelMsg['text']) ?></p>
      <?php endif; ?>

      <!-- 条目列表 -->
      <?php if (!$navItems): ?>
        <div class="uc-empty">
          <span class="uc-empty-icon">🗂️</span>
          <p>还没有自定义导航，在上方添加第一条吧。</p>
        </div>
      <?php else: ?>
      <div class="uc-nav-grid">
        <?php foreach ($navItems as $it): $iid = (int)$it['id']; ?>
          <div class="uc-nav-card">
            <div class="uc-nav-card-ops">
              <button type="button" class="uc-op-btn" title="编辑" data-edit="<?= $iid ?>">✏️</button>
              <form method="post" class="uc-op-del-form"
                    onsubmit="return confirm('确定删除「<?= e($it['title']) ?>」？');">
                <input type="hidden" name="action" value="nav_delete">
                <input type="hidden" name="ret_tab" value="nav">
                <input type="hidden" name="id" value="<?= $iid ?>">
                <button type="submit" class="uc-op-btn uc-op-del" title="删除">🗑️</button>
              </form>
            </div>
            <span class="nav-icon"><?= icon_html((string)$it['icon']) ?></span>
            <span class="nav-title"><?= e($it['title']) ?></span>
            <span class="uc-nav-card-url"><?= e($it['url']) ?></span>
          </div>

          <!-- 编辑弹窗 -->
          <div class="uc-modal-mask" id="editModal-<?= $iid ?>">
            <div class="uc-modal">
              <div class="uc-modal-head">
                <span>✏️ 编辑导航</span>
                <button type="button" class="uc-modal-close" data-close>&times;</button>
              </div>
              <form method="post" action="/settings.html#nav">
                <input type="hidden" name="action" value="nav_update">
                <input type="hidden" name="ret_tab" value="nav">
                <input type="hidden" name="id" value="<?= $iid ?>">
                <div class="uc-modal-body">
                  <label class="uc-field">
                    <span class="uc-field-label">标题 <i>*</i></span>
                    <input type="text" name="title" value="<?= e($it['title']) ?>" required>
                  </label>
                  <label class="uc-field">
                    <span class="uc-field-label">URL <i>*</i></span>
                    <input type="text" name="url" value="<?= e($it['url']) ?>" required>
                  </label>
                  <label class="uc-field">
                    <span class="uc-field-label">图标（emoji 或图片 URL）</span>
                    <input type="text" name="icon" value="<?= e($it['icon']) ?>">
                  </label>
                  <label class="uc-field">
                    <span class="uc-field-label">排序</span>
                    <input type="number" name="sort_order" value="<?= (int)$it['sort_order'] ?>">
                  </label>
                </div>
                <div class="uc-modal-foot">
                  <button type="button" class="btn-mini" data-close>取消</button>
                  <button type="submit" class="btn-primary">💾 保存修改</button>
                </div>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>

    <!-- ③ 登录设备 -->
    <section class="uc-panel" data-panel="devices">
      <h2 class="uc-panel-title">📱 登录设备
        <span class="uc-title-tag">当前 <?= $activeDeviceCount ?> 台在线</span>
      </h2>
      <?php if ($panelMsg !== null && $panelMsg['tab'] === 'devices'): ?>
        <p class="<?= $panelMsg['type'] === 'error' ? 'form-error' : 'form-success' ?>"><?= e($panelMsg['text']) ?></p>
      <?php endif; ?>

      <?php if (!$sessions): ?>
        <p class="empty-tip">暂无登录记录。</p>
      <?php else: ?>
      <div class="uc-device-list">
        <?php foreach ($sessions as $s):
          $isCurrent = $s['session_id'] === $curSid; ?>
          <div class="uc-device-item">
            <div class="uc-device-body">
              <div class="uc-device-main">
                <span class="uc-device-name" title="<?= e($s['user_agent']) ?>"><?= e($s['device_name']) ?></span>
                <?php if ((int)$s['revoked'] === 1): ?>
                  <span class="uc-device-status st-off">⚫ 已下线</span>
                <?php elseif ($isCurrent): ?>
                  <span class="uc-device-status st-cur">🟢 当前设备</span>
                <?php else: ?>
                  <span class="uc-device-status st-live">🔵 登录中</span>
                <?php endif; ?>
              </div>
              <div class="uc-device-meta">
                IP：<?= e((string)$s['ip'] ?: '—') ?>
                · 登录于 <?= e((string)$s['created_at']) ?>
                · 最后活跃 <?= e((string)$s['last_access_at']) ?>
              </div>
            </div>
            <?php if (!$isCurrent && (int)$s['revoked'] === 0): ?>
            <form method="post" class="uc-device-ops"
                  onsubmit="return confirm('确定下线该设备？该设备下次操作将被要求重新登录。');">
              <input type="hidden" name="action" value="revoke_own">
              <input type="hidden" name="ret_tab" value="devices">
              <input type="hidden" name="device_id" value="<?= (int)$s['id'] ?>">
              <button type="submit" class="btn-mini danger">强制下线</button>
            </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>

    <!-- ④ 修改密码 -->
    <section class="uc-panel" data-panel="password">
      <h2 class="uc-panel-title">🔒 修改密码</h2>
      <?php if ($panelMsg !== null && $panelMsg['tab'] === 'password'): ?>
        <p class="<?= $panelMsg['type'] === 'error' ? 'form-error' : 'form-success' ?>"><?= e($panelMsg['text']) ?></p>
      <?php endif; ?>
      <form method="post" class="uc-pwd-form" action="/settings.html#password">
        <input type="hidden" name="action" value="password">
        <input type="hidden" name="ret_tab" value="password">
        <label class="uc-field">
          <span class="uc-field-label">当前密码</span>
          <input type="password" name="cur_password" required autocomplete="current-password">
        </label>
        <label class="uc-field">
          <span class="uc-field-label">新密码</span>
          <input type="password" name="new_password" required minlength="4" autocomplete="new-password">
        </label>
        <label class="uc-field">
          <span class="uc-field-label">确认新密码</span>
          <input type="password" name="confirm_password" required minlength="4" autocomplete="new-password">
        </label>
        <button type="submit" class="btn-primary uc-submit">保存新密码</button>
      </form>
      <p class="auth-tip">密码以 MD5 加密保存；修改后其他设备仍可继续操作，如需全设备更新请手动下线。</p>
    </section>

    <!-- ⑤ 主题皮肤 -->
    <?php if ($showThemeMenu): ?>
    <section class="uc-panel" data-panel="theme">
      <h2 class="uc-panel-title">🎨 主题皮肤</h2>
      <p class="page-sub">选择喜欢的界面配色，选择保存在账号中，换设备登录同样生效。</p>
      <div class="theme-grid uc-theme-grid">
        <?php foreach ($__switchThemes as $t):
          $isThemeCur = $t['id'] === $__themeId;
          $applyUrl = '/theme.html?set=' . urlencode($t['id']) . '&back=' . urlencode('/settings.html');
        ?>
          <div class="theme-card<?= $isThemeCur ? ' current' : '' ?>">
            <div class="theme-preview<?= !empty($t['dark']) ? ' dark-preview' : '' ?>">
              <?php if (!empty($t['swatches'])): foreach (array_slice($t['swatches'], 0, 3) as $i => $c): ?>
                <span class="tp-swatch tp-s<?= $i ?>" style="background:<?= e($c) ?>"></span>
              <?php endforeach; else: ?>
                <span class="tp-swatch tp-s0" style="background:var(--primary)"></span>
                <span class="tp-swatch tp-s1" style="background:var(--primary-dark)"></span>
                <span class="tp-swatch tp-s2" style="background:var(--primary-soft)"></span>
              <?php endif; ?>
            </div>
            <div class="theme-card-body">
              <div class="theme-name-row">
                <strong><?= e($t['name']) ?></strong>
                <?php if (!empty($t['dark'])): ?><span class="theme-dark-tag">暗色</span><?php endif; ?>
                <?php if ($isThemeCur): ?><span class="theme-current-tag">✓ 使用中</span><?php endif; ?>
              </div>
              <p class="theme-desc"><?= e($t['desc']) ?></p>
              <p class="theme-meta">v<?= e($t['version']) ?> · <?= e($t['author']) ?>
                <?= !empty($t['builtin']) ? '· 内置' : '· 自定义' ?></p>
              <?php if (!$isThemeCur): ?>
                <a class="btn-primary theme-apply-btn" href="<?= e($applyUrl) ?>">使用此皮肤</a>
              <?php else: ?>
                <a class="btn-mini theme-apply-btn" href="/theme.html?reset=1&back=<?= urlencode('/settings.html') ?>">恢复跟随站点默认</a>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

  </div>
</div>

<script>
// 实时时钟：按访客本机时间每秒刷新
(function () {
  var elClock = document.getElementById('msClock');
  var elDate  = document.getElementById('msDate');
  var elWeek  = document.getElementById('msWeek');
  if (!elClock) return;
  var WEEK = ['日', '一', '二', '三', '四', '五', '六'];
  function pad2(n) { return n < 10 ? '0' + n : '' + n; }
  function render() {
    var d = new Date();
    elClock.textContent = pad2(d.getHours()) + ':' + pad2(d.getMinutes()) + ':' + pad2(d.getSeconds());
    elDate.textContent  = d.getFullYear() + '年' + pad2(d.getMonth() + 1) + '月' + pad2(d.getDate()) + '日';
    elWeek.textContent  = '星期' + WEEK[d.getDay()];
  }
  render();
  setInterval(render, 1000);
})();

// 列表菜单与面板切换：hash 定位（#home/#nav/#devices/#password/#theme）
(function () {
  var wrap = document.querySelector('.uc-wrap');
  if (!wrap) return;
  var items = Array.prototype.slice.call(document.querySelectorAll('.uc-menu-item'));
  var panels = Array.prototype.slice.call(document.querySelectorAll('.uc-panel'));
  var TABS = items.map(function (b) { return b.getAttribute('data-tab'); });

  function activate(tab, updateHash) {
    if (TABS.indexOf(tab) === -1) tab = 'home';
    items.forEach(function (b) {
      b.classList.toggle('active', b.getAttribute('data-tab') === tab);
    });
    panels.forEach(function (p) {
      p.classList.toggle('show', p.getAttribute('data-panel') === tab);
    });
    if (updateHash && window.location.hash !== '#' + tab) {
      history.replaceState(null, '', '#' + tab);
    }
  }

  items.forEach(function (b) {
    b.addEventListener('click', function () { activate(b.getAttribute('data-tab'), true); });
  });
  // 面板内快捷跳转（主页的「管理 / 详情」按钮）
  document.querySelectorAll('[data-goto]').forEach(function (btn) {
    btn.addEventListener('click', function () { activate(btn.getAttribute('data-goto'), true); });
  });

  // 自定义导航：编辑弹窗
  function openEdit(id) {
    var m = document.getElementById('editModal-' + id);
    if (!m) return;
    m.classList.add('show');
    document.body.style.overflow = 'hidden';
    var inp = m.querySelector('input[name="title"]');
    if (inp) inp.focus();
  }
  function closeMask(m) {
    m.classList.remove('show');
    document.body.style.overflow = '';
  }
  document.querySelectorAll('[data-edit]').forEach(function (btn) {
    btn.addEventListener('click', function () { openEdit(btn.getAttribute('data-edit')); });
  });
  document.querySelectorAll('.uc-modal-mask').forEach(function (m) {
    m.addEventListener('click', function (e) { if (e.target === m) closeMask(m); });
    m.querySelectorAll('[data-close]').forEach(function (c) {
      c.addEventListener('click', function () { closeMask(m); });
    });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('.uc-modal-mask.show').forEach(closeMask);
    }
  });

  // 初始面板：hash 优先，其次服务端 data-default-tab（如报错后回到对应面板）
  var hash = (window.location.hash || '').replace('#', '');
  activate(TABS.indexOf(hash) !== -1 ? hash : wrap.getAttribute('data-default-tab'), false);
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
