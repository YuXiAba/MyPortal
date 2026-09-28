<?php
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';

$user = current_user();
$nav = visible_nav($pdo, $user);

// 首页天气显示开关（网站参数设置；manage_settings 权限可改，可二级授权）
$showWeather = (site_settings($pdo)['home_show_weather'] ?? '1') === '1';

// 当前用户的自定义导航（仅登录用户）；对历史数据做 URL 规范化，保证各页面跳转一致
$myNav = [];
if ($user) {
    $st = $pdo->prepare("SELECT * FROM user_nav_items WHERE user_id=? ORDER BY sort_order ASC, id ASC");
    $st->execute([(int)$user['id']]);
    foreach ($st->fetchAll() as $r) {
        $r['url'] = normalize_nav_url($r['url']);
        $myNav[] = $r;
    }
}

// 搜索引擎：从数据库读取启用项（后台「搜索引擎管理」维护）
$engines = $pdo->query("SELECT * FROM search_engines WHERE status=1 ORDER BY sort_order ASC, id ASC")->fetchAll();
if (empty($engines)) {
    // 极端兜底：未初始化时仍可用必应
    $engines = [['id' => 0, 'name' => '必应', 'url_template' => 'https://www.bing.com/search?q=%s']];
}
$selectedId = (int)($_GET['engine'] ?? $engines[0]['id']);
$selected = null;
foreach ($engines as $e) {
    if ((int)$e['id'] === $selectedId) { $selected = $e; break; }
}
if ($selected === null) { $selected = $engines[0]; $selectedId = (int)$selected['id']; }

