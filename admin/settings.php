<?php
/**
 * 后台：网站参数设置
 * 访问权限（满足其一即可）：
 *   manage_settings    —— 维护网站参数（系统管理员默认拥有，可二级授权）；
 *   manage_user_entry  —— 仅使用「用户设置入口管理」分区（可二级授权）。
 * 分类：
 *   ① 基础信息（站名 / Logo：emoji 或图片 URL）
 *   ② 顶部导航（内置按钮显隐 + 自定义链接增删改，链接可设访客可见度）
 *   ③ 底部版权（纯文本 / HTML / Markdown）
 *   ④ 自定义代码（全站 CSS / JS）
 *   ⑤ 登录与会话（登录总开关 + 登录状态有效期 + 最大登录设备数）
 *   ⑥ 界面显示开关（首页天气 / 用户列表上次登录列）
 *   ⑦ 用户设置入口（每用户「我的设置」入口开关，按管理员 / 普通用户分组）
 */
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require_admin();

$canSettings = has_perm('manage_settings');
$canEntry    = has_perm('manage_user_entry');
if (!$canSettings && !$canEntry) {
    header('Location: /index.html');
    exit;
}
$entryError = '';

$TEXT_FIELDS = [
    'site_name', 'site_logo',
    'site_footer', 'site_footer_type',
    'custom_css', 'custom_js',
];
$TOGGLE_FIELDS = [
    'top_show_admin', 'top_show_password', 'top_show_logout', 'top_show_theme',
    'login_disabled',
    'home_show_weather', 'users_show_last_login',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ((string)($_POST['action'] ?? '') === 'toggle_entry') {
        // ⑦ 用户设置入口开关（需 manage_user_entry；目标须在管理范围内）
        $id     = (int)($_POST['id'] ?? 0);
        $enable = (string)($_POST['enable'] ?? '') === '1';
        if (!$canEntry) {
            $entryError = '无权控制设置入口：需要「用户设置入口管理」权限。';
        } elseif ($id <= 0 || !can_manage_user($pdo, $id)) {
            $entryError = '无权操作该用户：只能管理级别低于自己的用户，或自管组中的同组其他成员。';
        } else {
            $pdo->prepare("UPDATE users SET settings_entry=? WHERE id=?")
                ->execute([$enable ? 1 : 0, $id]);
            $qName = $pdo->prepare("SELECT username FROM users WHERE id=?");
            $qName->execute([$id]);
            $uname = (string)$qName->fetchColumn();
            write_log($pdo, 'toggle_entry', '网站参数设置',
                ($enable ? '开启' : '关闭') . "用户设置入口：{$uname}");
            header('Location: /admin/settings.html?entrysaved=1#entry');
            exit;
        }
    } elseif (!$canSettings) {
        $entryError = '无权保存网站参数：需要「网站参数设置」权限。';
    } else {
    $upsert = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?)
                             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    foreach ($TEXT_FIELDS as $k) {
        $upsert->execute([$k, trim((string)($_POST[$k] ?? ''))]);
    }
    foreach ($TOGGLE_FIELDS as $k) {
        $upsert->execute([$k, isset($_POST[$k]) ? '1' : '0']);
    }

    // 登录状态有效期：数字 × 单位 -> 秒
    $lifeNum = max(0, (int)($_POST['life_num'] ?? 0));
    $lifeUnit = (int)($_POST['life_unit'] ?? 1);
    if (!in_array($lifeUnit, [60, 3600, 86400], true)) $lifeUnit = 3600;
    $upsert->execute(['session_lifetime', (string)($lifeNum * $lifeUnit)]);

    // 每个用户最大同时登录设备数：0=不限，范围 1–100
    $maxDev = max(0, min(100, (int)($_POST['max_login_devices'] ?? 0)));
    $upsert->execute(['max_login_devices', (string)$maxDev]);

    // 顶部自定义链接
    $links = [];
    $icons = $_POST['l_icon'] ?? [];
    $texts = $_POST['l_text'] ?? [];
    $urls  = $_POST['l_url']  ?? [];
    $vises = $_POST['l_vis']  ?? [];
    if (is_array($texts)) {
        foreach ($texts as $i => $text) {
            $text = trim((string)$text);
            $url  = trim((string)($urls[$i] ?? ''));
            if ($text === '' && $url === '') continue;
            $links[] = [
                'icon' => trim((string)($icons[$i] ?? '')),
                'text' => $text,
                'url'  => $url,
                'vis'  => ($vises[$i] ?? 'guest') === 'login' ? 'login' : 'guest',
            ];
        }
    }
    $upsert->execute(['top_custom_links', json_encode(array_values($links), JSON_UNESCAPED_UNICODE)]);

    write_log($pdo, 'save_settings', '网站参数设置', '保存网站参数设置');
    header('Location: /admin/settings.html?saved=1'); exit;
    }
}

