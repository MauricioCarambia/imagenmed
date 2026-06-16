<?php
$pageTitle  = 'Usuarios';
$activePage = 'usuarios';
require_once __DIR__ . '/_layout.php';

if (!puedeHacer('ver_usuarios')) {
    echo '<div class="alert alert-warning">Acceso restringido.</div>';
    require_once __DIR__ . '/_layout_end.php';
    exit;
}

$error = '';
$ok    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear') {
        $nombre = trim($_POST['nombre'] ?? '');
        $email  = trim($_POST['email'] ?? '');
        $rol    = $_POST['rol'] ?? 'usuario';
        $pass   = $_POST['password'] ?? '';

        if (!in_array($rol, ['admin','radiologo','recepcionista','tecnico','usuario'], true)) $rol = 'usuario';

        if ($nombre === '' || $email === '' || $pass === '') {
            $error = 'Completá nombre, email y contraseña.';
        } elseif (strlen($pass) < 6) {
            $error = 'La contraseña debe tener al menos 6 caracteres.';
        } else {
            $existe = db()->prepare('SELECT id FROM usuarios WHERE email = ?');
            $existe->execute([$email]);
            if ($existe->fetch()) {
                $error = 'Ya existe un usuario con ese email.';
            } else {
                $stmt = db()->prepare('INSERT INTO usuarios (nombre, email, password, rol, activo) VALUES (?, ?, ?, ?, 1)');
                $stmt->execute([$nombre, $email, password_hash($pass, PASSWORD_DEFAULT), $rol]);
                registrarAuditoria('crear', 'usuario', (int)db()->lastInsertId(), $nombre . ' (' . $email . ')');
                $ok = 'Usuario creado correctamente.';
            }
        }
    }

    if ($accion === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id !== ($u['id'] ?? 0)) {
            db()->prepare('UPDATE usuarios SET activo = 1 - activo WHERE id = ?')->execute([$id]);
            registrarAuditoria('cambiar_estado', 'usuario', $id);
            $ok = 'Estado actualizado.';
        } else {
            $error = 'No podés desactivar tu propio usuario.';
        }
    }

    if ($accion === 'reset_pass') {
        $id   = (int)($_POST['id'] ?? 0);
        $pass = $_POST['password'] ?? '';
        if (strlen($pass) < 6) {
            $error = 'La contraseña debe tener al menos 6 caracteres.';
        } else {
            db()->prepare('UPDATE usuarios SET password = ? WHERE id = ?')
                ->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
            registrarAuditoria('reset_password', 'usuario', $id);
            $ok = 'Contraseña actualizada.';
        }
    }

    if ($accion === 'editar') {
        $id     = (int)($_POST['id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $email  = trim($_POST['email'] ?? '');
        $rol    = $_POST['rol'] ?? 'usuario';
        if (!in_array($rol, ['admin','radiologo','recepcionista','tecnico','usuario'], true)) $rol = 'usuario';
        if ($nombre === '' || $email === '') {
            $error = 'Completá nombre y email.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Email inválido.';
        } else {
            $existe = db()->prepare('SELECT id FROM usuarios WHERE email = ? AND id <> ?');
            $existe->execute([$email, $id]);
            if ($existe->fetch()) {
                $error = 'Ya existe otro usuario con ese email.';
            } else {
                db()->prepare('UPDATE usuarios SET nombre=?, email=?, rol=? WHERE id=?')
                    ->execute([$nombre, $email, $rol, $id]);
                registrarAuditoria('editar', 'usuario', $id);
                $ok = 'Usuario actualizado.';
            }
        }
    }

    if ($accion === 'eliminar') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === ($u['id'] ?? 0)) {
            $error = 'No podés eliminar tu propio usuario.';
        } else {
            db()->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
            registrarAuditoria('eliminar', 'usuario', $id);
            $ok = 'Usuario eliminado.';
        }
    }
}

$columnasU = [
    'nombre'     => 'nombre',
    'email'      => 'email',
    'rol'        => 'rol',
    'activo'     => 'activo',
    'created_at' => 'created_at',
];
$sortU = $_GET['sort'] ?? 'nombre';
$dirU  = (($_GET['dir'] ?? 'asc') === 'desc') ? 'DESC' : 'ASC';
if (!array_key_exists($sortU, $columnasU)) $sortU = 'nombre';

