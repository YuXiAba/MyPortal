<?php
/**
 * 主题皮肤管理
 * ============================================
 * 两类权限（均可经权限矩阵分配、可二级授权）：
 *   - manage_themes：查看皮肤、控制对用户可见性、设置站点默认皮肤；
 *   - manage_theme_meta：修改皮肤名称/版本/作者/说明/暗色类型/可见用户组。
 * 持任一权限即可进入本页，按钮与接口分别按权限守卫。
 */
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
// 本页在 require header.php 之前即调用 all_themes()/available_themes() 等，
// 而皮肤函数定义在 themes.php（header.php 才会加载），故必须提前显式引入
require_once __DIR__ . '/../includes/themes.php';
require_admin();

$canThemes = has_perm('manage_themes');
$canMeta   = has_perm('manage_theme_meta');
if (!$canThemes && !$canMeta) {
    header('Location: /index.html'); exit;
}

/** 读取皮肤可见性原始 map（id=>1/0；缺省=可见） */
function themes_raw_map(PDO $pdo): array {
    $s = site_settings($pdo);
    $raw = json_decode((string)($s['themes_enabled'] ?? ''), true);
    return is_array($raw) ? $raw : [];
}

/** 保存可见性 map（仅记录 0=隐藏；可见的默认值不落库，保持 JSON 精简） */
function save_themes_map(PDO $pdo, array $map): void {
    $stored = [];
    foreach (all_themes() as $id => $t) {
        if (isset($map[$id]) && (int)$map[$id] === 0) $stored[$id] = 0;
    }
    $up = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('themes_enabled', ?)
                         ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    $up->execute([json_encode($stored, JSON_UNESCAPED_UNICODE)]);
    // site_settings 有缓存，本页后续重新查询需清缓存
    site_settings_reset();
}

$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'set_default' && $canThemes) {
        $id = (string)($_POST['id'] ?? '');
        $themes = available_themes($pdo);
        if (isset($themes[$id])) {
            $up = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('theme_default', ?)
                                 ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
            $up->execute([$id]);
            site_settings_reset();
            write_log($pdo, 'update', '主题皮肤', '设置站点默认皮肤：' . $themes[$id]['name']);
            $saved = true;
        }
    } elseif ($action === 'toggle' && $canThemes) {
        $id = (string)($_POST['id'] ?? '');
        $enabled = (string)($_POST['enabled'] ?? '0') === '1';
        $all = all_themes();
        if (isset($all[$id]) && $id !== 'default') {
            $map = themes_raw_map($pdo);
            $map[$id] = $enabled ? 1 : 0;
            save_themes_map($pdo, $map);
            // 若隐藏的是当前默认皮肤，默认皮肤回退 default
            $s = site_settings($pdo);
            if (!$enabled && (string)$s['theme_default'] === $id) {
                $pdo->exec("UPDATE settings SET setting_value='default' WHERE setting_key='theme_default'");
                site_settings_reset();
            }
            write_log($pdo, 'update', '主题皮肤',
                ($enabled ? '显示皮肤：' : '隐藏皮肤：') . $all[$id]['name']);
            $saved = true;
        }
    } elseif ($action === 'edit_meta' && $canMeta) {
        $id  = trim((string)($_POST['id'] ?? ''));
        $all = all_themes();
        if (isset($all[$id])) {
            // 删除覆盖：恢复内置注册值 / CSS 注释头
            if (!empty($_POST['reset_override'])) {
                $pdo->prepare("DELETE FROM theme_overrides WHERE theme_id=?")->execute([$id]);
                all_themes_reset();
                write_log($pdo, 'update', '主题皮肤', '恢复皮肤原始元数据：' . $all[$id]['name']);
                $saved = true;
            } else {
                $name    = trim((string)($_POST['name'] ?? ''));
                $author  = trim((string)($_POST['author'] ?? ''));
                $version = trim((string)($_POST['version'] ?? ''));
                $desc    = trim((string)($_POST['description'] ?? ''));
                $dark    = (int)($_POST['dark'] ?? -1);
                if ($name === '') {
                    $error = '皮肤名称不能为空。';
                } elseif (mb_strlen($name) > 60 || mb_strlen($author) > 60
                       || mb_strlen($version) > 20 || mb_strlen($desc) > 255) {
                    $error = '字段超长：名称/作者最多 60 字，版本最多 20 字，说明最多 255 字。';
                } elseif (!in_array($dark, [-1, 0, 1], true)) {
                    $error = '暗色类型取值非法。';
                } else {
                    // 可见用户组：仅保留真实存在的组；default 皮肤始终可见、强制不限
                    $vgIds = [];
                    if ($id !== 'default' && isset($_POST['visible_groups'])
                        && is_array($_POST['visible_groups'])) {
                        foreach ($_POST['visible_groups'] as $v) $vgIds[] = (int)$v;
                        $vgIds = array_values(array_unique(array_filter($vgIds)));
                        if ($vgIds) {
                            $in = implode(',', $vgIds);
                            $existRows = $pdo->query("SELECT id FROM user_groups WHERE id IN ($in)")
                                              ->fetchAll(PDO::FETCH_COLUMN);
                            $vgIds = array_map('intval', $existRows);
                        }
                    }
                    save_theme_override($pdo, $id, [
                        'name' => $name, 'author' => $author, 'version' => $version,
                        'description' => $desc, 'dark' => $dark, 'visible_groups' => $vgIds,
                    ]);
                    all_themes_reset();
                    write_log($pdo, 'update', '主题皮肤', "修改皮肤元数据：{$name}"
                        . ($vgIds ? '（仅 ' . count($vgIds) . ' 个用户组可见）' : '（所有用户组可见）'));
                    $saved = true;
                }
            }
        }
    }
    // 仅在成功且无错误时跳转，避免出错时丢失错误信息
    if ($saved && empty($error)) {
        header('Location: /admin/themes.html?saved=1');
        exit;
    }
}

