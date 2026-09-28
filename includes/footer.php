</main>
<footer class="footer"><?php
$__s = site_settings($pdo);
$__footerHtml = render_rich((string)($__s['site_footer'] ?? ''), (string)($__s['site_footer_type'] ?? 'text'));
if ($__footerHtml !== '') {
    echo '<span class="rich">' . $__footerHtml . '</span>';
} else {
    echo e($SITE_NAME . ' · PHP 门户系统');
}
?></footer>
<?php if (trim((string)($__s['custom_js'] ?? '')) !== ''): ?>
<script><?= $__s['custom_js'] ?></script>
<?php endif; ?>
<script>
/* 通用弹窗：触发器加 data-modal-open="<id>"；弹窗自身加 data-modal、id；
   关闭元素加 data-modal-close。无依赖、幂等，前台无弹窗时不产生任何效果。 */
(function () {
  function getModal(id) { return document.getElementById(id); }
  window.openModal = function (id) {
    var m = getModal(id);
    if (!m) return;
    m.classList.add('is-open');
    document.body.classList.add('modal-lock');
    var f = m.querySelector('[data-modal-autofocus]');
    if (f) setTimeout(function () { try { f.focus(); } catch (e) {} }, 60);
  };
  window.closeModal = function (id) {
    var m = typeof id === 'string' ? getModal(id)
        : (id ? id.closest('[data-modal]') : null);
    if (!m) return;
    m.classList.remove('is-open');
    if (!document.querySelector('[data-modal].is-open'))
      document.body.classList.remove('modal-lock');
  };
  document.addEventListener('click', function (e) {
    var op = e.target.closest('[data-modal-open]');
    if (op) { e.preventDefault(); window.openModal(op.getAttribute('data-modal-open')); return; }
    var cl = e.target.closest('[data-modal-close]');
    if (cl) { window.closeModal(cl); return; }
    if (e.target.hasAttribute && e.target.hasAttribute('data-modal'))
      window.closeModal(e.target);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      var open = document.querySelectorAll('[data-modal].is-open');
      if (open.length) window.closeModal(open[open.length - 1]);
    }
  });
  /* picker 过滤：
     - input[data-picker-filter] 按文本过滤同 picker 中的 label；
     - select[data-picker-group] 按 label 的 data-gids（所属组 CSV）过滤；
     两者可组合；没有组下拉的 picker（组/权限选择）行为不变。 */
  function applyPicker(box) {
    var textInp = document.querySelector('[data-picker-filter="' + box.id + '"]');
    var groupSel = document.querySelector('[data-picker-group="' + box.id + '"]');
    var q = textInp ? textInp.value.trim().toLowerCase() : '';
    var gid = groupSel ? groupSel.value : '';
    var labels = box.querySelectorAll('label');
    for (var i = 0; i < labels.length; i++) {
      var lb = labels[i];
      var textHit = !q || lb.textContent.toLowerCase().indexOf(q) !== -1;
      var gids = lb.getAttribute('data-gids');
      var groupHit = !gid || (gids ? gids.split(',').indexOf(gid) !== -1 : false);
      lb.style.display = (textHit && groupHit) ? '' : 'none';
    }
  }
  document.addEventListener('input', function (e) {
    var inp = e.target;
    if (!inp.hasAttribute || !inp.hasAttribute('data-picker-filter')) return;
    var box = document.getElementById(inp.getAttribute('data-picker-filter'));
    if (box) applyPicker(box);
  });
  document.addEventListener('change', function (e) {
    var sel = e.target;
    if (!sel.hasAttribute || !sel.hasAttribute('data-picker-group')) return;
    var box = document.getElementById(sel.getAttribute('data-picker-group'));
    if (box) applyPicker(box);
  });
})();
</script>
</body>
</html>
