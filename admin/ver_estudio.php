<?php
// Incluir dependencias antes del layout para poder responder AJAX sin HTML
require_once __DIR__ . '/../config/auth.php';
requireLogin();
require_once __DIR__ . '/../config/helpers.php';
$u = sesionUsuario();

if (!puedeHacer('ver_estudios')) {
    http_response_code(403);
    die('No tenés permiso para ver estudios.');
}

$id = (int)($_GET['id'] ?? 0);

// Cargar datos del estudio (necesarios por handlers POST y por la vista)
$stmtEst = db()->prepare(
    'SELECT e.*, p.nombre, p.apellido, p.dni, p.fecha_nac, p.obra_social, p.telefono, p.email
     FROM estudios e JOIN pacientes p ON p.id=e.paciente_id WHERE e.id=?'
);
$stmtEst->execute([$id]);
$est = $stmtEst->fetch();

$stmtImgs = db()->prepare('SELECT * FROM imagenes WHERE estudio_id=? ORDER BY orden');
$stmtImgs->execute([$id]);
$imgs = $stmtImgs->fetchAll();

$infStmt = db()->prepare(
    'SELECT i.*, i.firmado_en, u2.nombre AS firmante_nombre
     FROM informes i LEFT JOIN usuarios u2 ON u2.id = i.firmado_por
     WHERE i.estudio_id=?'
);
$infStmt->execute([$id]);
$inf = $infStmt->fetch();
$informe = $inf ? $inf['cuerpo'] : '';

// Cargar anotaciones (GET AJAX) — antes del layout
if (($_GET['accion'] ?? '') === 'cargar_anotaciones') {
    $filename = basename($_GET['filename'] ?? '');
    $row = db()->prepare('SELECT data FROM anotaciones_visor WHERE estudio_id=? AND imagen_filename=?');
    $row->execute([$id, $filename]);
    $r = $row->fetchColumn();
    header('Content-Type: application/json');
    echo $r ?: '[]';
    exit;
}

// Handlers POST AJAX — antes del layout para evitar que HTML contamine la respuesta JSON
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck(true);
    $accionPost = $_POST['accion'] ?? '';

    if ($accionPost === 'guardar_anotaciones') {
        if (!puedeHacer('ver_estudios')) jsonOut(['ok' => false, 'msg' => 'Sin permiso'], 403);
        $filename = basename($_POST['filename'] ?? '');
        $data = $_POST['data'] ?? '[]';
        json_decode($data);
        if (json_last_error() !== JSON_ERROR_NONE) jsonOut(['ok' => false, 'msg' => 'JSON inválido']);
        db()->prepare('INSERT INTO anotaciones_visor (estudio_id,imagen_filename,data) VALUES (?,?,?)
                       ON DUPLICATE KEY UPDATE data=VALUES(data), updated_at=NOW()')
           ->execute([$id, $filename, $data]);
        jsonOut(['ok' => true]);
    }

    if ($accionPost === 'compartir_estudio') {
        if (!puedeHacer('ver_estudios')) jsonOut(['ok' => false, 'msg' => 'Sin permiso'], 403);
        $horas = (int)($_POST['horas'] ?? 24);
        $horas = max(1, min(168, $horas));
        $desc  = mb_substr(trim($_POST['descripcion'] ?? ''), 0, 120);
        $token = bin2hex(random_bytes(16));
        $vence = date('Y-m-d H:i:s', strtotime("+{$horas} hours"));
        db()->prepare('INSERT INTO compartidos (estudio_id,token,descripcion,vence_en,created_by) VALUES (?,?,?,?,?)')
           ->execute([$id, $token, $desc, $vence, $u['id']]);
        registrarAuditoria('compartir_estudio', "Token: $token, vence: $vence", $id);
        jsonOut(['ok' => true, 'url' => BASE_URL . '/ver/compartido.php?t=' . $token, 'vence' => $vence]);
    }

    if ($accionPost === 'firmar_informe') {
        if (!puedeHacer('escribir_informe')) jsonOut(['ok' => false, 'msg' => 'Sin permiso'], 403);
        $infCheck = db()->prepare('SELECT id, firmado_en FROM informes WHERE estudio_id=?');
        $infCheck->execute([$id]);
        $infRow = $infCheck->fetch();
        if (!$infRow) jsonOut(['ok' => false, 'msg' => 'No hay informe cargado para firmar']);
        if ($infRow['firmado_en']) jsonOut(['ok' => false, 'msg' => 'El informe ya está firmado']);
        // Leer cuerpo actual para calcular hash
        $cuerpoActual = db()->prepare('SELECT cuerpo FROM informes WHERE id=?');
        $cuerpoActual->execute([$infRow['id']]);
        $hashInput = $cuerpoActual->fetchColumn() ?? '';
        $hashDoc = hash('sha256', $hashInput);
        db()->prepare('UPDATE informes SET firmado_en=NOW(), firmado_por=?, hash_contenido=? WHERE id=?')
           ->execute([$u['id'], $hashDoc, $infRow['id']]);
        registrarAuditoria('firmar_informe', 'Informe firmado · hash: ' . substr($hashDoc, 0, 16) . '…', $id);
        db()->prepare("UPDATE estudios SET estado='informado' WHERE id=? AND estado='pendiente'")
           ->execute([$id]);
        jsonOut(['ok' => true, 'firmado_en' => date('Y-m-d H:i:s')]);
    }

    if (isset($_POST['informe'])) {
        if (!puedeHacer('escribir_informe')) jsonOut(['ok' => false, 'error' => 'Sin permiso'], 403);
        $cuerpo = trim($_POST['informe']);
        $nuevoHash = hash('sha256', $cuerpo);
        $db2 = db();
        $ex = $db2->prepare('SELECT id, firmado_en FROM informes WHERE estudio_id=?');
        $ex->execute([$id]);
        $infRow2 = $ex->fetch();
        if ($infRow2) {
            // Si estaba firmado, actualizar también el hash para que refleje el contenido actual
            $db2->prepare('UPDATE informes SET cuerpo=?,usuario_id=?,hash_contenido=? WHERE estudio_id=?')
                ->execute([$cuerpo, $u['id'], $nuevoHash, $id]);
        } else {
            $db2->prepare('INSERT INTO informes (estudio_id,usuario_id,cuerpo,hash_contenido) VALUES (?,?,?,?)')
                ->execute([$id, $u['id'], $cuerpo, $nuevoHash]);
        }
    jsonOut(['ok' => true]);
    } // fin if(isset informe)
} // fin if(POST)

// Editar datos del estudio
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'editar_estudio') {
    if (!puedeHacer('editar_estudio')) { redir('ver_estudio.php?id='.$id); }
    $descripcion = trim($_POST['descripcion'] ?? '');
    $medico      = trim($_POST['medico_der'] ?? '');
    $fecha       = $_POST['fecha_estudio'] ?? '';
    $tipo        = $_POST['tipo'] ?? '';
    $diasVig     = (int)($_POST['dias_vigencia'] ?? -1);
    if (!array_key_exists($tipo, TIPOS_ESTUDIO)) $tipo = $est['tipo'];
    $vence = $est['vence_en'];
    if ($diasVig === 0) {
        $vence = null;
    } elseif ($diasVig > 0) {
        $vence = date('Y-m-d', strtotime("+{$diasVig} days"));
    }
    db()->prepare('UPDATE estudios SET descripcion=?, medico_der=?, fecha_estudio=?, tipo=?, vence_en=? WHERE id=?')
        ->execute([$descripcion, $medico, $fecha ?: $est['fecha_estudio'], $tipo, $vence, $id]);
    registrarAuditoria('editar', 'estudio', $id, $est['apellido'] . ', ' . $est['nombre']);
    redir('ver_estudio.php?id=' . $id);
}