$all       = all_themes();
$enabled   = theme_enabled_map($pdo);
$settings  = site_settings($pdo);
$defaultId = (string)($settings['theme_default'] ?? 'default');
if (!isset($all[$defaultId])) $defaultId = 'default';

// 全部用户组（编辑可见用户组用），并建 id=>行 映射
$groupRows = $pdo->query("SELECT id, name, is_admin, level FROM user_groups ORDER BY level, id")
                 ->fetchAll(PDO::FETCH_ASSOC);
$groupMap = [];
foreach ($groupRows as $g) $groupMap[(int)$g['id']] = $g;

// 原始覆盖行（编辑表单回显空字段占位时使用）
$ovRawMap = theme_overrides_map($pdo);

$pageTitle = '主题皮肤管理';
require __DIR__ . '/../includes/header.php';
?>
<div class="admin-layout">
  <?php render_admin_sidebar('themes'); ?>

  <section class="admin-main">
    <h2>主题皮肤管理</h2>
    <p class="page-sub">控制皮肤对用户的可见性、设置站点默认皮肤，并可修改各皮肤的元数据。</p>
    <?php if (isset($_GET['saved'])): ?><p class="form-success">已保存。</p><?php endif; ?>
    <?php if (!empty($error)): ?><p class="form-error"><?= e($error) ?></p><?php endif; ?>

    <p class="admin-tip">
      🔐 本页两个权限——「主题皮肤管理」（可见性/默认皮肤）与「皮肤元数据管理」（名称/版本/作者/暗色类型/可见用户组），
      均可在「权限矩阵」分配给其他管理员组，也可经「用户级授权」二级授权给单个用户。
      用户在 <a href="/theme.html" target="_blank">主题页</a> 只能看到对其可见的皮肤：
      全不选可见用户组=所有用户及游客可见；勾选后仅勾选组的成员可见。
      自定义皮肤开发请参见 <code>docs/主题皮肤开发文档.md</code>。
    </p>

    <table class="data-table">
      <thead><tr><th>预览</th><th>皮肤</th><th>版本/作者</th><th>类型</th><th>站点默认</th><th>对用户可见</th><th>操作</th></tr></thead>
      <tbody>
        <?php foreach ($all as $t):
          $isDefault = $t['id'] === $defaultId;
          $isEnabled = !empty($enabled[$t['id']]);
          $vgIds     = isset($t['visible_groups']) && is_array($t['visible_groups']) ? $t['visible_groups'] : [];
          $ov        = $ovRawMap[$t['id']] ?? null;
        ?>
          <tr>
            <td>
              <span class="adm-swatch-row">
                <?php $sw = !empty($t['swatches']) ? array_slice($t['swatches'], 0, 3) : [];
                if (!$sw) { $sw = ['#2563eb', '#1d4ed8', '#eef2ff']; }
                foreach ($sw as $c): ?>
                  <i class="adm-swatch" style="background:<?= e($c) ?>"></i>
                <?php endforeach; ?>
              </span>
            </td>
            <td>
              <strong><?= e($t['name']) ?></strong>
              <?php if (!empty($t['dark'])): ?><span class="theme-dark-tag">暗色</span><?php endif; ?>
              <?php if (!empty($t['overridden'])): ?><span class="grp-hint" title="元数据已被后台自定义">✏️</span><?php endif; ?>
              <br><span class="grp-hint"><?= e($t['desc']) ?></span>
            </td>
            <td class="nowrap">v<?= e($t['version']) ?><br><span class="grp-hint"><?= e($t['author']) ?></span></td>
            <td><?= !empty($t['builtin']) ? '内置' : '自定义' ?></td>
            <td>
              <?php if ($isDefault): ?>
                <span class="theme-current-tag">✓ 默认</span>
              <?php elseif ($isEnabled && $canThemes): ?>
                <form method="post" action="/admin/themes.html">
                  <input type="hidden" name="action" value="set_default">
                  <input type="hidden" name="id" value="<?= e($t['id']) ?>">
                  <button type="submit" class="btn-mini">设为默认</button>
                </form>
              <?php else: ?>
                <span class="grp-hint">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($t['id'] === 'default'): ?>
                <span class="grp-hint">始终可见</span>
              <?php elseif ($canThemes): ?>
                <form method="post" action="/admin/themes.html">
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?= e($t['id']) ?>">
                  <input type="hidden" name="enabled" value="<?= $isEnabled ? '0' : '1' ?>">
                  <button type="submit" class="btn-mini <?= $isEnabled ? 'danger' : '' ?>">
                    <?= $isEnabled ? '隐藏' : '显示' ?>
                  </button>
                </form>
              <?php else: ?>
                <span class="grp-hint"><?= $isEnabled ? '已显示' : '已隐藏' ?></span>
              <?php endif; ?>
              <?php if ($vgIds): ?>
                <br><span class="grp-hint" title="<?= e(implode('、', array_map(function($gid) use ($groupMap) {
                    return isset($groupMap[$gid]) ? $groupMap[$gid]['name'] : '#'.$gid;
                }, $vgIds))) ?>">限 <?= count($vgIds) ?> 个用户组</span>
              <?php endif; ?>
            </td>
            <td class="nowrap">
              <?php if ($canMeta): ?>
                <button class="btn-mini" onclick="toggleMeta('<?= e($t['id']) ?>')">✏️ 编辑元数据</button>
              <?php endif; ?>
              <a class="btn-mini" href="/theme.html" target="_blank">主题页</a>
            </td>
          </tr>
          <?php if ($canMeta): ?>
          <tr id="meta-<?= e($t['id']) ?>" class="edit-row" style="display:none">
            <td colspan="7">
              <form method="post" class="admin-form" action="/admin/themes.html">
                <input type="hidden" name="action" value="edit_meta">
                <input type="hidden" name="id" value="<?= e($t['id']) ?>">
                <div class="form-row">
                  <label class="inline-label">皮肤 ID
                    <input type="text" value="<?= e($t['id']) ?>" readonly style="width:110px">
                  </label>
                  <label class="inline-label">皮肤名称
                    <input type="text" name="name" required maxlength="60" style="width:150px"
                           value="<?= e((string)($ov['name'] ?? '') ?: $t['name']) ?>">
                  </label>
                  <label class="inline-label">版本
                    <input type="text" name="version" maxlength="20" style="width:90px"
                           value="<?= e((string)($ov['version'] ?? '') ?: $t['version']) ?>">
                  </label>
                  <label class="inline-label">作者
                    <input type="text" name="author" maxlength="60" style="width:140px"
                           value="<?= e((string)($ov['author'] ?? '') ?: $t['author']) ?>">
                  </label>
                  <label class="inline-label">暗色类型
                    <select name="dark">
                      <?php $darkOv = $ov !== null ? (int)$ov['dark'] : -1; ?>
                      <option value="-1" <?= $darkOv===-1?'selected':'' ?>>跟随原始（<?= !empty($t['dark'])?'暗色':'浅色'?>）</option>
                      <option value="1"  <?= $darkOv===1 ?'selected':'' ?>>强制暗色</option>
                      <option value="0"  <?= $darkOv===0 ?'selected':'' ?>>强制浅色</option>
                    </select>
                  </label>
                </div>
                <div class="form-row">
                  <label class="inline-label" style="flex:1">皮肤说明
                    <input type="text" name="description" maxlength="255" style="width:100%"
                           value="<?= e((string)($ov['description'] ?? '') ?: $t['desc']) ?>">
                  </label>
                </div>
                <div class="perm-box">
                  <span class="perm-label">可见用户组（全不选 = 所有用户及游客可见；勾选后仅勾选组成员可见<?= $t['id']==='default'?'；默认皮肤始终可见，此项不生效':''?>）：</span>
                  <div>
                    <?php if (empty($groupRows)): ?><span class="grp-hint">当前没有用户组。</span><?php endif; ?>
                    <?php
                      $selVg = $ov !== null ? override_visible_group_ids($ov) : [];
                      foreach ($groupRows as $g):
                        $disabled = $t['id'] === 'default';
                    ?>
                      <label class="chk"><input type="checkbox" name="visible_groups[]"
                        value="<?= (int)$g['id'] ?>"
                        <?= $disabled ? 'disabled title="默认皮肤始终可见"' : '' ?>
                        <?= in_array((int)$g['id'], $selVg, true) ? 'checked' : '' ?>>
                        <?= e($g['name']) ?><?= (int)$g['is_admin']===1?'（管理员组）':'' ?></label>
                    <?php endforeach; ?>
                  </div>
                </div>
                <div class="form-row">
                  <label class="chk"><input type="checkbox" name="reset_override" value="1"
                    onclick="return confirm('勾选并保存后，将删除该皮肤全部自定义元数据，恢复为内置/文件注释头的原始值，确定？')">
                    清除全部自定义，恢复原始元数据</label>
                  <button type="submit" class="btn-primary">保存元数据</button>
                </div>
              </form>
            </td>
          </tr>
          <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>
</div>

<script>
function toggleMeta(id) {
  var row = document.getElementById('meta-' + id);
  row.style.display = row.style.display === 'none' ? '' : 'none';
}
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
