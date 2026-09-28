<?php
/**
 * 前台：修改我的密码（需验证当前密码）
 */
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require_login();

$me  = current_user();
$ok  = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cur   = $_POST['cur_password'] ?? '';
    $new   = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($cur === '' || $new === '') {
        $error = '请输入当前密码和新密码。';
    } elseif (u_len($new) < 4) {
        $error = '新密码至少 4 位。';
    } elseif ($new !== $confirm) {
        $error = '两次输入的新密码不一致。';
    } else {
        $st = $pdo->prepare("SELECT password FROM users WHERE id=?");
        $st->execute([(int)$me['id']]);
        $stored = (string)$st->fetchColumn();
        if (md5($cur) !== $stored) {
            $error = '当前密码不正确。';
        } else {
            $up = $pdo->prepare("UPDATE users SET password=? WHERE id=?");
            $up->execute([md5($new), (int)$me['id']]);
            write_log($pdo, 'change_password', '登录认证', '用户自助修改密码');
            $ok = '密码已修改成功。';
        }
    }
}

$pageTitle = '修改密码';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-card">
  <h2>修改密码</h2>
  <?php if ($ok): ?><p class="form-success"><?= e($ok) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="form-error"><?= e($error) ?></p><?php endif; ?>
  <form method="post" class="auth-form">
    <label>当前密码
      <input type="password" name="cur_password" required autocomplete="current-password">
    </label>
    <label>新密码
      <input type="password" name="new_password" required minlength="4" autocomplete="new-password">
    </label>
    <label>确认新密码
      <input type="password" name="confirm_password" required minlength="4" autocomplete="new-password">
    </label>
    <button type="submit" class="btn-primary">保存新密码</button>
  </form>
  <p class="auth-tip">密码以 MD5 加密保存。改完后下次登录请使用新密码。</p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