// Agregar imágenes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'agregar_imagenes') {
    if (!puedeHacer('subir_imagenes')) { redir('ver_estudio.php?id='.$id); }
    if (!empty($_FILES['imagenes']['name'][0])) {
        $maxOrden = db()->prepare('SELECT COALESCE(MAX(orden),-1) FROM imagenes WHERE estudio_id=?');
        $maxOrden->execute([$id]);
        $orden = (int)$maxOrden->fetchColumn() + 1;
        foreach ($_FILES['imagenes']['name'] as $i => $name) {
            if ($_FILES['imagenes']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $file = [
                'name'     => $_FILES['imagenes']['name'][$i],
                'tmp_name' => $_FILES['imagenes']['tmp_name'][$i],
                'size'     => $_FILES['imagenes']['size'][$i],
            ];
            $fn = subirImagen($file);
            if ($fn) {
                db()->prepare('INSERT INTO imagenes (estudio_id, filename, orden) VALUES (?,?,?)')
                    ->execute([$id, $fn, $orden++]);
            }
        }
    }
    redir('ver_estudio.php?id=' . $id);
}

// Eliminar imagen individual
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar_imagen') {
    if (!puedeHacer('subir_imagenes')) { redir('ver_estudio.php?id='.$id); }
    $imgId = (int)($_POST['img_id'] ?? 0);
    $imgStmt = db()->prepare('SELECT filename FROM imagenes WHERE id=? AND estudio_id=?');
    $imgStmt->execute([$imgId, $id]);
    $imgRow = $imgStmt->fetch();
    if ($imgRow) {
        $path = UPLOAD_DIR . $imgRow['filename'];
        if (is_file($path)) unlink($path);
        db()->prepare('DELETE FROM imagenes WHERE id=?')->execute([$imgId]);
        registrarAuditoria('eliminar_imagen', 'estudio', $id, $imgRow['filename']);
    }
    redir('ver_estudio.php?id=' . $id);
}

// Cambiar estado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'cambiar_estado') {
    if (!puedeHacer('cambiar_estado')) { redir('ver_estudio.php?id='.$id); }
    $nuevoEstado = $_POST['estado'] ?? '';
    if (in_array($nuevoEstado, ['pendiente','informado','entregado'])) {
        db()->prepare('UPDATE estudios SET estado=? WHERE id=?')->execute([$nuevoEstado, $id]);
        registrarAuditoria('cambiar_estado', 'estudio', $id, $nuevoEstado);
        // Notificar al paciente cuando pasa a "informado"
        if ($nuevoEstado === 'informado') {
            $nombreCentro = getCfg('nombre_centro', 'el centro de diagnóstico');
            $urlEst = BASE_URL . '/ver/' . $est['codigo_acceso'];
            $msg = "Hola {$est['nombre']}, su informe de {$est['tipo']} del " . fmtFecha($est['fecha_estudio']) . " está disponible.\nPuede acceder en: $urlEst\nCódigo: {$est['codigo_acceso']}";
            if (!empty($est['email'])) {
                enviarEmail($est['email'], "Su informe está listo — $nombreCentro",
                    "Estimado/a {$est['nombre']} {$est['apellido']},\n\nSu informe de estudio ya está disponible.\n\nTipo: " . labelTipo($est['tipo']) . "\nFecha: " . fmtFecha($est['fecha_estudio']) . "\n\nAcceda en: $urlEst\nCódigo de acceso: {$est['codigo_acceso']}\n\n$nombreCentro");
            }
            $wa = waLink($est['telefono'] ?? null, $msg);
            // El link de WA se usa desde el frontend; aquí solo guardamos en auditoría que se intentó notificar
            if ($wa) {
                registrarAuditoria('notificar_wa', 'estudio', $id, 'Link WA: ' . $wa);
            }
        }
    }
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        $waUrl = ($nuevoEstado === 'informado') ? waLink($est['telefono'] ?? null,
            "Su informe de " . labelTipo($est['tipo']) . " está disponible: " . BASE_URL . '/ver/' . $est['codigo_acceso']) : null;
        jsonOut(['ok' => true, 'wa' => $waUrl]);
    }
    redir('ver_estudio.php?id='.$id);
}

// Eliminar estudio completo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar_estudio') {
    if (!puedeHacer('eliminar_estudio')) { redir('ver_estudio.php?id='.$id); }
    $imgsDel = db()->prepare('SELECT filename FROM imagenes WHERE estudio_id=?');
    $imgsDel->execute([$id]);
    foreach ($imgsDel->fetchAll() as $im) {
        $p = UPLOAD_DIR . $im['filename'];
        if (is_file($p)) unlink($p);
    }
    db()->prepare('DELETE FROM imagenes WHERE estudio_id=?')->execute([$id]);
    db()->prepare('DELETE FROM informes WHERE estudio_id=?')->execute([$id]);
    db()->prepare('DELETE FROM accesos_log WHERE estudio_id=?')->execute([$id]);
    db()->prepare('DELETE FROM estudios WHERE id=?')->execute([$id]);
    registrarAuditoria('eliminar', 'estudio', $id, $est['apellido'] . ', ' . $est['nombre'] . ' · ' . $est['codigo_acceso']);
    redir('estudios.php');
}

$accesos = db()->prepare('SELECT ip, user_agent, accessed_at FROM accesos_log WHERE estudio_id=? ORDER BY accessed_at DESC LIMIT 50');
$accesos->execute([$id]);
$accesos = $accesos->fetchAll();

$urlPublica = BASE_URL . '/ver/' . $est['codigo_acceso'];

$pageTitle  = 'Detalle del estudio';
$activePage = 'estudios';
require_once __DIR__ . '/_layout.php';

if (!$est) {
    echo '<div class="alert alert-danger">Estudio no encontrado.</div>';
    require_once __DIR__ . '/_layout_end.php';
    exit;
}
?>

