<?php
/**
 * 数据库连接与初始化（MySQL + PDO）
 * 依赖 PHP 的 pdo_mysql 扩展。首次运行会自动创建数据库与表结构并写入种子数据。
 */

// ====== 数据库连接配置（按需修改） ======
$DB_HOST = '127.0.0.1';
$DB_PORT = 3306;
$DB_NAME = 'NAME';
$DB_USER = 'USER';
$DB_PASS = 'PASS';
// =======================================

$dsn = "mysql:host=$DB_HOST;port=$DB_PORT;charset=utf8mb4";
try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch (PDOException $e) {
    die('数据库连接失败：' . htmlspecialchars($e->getMessage()));
}

// 自动创建数据库（避免手动建库）
$pdo->exec("CREATE DATABASE IF NOT EXISTS `$DB_NAME` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$DB_NAME`");

/**
 * 初始化表结构与种子数据（幂等，可重复调用）
 * 密码统一使用 md5 保存。
 */
function db_init(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_groups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(50) NOT NULL UNIQUE,
        is_admin TINYINT NOT NULL DEFAULT 0,
        is_super TINYINT NOT NULL DEFAULT 0,
        permissions TEXT,
        level INT NOT NULL DEFAULT 3
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 兼容旧库：为已存在的 user_groups 补齐新列
    // manageable_groups: 普通管理员组可管理的用户组 ID 列表（CSV，由超级管理员分配）
    // level: 管理级别（数字越小级别越高：系统管理员=1，普通管理员=2，普通用户组=3+）；同级管理员禁止相互修改
    foreach ([
        'is_super'          => 'TINYINT NOT NULL DEFAULT 0',
        'permissions'       => 'TEXT',
        'manageable_groups' => 'TEXT',
        'level'             => 'INT NOT NULL DEFAULT 3',
    ] as $col => $def) {
        if (!column_exists($pdo, 'user_groups', $col)) {
            $pdo->exec("ALTER TABLE user_groups ADD COLUMN `$col` $def");
        }
    }

    // 网站参数（logo / footer 等）
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 搜索引擎（系统管理员可增删改查；前台搜索框下拉读取）
    $pdo->exec("CREATE TABLE IF NOT EXISTS search_engines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(50) NOT NULL,
        url_template VARCHAR(500) NOT NULL,
        icon VARCHAR(255) NOT NULL DEFAULT '',
        sort_order INT NOT NULL DEFAULT 0,
        status TINYINT NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 兼容旧库：图标列加宽到 255（图片 URL 可能超过原 50 长度）
    foreach (['nav_groups' => 'icon', 'nav_items' => 'icon'] as $tbl => $col) {
        $st = $pdo->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
                           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$tbl' AND COLUMN_NAME = '$col'");
        $len = (int)$st->fetchColumn();
        if ($len > 0 && $len < 255) {
            $pdo->exec("ALTER TABLE `$tbl` MODIFY COLUMN `$col` VARCHAR(255) NOT NULL DEFAULT ''");
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password CHAR(32) NOT NULL,
        display_name VARCHAR(100) NOT NULL DEFAULT '',
        group_id INT NULL,
        last_login_ip VARCHAR(45) NULL,
        last_login_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_user_group FOREIGN KEY (group_id) REFERENCES user_groups(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 兼容旧库：为已存在的 users 补齐登录记录列
    foreach ([
        'last_login_ip' => 'VARCHAR(45) NULL',
        'last_login_at' => 'DATETIME NULL',
        // 主题皮肤：用户个人选择（空=跟随站点默认）
        'theme'         => "VARCHAR(40) NOT NULL DEFAULT ''",
        // 「我的设置」入口开关（1=显示并允许访问，0=隐藏并拦截；默认开）
        'settings_entry' => "TINYINT NOT NULL DEFAULT 1",
    ] as $col => $def) {
        if (!column_exists($pdo, 'users', $col)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN `$col` $def");
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS nav_groups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        icon VARCHAR(50) NOT NULL DEFAULT '',
        sort_order INT NOT NULL DEFAULT 0,
        status TINYINT NOT NULL DEFAULT 1,
        group_ids VARCHAR(500) NOT NULL DEFAULT '',
        user_ids VARCHAR(500) NOT NULL DEFAULT '',
        manageable_group_ids VARCHAR(500) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 升级：导航分组新增「可管理用户组」——命中用户组的成员可管理本分组及其导航项
    if (!column_exists($pdo, 'nav_groups', 'manageable_group_ids')) {
        $pdo->exec("ALTER TABLE nav_groups
                    ADD COLUMN manageable_group_ids VARCHAR(500) NOT NULL DEFAULT '' AFTER user_ids");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS nav_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_id INT NULL,
        title VARCHAR(100) NOT NULL,
        url VARCHAR(500) NOT NULL,
        icon VARCHAR(50) NOT NULL DEFAULT '',
        sort_order INT NOT NULL DEFAULT 0,
        status TINYINT NOT NULL DEFAULT 1,
        click_count INT NOT NULL DEFAULT 0,
        group_ids VARCHAR(500) NOT NULL DEFAULT '',
        user_ids VARCHAR(500) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_nav_group FOREIGN KEY (group_id) REFERENCES nav_groups(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 用户自定义导航（用户在前台自助添加的个人导航，仅本人可见）
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_nav_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(100) NOT NULL,
        url VARCHAR(500) NOT NULL,
        icon VARCHAR(255) NOT NULL DEFAULT '',
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_user (user_id),
        CONSTRAINT fk_un_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 登录设备/会话：每次登录登记一行（PHP session 维度），
    // 用于「当前登录设备」展示、最大设备数限制与管理员强制下线。
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        session_id VARCHAR(64) NOT NULL,
        ip VARCHAR(45) NULL,
        user_agent VARCHAR(500) NOT NULL DEFAULT '',
        device_name VARCHAR(100) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_access_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        revoked TINYINT NOT NULL DEFAULT 0,
        UNIQUE KEY uq_session (session_id),
        KEY idx_user (user_id),
        CONSTRAINT fk_us_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 搜索记录：首页搜索框每次检索一条（含未登录访客，user_id 可空）。
    // 不设用户外键：用户删除后记录保留（user_id 置空），便于审计与热门词统计。
    $pdo->exec("CREATE TABLE IF NOT EXISTS search_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        username VARCHAR(50) NOT NULL DEFAULT '',
        display_name VARCHAR(50) NOT NULL DEFAULT '',
        keyword VARCHAR(191) NOT NULL,
        engine_id INT NOT NULL DEFAULT 0,
        engine_name VARCHAR(50) NOT NULL DEFAULT '',
        ip VARCHAR(45) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_sl_user (user_id),
        KEY idx_sl_keyword (keyword),
        KEY idx_sl_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 用户级权限（二级授权）：在用户组权限之外给单个用户追加权限
    // granted_by 记录授权人，便于追溯及"仅可撤销本人授予的权限"
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        perm_key VARCHAR(50) NOT NULL,
        granted_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_user_perm (user_id, perm_key),
        KEY idx_granted_by (granted_by),
        CONSTRAINT fk_up_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_up_granter FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 用户操作日志：登录/退出、后台增删改、重置密码、导入、设置保存等
    // username/display_name 为快照（用户删除后日志保留）；ip/status 记录登录 IP 与成功失败状态
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_logs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        username VARCHAR(50) NOT NULL DEFAULT '',
        display_name VARCHAR(100) NOT NULL DEFAULT '',
        action VARCHAR(30) NOT NULL,
        module VARCHAR(50) NOT NULL DEFAULT '',
        detail VARCHAR(1000) NOT NULL DEFAULT '',
        ip VARCHAR(45) NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'success',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_log_user (user_id),
        KEY idx_log_created (created_at),
        KEY idx_log_action (action),
        CONSTRAINT fk_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 用户—用户组多对多关联：同一用户可同时属于多个用户组，
    // 权限取各组并集、级别取各组最高（数字最小）；
    // users.group_id 保留为"主用户组"缓存（级别最高的组），供列表 JOIN 使用。
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_group_members (
        user_id INT NOT NULL,
        group_id INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, group_id),
        KEY idx_m_group (group_id),
        CONSTRAINT fk_m_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_m_group FOREIGN KEY (group_id) REFERENCES user_groups(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 皮肤元数据覆盖表：后台可修改每套皮肤的名称/版本/作者/说明/暗色类型，
    // 以及「可见用户组」（空=不限用户组；非空=仅勾选组的成员可见）。
    // 缺省字段为空时，回退到内置注册值或 CSS 注释头，不改变皮肤文件本身。
    $pdo->exec("CREATE TABLE IF NOT EXISTS theme_overrides (
        theme_id VARCHAR(40) NOT NULL,
        name VARCHAR(60) NOT NULL DEFAULT '',
        author VARCHAR(60) NOT NULL DEFAULT '',
        version VARCHAR(20) NOT NULL DEFAULT '',
        description VARCHAR(255) NOT NULL DEFAULT '',
        dark TINYINT NOT NULL DEFAULT -1,
        visible_groups VARCHAR(500) NOT NULL DEFAULT '',
        PRIMARY KEY (theme_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ---- 登录凭据功能已移除：旧库清理（每次启动检查，幂等） ----
    // 1) 删除旧的每用户登录覆盖表
    $pdo->exec("DROP TABLE IF EXISTS nav_login_overrides");
    // 2) 删除 nav_items 上旧的登录凭据列
    foreach (['login_type','login_url','login_user','login_pass','login_cookie',
              'login_domain','login_field_user','login_field_pass'] as $dropCol) {
        if (column_exists($pdo, 'nav_items', $dropCol)) {
            $pdo->exec("ALTER TABLE nav_items DROP COLUMN `$dropCol`");
        }
    }

    // ---- 兼容旧库：为已存在的 nav_items 补齐仍在使用的新增列 ----
    foreach ([
        'group_id'    => 'INT NULL',
        'click_count' => 'INT NOT NULL DEFAULT 0',
        'user_ids'    => "VARCHAR(500) NOT NULL DEFAULT ''",
    ] as $col => $def) {
        if (!column_exists($pdo, 'nav_items', $col)) {
            $pdo->exec("ALTER TABLE nav_items ADD COLUMN `$col` $def");
        }
    }

    // ---- 旧库迁移：user_logs 补 display_name 列并回填快照 ----
    if (!column_exists($pdo, 'user_logs', 'display_name')) {
        $pdo->exec("ALTER TABLE user_logs ADD COLUMN display_name VARCHAR(100) NOT NULL DEFAULT '' AFTER username");
    }
    // 已注册用户的历史日志：从 users 回填显示名
    $pdo->exec("UPDATE user_logs l JOIN users u ON l.user_id = u.id
                SET l.display_name = u.display_name WHERE l.display_name = ''");
    // 登录失败且用户不存在的历史日志：非注册用户
    $pdo->exec("UPDATE user_logs SET display_name='非注册用户'
                WHERE display_name='' AND user_id IS NULL AND action='login' AND status='fail'");

    // ---- 旧库迁移：把 users.group_id 现有归属导入多组关联表（一次性，幂等） ----
    $memMark = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key='_migration_members'");
    $memMark->execute();
    if ($memMark->fetchColumn() === false) {
        $pdo->exec("INSERT IGNORE INTO user_group_members (user_id, group_id)
                    SELECT id, group_id FROM users WHERE group_id IS NOT NULL");
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('_migration_members','1')")
            ->execute();
    }

    // ---- 种子数据：用户组（含级别 level：超管1 / 普通管理员2 / 普通组3） ----
    if ((int)$pdo->query("SELECT COUNT(*) FROM user_groups")->fetchColumn() === 0) {
        $pdo->exec("INSERT INTO user_groups (name, is_admin, is_super, permissions, level)
                    VALUES ('超级管理员组', 1, 1, '', 1)");
        $pdo->exec("INSERT INTO user_groups (name, is_admin, is_super, permissions, level)
                    VALUES ('管理员组', 1, 0, 'access_admin,manage_navgroups,manage_nav,manage_groups,manage_users,create_users,reset_password,import_users,manage_user_navs', 2)");
        $pdo->exec("INSERT INTO user_groups (name, is_admin, is_super, permissions, level) VALUES ('普通用户组', 0, 0, '', 3)");
    }
    // 兼容旧库：确保存在超级管理员组
    if ((int)$pdo->query("SELECT COUNT(*) FROM user_groups WHERE is_super=1")->fetchColumn() === 0) {
        $chk = $pdo->prepare("SELECT id FROM user_groups WHERE name='超级管理员组'");
        $chk->execute();
        $sid = (int)$chk->fetchColumn();
        if ($sid > 0) {
            $pdo->exec("UPDATE user_groups SET is_super=1, is_admin=1, level=1 WHERE id=" . $sid);
        } else {
            $pdo->exec("INSERT INTO user_groups (name, is_admin, is_super, permissions, level) VALUES ('超级管理员组',1,1,'',1)");
        }
    }
    // 超级管理员组级别恒为 1（系统专属，且用户组编辑不允许改超管级别）
    $pdo->exec("UPDATE user_groups SET level=1 WHERE is_super=1 AND level<>1");

    // 旧库一次性迁移：早期版本级别由组类型固定（管理员=2/普通组=3），
    // 升级到「系统管理员可自定义级别」后，仅在首次升级时校正历史错位一次，
    // 此后系统管理员设置的自定义级别永久保留（用 settings 标记，幂等）。
    $lvlMarkSt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key='_migration_levels'");
    $lvlMarkSt->execute();
    if ($lvlMarkSt->fetchColumn() === false) {
        $pdo->exec("UPDATE user_groups SET level=2 WHERE is_super=0 AND is_admin=1 AND level IN (1,3)");
        $pdo->exec("UPDATE user_groups SET level=3 WHERE is_admin=0 AND is_super=0 AND level IN (1,2)");
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('_migration_levels','1')")
            ->execute();
    }
    // 为已存在的普通管理员组补齐默认权限（超级管理员不受权限限制）
    $pdo->exec("UPDATE user_groups SET permissions='manage_navgroups,manage_nav,manage_groups,manage_users,create_users,reset_password,import_users,manage_user_navs'
                WHERE is_admin=1 AND is_super=0 AND (permissions IS NULL OR permissions='')");

    // 旧库兼容：后台访问已改为权限（access_admin）。
    // 所有现存管理员组若缺少该权限则追加，避免普通管理员升级后无法进入后台。
    foreach ($pdo->query("SELECT id, permissions FROM user_groups WHERE is_admin=1 AND is_super=0") as $ag) {
        $keys = array_filter(array_map('trim', explode(',', (string)$ag['permissions'])));
        if (!in_array('access_admin', $keys, true)) {
            $keys[] = 'access_admin';
            $up = $pdo->prepare("UPDATE user_groups SET permissions=? WHERE id=?");
            $up->execute([implode(',', $keys), (int)$ag['id']]);
        }
    }

    // 旧库升级：管理员用户组默认拥有「重置用户密码」权限（含后续二级授权资格）。
    // 仅在升级时一次性补齐（用 settings 标记），此后系统管理员可在权限矩阵中自由撤销/重授。
    $markSt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key='_migration_reset_password'");
    $markSt->execute();
    if ($markSt->fetchColumn() === false) {
        foreach ($pdo->query("SELECT id, permissions FROM user_groups WHERE is_admin=1 AND is_super=0") as $ag) {
            $keys = array_filter(array_map('trim', explode(',', (string)$ag['permissions'])));
            if (!in_array('reset_password', $keys, true)) {
                $keys[] = 'reset_password';
                $up = $pdo->prepare("UPDATE user_groups SET permissions=? WHERE id=?");
                $up->execute([implode(',', $keys), (int)$ag['id']]);
            }
        }
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('_migration_reset_password','1')")
            ->execute();
    }

    // 旧库升级：概览「导航点击」改为独立权限 manage_nav_clicks（支持清零与二级授权）。
    // 升级时为所有现存管理员组一次性补入，保持原有查看行为；此后可在矩阵撤销/重授。
    $ncMarkSt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key='_migration_nav_clicks'");
    $ncMarkSt->execute();
    if ($ncMarkSt->fetchColumn() === false) {
        foreach ($pdo->query("SELECT id, permissions FROM user_groups WHERE is_admin=1 AND is_super=0") as $ag) {
            $keys = array_filter(array_map('trim', explode(',', (string)$ag['permissions'])));
            if (!in_array('manage_nav_clicks', $keys, true)) {
                $keys[] = 'manage_nav_clicks';
                $up = $pdo->prepare("UPDATE user_groups SET permissions=? WHERE id=?");
                $up->execute([implode(',', $keys), (int)$ag['id']]);
            }
        }
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('_migration_nav_clicks','1')")
            ->execute();
    }

    // 旧库升级：新增独立权限 manage_user_themes（管理员可增删改查用户的主题选择，
    // 支持二级授权）。升级时为所有现存管理员组一次性补入，保持原有管理行为；
    // 此后系统管理员可在权限矩阵中撤销/重授。
    $utMarkSt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key='_migration_user_themes'");
    $utMarkSt->execute();
    if ($utMarkSt->fetchColumn() === false) {
        foreach ($pdo->query("SELECT id, permissions FROM user_groups WHERE is_admin=1 AND is_super=0") as $ag) {
            $keys = array_filter(array_map('trim', explode(',', (string)$ag['permissions'])));
            if (!in_array('manage_user_themes', $keys, true)) {
                $keys[] = 'manage_user_themes';
                $up = $pdo->prepare("UPDATE user_groups SET permissions=? WHERE id=?");
                $up->execute([implode(',', $keys), (int)$ag['id']]);
            }
        }
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('_migration_user_themes','1')")
            ->execute();
    }

    // 旧库升级：新增独立权限 manage_user_entry（控制单个用户的「我的设置」
    // 入口开关，支持二级授权）。升级时为持有 manage_users 的非超管管理员组
    // 一次性补入，保持原有管理行为；此后系统管理员可在权限矩阵中撤销/重授。
    $enMarkSt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key='_migration_user_entry'");
    $enMarkSt->execute();
    if ($enMarkSt->fetchColumn() === false) {
        foreach ($pdo->query("SELECT id, permissions FROM user_groups WHERE is_admin=1 AND is_super=0") as $ag) {
            $keys = array_filter(array_map('trim', explode(',', (string)$ag['permissions'])));
            if (in_array('manage_users', $keys, true) && !in_array('manage_user_entry', $keys, true)) {
                $keys[] = 'manage_user_entry';
                $up = $pdo->prepare("UPDATE user_groups SET permissions=? WHERE id=?");
                $up->execute([implode(',', $keys), (int)$ag['id']]);
            }
        }
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('_migration_user_entry','1')")
            ->execute();
    }

    // 旧库升级：新增独立权限 manage_theme_meta（修改皮肤名称/版本/作者/暗色类型/
    // 可见用户组等元数据，支持二级授权）。为现存管理员组一次性补入；
    // 此后系统管理员可在权限矩阵中撤销/重授。
    $tmMarkSt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key='_migration_theme_meta'");
    $tmMarkSt->execute();
    if ($tmMarkSt->fetchColumn() === false) {
        foreach ($pdo->query("SELECT id, permissions FROM user_groups WHERE is_admin=1 AND is_super=0") as $ag) {
            $keys = array_filter(array_map('trim', explode(',', (string)$ag['permissions'])));
            if (!in_array('manage_theme_meta', $keys, true)) {
                $keys[] = 'manage_theme_meta';
                $up = $pdo->prepare("UPDATE user_groups SET permissions=? WHERE id=?");
                $up->execute([implode(',', $keys), (int)$ag['id']]);
            }
        }
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('_migration_theme_meta','1')")
            ->execute();
    }

    // 旧库升级：「新建用户」从「用户管理」中拆分为独立权限。
    // 升级时为所有持有 manage_users 的非超管组一次性补入 create_users，
    // 保持原有行为不变；此后系统管理员可在权限矩阵中单独撤销/重授。
    $cuMarkSt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key='_migration_create_users'");
    $cuMarkSt->execute();
    if ($cuMarkSt->fetchColumn() === false) {
        foreach ($pdo->query("SELECT id, permissions FROM user_groups WHERE is_admin=1 AND is_super=0") as $ag) {
            $keys = array_filter(array_map('trim', explode(',', (string)$ag['permissions'])));
            if (in_array('manage_users', $keys, true) && !in_array('create_users', $keys, true)) {
                $keys[] = 'create_users';
                $up = $pdo->prepare("UPDATE user_groups SET permissions=? WHERE id=?");
                $up->execute([implode(',', $keys), (int)$ag['id']]);
            }
        }
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('_migration_create_users','1')")
            ->execute();
    }

    // 旧库升级：「用户自定义导航」从只读查看（原借用 manage_users）
    // 升级为独立权限 manage_user_navs 的增删改查，并支持二级授权。
    // 升级时为所有持有 manage_users 的非超管组一次性补入，保持原有访问；
    // 此后系统管理员可在权限矩阵中单独撤销/重授。
    $unMarkSt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key='_migration_manage_user_navs'");
    $unMarkSt->execute();
    if ($unMarkSt->fetchColumn() === false) {
        foreach ($pdo->query("SELECT id, permissions FROM user_groups WHERE is_admin=1 AND is_super=0") as $ag) {
            $keys = array_filter(array_map('trim', explode(',', (string)$ag['permissions'])));
            if (in_array('manage_users', $keys, true) && !in_array('manage_user_navs', $keys, true)) {
                $keys[] = 'manage_user_navs';
                $up = $pdo->prepare("UPDATE user_groups SET permissions=? WHERE id=?");
                $up->execute([implode(',', $keys), (int)$ag['id']]);
            }
        }
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('_migration_manage_user_navs','1')")
            ->execute();
    }

    // ---- 种子数据：管理员账号 admin / admin123（md5，默认归入超级管理员组） ----
    $superGroupId = (int)$pdo->query("SELECT id FROM user_groups WHERE is_super=1 ORDER BY id LIMIT 1")->fetchColumn();
    if ((int)$pdo->query("SELECT COUNT(*) FROM users WHERE username='admin'")->fetchColumn() === 0) {
        $stmt = $pdo->prepare("INSERT INTO users (username, password, display_name, group_id) VALUES (?,?,?,?)");
        $stmt->execute(['admin', md5('admin123'), '系统管理员', $superGroupId > 0 ? $superGroupId : null]);
        // 多组关联表同步（全新安装时迁移块先于种子执行，不会自动补入）
        if ($superGroupId > 0) {
            $pdo->prepare("INSERT IGNORE INTO user_group_members (user_id, group_id) VALUES (?,?)")
                ->execute([(int)$pdo->lastInsertId(), $superGroupId]);
        }
    } elseif ($superGroupId > 0) {
        // 迁移旧部署：把 admin 账号归入超级管理员组（缓存 + 关联表）
        $pdo->prepare("UPDATE users SET group_id=? WHERE username='admin'")->execute([$superGroupId]);
        $pdo->prepare("INSERT IGNORE INTO user_group_members (user_id, group_id)
                       SELECT id, ? FROM users WHERE username='admin'")->execute([$superGroupId]);
    }

    // ---- 种子数据：搜索引擎（仅在表为空时写入，避免覆盖管理员的增删改） ----
    if ((int)$pdo->query("SELECT COUNT(*) FROM search_engines")->fetchColumn() === 0) {
        $engineSeed = [
            ['必应', 'https://www.bing.com/search?q=%s', '🅱️', 1],
            ['百度', 'https://www.baidu.com/s?wd=%s',   '🐾', 2],
            ['Google', 'https://www.google.com/search?q=%s', '🌐', 3],
        ];
        $insE = $pdo->prepare("INSERT INTO search_engines (name, url_template, icon, sort_order, status) VALUES (?,?,?,?,1)");
        foreach ($engineSeed as $e) {
            $insE->execute($e);
        }
    }

    // ---- 种子数据：导航分组 ----
    if ((int)$pdo->query("SELECT COUNT(*) FROM nav_groups")->fetchColumn() === 0) {
        $groupsSeed = [
            ['搜索引擎', '🔍', 1],
            ['新闻资讯', '📰', 2],
            ['办公工具', '🛠️', 3],
            ['开发资源', '💻', 4],
        ];
        $insG = $pdo->prepare("INSERT INTO nav_groups (name, icon, sort_order, status, group_ids, user_ids) VALUES (?,?,?,1,'','')");
        foreach ($groupsSeed as $gs) {
            $insG->execute($gs);
        }
    }

    // ---- 种子数据：默认导航项（按分组归属） ----
    if ((int)$pdo->query("SELECT COUNT(*) FROM nav_items")->fetchColumn() === 0) {
        $map = [
            '搜索引擎' => [['必应搜索', 'https://www.bing.com', '🔍'], ['百度', 'https://www.baidu.com', '🧭'], ['Google', 'https://www.google.com', '🌐']],
            '新闻资讯' => [['知乎', 'https://www.zhihu.com', '💬'], ['豆瓣', 'https://www.douban.com', '🎬'], ['哔哩哔哩', 'https://www.bilibili.com', '📺']],
            '办公工具' => [['网易邮箱', 'https://mail.163.com', '✉️'], ['腾讯文档', 'https://docs.qq.com', '📄'], ['飞书', 'https://www.feishu.cn', '🚀']],
            '开发资源' => [['GitHub', 'https://github.com', '🐙'], ['Stack Overflow', 'https://stackoverflow.com', '🧩'], ['菜鸟教程', 'https://www.runoob.com', '📖']],
        ];
        $ins = $pdo->prepare("INSERT INTO nav_items (group_id, title, url, icon, sort_order, status, group_ids, user_ids) VALUES (?,?,?,?,?,1,'','')");
        $sort = 0;
        foreach ($map as $gname => $items) {
            $gidVal = (int)$pdo->query("SELECT id FROM nav_groups WHERE name='" . addslashes($gname) . "'")->fetchColumn();
            foreach ($items as $it) {
                $sort++;
                $ins->execute([$gidVal, $it[0], $it[1], $it[2], $sort]);
            }
        }
    } else {
        // 旧数据迁移：把未归属分组的导航项归入第一个分组
        $firstGroup = (int)$pdo->query("SELECT id FROM nav_groups ORDER BY id LIMIT 1")->fetchColumn();
        if ($firstGroup > 0) {
            $pdo->exec("UPDATE nav_items SET group_id=" . $firstGroup . " WHERE group_id IS NULL");
        }
    }
}

function column_exists(PDO $pdo, string $table, string $col): bool {
    // $col 来自本文件内的固定字段列表，非用户输入，安全
    $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '" . addslashes($col) . "'");
    return (bool)$stmt->fetch();
}

db_init($pdo);

// 读取登录状态有效期（秒），供 auth.php 在 session_start 之前设置会话 Cookie 生命周期
$__lifeRow = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='session_lifetime'")->fetchColumn();
$SESSION_LIFETIME = max(0, (int)$__lifeRow);
if ($SESSION_LIFETIME > 0) {
    ini_set('session.gc_maxlifetime', (string)$SESSION_LIFETIME);
}
