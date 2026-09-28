<?php
/**
 * 内容与图标渲染辅助
 * ===================
 * - 网站参数中的横幅 / 版权 / 公告等内容支持三种语法：
 *     text = 纯文本（自动转义、换行）
 *     html = 原生 HTML（仅超级管理员可写入，受信内容）
 *     md   = Markdown（服务端轻量解析，先转义再渲染）
 * - 所有图标统一走 icon_html()：自动识别图片 URL（http(s)/data/相对路径图片）与 emoji/文字。
 */

/**
 * 规范化导航 URL：
 * - http(s)://、// 协议相对地址、# 锚点、mailto:/tel: 等保持原样；
 * - 以 / 开头的根相对路径保持原样；
 * - 其余（如「admin」「xxx.com/admin」）一律补前导「/」，
 *   避免在不同目录层级的页面中被解析成不同地址
 *   （首页解析成 /admin，后台页却解析成 /admin/admin 的问题）。
 */
function normalize_nav_url(string $url): string {
    $url = trim($url);
    if ($url === '') return '';
    // 带协议 / 协议相对 / 特殊协议
    if (preg_match('#^([a-z][a-z0-9+.\-]*:|//)#i', $url)) return $url;
    // 根路径 / 锚点
    if ($url[0] === '/' || $url[0] === '#') return $url;
    return '/' . ltrim($url, '/');
}

/**
 * 判断图标值是否为图片地址。
 * 支持：
 *   - http(s)://、// 协议相对地址
 *   - data:image（含 png/jpeg/gif/svg/webp 及 .ico 对应的
 *     image/x-icon、image/vnd.microsoft.icon）
 *   - 以 / 或相对路径开头、URL 任意位置含常见图片扩展名（含 ico），
 *     允许后续查询串/锚点（如 /a.ico?v=2#x）
 * 单独把 ico 列出，保证各种写法都能被识别为图片。
 */
function icon_is_image_url(string $icon): bool {
    $icon = trim($icon);
    if ($icon === '') return false;
    // 协议相对 / data URI（含 ico 的 MIME）
    if (preg_match('#^(https?:)?//#i', $icon)) return true;
    if (preg_match('#^data:image/#i', $icon))  return true;
    // URL 中出现图片扩展名（ico 显式包含），其后只允许查询串/锚点/路径结尾
    if (preg_match('#\.(png|jpe?g|gif|svg|webp|avif|bmp|ico)(?:[?#]|$)#i', $icon)) return true;
    return false;
}

/**
 * 把图标值渲染为 HTML：
 * - 图片地址（含 .ico）=> <img>（带 alt、loading 与错误兜底）
 * - 其他 => emoji / 文字
 */
function icon_html(?string $icon, string $class = ''): string {
    $icon = trim((string)$icon);
    if ($icon === '') return '';
    $cls = $class !== '' ? ' class="' . e($class) . '"' : '';
    if (icon_is_image_url($icon)) {
        $src = e($icon);
        // ico 在个别浏览器缩放模糊，decode 失败时隐藏裂图（保留布局占位）
        $onerr = 'this.style.visibility="hidden"';
        return '<img src="' . $src . '" alt="" loading="lazy" onerror="' . $onerr . '"' . $cls . '>';
    }
    return '<span' . $cls . '>' . e($icon) . '</span>';
}

/**
 * 按语法类型把内容渲染为 HTML。
 * @param string $text 原始内容
 * @param string $type text | html | md
 */
function render_rich(string $text, string $type = 'text'): string {
    $text = trim($text);
    if ($text === '') return '';
    if ($type === 'html') return $text;          // 受信管理员写入的原生 HTML
    if ($type === 'md')   return md_to_html($text);
    return nl2br(e($text));                       // 纯文本
}

/**
 * 极简 Markdown -> HTML（无第三方依赖）
 * 支持：代码块、行内代码、标题、粗体/斜体/删除线、链接、图片、
 *       有序/无序列表、引用、分隔线、段落与换行。
 * 输入会先做 HTML 转义，链接仅允许 http/https/mailto/相对地址。
 */