<div class="row g-3">

  <!-- Info -->
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold small">Paciente</span>
      </div>
      <div class="card-body small">
        <div class="fw-semibold fs-6 mb-1">
          <a href="<?= BASE_URL ?>/admin/historial_paciente.php?pac_id=<?= $est['paciente_id'] ?>" class="text-decoration-none" title="Ver historial del paciente">
            <?= e($est['apellido'] . ', ' . $est['nombre']) ?>
          </a>
        </div>
        <div class="text-muted">DNI: <?= e($est['dni']) ?></div>
        <?php if ($est['fecha_nac']): ?>
          <div class="text-muted">Nac: <?= fmtFecha($est['fecha_nac']) ?></div>
        <?php endif; ?>
        <?php if ($est['obra_social']): ?>
          <div class="text-muted">OS: <?= e($est['obra_social']) ?></div>
        <?php endif; ?>
        <?php if ($est['telefono']): ?>
          <div class="text-muted">Tel: <?= e($est['telefono']) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold small">Estudio</span>
        <div class="d-flex gap-1">
          <?php if (puedeHacer('editar_estudio')): ?>
          <button class="btn btn-sm btn-outline-secondary py-0" data-cmodal-open="modalEditarEstudio">
            <i class="bi bi-pencil"></i>
          </button>
          <?php endif; ?>
          <?php if (puedeHacer('eliminar_estudio')): ?>
          <button class="btn btn-sm btn-outline-danger py-0" data-cmodal-open="modalEliminarEstudio">
            <i class="bi bi-trash"></i>
          </button>
          <?php endif; ?>
        </div>
      </div>
      <div class="card-body small">
        <table class="table table-sm table-borderless mb-0">
          <tr><th class="text-muted fw-normal ps-0">Tipo</th>
              <td><span class="badge-tipo badge-<?= e($est['tipo']) ?>"><?= e(labelTipo($est['tipo'])) ?></span></td></tr>
          <tr><th class="text-muted fw-normal ps-0">Descripción</th><td><?= e($est['descripcion']) ?></td></tr>
          <tr><th class="text-muted fw-normal ps-0">Médico</th><td><?= e($est['medico_der']) ?></td></tr>
          <tr><th class="text-muted fw-normal ps-0">Fecha</th><td><?= fmtFecha($est['fecha_estudio']) ?></td></tr>
          <tr><th class="text-muted fw-normal ps-0">Link público</th>
            <td>
              <?php if ($est['vence_en']): ?>
                <?php $vencido = $est['vence_en'] < date('Y-m-d'); ?>
                <span class="<?= $vencido ? 'text-danger' : '' ?>">
                  <?= $vencido ? 'Venció el' : 'Vence el' ?> <?= fmtFecha($est['vence_en']) ?>
                </span>
              <?php else: ?>
                Sin vencimiento
              <?php endif; ?>
            </td>
          </tr>
        </table>
      </div>
    </div>

    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold small">Acceso público</span>
        <?php if (puedeHacer('ver_estudios')): ?>
        <button class="btn btn-sm btn-outline-primary py-0" data-bs-toggle="modal" data-bs-target="#modalCompartir">
          <i class="bi bi-share"></i> Compartir
        </button>
        <?php endif; ?>
      </div>
      <div class="card-body text-center">
        <div id="qr-admin" class="mb-2 d-flex justify-content-center"></div>
        <code class="d-block mb-2"><?= e($est['codigo_acceso']) ?></code>
        <div class="d-flex gap-2 justify-content-center">
          <a href="<?= e($urlPublica) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-eye"></i> Ver como paciente
          </a>
          <a href="imprimir_qr.php?id=<?= $id ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-printer"></i> Imprimir
          </a>
          <?php
          $waMsg = "Hola {$est['nombre']}, tu estudio de " . labelTipo($est['tipo']) . " está disponible.\n"
                 . "Código de acceso: {$est['codigo_acceso']}\n"
                 . "Link: {$urlPublica}";
          $waUrl = waLink($est['telefono'], $waMsg);
          ?>
          <?php if ($waUrl): ?>
          <a href="<?= e($waUrl) ?>" target="_blank" class="btn btn-sm btn-outline-success">
            <i class="bi bi-whatsapp"></i> WhatsApp
          </a>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </div>

  <!-- Visor + Informe -->
  <div class="col-lg-8">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="fw-semibold small">Imágenes (<?= count($imgs) ?>)</span>
        <div class="d-flex align-items-center gap-2">
          <?php
          $estadoLabels = ['pendiente'=>'Pendiente','informado'=>'Informado','entregado'=>'Entregado'];
          $estadoColors = ['pendiente'=>'bg-warning text-dark','informado'=>'bg-primary text-white','entregado'=>'bg-success text-white'];
          $estadoActual = $est['estado'] ?? 'pendiente';
          ?>
          <?php if (puedeHacer('cambiar_estado')): ?>
          <div class="dropdown">
            <button class="btn btn-sm dropdown-toggle py-0 px-2 <?= $estadoColors[$estadoActual] ?>" style="font-size:.75rem;border:none;" data-bs-toggle="dropdown">
              <?= $estadoLabels[$estadoActual] ?>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="min-width:140px;font-size:.85rem;">
              <?php foreach ($estadoLabels as $val => $lbl): ?>
              <li>
                <form method="post">
                  <?php csrfField(); ?>
                  <input type="hidden" name="accion" value="cambiar_estado">
                  <input type="hidden" name="estado" value="<?= $val ?>">
                  <button type="submit" class="dropdown-item <?= $val===$estadoActual?'fw-semibold':'' ?>"><?= $lbl ?></button>
                </form>
              </li>
              <?php endforeach; ?>
            </ul>
          </div>
          <?php else: ?>
          <span class="badge <?= $estadoColors[$estadoActual] ?> py-1 px-2"><?= $estadoLabels[$estadoActual] ?></span>
          <?php endif; ?>
          <?php if (puedeHacer('subir_imagenes')): ?>
          <button class="btn btn-sm btn-outline-primary py-0" data-cmodal-open="modalAgregarImg">
            <i class="bi bi-upload"></i> Agregar
          </button>
          <?php endif; ?>
        </div>
      </div>
      <div class="card-body p-0">
        <!-- Visor principal -->
        <div id="visor-main" style="background:#0a0e1a;min-height:360px;display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden;">
          <div style="position:absolute;top:10px;left:50%;transform:translateX(-50%);display:flex;gap:4px;background:rgba(0,0,0,.5);padding:5px 10px;border-radius:999px;z-index:10;">
            <button class="vbtn" onclick="vZoom(1.2)" title="Zoom +"><i class="bi bi-zoom-in"></i></button>
            <button class="vbtn" onclick="vZoom(0.8)" title="Zoom -"><i class="bi bi-zoom-out"></i></button>
            <button class="vbtn" onclick="vRot()" title="Rotar"><i class="bi bi-arrow-clockwise"></i></button>
            <button class="vbtn" onclick="vInv()" title="Invertir"><i class="bi bi-sun"></i></button>
            <button class="vbtn" onclick="vReset()" title="Resetear"><i class="bi bi-arrow-counterclockwise"></i></button>
          </div>
          <?php if ($imgs): ?>
            <img id="vimg" src="<?= e(urlImagen($imgs[0]['filename'])) ?>"
                 style="max-height:70vh;max-width:100%;transition:filter .15s,transform .15s;"
                 alt="Imagen del estudio">
            <canvas id="vcanvas" class="d-none" style="max-height:70vh;max-width:100%;transition:filter .15s,transform .15s;"></canvas>
            <canvas id="voverlay" style="position:absolute;top:0;left:0;pointer-events:none;"></canvas>
            <div id="vmsg" class="d-none" style="color:#cbd5e1;font-size:.85rem;text-align:center;padding:1rem;"></div>
            <span id="v-scale-badge" class="badge bg-secondary" style="position:absolute;top:10px;left:10px;z-index:5;cursor:pointer;" onclick="vClearCalib()" title="Escala de medición. Click para borrar la calibración.">Sin calibrar</span>
            <!-- Overlay de metadatos DICOM -->
            <div id="vdicom-overlay" class="d-none" style="position:absolute;inset:0;pointer-events:none;z-index:4;font-size:11px;line-height:1.55;font-family:'Inter',system-ui,sans-serif;">
              <div id="vdoi-tl" style="position:absolute;top:34px;left:8px;color:rgba(255,255,255,.85);text-shadow:0 1px 3px rgba(0,0,0,.95);max-width:45%;"></div>
              <div id="vdoi-tr" style="position:absolute;top:34px;right:8px;color:rgba(255,255,255,.85);text-shadow:0 1px 3px rgba(0,0,0,.95);text-align:right;max-width:45%;"></div>
              <div id="vdoi-bl" style="position:absolute;bottom:52px;left:8px;color:rgba(255,255,255,.75);text-shadow:0 1px 3px rgba(0,0,0,.95);"></div>
              <div id="vdoi-br" style="position:absolute;bottom:52px;right:8px;color:rgba(255,255,255,.75);text-shadow:0 1px 3px rgba(0,0,0,.95);text-align:right;"></div>
            </div>
            <div style="position:absolute;bottom:10px;left:50%;transform:translateX(-50%);display:flex;gap:4px;flex-wrap:wrap;justify-content:center;max-width:90%;background:rgba(0,0,0,.5);padding:5px 10px;border-radius:999px;z-index:10;">
              <button class="vbtn vtbtn active" data-tool="pan" onclick="vTool('pan',this)" title="Mover"><i class="bi bi-arrows-move"></i></button>
              <button class="vbtn vtbtn" data-tool="wl" onclick="vTool('wl',this)" title="Brillo/Contraste (arrastrar)"><i class="bi bi-sliders"></i></button>
              <button class="vbtn vtbtn" data-tool="length" onclick="vTool('length',this)" title="Medir distancia"><i class="bi bi-rulers"></i></button>
              <button class="vbtn vtbtn" data-tool="angle" onclick="vTool('angle',this)" title="Medir ángulo"><i class="bi bi-triangle"></i></button>
              <button class="vbtn vtbtn" data-tool="calibrate" onclick="vTool('calibrate',this)" title="Calibrar escala: arrastrá la regla celeste sobre un objeto de tamaño conocido"><i class="bi bi-arrow-left-right"></i></button>
              <button class="vbtn vtbtn" data-tool="density" onclick="vTool('density',this)" title="Perfil de densidad (dibujar línea)"><i class="bi bi-activity"></i></button>
              <button class="vbtn vtbtn" data-tool="rect" onclick="vTool('rect',this)" title="Región (ROI)"><i class="bi bi-bounding-box"></i></button>
              <button class="vbtn vtbtn" data-tool="ellipse" onclick="vTool('ellipse',this)" title="Elipse (ROI oval)"><i class="bi bi-circle"></i></button>
              <button class="vbtn vtbtn" data-tool="text" onclick="vTool('text',this)" title="Anotación de texto"><i class="bi bi-fonts"></i></button>
              <button class="vbtn" onclick="vClearAnotaciones()" title="Borrar anotaciones"><i class="bi bi-eraser"></i></button>
              <button class="vbtn" onclick="vExportPng()" title="Descargar imagen con anotaciones"><i class="bi bi-image-fill"></i></button>
            </div>
          <?php else: ?>
            <div class="text-muted small">Sin imágenes cargadas.</div>
          <?php endif; ?>
        </div>
        <!-- Controles brillo/contraste -->
        <div class="px-3 py-2 border-top d-flex align-items-center gap-3 flex-wrap visor-toolbar" style="background:#fff;">
          <div class="d-flex align-items-center gap-2">
            <label class="small text-muted mb-0" id="vlbl-br-name">Brillo</label>
            <input type="range" min="20" max="200" value="100" step="1" id="sl-br"
                   oninput="vFiltro()" style="width:100px;">
            <span id="lbl-br" class="small">100%</span>
          </div>
          <div class="d-flex align-items-center gap-2">
            <label class="small text-muted mb-0" id="vlbl-ct-name">Contraste</label>
            <input type="range" min="20" max="300" value="100" step="1" id="sl-ct"
                   oninput="vFiltro()" style="width:100px;">
            <span id="lbl-ct" class="small">100%</span>
          </div>
          <div class="d-flex align-items-center gap-2 d-none" id="vframe-ctrl">
            <label class="small text-muted mb-0">Corte</label>
            <input type="range" min="0" max="0" value="0" id="sl-frame" oninput="vFrame()" style="width:100px;">
            <span id="lbl-frame" class="small">1/1</span>
          </div>
        </div>
        <!-- Presets de ventana DICOM -->
        <div id="vpreset-bar" class="d-none px-3 py-2 border-top d-flex align-items-center gap-2 flex-wrap" style="background:#f8f9fa;">
          <span class="small text-muted" style="white-space:nowrap;">Ventana:</span>
          <button class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px;" onclick="vApplyPreset(null,null)" title="Ventana original del DICOM">Auto</button>
          <button class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px;" onclick="vApplyPreset(40,80)">Cerebro</button>
          <button class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px;" onclick="vApplyPreset(50,350)">Mediastino</button>
          <button class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px;" onclick="vApplyPreset(60,400)">Abdomen</button>
          <button class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px;" onclick="vApplyPreset(-600,1500)">Pulmón</button>
          <button class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px;" onclick="vApplyPreset(700,2000)">Hueso</button>
          <button class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px;" onclick="vApplyPreset(300,600)">Angio</button>
        </div>
        <!-- Perfil de densidad -->
        <div id="vdensity-panel" class="d-none border-top px-3 py-2" style="background:#f8f9fa;">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="small fw-semibold">Perfil de densidad</span>
            <button type="button" class="btn-close btn-close-sm" style="font-size:.6rem;" onclick="vCloseDensity()"></button>
          </div>
          <canvas id="vdensity-canvas" height="80" style="width:100%;border-radius:4px;background:#16181d;display:block;"></canvas>
          <div id="vdensity-info" class="text-muted mt-1" style="font-size:.75rem;"></div>
        </div>
        <!-- Thumbnails -->
        <?php if ($imgs): ?>
        <div class="d-flex gap-2 p-2 border-top overflow-auto visor-thumbs" style="background:#f8f9fa;">
          <?php foreach ($imgs as $i => $img): ?>
            <div class="position-relative" style="flex-shrink:0;">
              <div onclick="vCargar('<?= e(urlImagen($img['filename'])) ?>', this)"
                   class="thumb-item <?= $i===0?'active':'' ?>"
                   style="width:56px;height:56px;border-radius:6px;overflow:hidden;cursor:pointer;border:2px solid <?= $i===0?'#5b8def':'transparent' ?>;background:#0a0e1a;">
                <?php if (strtolower(pathinfo($img['filename'], PATHINFO_EXTENSION)) === 'dcm'): ?>
                  <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:#cbd5e1;font-size:.65rem;font-weight:700;">
                    <i class="bi bi-file-medical fs-5 d-block"></i>DCM
                  </div>
                <?php else: ?>
                  <img src="<?= e(urlImagen($img['filename'])) ?>"
                       style="width:100%;height:100%;object-fit:cover;"
                       alt="Imagen <?= $i+1 ?>">
                <?php endif; ?>
              </div>
              <form method="post" onsubmit="return confirm('¿Eliminar esta imagen?');"
                    style="position:absolute;top:-6px;right:-6px;">
                <?php csrfField(); ?>
                <input type="hidden" name="accion" value="eliminar_imagen">
                <input type="hidden" name="img_id" value="<?= $img['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger rounded-circle"
                        style="width:20px;height:20px;padding:0;line-height:1;font-size:11px;">
                  <i class="bi bi-x"></i>
                </button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div>

