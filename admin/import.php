<?php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require_admin();
require_perm('import_users');

// 下载官方 CSV 模板（中文表头，带 UTF-8 BOM，Excel 直接打开不乱码）
if (isset($_GET['template'])) {
    $csv = "\xEF\xBB\xBF用户名,密码,显示名,用户组\r\n"
         . "zhangsan,123456,张三,普通用户组\r\n"
         . "lisi,abc123,李四,普通用户组\r\n"
         . "wangwu,pwd123,王五,管理员组\r\n"
         . "zhaoliu,pwd456,赵六,普通用户组|管理员组\r\n";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="users-import-template.csv"; '
         . "filename*=UTF-8''" . rawurlencode('批量导入用户信息.csv'));
    header('Content-Length: ' . strlen($csv));
    echo $csv;
    exit;
}

// 普通管理员仅能向其被分配的"可管理用户组"导入；超级管理员不限
$groups = allowed_target_groups($pdo);
$groupByName = [];
foreach ($groups as $g) {
    $groupByName[$g['name']] = (int)$g['id'];
}

$result = ['imported' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];

/**
 * 判断一行是否为表头：兼容中文「用户名…」与英文「username…」
 * （官方模板首行：用户名,密码,显示名,用户组）
 */
function is_header_line(string $line): bool {
    return preg_match('/用户名|username/i', trim($line)) === 1;
}

/**
 * 解析一行 CSV：用户名,密码[,显示名][,用户组]
 * 用户组列支持多组：以 | 或 ; 分隔多个组名（如 普通用户组|管理员组）。
 * 使用 str_getcsv，兼容带引号、字段内含逗号的标准 CSV。
 * 返回数组或 null（空行/注释行）。
 */
function parse_line(string $line): ?array {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || $line[0] === ';') {
        return null; // 空行或注释行
    }
    $parts = array_map(fn($v) => trim((string)$v), str_getcsv($line));
    $username = $parts[0] ?? '';
    $password = $parts[1] ?? '';
    $display  = isset($parts[2]) && $parts[2] !== '' ? $parts[2] : null;
    $groupNames = [];
    if (isset($parts[3]) && trim($parts[3]) !== '') {
        // 多个用户组用 | 或 ; 分隔
        foreach (preg_split('/[|;；]/', $parts[3]) as $gn) {
            $gn = trim($gn);
            if ($gn !== '') $groupNames[] = $gn;
        }
    }
    if ($username === '' || $password === '') {
        return ['invalid' => true, 'raw' => $line];
    }
    return ['username' => $username, 'password' => $password, 'display' => $display,
            'group_names' => array_values(array_unique($groupNames)), 'invalid' => false];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $defaultGroupId = ($_POST['default_group_id'] ?? '') === '' ? null : (int)$_POST['default_group_id'];
    // 普通管理员的默认用户组必须在其可管理范围内
    if ($defaultGroupId !== null && !is_super()) {
        $scope = manageable_group_ids($pdo) ?? [];
        if (!in_array($defaultGroupId, $scope, true)) {
            $defaultGroupId = null;
        }
    }
    $lines = [];

    // 方式一：文本批量粘贴
    if (isset($_POST['text_import']) && trim($_POST['text_import']) !== '') {
        $lines = preg_split('/\r\n|\r|\n/', $_POST['text_import']);
    }

    // 方式二：上传 CSV 文件
    if (isset($_FILES['csv_file']) && ($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $tmp = $_FILES['csv_file']['tmp_name'];
        $raw = @file_get_contents($tmp);
        if ($raw !== false) {
            $raw = trim($raw);
            // 兼容 UTF-8 BOM 与 GBK 编码
            if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
                $raw = substr($raw, 3);
            }
            if (!mb_check_encoding($raw, 'UTF-8')) {
                $raw = mb_convert_encoding($raw, 'UTF-8', 'GBK');
            }
            $fileLines = preg_split('/\r\n|\r|\n/', $raw);
            $lines = array_merge($lines, $fileLines);
        }
    }

    // 统一跳过首个有效表头行（兼容官方模板中文表头与旧英文表头；
    // 粘贴文本和上传文件均生效）
    foreach ($lines as $idx => $ln) {
        $t = trim($ln);
        if ($t === '' || $t[0] === '#' || $t[0] === ';') continue;
        if (is_header_line($t)) unset($lines[$idx]);
        break;
    }

    if ($lines) {
        // 归属统一写 user_group_members 关联表，users.group_id 仅作主组缓存
        $ins = $pdo->prepare("INSERT INTO users (username, password, display_name) VALUES (?,?,?)");
        $mmIns = $pdo->prepare("INSERT IGNORE INTO user_group_members (user_id, group_id) VALUES (?,?)");
        $chk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");

        foreach ($lines as $line) {
            $parsed = parse_line($line);
            if ($parsed === null) {
                continue; // 空/注释行
            }
            if (!empty($parsed['invalid'])) {
                $result['failed']++;
                $result['errors'][] = "非法行：{$parsed['raw']}（需 用户名,密码）";
                continue;
            }

            // 去重：已存在则跳过
            $chk->execute([$parsed['username']]);
            if ((int)$chk->fetchColumn() > 0) {
                $result['skipped']++;
                $result['errors'][] = "跳过（已存在）：{$parsed['username']}";
                continue;
            }

            // 解析用户组：行内指定了组则按行内为准（支持多组），否则使用默认用户组
            $gids = [];
            if (!empty($parsed['group_names'])) {
                $missing = [];
                foreach ($parsed['group_names'] as $gname) {
                    if (isset($groupByName[$gname])) {
                        $gids[] = $groupByName[$gname];
                    } else {
                        $missing[] = $gname;
                    }
                }
                if ($missing) {
                    $result['failed']++;
                    $result['errors'][] = "用户组不存在：" . implode('、', $missing)
                                        . "（用户 {$parsed['username']} 未导入）";
                    continue;
                }
                $gids = array_values(array_unique($gids));
            } elseif ($defaultGroupId !== null) {
                $gids[] = $defaultGroupId;
            }

            $display = $parsed['display'] !== null ? $parsed['display'] : $parsed['username'];
            $ins->execute([$parsed['username'], md5($parsed['password']), $display]);
            $newId = (int)$pdo->lastInsertId();
            foreach ($gids as $gid) {
                $mmIns->execute([$newId, $gid]);
            }
            sync_primary_group($pdo, $newId);
            $result['imported']++;
        }
    } else {
        $result['errors'][] = '未收到可导入的数据。';
    }
    write_log($pdo, 'import', '批量导入用户',
              "批量导入：成功 {$result['imported']}，跳过 {$result['skipped']}，失败 {$result['failed']}",
              $result['failed'] > 0 ? 'fail' : 'success');
}