$s = site_settings($pdo);
$customLinks = json_decode((string)$s['top_custom_links'], true);
$customLinks = is_array($customLinks) ? $customLinks : [];

// ⑦ 用户设置入口：当前管理员可管理的用户，按管理员 / 普通用户分组
$entryAdminUsers = [];
$entryNormalUsers = [];
if ($canEntry) {
    $gNamesSql = group_names_sql();
    $lvlSql    = target_level_sql();
    $entryRows = $pdo->query("SELECT u.*,
                                     $gNamesSql AS group_name,
                                     $lvlSql AS group_level,
                                     EXISTS (SELECT 1 FROM user_group_members em
                                               JOIN user_groups eg ON em.group_id=eg.id
                                              WHERE em.user_id=u.id AND eg.is_admin=1) AS is_admin_user
                               FROM users u ORDER BY u.id ASC")->fetchAll();
    foreach ($entryRows as $er) {
        if (!can_manage_user($pdo, (int)$er['id'])) continue;
        if ((int)$er['is_admin_user'] === 1) $entryAdminUsers[] = $er;
        else                                 $entryNormalUsers[] = $er;
    }
}

// 会话有效期（秒）-> 数字 + 单位回显
$lifeSeconds = (int)$s['session_lifetime'];
$lifeNum = 0; $lifeUnit = 3600;
if ($lifeSeconds > 0) {
    if ($lifeSeconds % 86400 === 0)      { $lifeNum = $lifeSeconds / 86400; $lifeUnit = 86400; }
    elseif ($lifeSeconds % 3600 === 0)   { $lifeNum = $lifeSeconds / 3600;  $lifeUnit = 3600; }
    elseif ($lifeSeconds % 60 === 0)     { $lifeNum = $lifeSeconds / 60;    $lifeUnit = 60; }
    else { $lifeNum = $lifeSeconds; $lifeUnit = 1; }
}

function syntax_select(string $name, string $current): string {
    $opts = [
        'text' => '纯文本（自动转义换行）',
        'html' => 'HTML（原生，支持内嵌 script/style）',
        'md'   => 'Markdown（轻量标记语法）',
    ];
    $out = '<select name="' . e($name) . '" class="syntax-select">';
    foreach ($opts as $k => $label) {
        $out .= '<option value="' . e($k) . '"' . ($current === $k ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $out . '</select>';
}

function toggle_checkbox(string $name, string $current, string $label, string $hint = ''): string {
    $h = $hint !== '' ? '<span class="grp-hint">' . e($hint) . '</span>' : '';
    return '<label class="set-toggle">'
         . '<input type="checkbox" name="' . e($name) . '" value="1"' . ($current === '1' ? ' checked' : '') . '>'
         . '<span>' . e($label) . '<br>' . $h . '</span></label>';
}

/** 单个用户的「我的设置」入口开关表单（开=普通样式 / 关=红色） */
function entry_toggle_form(array $u): string {
    $on = (int)($u['settings_entry'] ?? 1) === 1;
    $next = $on ? 0 : 1;
    $confirmMsg = $on
        ? '确定关闭该用户的「我的设置」入口？入口将立即从顶栏隐藏，直接访问也会被拦截。'
        : '确定开启该用户的「我的设置」入口？';
    $out = '<form method="post" class="entry-toggle-form" action="/admin/settings.html" '
         . 'onsubmit="return confirm(\'' . $confirmMsg . '\');">';
    $out .= '<input type="hidden" name="action" value="toggle_entry">';
    $out .= '<input type="hidden" name="id" value="' . (int)$u['id'] . '">';
    $out .= '<input type="hidden" name="enable" value="' . $next . '">';
    $out .= '<button type="submit" class="btn-mini' . ($on ? '' : ' danger') . '">'
          . ($on ? '⚙️ 入口：开' : '🚫 入口：关') . '</button>';
    $out .= '</form>';
    return $out;
}

$pageTitle = '网站参数设置';
require __DIR__ . '/../includes/header.php';
?>
<div class="admin-layout">
  <?php render_admin_sidebar('settings'); ?>

  <section class="admin-main">
    <h2>网站参数设置</h2>
    <?php if ($canSettings): ?>
    <p class="page-sub">配置站点基础信息、顶部导航、登录策略、显示开关与自定义代码。</p>
    <?php if (isset($_GET['saved'])): ?><p class="form-success">已保存，全站即时生效。</p><?php endif; ?>
    <p class="admin-tip">内容支持 <strong>纯文本 / HTML / Markdown</strong>；所有图标支持 <strong>emoji 或图片 URL</strong>。拥有 manage_settings 权限的管理员均可修改（可经权限矩阵/二级授权）。</p>
    <?php else: ?>
    <p class="page-sub">在此管理每个用户的「我的设置」入口。</p>
    <?php endif; ?>

    <?php if ($canSettings): ?>
    <form method="post" action="/admin/settings.html">

      <!-- ① 基础信息 -->
      <div class="set-cat">
        <div class="set-cat-title"><span class="set-cat-no">1</span> 基础信息</div>
        <label class="lc-field" style="margin-bottom:12px">
          <span class="lc-label">站点名称</span>
          <input type="text" name="site_name" class="lc-input" value="<?= e($s['site_name']) ?>">
        </label>
        <label class="lc-field">
          <span class="lc-label">站点 Logo（emoji 或图片 URL）</span>
          <div class="logo-preview-row">
            <input type="text" name="site_logo" class="lc-input" id="logoInput" value="<?= e($s['site_logo']) ?>" placeholder="🏠">
            <span class="logo-preview" id="logoPreview"><?= icon_html((string)$s['site_logo']) ?></span>
          </div>
        </label>
      </div>

      <!-- ② 顶部导航 -->
      <div class="set-cat">
        <div class="set-cat-title"><span class="set-cat-no">2</span> 顶部导航（按钮显隐与自定义链接）</div>
        <div class="set-toggles">
          <?= toggle_checkbox('top_show_admin', (string)$s['top_show_admin'], '显示「后台管理」', '仅对管理员生效') ?>
          <?= toggle_checkbox('top_show_password', (string)$s['top_show_password'], '显示「修改密码」') ?>
          <?= toggle_checkbox('top_show_logout', (string)$s['top_show_logout'], '显示「退出」') ?>
          <?= toggle_checkbox('top_show_theme', (string)$s['top_show_theme'], '显示「主题」', '至少 2 套可见皮肤时展示') ?>
        </div>

        <div class="custom-links-box">
          <div class="custom-links-head">
            <span class="lc-label">自定义顶部链接（可增删改；图标支持 emoji/图片 URL；可设置访客可见度）</span>
            <button type="button" class="btn-mini" id="addLinkBtn">➕ 新增链接</button>
          </div>
          <div class="link-row link-row-head">
            <span>图标</span><span>链接文字</span><span>链接 URL</span><span>可见度</span><span></span>
          </div>
          <div id="linksBox">
            <?php foreach ($customLinks as $l): ?>
              <div class="link-row">
                <input type="text" name="l_icon[]" placeholder="emoji/URL" value="<?= e($l['icon'] ?? '') ?>">
                <input type="text" name="l_text[]" placeholder="链接文字" value="<?= e($l['text'] ?? '') ?>">
                <input type="text" name="l_url[]" placeholder="https://... 或 /xxx.html" value="<?= e($l['url'] ?? '') ?>">
                <select name="l_vis[]" class="l-vis-select">
                  <option value="guest" <?= ($l['vis'] ?? 'guest') === 'guest' ? 'selected' : '' ?>>👀 访客可见</option>
                  <option value="login" <?= ($l['vis'] ?? '') === 'login' ? 'selected' : '' ?>>🔒 仅登录可见</option>
                </select>
                <button type="button" class="btn-mini danger del-link">删除</button>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- ③ 底部版权 -->
      <div class="set-cat">
        <div class="set-cat-title"><span class="set-cat-no">3</span> 底部版权信息（可留空，留空显示默认文字）</div>
        <label class="lc-field" style="margin-bottom:10px">
          <span class="lc-label">内容语法</span>
          <?= syntax_select('site_footer_type', (string)$s['site_footer_type']) ?>
        </label>
        <label class="lc-field">
          <span class="lc-label">版权内容</span>
          <textarea name="site_footer" class="lc-textarea code-editor" rows="3"
                    placeholder="© 2026 我的门户 · 保留所有权利&#10;HTML：<a href=&quot;/about.html&quot;>关于我们</a> | <a href=&quot;/privacy.html&quot;>隐私政策</a>&#10;Markdown：[关于我们](/about.html) | [隐私政策](/privacy.html)"><?= e($s['site_footer']) ?></textarea>
        </label>
      </div>

      <!-- ④ 自定义代码 -->
      <div class="set-cat">
        <div class="set-cat-title"><span class="set-cat-no">4</span> 自定义代码（全站生效）</div>
        <label class="lc-field" style="margin-bottom:12px">
          <span class="lc-label">自定义 CSS（输出到每页 &lt;head&gt;）</span>
          <textarea name="custom_css" class="lc-textarea code-editor" rows="5"
                    placeholder=".nav-card { border-radius: 20px; }&#10;.footer { background: #0f172a; color: #fff; }"><?= e($s['custom_css']) ?></textarea>
        </label>
        <label class="lc-field">
          <span class="lc-label">自定义 JavaScript（输出到每页底部）</span>
          <textarea name="custom_js" class="lc-textarea code-editor" rows="5"
                    placeholder="document.addEventListener('DOMContentLoaded', function () {&#10;  // your code&#10;});"><?= e($s['custom_js']) ?></textarea>
        </label>
      </div>

      <!-- ⑤ 登录与会话 -->
      <div class="set-cat set-cat-danger">
        <div class="set-cat-title"><span class="set-cat-no">5</span> 登录与会话</div>

        <div class="session-life-row">
          <span class="lc-label" style="min-width:110px">登录状态有效期</span>
          <input type="number" name="life_num" class="life-num-input" min="0" value="<?= (int)$lifeNum ?>">
          <select name="life_unit" class="life-unit-select">
            <option value="60"    <?= $lifeUnit === 60    ? 'selected' : '' ?>>分钟</option>
            <option value="3600"  <?= $lifeUnit === 3600  ? 'selected' : '' ?>>小时</option>
            <option value="86400" <?= $lifeUnit === 86400 ? 'selected' : '' ?>>天</option>
          </select>
          <span class="grp-hint">填 0 时登录状态在浏览器关闭后失效；有效期内无操作超过该时长需重新登录。</span>
        </div>

        <label class="set-toggle" style="margin-top:12px">
          <input type="checkbox" name="login_disabled" value="1" <?= ($s['login_disabled'] ?? '0') === '1' ? 'checked' : '' ?> style="margin-top:3px">
          <span>
            <strong style="color:var(--danger)">🚫 关闭所有人登录</strong><br>
            <span class="grp-hint">勾选后除系统管理员（超级管理员组）外，所有用户（含普通管理员）无法登录，已登录会话被强制踢出。用于紧急维护。</span>
          </span>
        </label>

        <div class="session-life-row" style="margin-top:14px">
          <span class="lc-label" style="min-width:130px">最大登录设备数 / 人</span>
          <input type="number" name="max_login_devices" class="life-num-input" min="0" max="100"
                 value="<?= (int)($s['max_login_devices'] ?? 0) ?>">
          <span class="grp-hint">每个账号允许同时在线的最大设备数量；填 0 = 不限制。超过时新设备登录会被拒绝，需在其他设备退出或由管理员下线。</span>
        </div>
      </div>

      <!-- ⑥ 界面显示开关 -->
      <div class="set-cat">
        <div class="set-cat-title"><span class="set-cat-no">6</span> 界面显示开关</div>
        <div class="set-toggles">
          <?= toggle_checkbox('home_show_weather', (string)$s['home_show_weather'],
              '首页显示当地天气',
              '免 key 公开接口（Open-Meteo）；优先浏览器精确定位，用户拒绝时按 IP 粗定位，均失败则不显示') ?>
          <?= toggle_checkbox('users_show_last_login', (string)$s['users_show_last_login'],
              '用户管理列表显示「上次登录」列',
              '显示每个用户最近一次登录时间；鼠标悬停可查看登录 IP') ?>
        </div>
      </div>

      <div class="form-row" style="margin:18px 0">
        <button type="submit" class="btn-primary" style="min-width:160px">💾 保存全部参数</button>
      </div>
    </form>
    <?php endif; ?>

    <!-- ⑦ 用户设置入口 -->
    <?php if ($canEntry): ?>
    <?php
    // 筛选下拉用：全部用户组名（按「、」拆分去重）
    $entryGroupNames = [];
    foreach (array_merge($entryAdminUsers, $entryNormalUsers) as $u) {
        foreach (explode('、', (string)$u['group_name']) as $gn) {
            $gn = trim($gn);
            if ($gn !== '') $entryGroupNames[$gn] = true;
        }
    }
    $entryGroupNames = array_keys($entryGroupNames);
    sort($entryGroupNames);
    ?>
    <div class="set-cat" id="entry">
      <div class="set-cat-title"><span class="set-cat-no">7</span> 用户设置入口管理</div>
      <?php if (isset($_GET['entrysaved'])): ?><p class="form-success">入口状态已更新，即时生效。</p><?php endif; ?>
      <?php if ($entryError !== ''): ?><p class="form-error"><?= e($entryError) ?></p><?php endif; ?>
      <p class="admin-tip">关闭后该用户的顶栏「⚙️ 设置」入口立即隐藏，直接访问 <code>/settings.html</code> 也会被拦截，用户无需重新登录。此处仅显示您管理范围内的用户。</p>

      <!-- 筛选条（纯前端即时过滤） -->
      <div class="entry-filter">
        <input type="text" id="entryKw" class="entry-filter-kw" placeholder="按用户名 / 显示名搜索…">
        <select id="entryGroup" class="entry-filter-sel">
          <option value="">全部用户组</option>
          <?php foreach ($entryGroupNames as $gn): ?>
          <option value="<?= e($gn) ?>"><?= e($gn) ?></option>
          <?php endforeach; ?>
        </select>
        <select id="entryType" class="entry-filter-sel">
          <option value="">管理员与普通用户</option>
          <option value="admin">仅管理员</option>
          <option value="normal">仅普通用户</option>
        </select>
      </div>

      <?php
      /** 渲染一类用户的入口开关组；$typeKey=admin/normal 供筛选 */
      function render_entry_group(string $title, array $users, string $typeKey): void {
          echo '<div class="entry-group" data-entry-group="' . e($typeKey) . '">';
          echo '<div class="entry-group-title">' . e($title) . '（' . count($users) . '）</div>';
          if (!$users) {
              echo '<p class="grp-hint">暂无可管理的该类用户。</p>';
          } else {
              echo '<div class="entry-grid">';
              foreach ($users as $u) {
                  $dn = (string)$u['display_name'] !== '' ? $u['display_name'] : $u['username'];
                  $nameHay = $u['username'] . ' ' . $dn . ' ' . (string)$u['group_name'];
                  echo '<div class="entry-cell" data-name="' . e($nameHay) . '"'
                     . ' data-groups="' . e((string)$u['group_name']) . '">';
                  echo '<span class="entry-cell-name">' . e($u['username']) . '</span>';
                  echo '<span class="entry-cell-dn">' . e($dn) . '</span>';
                  echo entry_toggle_form($u);
                  echo '</div>';
              }
              echo '</div>';
          }
          echo '</div>';
      }
      render_entry_group('🛡️ 管理员', $entryAdminUsers, 'admin');
      render_entry_group('👥 普通用户', $entryNormalUsers, 'normal');
      ?>
    </div>
    <script>
    (function () {
      var kw = document.getElementById('entryKw');
      var grp = document.getElementById('entryGroup');
      var typ = document.getElementById('entryType');
      if (!kw) return;
      function applyFilter() {
        var k = kw.value.trim().toLowerCase();
        var g = grp.value;
        var t = typ.value;
        var hasFilter = k !== '' || g !== '' || t !== '';
        document.querySelectorAll('.entry-group').forEach(function (eg) {
          var isAdminGroup = eg.getAttribute('data-entry-group') === 'admin';
          var groupHiddenByType = (t === 'admin' && !isAdminGroup)
                               || (t === 'normal' && isAdminGroup);
          var shown = 0;
          if (!groupHiddenByType) {
            eg.querySelectorAll('.entry-cell').forEach(function (c) {
              var ok = true;
              if (k !== '' && c.getAttribute('data-name').toLowerCase().indexOf(k) === -1) ok = false;
              if (ok && g !== '' && (c.getAttribute('data-groups') || '').split('、').indexOf(g) === -1) ok = false;
              c.style.display = ok ? '' : 'none';
              if (ok) shown++;
            });
          }
          eg.style.display = (groupHiddenByType || (hasFilter && shown === 0)) ? 'none' : '';
        });
      }
      kw.addEventListener('input', applyFilter);
      grp.addEventListener('change', applyFilter);
      typ.addEventListener('change', applyFilter);
    })();
    </script>
    <?php endif; ?>
  </section>
</div>

<script>
// Logo 实时预览
var logoInput = document.getElementById('logoInput');
var logoPreview = document.getElementById('logoPreview');
logoInput.addEventListener('input', function () {
  var v = logoInput.value.trim();
  if (v === '') { logoPreview.innerHTML = ''; return; }
  // 与后端 icon_is_image_url 同口径（含 .ico；支持 data:image/x-icon、URL 中间含扩展名）
  var isUrl = /^(https?:)?\/\//i.test(v)
           || /^data:image\//i.test(v)
           || /\.(png|jpe?g|gif|svg|webp|avif|bmp|ico)(?:[?#]|$)/i.test(v);
  logoPreview.innerHTML = isUrl
    ? '<img src="' + v.replace(/"/g, '&quot;') + '" alt="" onerror="this.style.visibility=\'hidden\'">'
    : '<span>' + v.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</span>';
});

// 自定义链接：新增 / 删除
var linksBox = document.getElementById('linksBox');
function bindDel(btn) {
  btn.addEventListener('click', function () { btn.closest('.link-row').remove(); });
}
linksBox.querySelectorAll('.del-link').forEach(bindDel);

document.getElementById('addLinkBtn').addEventListener('click', function () {
  var row = document.createElement('div');
  row.className = 'link-row';
  row.innerHTML =
    '<input type="text" name="l_icon[]" placeholder="emoji/URL">' +
    '<input type="text" name="l_text[]" placeholder="链接文字">' +
    '<input type="text" name="l_url[]" placeholder="https://... 或 /xxx.html">' +
    '<select name="l_vis[]" class="l-vis-select">' +
      '<option value="guest">👀 访客可见</option>' +
      '<option value="login">🔒 仅登录可见</option>' +
    '</select>' +
    '<button type="button" class="btn-mini danger del-link">删除</button>';
  linksBox.appendChild(row);
  bindDel(row.querySelector('.del-link'));
  row.querySelector('input').focus();
});
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