</div>

<div class="row g-3 mt-3">
  <div class="col-12">
    <!-- Informe -->
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
          <span class="fw-semibold small">Informe del radiólogo</span>
          <?php if ($inf && $inf['firmado_en']): ?>
          <span class="badge bg-success" style="font-size:.7rem;">
            <i class="bi bi-patch-check-fill"></i>
            Firmado · <?= date('d/m/Y H:i', strtotime($inf['firmado_en'])) ?>
            · <?= e($inf['firmante_nombre']) ?>
          </span>
          <?php endif; ?>
        </div>
        <div class="d-flex gap-1 align-items-center flex-wrap">
          <?php
          $tipo_est = $est['tipo'] ?? '';
          $plantillasInf = db()->prepare(
            'SELECT id, nombre, cuerpo FROM plantillas_informe WHERE tipo=? OR tipo=\'\' ORDER BY tipo DESC, nombre'
          );
          $plantillasInf->execute([$tipo_est]);
          $pls = $plantillasInf->fetchAll();
          if ($pls && puedeHacer('escribir_informe')):
          ?>
          <div class="dropdown">
            <button class="btn btn-sm btn-outline-secondary py-0 dropdown-toggle" data-bs-toggle="dropdown" style="font-size:.75rem;">
              <i class="bi bi-file-text"></i> Plantilla
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="min-width:240px;font-size:.83rem;max-height:300px;overflow-y:auto;">
              <?php foreach ($pls as $pl): ?>
              <li>
                <button type="button" class="dropdown-item py-1"
                  onclick="cargarPlantilla(<?= e(json_encode($pl['cuerpo'])) ?>)">
                  <?= e($pl['nombre']) ?>
                </button>
              </li>
              <?php endforeach; ?>
            </ul>
          </div>
          <?php endif; ?>
          <a href="<?= BASE_URL ?>/admin/imprimir_informe.php?id=<?= $id ?>" target="_blank"
             class="btn btn-sm btn-outline-secondary py-0">
            <i class="bi bi-printer"></i> Imprimir
          </a>
          <?php if (puedeHacer('escribir_informe')): ?>
          <button class="btn btn-sm btn-outline-success py-0" onclick="guardarInforme()">
            <i class="bi bi-floppy"></i> Guardar
          </button>
          <button class="btn btn-sm btn-outline-primary py-0" onclick="vFirmarInforme()">
            <i class="bi bi-patch-check"></i> Firmar
          </button>
          <?php endif; ?>
        </div>
      </div>
      <div class="card-body p-0">
        <textarea id="inf-txt"
                  placeholder="Escribí los hallazgos y conclusiones del estudio..."
                  <?= puedeHacer('escribir_informe') ? '' : 'readonly' ?>
                  style="width:100%;min-height:280px;border:none;border-radius:0;resize:vertical;
                         padding:16px 20px;font-size:14px;line-height:1.75;font-family:'Inter',system-ui,sans-serif;
                         color:#1e293b;background:#fff;outline:none;display:block;
                         border-bottom:1px solid var(--border-c);"><?= e($informe ?: '') ?></textarea>
        <div class="d-flex justify-content-between align-items-center px-3 py-2" style="background:#f8f9fb;">
          <div id="inf-word-count" class="text-muted" style="font-size:.75rem;"></div>
          <div id="inf-msg" class="small" style="font-size:.75rem;"></div>
        </div>
      </div>
    </div>
  </div>