$pageTitle = '批量导入用户';
require __DIR__ . '/../includes/header.php';
?>
<div class="admin-layout">
  <?php render_admin_sidebar('users'); ?>

  <section class="admin-main">
    <h2>批量导入用户</h2>
    <p class="page-sub">粘贴文本或上传 CSV，批量创建用户账号。</p>

    <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
      <div class="admin-form">
        <p><strong>导入结果：</strong>
          成功导入 <strong class="ok"><?= $result['imported'] ?></strong> 条，
          跳过（已存在）<strong><?= $result['skipped'] ?></strong> 条，
          失败 <strong class="bad"><?= $result['failed'] ?></strong> 条。</p>
        <?php if ($result['errors']): ?>
          <ul class="import-errors">
            <?php foreach ($result['errors'] as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <p><a href="/admin/import.html" class="btn-mini">继续导入</a> <a href="/admin/users.html" class="btn-mini">返回用户管理</a></p>
      </div>
    <?php endif; ?>

    <div class="admin-form">
      <h3 style="margin-top:0">方式一：粘贴文本批量导入</h3>
      <p class="grp-hint">每行一条，列顺序与官方模板一致：<code>用户名,密码[,显示名][,用户组]</code>。<br>
      「用户组」列可填多个组，以 <code>|</code> 分隔，例如 <code>普通用户组|管理员组</code>；留空且设置了默认用户组时归入默认组。<br>
      首行可直接粘贴表头 <code>用户名,密码,显示名,用户组</code>，会被自动识别跳过；以 <code>#</code> 或 <code>;</code> 开头的行视为注释。已存在的用户名会被自动跳过。</p>
      <form method="post" action="/admin/import.html">
        <textarea name="text_import" rows="8" placeholder="用户名,密码,显示名,用户组&#10;zhangsan,123456,张三,普通用户组&#10;lisi,abc123,李四,普通用户组|管理员组&#10;# 这是注释"></textarea>
        <div class="form-row" style="margin-top:10px">
          <label class="inline-label">默认用户组
            <select name="default_group_id">
              <option value="">无分组</option>
              <?php foreach ($groups as $g): ?>
                <option value="<?= (int)$g['id'] ?>"><?= e($g['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <button type="submit" class="btn-primary">导入</button>
        </div>
      </form>
    </div>

    <div class="admin-form">
      <h3 style="margin-top:0">方式二：上传 CSV 文件</h3>
      <p class="grp-hint">请使用官方模板，列顺序：<code>用户名,密码,显示名,用户组</code>；表头行会被自动识别跳过（中英文表头均兼容）。支持 UTF-8（含 BOM）与 GBK 编码，密码以 md5 保存。</p>
      <form method="post" action="/admin/import.html" enctype="multipart/form-data">
        <div class="form-row">
          <input type="file" name="csv_file" accept=".csv,text/csv" required>
          <label class="inline-label">默认用户组
            <select name="default_group_id">
              <option value="">无分组</option>
              <?php foreach ($groups as $g): ?>
                <option value="<?= (int)$g['id'] ?>"><?= e($g['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <button type="submit" class="btn-primary">上传并导入</button>
        </div>
      </form>
    </div>

    <div class="admin-form">
      <h3 style="margin-top:0">CSV 模板下载</h3>
      <p class="grp-hint">官方模板为「批量导入用户信息.csv」，首行表头 <code>用户名,密码,显示名,用户组</code>，文件带 UTF-8 BOM，Excel 打开不乱码：</p>
      <p><a class="btn-primary" href="/admin/import.html?template=1">⬇️ 下载 CSV 模板</a></p>
      <pre class="csv-template">用户名,密码,显示名,用户组
zhangsan,123456,张三,普通用户组
lisi,abc123,李四,普通用户组
wangwu,pwd123,王五,管理员组
zhaoliu,pwd456,赵六,普通用户组|管理员组</pre>
    </div>
  </section>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
