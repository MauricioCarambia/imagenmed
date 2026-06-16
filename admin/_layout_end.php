</div><!-- /.inner -->
</div><!-- /#content -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Modales propios (data-cmodal-open / data-cmodal-close)
document.addEventListener('click', function (e) {
  var opener = e.target.closest('[data-cmodal-open]');
  if (opener) {
    var ov = document.getElementById(opener.getAttribute('data-cmodal-open'));
    if (ov) ov.classList.add('show');
  }
  var closer = e.target.closest('[data-cmodal-close]');
  if (closer) {
    var ov2 = closer.closest('.cmodal-overlay');
    if (ov2) ov2.classList.remove('show');
  }
  if (e.target.classList.contains('cmodal-overlay')) {
    e.target.classList.remove('show');
  }
});
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') {
    document.querySelectorAll('.cmodal-overlay.show').forEach(function (ov) {
      ov.classList.remove('show');
    });
  }
});

// Menú lateral en mobile
(function () {
  var btn = document.getElementById('sidebar-toggle');
  var sidebar = document.getElementById('sidebar');
  var overlay = document.getElementById('sidebar-overlay');
  if (!btn || !sidebar || !overlay) return;

  function cerrar() {
    sidebar.classList.remove('show');
    overlay.classList.remove('show');
  }

  btn.addEventListener('click', function () {
    sidebar.classList.toggle('show');
    overlay.classList.toggle('show');
  });
  overlay.addEventListener('click', cerrar);
})();

// Modo oscuro / claro
(function () {
  var btn = document.getElementById('theme-toggle');
  if (!btn) return;
  var icon = btn.querySelector('i');

  function actualizarIcono() {
    var actual = document.documentElement.getAttribute('data-bs-theme');
    icon.className = actual === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars';
  }
  actualizarIcono();

  btn.addEventListener('click', function () {
    var actual = document.documentElement.getAttribute('data-bs-theme');
    var nuevo = actual === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-bs-theme', nuevo);
    localStorage.setItem('theme', nuevo);
    actualizarIcono();
  });
})();
</script>
<?php if (!empty($extraJs)) echo $extraJs; ?>
</body>
</html>