</div>

<!-- Modal editar estudio -->
<div class="cmodal-overlay" id="modalEditarEstudio">
  <div class="cmodal-box">
    <form method="post">
      <?php csrfField(); ?>
      <input type="hidden" name="accion" value="editar_estudio">
      <div class="cmodal-header">
        <h5>Editar estudio</h5>
        <button type="button" class="btn-close" data-cmodal-close></button>
      </div>
      <div class="cmodal-body">
        <div class="mb-3">
          <label class="form-label small">Tipo</label>
          <select name="tipo" class="form-select form-select-sm">
            <?php foreach (TIPOS_ESTUDIO as $k => $lbl): ?>
              <option value="<?= e($k) ?>" <?= $est['tipo']===$k?'selected':'' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label small">Descripción</label>
          <input type="text" name="descripcion" class="form-control form-control-sm"
                 value="<?= e($est['descripcion'] ?? '') ?>">
        </div>
        <div class="mb-3">
          <label class="form-label small">Médico derivante</label>
          <input type="text" name="medico_der" class="form-control form-control-sm"
                 value="<?= e($est['medico_der'] ?? '') ?>">
        </div>
        <div class="mb-3">
          <label class="form-label small">Fecha del estudio</label>
          <input type="date" name="fecha_estudio" class="form-control form-control-sm"
                 value="<?= e($est['fecha_estudio'] ?? '') ?>">
        </div>
        <div class="mb-3">
          <label class="form-label small">Vigencia del link público</label>
          <select name="dias_vigencia" class="form-select form-select-sm">
            <option value="-1">Mantener actual<?= $est['vence_en'] ? ' (vence '.fmtFecha($est['vence_en']).')' : ' (sin vencimiento)' ?></option>
            <option value="0">Quitar vencimiento</option>
            <option value="7">7 días desde hoy</option>
            <option value="15">15 días desde hoy</option>
            <option value="30">30 días desde hoy</option>
            <option value="60">60 días desde hoy</option>
            <option value="90">90 días desde hoy</option>
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

<!-- Modal agregar imágenes -->
<div class="cmodal-overlay" id="modalAgregarImg">
  <div class="cmodal-box">
    <form method="post" enctype="multipart/form-data">
      <?php csrfField(); ?>
      <input type="hidden" name="accion" value="agregar_imagenes">
      <div class="cmodal-header">
        <h5>Agregar imágenes</h5>
        <button type="button" class="btn-close" data-cmodal-close></button>
      </div>
      <div class="cmodal-body">
        <input type="file" name="imagenes[]" class="form-control form-control-sm" multiple
               accept=".jpg,.jpeg,.png,.gif,.dcm" required>
        <div class="form-text small">Formatos permitidos: JPG, PNG, GIF, DCM. Tamaño máximo: 20 MB por archivo.</div>
      </div>
      <div class="cmodal-footer">
        <button type="button" class="btn btn-sm btn-outline-secondary" data-cmodal-close>Cancelar</button>
        <button class="btn btn-sm" style="background:var(--accent);color:#fff;">Subir</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal eliminar estudio -->
<div class="cmodal-overlay" id="modalEliminarEstudio">
  <div class="cmodal-box">
    <form method="post">
      <?php csrfField(); ?>
      <input type="hidden" name="accion" value="eliminar_estudio">
      <div class="cmodal-header">
        <h5>Eliminar estudio</h5>
        <button type="button" class="btn-close" data-cmodal-close></button>
      </div>
      <div class="cmodal-body">
        <p class="mb-0">Se eliminará el estudio de <strong><?= e($est['apellido'] . ', ' . $est['nombre']) ?></strong>,
        junto con todas sus imágenes, el informe y el historial de accesos. Esta acción no se puede deshacer.</p>
      </div>
      <div class="cmodal-footer">
        <button type="button" class="btn btn-sm btn-outline-secondary" data-cmodal-close>Cancelar</button>
        <button class="btn btn-sm btn-danger">Eliminar definitivamente</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal compartir estudio -->
<div class="modal fade" id="modalCompartir" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Compartir estudio</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label small">Descripción (opcional)</label>
          <input type="text" id="cmp-desc" class="form-control form-control-sm" placeholder="Ej: Segunda opinión Dr. García">
        </div>
        <div class="mb-3">
          <label class="form-label small">Vence en</label>
          <select id="cmp-horas" class="form-select form-select-sm">
            <option value="1">1 hora</option>
            <option value="6">6 horas</option>
            <option value="24" selected>24 horas</option>
            <option value="72">3 días</option>
            <option value="168">7 días</option>
          </select>
        </div>
        <div id="cmp-result" class="d-none">
          <label class="form-label small fw-semibold text-success">Link generado:</label>
          <div class="input-group input-group-sm">
            <input type="text" id="cmp-url" class="form-control" readonly>
            <button class="btn btn-outline-secondary" onclick="navigator.clipboard.writeText(document.getElementById('cmp-url').value)">Copiar</button>
          </div>
          <div class="small text-muted mt-1" id="cmp-vence"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
        <button class="btn btn-primary btn-sm" onclick="vCompartir()">Generar link</button>
      </div>
    </div>
  </div>
</div>

<?php
$baseUrl = BASE_URL;
$canEscribir = puedeHacer('escribir_informe') ? 'true' : 'false';
$csrfTok = csrfToken();
$extraJs = <<<JS
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>if (typeof window.require === 'undefined') window.require = function () {};</script>
<script src="{$baseUrl}/assets/js/daikon.min.js"></script>
<script src="{$baseUrl}/assets/js/dicom-viewer.js"></script>
<script src="{$baseUrl}/assets/js/image-tools.js"></script>
<script>var CSRF_TOKEN = '{$csrfTok}';</script>
<script>
new QRCode(document.getElementById('qr-admin'), {
  text: '{$urlPublica}', width: 120, height: 120
});