function md_to_html(string $src): string {
    $src = str_replace(["\r\n", "\r"], "\n", $src);
    $blocks = [];
    // 先提取围栏代码块，避免内部语法被解析
    $src = preg_replace_callback('/```(\w*)\n(.*?)\n```/s', function ($m) use (&$blocks) {
        $lang = $m[1] !== '' ? ' class="lang-' . e($m[1]) . '"' : '';
        $blocks[] = '<pre><code' . $lang . '>' . e($m[2]) . '</code></pre>';
        return "\x00BLOCK" . (count($blocks) - 1) . "\x00";
    }, $src);

    $lines = explode("\n", $src);
    $html = [];
    $listType = null; // ul | ol
    $para = [];

    $flushPara = function () use (&$para, &$html) {
        if ($para) {
            $html[] = '<p>' . md_inline(implode("\n", $para)) . '</p>';
            $para = [];
        }
    };
    $closeList = function () use (&$listType, &$html) {
        if ($listType !== null) {
            $html[] = "</{$listType}>";
            $listType = null;
        }
    };

    for ($i = 0; $i < count($lines); $i++) {
        $line = $lines[$i];
        // 代码块占位符单独成段
        if (preg_match('/^\x00BLOCK(\d+)\x00$/', trim($line), $mm)) {
            $flushPara(); $closeList();
            $html[] = $blocks[(int)$mm[1]];
            continue;
        }
        if (trim($line) === '') { $flushPara(); $closeList(); continue; }

        // 分隔线
        if (preg_match('/^ {0,3}(-{3,}|\*{3,}|_{3,})$/', trim($line))) {
            $flushPara(); $closeList(); $html[] = '<hr>'; continue;
        }
        // 标题
        if (preg_match('/^ {0,3}(#{1,6})\s+(.*)$/', $line, $mm)) {
            $flushPara(); $closeList();
            $lvl = strlen($mm[1]);
            $html[] = "<h$lvl>" . md_inline(trim($mm[2])) . "</h$lvl>";
            continue;
        }
        // 引用
        if (preg_match('/^ {0,3}>\s?(.*)$/', $line, $mm)) {
            $flushPara(); $closeList();
            $quote = [$mm[1]];
            while (isset($lines[$i + 1]) && preg_match('/^ {0,3}>\s?(.*)$/', $lines[$i + 1], $nm)) {
                $quote[] = $nm[1]; $i++;
            }
            $html[] = '<blockquote>' . md_inline(implode("\n", $quote)) . '</blockquote>';
            continue;
        }
        // 无序列表
        if (preg_match('/^ {0,3}[-*+]\s+(.*)$/', $line, $mm)) {
            $flushPara();
            if ($listType !== 'ul') { $closeList(); $html[] = '<ul>'; $listType = 'ul'; }
            $html[] = '<li>' . md_inline(trim($mm[1])) . '</li>';
            continue;
        }
        // 有序列表
        if (preg_match('/^ {0,3}\d+\.\s+(.*)$/', $line, $mm)) {
            $flushPara();
            if ($listType !== 'ol') { $closeList(); $html[] = '<ol>'; $listType = 'ol'; }
            $html[] = '<li>' . md_inline(trim($mm[1])) . '</li>';
            continue;
        }
        $closeList();
        $para[] = trim($line);
    }
    $flushPara(); $closeList();

    $out = implode("\n", $html);
    // 还原正文中的代码块占位符
    return preg_replace_callback('/\x00BLOCK(\d+)\x00/', function ($m) use ($blocks) {
        return $blocks[(int)$m[1]] ?? '';
    }, $out);
}

/** Markdown 行内语法：转义已在外层完成 */
function md_inline(string $s): string {
    // 输入已经过 e() 转义的语境：本函数先转义，再替换
    $s = e($s);
    // 行内代码
    $s = preg_replace_callback('/`([^`]+)`/', function ($m) {
        return '<code>' . $m[1] . '</code>';
    }, $s);
    // 图片 ![alt](url)
    $s = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)(?:\s+&quot;([^&]*)&quot;)?\)/', function ($m) {
        $url = md_safe_url($m[2]);
        return $url === '' ? '' : '<img src="' . $url . '" alt="' . $m[1] . '">';
    }, $s);
    // 链接 [text](url)
    $s = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
        $url = md_safe_url($m[2]);
        return $url === '' ? $m[1] : '<a href="' . $url . '">' . $m[1] . '</a>';
    }, $s);
    // 粗体 / 删除线 / 斜体
    $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s);
    $s = preg_replace('/__([^_]+)__/', '<strong>$1</strong>', $s);
    $s = preg_replace('/~~([^~]+)~~/', '<del>$1</del>', $s);
    $s = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $s);
    $s = preg_replace('/(?<!_)_([^_]+)_(?!_)/', '<em>$1</em>', $s);
    // 段落内换行
    $s = str_replace("\n", "<br>\n", $s);
    return $s;
}

/** 校验 Markdown 链接/图片地址，拒绝 javascript: 等危险协议 */
function md_safe_url(string $url): string {
    $url = str_replace(['&amp;'], ['&'], $url); // e() 把 & 转义过
    if ($url === '') return '';
    if (preg_match('#^(https?://|mailto:|/|\#|\./|\.\/)#i', $url) || preg_match('#^[a-z0-9\-_?=&%./]+$#i', $url)) {
        return e($url);
    }
    return '';
}