$usuarios = db()->query("SELECT id, nombre, email, rol, activo, created_at FROM usuarios ORDER BY {$columnasU[$sortU]} {$dirU}")->fetchAll();

function ordenLinkUsuarios(string $col, string $label, string $sort, string $dir): string {
    $nuevoDir = ($sort === $col && $dir === 'ASC') ? 'desc' : 'asc';
    $params = array_merge($_GET, ['sort' => $col, 'dir' => $nuevoDir]);
    $icon = '';
    if ($sort === $col) {
        $icon = ' <i class="bi bi-caret-' . ($dir === 'ASC' ? 'up' : 'down') . '-fill"></i>';
    }
    return '<a href="?' . e(http_build_query($params)) . '" class="text-decoration-none text-reset">' . e($label) . $icon . '</a>';
}
?>

<?php if ($error): ?>
  <div class="alert alert-danger py-2 small"><?= e($error) ?></div>
<?php endif; ?>
<?php if ($ok): ?>
  <div class="alert alert-success py-2 small"><?= e($ok) ?></div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-4">
  <div class="card-header bg-white d-flex justify-content-between align-items-center">
    <span class="fw-semibold small">Usuarios del sistema</span>
    <button class="btn btn-sm" style="background:var(--accent);color:#fff;" data-cmodal-open="modalCrear">
      <i class="bi bi-person-plus"></i> Nuevo usuario
    </button>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0 small align-middle">
      <thead class="table-light">
        <tr>
          <th><?= ordenLinkUsuarios('nombre', 'Nombre', $sortU, $dirU) ?></th>
          <th><?= ordenLinkUsuarios('email', 'Email', $sortU, $dirU) ?></th>
          <th><?= ordenLinkUsuarios('rol', 'Rol', $sortU, $dirU) ?></th>
          <th><?= ordenLinkUsuarios('activo', 'Estado', $sortU, $dirU) ?></th>
          <th><?= ordenLinkUsuarios('created_at', 'Alta', $sortU, $dirU) ?></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($usuarios as $row): ?>
        <tr>
          <td><?= e($row['nombre']) ?></td>
          <td><?= e($row['email']) ?></td>
          <td><span class="badge bg-secondary"><?= e($row['rol']) ?></span></td>
          <td>
            <?php if ($row['activo']): ?>
              <span class="badge bg-success">Activo</span>
            <?php else: ?>
              <span class="badge bg-danger">Inactivo</span>
            <?php endif; ?>
          </td>
          <td><?= fmtFecha(substr($row['created_at'], 0, 10)) ?></td>
          <td class="text-nowrap">
            <button class="btn btn-sm btn-outline-secondary py-0"
                    data-cmodal-open="modalEdit<?= $row['id'] ?>" title="Editar">
              <i class="bi bi-pencil"></i>
            </button>
            <button class="btn btn-sm btn-outline-secondary py-0"
                    data-cmodal-open="modalPass<?= $row['id'] ?>" title="Cambiar contraseña">
              <i class="bi bi-key"></i>
            </button>
            <?php if ($row['id'] != ($u['id'] ?? 0)): ?>
            <form method="post" class="d-inline">
              <input type="hidden" name="accion" value="toggle">
              <input type="hidden" name="id" value="<?= $row['id'] ?>">
              <button class="btn btn-sm btn-outline-<?= $row['activo'] ? 'danger' : 'success' ?> py-0">
                <?= $row['activo'] ? 'Desactivar' : 'Activar' ?>
              </button>
            </form>
            <button class="btn btn-sm btn-outline-danger py-0"
                    data-cmodal-open="modalDel<?= $row['id'] ?>" title="Eliminar">
              <i class="bi bi-trash"></i>
            </button>
            <?php endif; ?>
          </td>
        </tr>

        <!-- Modal editar usuario -->
        <div class="cmodal-overlay" id="modalEdit<?= $row['id'] ?>">
          <div class="cmodal-box">
            <form method="post">
              <input type="hidden" name="accion" value="editar">
              <input type="hidden" name="id" value="<?= $row['id'] ?>">
              <div class="cmodal-header">
                <h5>Editar usuario</h5>
                <button type="button" class="btn-close" data-cmodal-close></button>
              </div>
              <div class="cmodal-body">
                <div class="mb-3">
                  <label class="form-label small">Nombre</label>
                  <input type="text" name="nombre" class="form-control" value="<?= e($row['nombre']) ?>" required>
                </div>
                <div class="mb-3">
                  <label class="form-label small">Email</label>
                  <input type="email" name="email" class="form-control" value="<?= e($row['email']) ?>" required>
                </div>
                <div class="mb-3">
                  <label class="form-label small">Rol</label>
                  <select name="rol" class="form-select">
                    <?php foreach (['admin'=>'Administrador','radiologo'=>'Radiólogo','recepcionista'=>'Recepcionista','tecnico'=>'Técnico','usuario'=>'Usuario general'] as $val => $lbl): ?>
                    <option value="<?= $val ?>" <?= $row['rol']===$val?'selected':'' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="cmodal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-cmodal-close>Cancelar</button>
                <button class="btn btn-sm" style="background:var(--accent);color:#fff;">Guardar</button>
              </div>
            </form>
          </div>
        </div>

        <!-- Modal cambiar contraseña -->
        <div class="cmodal-overlay" id="modalPass<?= $row['id'] ?>">
          <div class="cmodal-box">
            <form method="post">
              <input type="hidden" name="accion" value="reset_pass">
              <input type="hidden" name="id" value="<?= $row['id'] ?>">
              <div class="cmodal-header">
                <h5>Cambiar contraseña · <?= e($row['nombre']) ?></h5>
                <button type="button" class="btn-close" data-cmodal-close></button>
              </div>
              <div class="cmodal-body">
                <label class="form-label small">Nueva contraseña</label>
                <input type="password" name="password" class="form-control" minlength="6" required>
              </div>
              <div class="cmodal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-cmodal-close>Cancelar</button>
                <button class="btn btn-sm" style="background:var(--accent);color:#fff;">Guardar</button>
              </div>
            </form>
          </div>
        </div>

        <!-- Modal eliminar usuario -->
        <?php if ($row['id'] != ($u['id'] ?? 0)): ?>
        <div class="cmodal-overlay" id="modalDel<?= $row['id'] ?>">
          <div class="cmodal-box" style="max-width:400px;">
            <form method="post">
              <input type="hidden" name="accion" value="eliminar">
              <input type="hidden" name="id" value="<?= $row['id'] ?>">
              <div class="cmodal-header">
                <h5>Eliminar usuario</h5>
                <button type="button" class="btn-close" data-cmodal-close></button>
              </div>
              <div class="cmodal-body">
                <p class="mb-0">¿Eliminar a <strong><?= e($row['nombre']) ?></strong> (<?= e($row['email']) ?>)?
                Esta acción no se puede deshacer.</p>
              </div>
              <div class="cmodal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-cmodal-close>Cancelar</button>
                <button class="btn btn-sm btn-danger">Eliminar</button>
              </div>
            </form>
          </div>
        </div>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal crear usuario -->
<div class="cmodal-overlay" id="modalCrear">
  <div class="cmodal-box">
    <form method="post">
      <input type="hidden" name="accion" value="crear">
      <div class="cmodal-header">
        <h5>Nuevo usuario</h5>
        <button type="button" class="btn-close" data-cmodal-close></button>
      </div>
      <div class="cmodal-body">
        <div class="mb-3">
          <label class="form-label small">Nombre</label>
          <input type="text" name="nombre" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label small">Email</label>
          <input type="email" name="email" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label small">Rol</label>
          <select name="rol" class="form-select">
            <option value="admin">Administrador</option>
            <option value="radiologo">Radiólogo</option>
            <option value="recepcionista">Recepcionista</option>
            <option value="tecnico">Técnico</option>
            <option value="usuario" selected>Usuario general (sin permisos)</option>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label small">Contraseña</label>
          <input type="password" name="password" class="form-control" minlength="6" required>
        </div>
      </div>
      <div class="cmodal-footer">
        <button type="button" class="btn btn-sm btn-outline-secondary" data-cmodal-close>Cancelar</button>
        <button class="btn btn-sm" style="background:var(--accent);color:#fff;">Crear</button>
      </div>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/_layout_end.php'; ?>