var vS = 1, vR = 0, vI = false, vDcmInfo = null, vDcmFrame = 0, vPanX = 0, vPanY = 0, vCurrentTool = 'pan';
var vitools = null;
var vCurrentSrc = '';
var vImgList = []; // [{url, el}] lista de imágenes del estudio para scroll
var vImgIdx  = 0;  // índice actual

function vCalibBadge(mm){
  var b = document.getElementById('v-scale-badge');
  if (!b) return;
  if (mm) {
    b.textContent = 'Escala: ' + mm.toFixed(4) + ' mm/px';
    b.classList.remove('bg-secondary');
    b.classList.add('bg-success');
  } else {
    b.textContent = 'Sin calibrar';
    b.classList.remove('bg-success');
    b.classList.add('bg-secondary');
  }
}

function vClearCalib(){
  if (vitools && vitools.getCalibration()) {
    if (confirm('¿Borrar la calibración de escala para esta imagen?')) {
      vitools.clearCalibration();
    }
  } else {
    alert('Esta imagen todavía no tiene una escala calibrada. Usá la herramienta "Calibrar" (↔) para definirla.');
  }
}

function vUpdateDicomOverlay() {
  var el  = document.getElementById('vdicom-overlay');
  var bar = document.getElementById('vpreset-bar');
  if (!el) return;
  if (!vDcmInfo) {
    el.classList.add('d-none');
    if (bar) { bar.classList.add('d-none'); bar.classList.remove('d-flex'); }
    return;
  }
  el.classList.remove('d-none');
  if (bar) { bar.classList.remove('d-none'); bar.classList.add('d-flex'); }
  var img = vDcmInfo.image;
  // Top-left: nombre y ID del paciente
  var name = img.getPatientName ? String(img.getPatientName() || '').replace(/\^/g,' ').trim() : '';
  var pid  = img.getPatientID  ? String(img.getPatientID()   || '').trim() : '';
  document.getElementById('vdoi-tl').innerHTML =
    (name ? '<strong>' + name + '</strong><br>' : '') + (pid ? 'ID: ' + pid : '');
  // Top-right: modalidad y fecha del estudio
  var mod  = img.getModality  ? (img.getModality()  || '') : '';
  var raw  = img.getStudyDate ? (img.getStudyDate() || '') : '';
  var fmtD = raw.length === 8
    ? raw.substr(6,2)+'/'+raw.substr(4,2)+'/'+raw.substr(0,4) : raw;
  var descTag = img.getTag ? (img.getTag(8,4158) || img.getTag(8,4144)) : null;
  var desc = descTag && descTag.value ? String(Array.isArray(descTag.value)?descTag.value[0]:descTag.value).trim() : '';
  document.getElementById('vdoi-tr').innerHTML =
    (mod  ? '<strong>' + mod  + '</strong>' : '') +
    (fmtD ? (mod?' · ':'')+fmtD : '') +
    (desc ? '<br>'+desc : '');
  // Bottom-left: WC/WW (actualizado en applyV)
  vUpdateDicomOverlayWL();
  // Bottom-right: número de imagen / localización del corte
  var imgNum   = img.getImageNumber   ? img.getImageNumber()   : null;
  var sliceLoc = img.getSliceLocation ? img.getSliceLocation() : null;
  document.getElementById('vdoi-br').innerHTML =
    (imgNum   ? 'Im: '+imgNum : '') +
    (sliceLoc ? (imgNum?'<br>':'')+'Loc: '+Number(sliceLoc).toFixed(1)+' mm' : '');
}

function vUpdateDicomOverlayWL() {
  var el = document.getElementById('vdoi-bl');
  if (!el || !vDcmInfo) return;
  var wc = Number(document.getElementById('sl-br').value);
  var ww = Number(document.getElementById('sl-ct').value);
  el.textContent = 'WC: '+Math.round(wc)+' / WW: '+Math.round(ww);
}

function vApplyPreset(wc, ww) {
  if (!vDcmInfo) return;
  if (wc === null) { wc = vDcmInfo.defaultWC; ww = vDcmInfo.defaultWW; }
  document.getElementById('sl-br').value = Math.round(wc);
  document.getElementById('sl-ct').value = Math.round(Math.max(1, ww));
  document.getElementById('lbl-br').textContent = Math.round(wc);
  document.getElementById('lbl-ct').textContent = Math.round(ww);
  applyV(wc, ww);
}