$pageTitle = '首页';
require __DIR__ . '/includes/header.php';
?>
<section class="search-area">
  <?php
  // 首屏由服务端按站点时区渲染，随后由前端每秒刷新（避免加载时空白闪烁）
  $__weekMap = ['日', '一', '二', '三', '四', '五', '六'];
  ?>
  <div class="current-time" id="currentTime" role="timer" aria-label="当前时间">
    <span class="ct-clock" id="ctClock"><?= date('H:i:s') ?></span>
    <span class="ct-date-line">
      <span class="ct-date" id="ctDate"><?= date('Y年m月d日') ?></span>
      <span class="ct-sep" aria-hidden="true">·</span>
      <span class="ct-week" id="ctWeek">星期<?= $__weekMap[(int)date('w')] ?></span>
    </span>
  </div>
  <h1 class="welcome-title">你好，<?= e($user ? ($user['display_name'] ?: $user['username']) : '欢迎使用') ?></h1>

  <form class="search-form" method="get" action="/index.html" id="searchForm">
    <div class="search-box">
      <select name="engine" id="engineSelect" class="engine-select engine-select-inner" title="搜索引擎" aria-label="搜索引擎">
        <?php foreach ($engines as $e): ?>
          <option value="<?= (int)$e['id'] ?>" <?= (int)$e['id']===$selectedId?'selected':'' ?>><?= e($e['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="q" id="searchInput" placeholder="输入关键词搜索..." autocomplete="off" value="<?= e($_GET['q'] ?? '') ?>">
      <button type="submit" class="search-btn">搜索</button>
      <div class="search-suggest" id="searchSuggest" role="listbox"></div>
    </div>
  </form>
  <?php if ($showWeather): ?>
  <div class="weather-bar" id="weatherBar" aria-live="polite"></div>
  <?php endif; ?>
</section>

<section class="nav-section">
  <h2 class="section-title">快捷导航</h2>

  <?php if ($user): ?>
    <div class="nav-section-block">
      <h2 class="section-title my-nav-title">⭐ 我的导航
        <a href="/mynav.html" class="manage-my-nav">⚙️ 管理</a>
      </h2>
      <?php if (empty($myNav)): ?>
        <p class="empty-tip">你还没有自定义导航，<a href="/mynav.html">点此添加</a>（仅你自己可见）。</p>
      <?php else: ?>
        <div class="nav-grid">
          <?php foreach ($myNav as $item): ?>
            <a class="nav-card" href="<?= e($item['url']) ?>" target="_blank" rel="noopener">
              <span class="nav-icon"><?= icon_html((string)$item['icon']) ?></span>
              <span class="nav-title"><?= e($item['title']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (empty($nav)): ?>
    <p class="empty-tip">当前没有可见的导航。<?= is_admin() ? '去 <a href="/admin/navgroups.html">后台</a> 添加导航分组。' : '' ?></p>
  <?php else: ?>
    <?php foreach ($nav as $section): $g = $section['group']; ?>
      <div class="nav-section-block">
        <h2 class="section-title"><?= icon_html((string)$g['icon'], 'grp-title-icon') ?> <?= e($g['name']) ?></h2>
        <div class="nav-grid">
          <?php foreach ($section['items'] as $item): ?>
            <a class="nav-card" href="/jump/<?= (int)$item['id'] ?>.html" target="_blank" rel="noopener">
              <span class="nav-icon"><?= icon_html((string)$item['icon']) ?></span>
              <span class="nav-title"><?= e($item['title']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

<script>
// ---- 首页当前时间：按访客本机时间每秒刷新 ----
(function () {
  var elDate = document.getElementById('ctDate');
  var elWeek = document.getElementById('ctWeek');
  var elClock = document.getElementById('ctClock');
  if (!elDate || !elWeek || !elClock) return;
  var WEEK = ['日', '一', '二', '三', '四', '五', '六'];
  function pad2(n) { return n < 10 ? '0' + n : '' + n; }
  function renderTime() {
    var d = new Date();
    elDate.textContent = d.getFullYear() + '年' + pad2(d.getMonth() + 1) + '月' + pad2(d.getDate()) + '日';
    elWeek.textContent = '星期' + WEEK[d.getDay()];
    elClock.textContent = pad2(d.getHours()) + ':' + pad2(d.getMinutes()) + ':' + pad2(d.getSeconds());
  }
  renderTime();
  setInterval(renderTime, 1000);
})();

// ---- 首页当地天气 ----
// 免 key 方案：优先浏览器精确定位 → 拒绝时用 IP 粗定位（ipapi.co）→
// 天气数据 Open-Meteo；全部失败则静默不显示，不打扰用户。
// 定位结果缓存 6 小时、天气缓存 10 分钟（localStorage）。
(function () {
  var bar = document.getElementById('weatherBar');
  if (!bar) return;
  var GEO_KEY = '__portal_geo_v1';
  var W_KEY   = '__portal_weather_v1';
  var TTL_GEO = 6 * 3600 * 1000;
  var TTL_W   = 10 * 60 * 1000;

  // WMO 天气代码 → 图标 + 中文描述
  var WMO = {
    0: ['☀️', '晴'], 1: ['🌤️', '大致晴朗'], 2: ['⛅', '多云'], 3: ['☁️', '阴'],
    45: ['🌫️', '雾'], 48: ['🌫️', '雾凇'],
    51: ['🌦️', '小毛毛雨'], 53: ['🌦️', '毛毛雨'], 55: ['🌦️', '浓毛毛雨'],
    56: ['🌦️', '小毛毛雨'], 57: ['🌦️', '浓毛毛雨'],
    61: ['🌧️', '小雨'], 63: ['🌧️', '中雨'], 65: ['🌧️', '大雨'],
    66: ['🌧️', '小阵雨'], 67: ['🌧️', '中阵雨'],
    71: ['🌨️', '小雪'], 73: ['🌨️', '中雪'], 75: ['🌨️', '大雪'], 77: ['🌨️', '雪粒'],
    80: ['🌦️', '小阵雨'], 81: ['🌦️', '中阵雨'], 82: ['🌦️', '大阵雨'],
    85: ['🌨️', '小阵雪'], 86: ['🌨️', '大阵雪'],
    95: ['⛈️', '雷阵雨'], 96: ['⛈️', '雷阵雨伴冰雹'], 99: ['⛈️', '强雷阵雨']
  };

  function esc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }
  function getCache(key) {
    try {
      var raw = localStorage.getItem(key);
      if (!raw) return null;
      var d = JSON.parse(raw);
      if (!d || Date.now() - d.t > d.ttl) return null;
      return d.v;
    } catch (e) { return null; }
  }
  function setCache(key, v, ttl) {
    try { localStorage.setItem(key, JSON.stringify({t: Date.now(), ttl: ttl, v: v})); } catch (e) {}
  }

  function browserGeo() {
    return new Promise(function (resolve, reject) {
      if (!navigator.geolocation) return reject(new Error('no geo'));
      navigator.geolocation.getCurrentPosition(
        function (p) { resolve({lat: p.coords.latitude, lon: p.coords.longitude, src: 'gps'}); },
        function () { reject(new Error('geo denied')); },
        {timeout: 6000, maximumAge: 30 * 60 * 1000}
      );
    });
  }

  function ipGeo() {
    return fetch('https://ipapi.co/json/', {credentials: 'omit'})
      .then(function (r) {
        if (!r.ok) throw new Error('ip');
        return r.json();
      })
      .then(function (d) {
        if (typeof d.latitude !== 'number' || typeof d.longitude !== 'number') throw new Error('ip');
        return {lat: d.latitude, lon: d.longitude, city: d.city || d.region || '', src: 'ip'};
      });
  }

  // 浏览器定位不含城市名；用 BigDataCloud 免费逆地理补一个中文名（失败则不显示城市）
  function reverseName(geo) {
    if (geo.city) return Promise.resolve(geo.city);
    return fetch('https://api.bigdatacloud.net/data/reverse-geocode-client?latitude='
        + geo.lat + '&longitude=' + geo.lon + '&localityLanguage=zh', {credentials: 'omit'})
      .then(function (r) { return r.json(); })
      .then(function (d) { return d.city || d.locality || d.principalSubdivision || ''; })
      .catch(function () { return ''; });
  }

  function fetchWeather(geo) {
    var url = 'https://api.open-meteo.com/v1/forecast?latitude=' + geo.lat
      + '&longitude=' + geo.lon
      + '&current=temperature_2m,relative_humidity_2m,weather_code,wind_speed_10m'
      + '&timezone=auto';
    return fetch(url, {credentials: 'omit'})
      .then(function (r) {
        if (!r.ok) throw new Error('wx');
        return r.json();
      });
  }

  function render(geo, data) {
    var c = data.current || {};
    var info = WMO[c.weather_code] || ['🌡️', ''];
    var parts = [
      '<span class="wb-icon">' + info[0] + '</span>',
      '<span class="wb-temp">' + Math.round(c.temperature_2m) + '°C</span>'
    ];
    if (info[1]) parts.push('<span class="wb-desc">' + esc(info[1]) + '</span>');
    parts.push('<span class="wb-hum">💧 ' + Math.round(c.relative_humidity_2m) + '%</span>');
    parts.push('<span class="wb-wind">🌬️ ' + Math.round(c.wind_speed_10m) + 'km/h</span>');
    if (geo.city) parts.push('<span class="wb-city">📍 ' + esc(geo.city) + '</span>');
    bar.innerHTML = parts.join('');
    bar.classList.add('show');
  }

  function run() {
    var cachedGeo = getCache(GEO_KEY);
    var geoP = cachedGeo
      ? Promise.resolve(cachedGeo)
      : browserGeo().catch(ipGeo).then(function (geo) {
          setCache(GEO_KEY, geo, TTL_GEO);
          return geo;
        });

    var cachedW = getCache(W_KEY);
    if (cachedW) geoP.then(function (geo) { render(geo, cachedW); }).catch(function () {});

    geoP.then(function (geo) {
      return reverseName(geo).then(function (name) {
        if (name) geo.city = name;
        if (!cachedGeo || name) setCache(GEO_KEY, geo, TTL_GEO);
        return geo;
      });
    }).then(function (geo) {
      return fetchWeather(geo).then(function (data) {
        setCache(W_KEY, data, TTL_W);
        render(geo, data);
      });
    }).catch(function () { /* 全部失败：静默 */ });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    setTimeout(run, 0);
  }
})();

// 搜索引擎 URL 模板由后台数据库提供（不再前端硬编码）
var engineTpls = {
<?php foreach ($engines as $e): ?>
  <?= (int)$e['id'] ?>: <?= json_encode($e['url_template'], JSON_UNESCAPED_SLASHES) ?>,
<?php endforeach; ?>
};

// 下拉框位于搜索框内左侧：切换后立即生效并保留已输入关键词
document.getElementById('engineSelect').addEventListener('change', function () {
  var q = document.getElementById('searchInput').value.trim();
  var url = '/index.html?engine=' + encodeURIComponent(this.value);
  if (q) url += '&q=' + encodeURIComponent(q);
  window.location.href = url;
});

// 统一搜索动作：埋点 + 按引擎模板新窗口打开结果页
var searchInput = document.getElementById('searchInput');
var engineSelect = document.getElementById('engineSelect');

function trackSearch(q, engineId) {
  var fd = new FormData();
  fd.append('keyword', q);
  fd.append('engine_id', engineId);
  // sendBeacon 不阻塞开窗且页面卸载也能发出；旧浏览器用 keepalive fetch 兜底
  if (navigator.sendBeacon) {
    navigator.sendBeacon('/search_track.php', fd);
  } else {
    fetch('/search_track.php', {method: 'POST', body: fd, keepalive: true})
      .catch(function () {});
  }
}

function doSearch(rawQ) {
  var q = (rawQ != null ? rawQ : searchInput.value).trim();
  if (!q) return;
  searchInput.value = q;
  var engineId = engineSelect.value;
  trackSearch(q, engineId);
  var tpl = engineTpls[engineId] || engineTpls[Object.keys(engineTpls)[0]];
  window.open(tpl.replace('%s', encodeURIComponent(q)), '_blank');
  hideSuggest();
}

document.getElementById('searchForm').addEventListener('submit', function (e) {
  e.preventDefault();
  // 回车选中联想项时 keydown 已执行搜索，本次提交直接吞掉，避免重复开窗/埋点
  if (window.__suggestEnterHandled) {
    window.__suggestEnterHandled = false;
    return;
  }
  doSearch();
});

/* ---------- 关键词联想（历史 + 热门） ---------- */
var suggestBox = document.getElementById('searchSuggest');
var suggestItems = [];
var activeIndex = -1;
var debounceTimer = null;

function renderSuggest(list) {
  suggestItems = list || [];
  activeIndex = -1;
  if (!suggestItems.length) { hideSuggest(); return; }
  var q = searchInput.value.trim();
  suggestBox.innerHTML = suggestItems.map(function (kw) {
    // 高亮输入片段
    var label = kw;
    if (q) {
      var safe = q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      label = kw.replace(new RegExp(safe, 'i'), function (m) {
        return '<b>' + m + '</b>';
      });
    }
    return '<div class="search-suggest-item" role="option" data-kw="'
         + encodeURIComponent(kw) + '">🔍 ' + label + '</div>';
  }).join('');
  suggestBox.classList.add('show');
}

function hideSuggest() {
  suggestBox.classList.remove('show');
  suggestItems = [];
  activeIndex = -1;
}

function setActive(i) {
  if (!suggestItems.length) return;
  activeIndex = (i + suggestItems.length) % suggestItems.length;
  var nodes = suggestBox.querySelectorAll('.search-suggest-item');
  nodes.forEach(function (n, idx) {
    n.classList.toggle('active', idx === activeIndex);
    if (idx === activeIndex) n.scrollIntoView({block: 'nearest'});
  });
}

searchInput.addEventListener('input', function () {
  window.__suggestEnterHandled = false;
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(function () {
    fetch('/search_suggest.php?q=' + encodeURIComponent(searchInput.value.trim()))
      .then(function (r) { return r.json(); })
      .then(function (d) { renderSuggest(d.items); })
      .catch(function () {});
  }, 180);
});

// 键盘：↑↓ 选择、Enter 选中、Esc 关闭
searchInput.addEventListener('keydown', function (e) {
  if (e.key === 'ArrowDown') {
    e.preventDefault();
    if (!suggestItems.length) return;
    setActive(activeIndex + 1);
  } else if (e.key === 'ArrowUp') {
    e.preventDefault();
    if (!suggestItems.length) return;
    setActive(activeIndex - 1);
  } else if (e.key === 'Enter' && activeIndex >= 0) {
    e.preventDefault();
    window.__suggestEnterHandled = true;
    doSearch(decodeURIComponent(suggestItems[activeIndex]));
    setTimeout(function () { window.__suggestEnterHandled = false; }, 0);
  } else if (e.key === 'Escape') {
    hideSuggest();
  }
});

// 鼠标点选（mousedown 早于 blur，能在输入框失焦前完成）
suggestBox.addEventListener('mousedown', function (e) {
  var item = e.target.closest('.search-suggest-item');
  if (!item) return;
  e.preventDefault();
  doSearch(decodeURIComponent(item.getAttribute('data-kw')));
});

searchInput.addEventListener('blur', function () {
  setTimeout(hideSuggest, 150);
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
