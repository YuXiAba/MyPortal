<?php
/**
 * 本站探针：采集服务器运行环境信息（仅后台概览展示）。
 * 所有采集均做函数/权限兼容，单条信息失败只标记 n/a，不影响页面。
 */

/** 字节数格式化为可读单位 */
function probe_size(int $bytes, int $precision = 2): string {
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = (int)floor(log($bytes, 1024));
    $i = min($i, count($units) - 1);
    return round($bytes / pow(1024, $i), $precision) . ' ' . $units[$i];
}

/** 秒数 → 天/时/分 */
function probe_uptime(int $seconds): string {
    $d = intdiv($seconds, 86400);
    $h = intdiv($seconds % 86400, 3600);
    $m = intdiv($seconds % 3600, 60);
    return ($d > 0 ? $d . ' 天 ' : '') . $h . ' 小时 ' . $m . ' 分';
}

/** 读取 /proc/uptime（Linux；不可用返回 null） */
function probe_proc_uptime(): ?int {
    $f = @file_get_contents('/proc/uptime');
    if ($f === false || !preg_match('/^([0-9.]+)/', $f, $m)) return null;
    return (int)$m[1];
}

/** 读取 /proc/meminfo 键值（KB；返回字节） */
function probe_proc_meminfo(string $key): ?int {
    $f = @file_get_contents('/proc/meminfo');
    if ($f === false) return null;
    if (preg_match('/^' . preg_quote($key, '/') . ':\s+(\d+)\s+kB/m', $f, $m)) {
        return (int)$m[1] * 1024;
    }
    return null;
}

/**
 * 采集探针信息：[分组 => [标签 => 值]]
 */
function site_probe(PDO $pdo): array {
    $out = [];

    // —— 站点 ——
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $out['站点'] = [
        '访问地址' => $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '?'),
        '服务器时间' => date('Y-m-d H:i:s') . '（' . date_default_timezone_get() . '）',
    ];

    // —— 系统 ——
    $memTotal = probe_proc_meminfo('MemTotal');
    $memAvail = probe_proc_meminfo('MemAvailable');
    $sys = [
        '操作系统' => PHP_OS_FAMILY . ' · ' . php_uname('s') . ' ' . php_uname('r'),
        '主机名'   => php_uname('n'),
        '系统运行时间' => ($u = probe_proc_uptime()) !== null ? probe_uptime($u) : 'n/a',
    ];
    if (function_exists('sys_getloadavg')) {
        $la = (array)sys_getloadavg();
        if (count($la) === 3) $sys['平均负载（1/5/15 分钟）'] = implode(' / ', array_map(
            fn($v) => number_format((float)$v, 2), $la));
    }
    if ($memTotal !== null) {
        $used = $memAvail !== null ? $memTotal - $memAvail : null;
        $sys['物理内存'] = probe_size($memTotal)
            . ($used !== null ? '（已用 ' . round($used / $memTotal * 100) . '%）' : '');
    }
    $out['系统'] = $sys;

    // —— Web 服务 ——
    $out['Web 服务'] = [
        '服务器软件' => $_SERVER['SERVER_SOFTWARE'] ?? 'n/a',
        '运行接口'   => PHP_SAPI,
    ];

    // —— PHP ——
    $exts = function_exists('get_loaded_extensions') ? count(get_loaded_extensions()) : 'n/a';
    $out['PHP'] = [
        '版本'          => PHP_VERSION,
        '已加载扩展数'  => $exts,
        '错误显示'      => ini_get('display_errors') ? '开启（建议生产关闭）' : '关闭',
        '最大执行时间'  => ((int)ini_get('max_execution_time')) . ' 秒',
        '内存上限'      => ini_get('memory_limit'),
        '上传大小限制'  => ini_get('upload_max_filesize') . '（POST 上限 ' . ini_get('post_max_size') . '）',
        '当前内存占用'  => probe_size(memory_get_usage(true))
                         . '（峰值 ' . probe_size(memory_get_peak_usage(true)) . '）',
    ];

    // —— MySQL ——
    $mysql = ['版本' => 'n/a', '服务器' => 'n/a'];
    try {
        $version = (string)$pdo->query("SELECT VERSION()")->fetchColumn();
        $mysql['版本'] = $version;
        $pst = $pdo->query("SHOW VARIABLES LIKE 'port'");
        $port = ($r = $pst->fetch()) ? (int)$r['Value'] : 0;
        $hst = $pdo->query("SHOW VARIABLES LIKE 'hostname'");
        $host = ($r = $hst->fetch()) ? (string)$r['Value'] : 'n/a';
        $mysql['服务器'] = $host . ':' . $port;

        // 数据库大小（可能无 information_schema 权限）
        $dbname = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
        $sizeSt = $pdo->prepare("SELECT COALESCE(SUM(data_length+index_length),0)
                                 FROM information_schema.tables
                                 WHERE table_schema=?");
        $sizeSt->execute([$dbname]);
        $mysql['当前数据库大小'] = probe_size((int)$sizeSt->fetchColumn());
    } catch (Throwable $e) {
        $mysql['错误'] = '部分信息无权限读取';
    }
    $out['MySQL'] = $mysql;

    // —— 磁盘 ——
    $root = (string)($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__));
    $disk = ['站点目录' => $root];
    $total = @disk_total_space($root);
    $free  = @disk_free_space($root);
    if ($total !== false && $free !== false) {
        $used = $total - $free;
        $disk['磁盘总量'] = probe_size((int)$total);
        $disk['已用 / 可用'] = probe_size((int)$used) . ' / ' . probe_size((int)$free)
            . '（已用 ' . round($used / $total * 100) . '%）';
    } else {
        $disk['磁盘空间'] = 'n/a';
    }
    $out['磁盘'] = $disk;

    return $out;
}
