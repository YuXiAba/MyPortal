<?php
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';

// 已登录则跳回首页
if (is_logged_in()) {
    header('Location: /index.html');
    exit;
}

// 预先初始化，保证任何代码路径下变量都存在（避免 PHP 8 Undefined variable 警告）
$username = '';
$password = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = '请输入用户名和密码。';
    } else {
        // 登录总开关：系统管理员可在后台一键关闭所有人登录（紧急维护）
        // 关闭后仅超级管理员可登录，其他人（含普通管理员）一律拒绝
        $loginDisabled = (site_settings($pdo)['login_disabled'] ?? '0') === '1';

        // 先按用户名取用户行（身份/权限聚合在多组关联表上进行）
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $userRow = $stmt->fetch();

        if ($userRow && md5($password) === $userRow['password']) {
            // 聚合该用户全部所属用户组的身份（超管标记取任一组）
            $ident = load_user_identity($pdo, (int)$userRow['id']);
            if ($loginDisabled && !$ident['is_super']) {
                $error = '系统维护中，登录已暂时关闭，请稍后再试。';
                write_log($pdo, 'login', '登录认证', "登录被拒：登录总开关已关闭（账号：$username）",
                          'fail', $username, (string)$userRow['display_name']);
            } else {
                // 最大登录设备数校验（0=不限；超限则拒绝本次登录）
                $cap = can_open_new_session($pdo, (int)$userRow['id']);
                if (!$cap['ok']) {
                    $error = '已达到最大登录设备数（' . $cap['max']
                           . ' 台），请先在其他设备退出登录，或联系管理员下线设备后重试。';
                    write_log($pdo, 'login', '登录认证',
                        "登录被拒：超过最大设备数 {$cap['max']} 台（账号：$username）",
                        'fail', $username, (string)$userRow['display_name']);
                } else {
                    $_SESSION['user'] = build_session_user($pdo, $ident);
                    $_SESSION['_last_activity'] = time();
                    // 登记本次登录设备（设备名/IP/时间）
                    register_user_session($pdo, (int)$userRow['id']);
                    // 记录本次登录 IP 与时间（供后台概览查看）
                    $loginIp = client_ip();
                    $pdo->prepare("UPDATE users SET last_login_ip=?, last_login_at=NOW() WHERE id=?")
                        ->execute([$loginIp !== '' ? $loginIp : null, (int)$userRow['id']]);
                    write_log($pdo, 'login', '登录认证', '登录成功' . ($loginIp !== '' ? "（IP：$loginIp）" : ''));
                    header('Location: /index.html');
                    exit;
                }
            }
        } else {
            $error = '用户名或密码错误。';
            // 账号存在则快照其显示名（空则用用户名兜底）；不存在记为"非注册用户"
            $failDisplay = $userRow !== false
                         ? ((string)$userRow['display_name'] !== '' ? (string)$userRow['display_name'] : $username)
                         : '非注册用户';
            write_log($pdo, 'login', '登录认证', "登录失败：用户名或密码错误（账号：$username）",
                      'fail', $username, $failDisplay);
        }
    }
}

$pageTitle = '登录';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-card">
  <h2>登录</h2>
  <?php if (isset($_GET['expired'])): ?><p class="form-error">登录状态已过期，请重新登录。</p><?php endif; ?>
  <?php if (isset($_GET['kicked'])): ?><p class="form-error">该登录设备已被管理员（或您本人在其他设备上）下线，请重新登录。</p><?php endif; ?>
  <?php if ($error): ?><p class="form-error"><?= e($error) ?></p><?php endif; ?>
  <form method="post" class="auth-form">
    <label>用户名
      <input type="text" name="username" required value="<?= e($_POST['username'] ?? '') ?>">
    </label>
    <label>密码
      <input type="password" name="password" required>
    </label>
    <button type="submit" class="btn-primary">登录</button>
  </form>
  <p class="auth-tip">账号由管理员统一开通，如需账号请联系管理员。</p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