function vFiltro() {
  var v1 = document.getElementById('sl-br').value;
  var v2 = document.getElementById('sl-ct').value;
  if (vDcmInfo) {
    document.getElementById('lbl-br').textContent = v1;
    document.getElementById('lbl-ct').textContent = v2;
  } else {
    document.getElementById('lbl-br').textContent = v1 + '%';
    document.getElementById('lbl-ct').textContent = v2 + '%';
  }
  applyV(v1, v2);
}
function applyV(v1, v2) {
  var img = document.getElementById('vimg');
  var canvas = document.getElementById('vcanvas');
  if (!img) return;
  var transform = 'translate(' + vPanX + 'px,' + vPanY + 'px) scale(' + vS + ') rotate(' + vR + 'deg)';
  if (vDcmInfo) {
    DicomViewer.draw(canvas, vDcmInfo, Number(v1), Math.max(1, Number(v2)), vI, vDcmFrame);
    canvas.style.filter = '';
    canvas.style.transform = transform;
  } else {
    var f = 'brightness(' + v1/100 + ') contrast(' + v2/100 + ')';
    if (vI) f += ' invert(1)';
    img.style.filter = f;
    img.style.transform = transform;
  }
  if (vitools) vitools.sync();
  vUpdateDicomOverlayWL();
}
function vZoom(f) { vS = Math.max(0.3, Math.min(5, vS * f)); vAplicarValores(); }
function vRot()  { vR = (vR + 90) % 360; vAplicarValores(); }
function vInv()  { vI = !vI; vAplicarValores(); }
function vAplicarValores() {
  applyV(document.getElementById('sl-br').value, document.getElementById('sl-ct').value);
}
function vTool(name, btn) {
  vCurrentTool = name;
  document.querySelectorAll('.vtbtn').forEach(b => { b.style.background='transparent'; b.style.color='rgba(255,255,255,.7)'; });
  if (btn) { btn.style.background='#5b8def'; btn.style.color='#fff'; }
  if (vitools) vitools.setTool(name);
}
function vClearAnotaciones() {
  if (vitools) vitools.clear();
}
function vExportPng() {
  if (!vitools) return;
  vitools.exportPng('imagen-anotada-{$est['codigo_acceso']}-' + (vDcmInfo ? (vDcmFrame+1) : 1) + '.png');
}
function vFrame() {
  vDcmFrame = Number(document.getElementById('sl-frame').value);
  document.getElementById('lbl-frame').textContent = (vDcmFrame+1) + '/' + vDcmInfo.numFrames;
  vAplicarValores();
}
function vReset() {
  vS=1; vR=0; vI=false; vDcmFrame=0; vPanX=0; vPanY=0;
  var frameCtrl = document.getElementById('vframe-ctrl');
  if (vDcmInfo) {
    document.getElementById('sl-br').min = vDcmInfo.min;
    document.getElementById('sl-br').max = vDcmInfo.max;
    document.getElementById('sl-br').value = Math.round(vDcmInfo.defaultWC);
    document.getElementById('sl-ct').min = 1;
    document.getElementById('sl-ct').max = Math.round((vDcmInfo.max - vDcmInfo.min) * 2) || 1;
    document.getElementById('sl-ct').value = Math.round(vDcmInfo.defaultWW);
    document.getElementById('vlbl-br-name').textContent = 'Centro';
    document.getElementById('vlbl-ct-name').textContent = 'Ancho';
    document.getElementById('lbl-br').textContent = Math.round(vDcmInfo.defaultWC);
    document.getElementById('lbl-ct').textContent = Math.round(vDcmInfo.defaultWW);
    if (vDcmInfo.numFrames > 1) {
      frameCtrl.classList.remove('d-none');
      document.getElementById('sl-frame').max = vDcmInfo.numFrames - 1;
      document.getElementById('sl-frame').value = 0;
      document.getElementById('lbl-frame').textContent = '1/' + vDcmInfo.numFrames;
    } else {
      frameCtrl.classList.add('d-none');
    }
  } else {
    document.getElementById('sl-br').min = 20;
    document.getElementById('sl-br').max = 200;
    document.getElementById('sl-br').value = 100;
    document.getElementById('sl-ct').min = 20;
    document.getElementById('sl-ct').max = 300;
    document.getElementById('sl-ct').value = 100;
    document.getElementById('vlbl-br-name').textContent = 'Brillo';
    document.getElementById('vlbl-ct-name').textContent = 'Contraste';
    document.getElementById('lbl-br').textContent = '100%';
    document.getElementById('lbl-ct').textContent = '100%';
    frameCtrl.classList.add('d-none');
  }
  vAplicarValores();
}
function vCargar(src, el) {
  // Sincronizar índice al cargar manualmente
  var _idx = vImgList.findIndex(function(x){ return x.url === src; });
  if (_idx >= 0) vImgIdx = _idx;
  vCurrentSrc = src;
  var img = document.getElementById('vimg');
  var canvas = document.getElementById('vcanvas');
  var msg = document.getElementById('vmsg');
  if (el) {
    document.querySelectorAll('.thumb-item').forEach(t => { t.style.borderColor='transparent'; });
    el.style.borderColor = '#5b8def';
  }
  msg.classList.add('d-none');
  img.style.transform=''; img.style.filter='';
  canvas.style.transform=''; canvas.style.filter='';

  if (typeof DicomViewer !== 'undefined' && DicomViewer.isDicom(src)) {
    img.classList.add('d-none');
    canvas.classList.add('d-none');
    msg.classList.remove('d-none');
    msg.textContent = 'Cargando imagen DICOM...';
    DicomViewer.load(src).then(function(info){
      vDcmInfo = info;
      msg.classList.add('d-none');
      canvas.classList.remove('d-none');
      vReset();
      vUpdateDicomOverlay();
      vCargarAnotaciones(src);
    }).catch(function(){
      vDcmInfo = null;
      vUpdateDicomOverlay();
      canvas.classList.add('d-none');
      msg.classList.remove('d-none');
      msg.textContent = 'No se pudo previsualizar este archivo DICOM.';
    });
  } else {
    vDcmInfo = null;
    vUpdateDicomOverlay();
    canvas.classList.add('d-none');
    img.classList.remove('d-none');
    img.onload = function(){
      if (vitools) vitools.sync();
      vCargarAnotaciones(src);
    };
    img.src = src;
    vReset();
  }
}
function vCargarAnotaciones(src) {
  var filename = src.split('/').pop();
  fetch('?id={$id}&accion=cargar_anotaciones&filename=' + encodeURIComponent(filename))
    .then(function(r){ return r.json(); })
    .then(function(data){ if (vitools && Array.isArray(data)) vitools.setShapes(data); })
    .catch(function(){});
}
document.querySelectorAll('.vbtn').forEach(b => {
  b.style.cssText = 'width:28px;height:28px;border-radius:50%;border:none;background:transparent;color:rgba(255,255,255,.7);cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:14px;';
  b.addEventListener('mouseenter', () => { if (!b.classList.contains('vtbtn') || vCurrentTool !== b.dataset.tool) b.style.background='rgba(255,255,255,.15)'; });
  b.addEventListener('mouseleave', () => { if (!b.classList.contains('vtbtn') || vCurrentTool !== b.dataset.tool) b.style.background='transparent'; });
});
var vPanBtn = document.querySelector('.vtbtn[data-tool="pan"]');
if (vPanBtn) { vPanBtn.style.background = '#5b8def'; vPanBtn.style.color = '#fff'; }

// Inicializa las herramientas de medición/anotación sobre el visor
(function(){
  var stage = document.getElementById('visor-main');
  var overlay = document.getElementById('voverlay');
  if (!stage || !overlay || typeof ImageTools === 'undefined') return;

  vitools = ImageTools.create({
    overlay: overlay,
    getActive: function(){
      return vDcmInfo ? document.getElementById('vcanvas') : document.getElementById('vimg');
    },
    getState: function(){ return { tr: vR }; },
    getPixelSpacing: function(){
      return vDcmInfo ? ImageTools.pixelSpacingFromImage(vDcmInfo.image) : null;
    },
    getCalibKey: function(){ return vCurrentSrc; },
    onCalibrationChange: vCalibBadge,
    onDensityLine: function(p0, p1) { vDensityProfile(p0, p1); },
    onChange: function() {
      var shapes = vitools.getShapes();
      var filename = vCurrentSrc.split('/').pop();
      fetch('?id={$id}', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'accion=guardar_anotaciones&filename=' + encodeURIComponent(filename) + '&data=' + encodeURIComponent(JSON.stringify(shapes)) + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
      });
    },
    onWheel: function(dir){
      if (vDcmInfo && vDcmInfo.numFrames > 1) {
        var slider = document.getElementById('sl-frame');
        var max = Number(slider.max);
        var val = Math.min(max, Math.max(0, vDcmFrame + dir));
        slider.value = val;
        vFrame();
      } else if (vImgList.length > 1) {
        vImgIdx = Math.min(vImgList.length - 1, Math.max(0, vImgIdx + dir));
        var item = vImgList[vImgIdx];
        vCargar(item.url, item.el);
      } else {
        vZoom(dir > 0 ? 0.9 : 1.1);
      }
    }
  });
  vitools.setTool('pan');

  var dragging=false, lastX=0, lastY=0;
  stage.addEventListener('pointerdown', function(e){
    if (vCurrentTool !== 'pan' && vCurrentTool !== 'wl') return;
    if (e.target.closest('button, a')) return;
    dragging=true; lastX=e.clientX; lastY=e.clientY;
    stage.setPointerCapture(e.pointerId);
    stage.style.cursor = vCurrentTool==='pan' ? 'grabbing' : 'ns-resize';
  });
  stage.addEventListener('pointermove', function(e){
    if (!dragging) return;
    var dx=e.clientX-lastX, dy=e.clientY-lastY;
    lastX=e.clientX; lastY=e.clientY;
    if (vCurrentTool === 'pan') {
      vPanX+=dx; vPanY+=dy;
      vAplicarValores();
    } else if (vCurrentTool === 'wl') {
      var br=document.getElementById('sl-br'), ct=document.getElementById('sl-ct');
      if (vDcmInfo) {
        var range=(vDcmInfo.max-vDcmInfo.min)||1;
        br.value = Math.round(Math.min(Number(br.max), Math.max(Number(br.min), Number(br.value) - dy*range/256)));
        ct.value = Math.round(Math.min(Number(ct.max), Math.max(Number(ct.min), Number(ct.value) + dx*range/128)));
      } else {
        br.value = Math.min(Number(br.max), Math.max(Number(br.min), Number(br.value) - dy));
        ct.value = Math.min(Number(ct.max), Math.max(Number(ct.min), Number(ct.value) + dx));
      }
      vFiltro();
    }
  });
  stage.addEventListener('pointerup', function(){
    dragging=false;
    stage.style.cursor='';
  });
  window.addEventListener('resize', function(){ if (vitools) vitools.sync(); });
})();
// Construir lista de imágenes para scroll de serie
document.querySelectorAll('.thumb-item').forEach(function(el, i) {
  var onclick = el.getAttribute('onclick') || '';
  var match = onclick.match(/vCargar\('([^']+)'/);
  if (match) vImgList.push({ url: match[1], el: el });
});

(function(){
  var img = document.getElementById('vimg');
  if (img) vCargar(img.getAttribute('src'), null);
})();
function vCloseDensity() {
  document.getElementById('vdensity-panel').classList.add('d-none');
}

function vDensityProfile(p0, p1) {
  var srcCanvas = document.getElementById('vcanvas');
  if (!srcCanvas || srcCanvas.classList.contains('d-none')) return;
  var steps = Math.ceil(Math.hypot(p1.x - p0.x, p1.y - p0.y));
  if (steps < 2) return;
  var ctx = srcCanvas.getContext('2d');
  var values = [];
  var scW = srcCanvas.width, scH = srcCanvas.height;
  for (var i = 0; i <= steps; i++) {
    var t = i / steps;
    var px = Math.round(p0.x + (p1.x - p0.x) * t);
    var py = Math.round(p0.y + (p1.y - p0.y) * t);
    if (px < 0 || py < 0 || px >= scW || py >= scH) continue;
    var pixel = ctx.getImageData(px, py, 1, 1).data;
    var val = vI ? 255 - pixel[0] : pixel[0];
    if (vDcmInfo) {
      var wc = Number(document.getElementById('sl-br').value);
      var ww = Math.max(1, Number(document.getElementById('sl-ct').value));
      val = Math.round((wc - ww / 2) + (val / 255) * ww);
    }
    values.push(val);
  }
  if (!values.length) return;
  var panel = document.getElementById('vdensity-panel');
  panel.classList.remove('d-none');
  var dc = document.getElementById('vdensity-canvas');
  dc.width = dc.offsetWidth || 400;
  var dctx = dc.getContext('2d');
  var dw = dc.width, dh = dc.height;
  dctx.clearRect(0, 0, dw, dh);
  dctx.fillStyle = '#16181d';
  dctx.fillRect(0, 0, dw, dh);
  var minV = Math.min.apply(null, values);
  var maxV = Math.max.apply(null, values);
  var rangeV = (maxV - minV) || 1;
  var pad = 8;
  dctx.strokeStyle = 'rgba(255,255,255,.08)';
  dctx.lineWidth = 1;
  for (var g = 0; g <= 4; g++) {
    var gy = pad + ((4 - g) / 4) * (dh - pad * 2);
    dctx.beginPath(); dctx.moveTo(pad, gy); dctx.lineTo(dw - pad, gy); dctx.stroke();
    dctx.fillStyle = 'rgba(255,255,255,.35)';
    dctx.font = '9px sans-serif';
    dctx.fillText(Math.round(minV + (g / 4) * rangeV), 2, gy + 3);
  }
  dctx.beginPath();
  dctx.strokeStyle = '#4ade80';
  dctx.lineWidth = 1.5;
  values.forEach(function(v, i) {
    var x = pad + (i / (values.length - 1)) * (dw - pad * 2);
    var y = pad + (1 - (v - minV) / rangeV) * (dh - pad * 2);
    i === 0 ? dctx.moveTo(x, y) : dctx.lineTo(x, y);
  });
  dctx.stroke();
  var unit = vDcmInfo ? ' HU' : '';
  var sp = vDcmInfo ? '' : ' (intensidad)';
  document.getElementById('vdensity-info').textContent =
    'Min: ' + Math.round(minV) + unit + '  Máx: ' + Math.round(maxV) + unit +
    '  Promedio: ' + Math.round(values.reduce(function(a,b){return a+b;},0)/values.length) + unit +
    '  ' + values.length + ' muestras' + sp;
}

function cargarPlantilla(texto) {
  if (document.getElementById('inf-txt').value.trim() && !confirm('¿Reemplazar el texto actual con la plantilla?')) return;
  var ta = document.getElementById('inf-txt');
  ta.value = texto;
  ta.dispatchEvent(new Event('input'));
  ta.focus();
}
var infAutoSaveTimer = null;

function infSetMsg(text, color) {
  var msg = document.getElementById('inf-msg');
  msg.textContent = text;
  msg.style.color = color || '#64748b';
}

function guardarInforme(silencioso) {
  var txt = document.getElementById('inf-txt').value;
  infSetMsg('Guardando…', '#94a3b8');
  fetch('?id={$id}', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: 'informe=' + encodeURIComponent(txt) + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
  }).then(function(r){ return r.json(); }).then(function(d){
    if (d.ok) {
      infSetMsg('✓ Guardado', '#22c55e');
      setTimeout(function(){ infSetMsg(''); }, 3000);
    } else {
      infSetMsg('Error al guardar', '#ef4444');
    }
  }).catch(function(){
    infSetMsg('Error de conexión', '#ef4444');
  });
}

function infUpdateCount() {
  var txt = document.getElementById('inf-txt').value.trim();
  var words = txt ? txt.split(/\s+/).length : 0;
  var chars = txt.length;
  document.getElementById('inf-word-count').textContent =
    words + ' palabras · ' + chars + ' caracteres';
}

(function() {
  var ta = document.getElementById('inf-txt');
  if (!ta) return;

  // Auto-grow
  function grow() {
    ta.style.height = 'auto';
    ta.style.height = Math.max(280, ta.scrollHeight) + 'px';
  }
  grow();

  // Auto-save con debounce 3s + contador
  ta.addEventListener('input', function() {
    grow();
    infUpdateCount();
    infSetMsg('Sin guardar…', '#f59e0b');
    clearTimeout(infAutoSaveTimer);
    if ({$canEscribir}) infAutoSaveTimer = setTimeout(function(){ guardarInforme(true); }, 3000);
  });

  infUpdateCount();
})();
function vCompartir() {
  var horas = document.getElementById('cmp-horas').value;
  var desc  = document.getElementById('cmp-desc').value;
  fetch('?id={$id}', {
    method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'accion=compartir_estudio&horas=' + horas + '&descripcion=' + encodeURIComponent(desc) + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
  }).then(function(r){ return r.json(); }).then(function(d){
    if (!d.ok) { alert(d.msg || 'Error'); return; }
    document.getElementById('cmp-result').classList.remove('d-none');
    document.getElementById('cmp-url').value = d.url;
    document.getElementById('cmp-vence').textContent = 'Vence: ' + d.vence;
  });
}
function vFirmarInforme() {
  if (!confirm('¿Firmar el informe? Esta acción es irreversible y el texto no podrá modificarse.')) return;
  var cuerpo = document.getElementById('inf-txt').value.trim();
  if (!cuerpo) { alert('El informe está vacío. Escribí el informe antes de firmar.'); return; }
  // Primero guardar, luego firmar
  fetch('?id={$id}', {
    method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'informe=' + encodeURIComponent(cuerpo) + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
  }).then(function(r){ return r.json(); }).then(function(saved){
    if (!saved.ok) { alert('Error al guardar el informe antes de firmar.'); return Promise.reject('save_failed'); }
    return fetch('?id={$id}', {
      method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: 'accion=firmar_informe&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
    });
  }).then(function(r){ return r.json(); }).then(function(d){
    if (!d.ok) { alert(d.msg || 'Error'); return; }
    location.reload();
  }).catch(function(){ alert('Error de conexión. Intentá de nuevo.'); });
}
</script>
JS;
require_once __DIR__ . '/_layout_end.php';
?>