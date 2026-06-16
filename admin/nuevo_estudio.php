<?php
$pageTitle  = 'Nuevo estudio';
$activePage = 'nuevo';
require_once __DIR__ . '/_layout.php';

if (!puedeHacer('crear_estudio')) {
    echo '<div class="alert alert-warning">No tenés permiso para crear estudios.</div>';
    require_once __DIR__.'/_layout_end.php';
    exit;
}

$errores = [];
$ok = false;
$codigoGenerado = '';
$emailEnviado = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- Validar paciente ---
    $dni      = trim($_POST['dni'] ?? '');
    $nombre   = trim($_POST['nombre'] ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    $fechaNac = $_POST['fecha_nac'] ?? null;
    $telefono = trim($_POST['telefono'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $obraSoc  = trim($_POST['obra_social'] ?? '');
    $nroAfil  = trim($_POST['nro_afiliado'] ?? '');

    // --- Validar estudio ---
    $tipo        = $_POST['tipo'] ?? '';
    $descripcion = trim($_POST['descripcion'] ?? '');
    $medicoDer   = trim($_POST['medico_der'] ?? '');
    $fechaEst    = $_POST['fecha_estudio'] ?? date('Y-m-d');
    $diasVig     = (int)($_POST['dias_vigencia'] ?? DIAS_VIGENCIA);

    if (!$dni)      $errores[] = 'DNI requerido.';
    if (!$nombre)   $errores[] = 'Nombre requerido.';
    if (!$apellido) $errores[] = 'Apellido requerido.';
    if (!array_key_exists($tipo, TIPOS_ESTUDIO)) $errores[] = 'Tipo de estudio inválido.';
    if (empty($_FILES['imagenes']['name'][0])) $errores[] = 'Subí al menos una imagen.';

    if (empty($errores)) {
        $db = db();
        $db->beginTransaction();
        try {
            // Upsert paciente por DNI
            $stmt = $db->prepare('SELECT id FROM pacientes WHERE dni = ?');
            $stmt->execute([$dni]);
            $pacId = $stmt->fetchColumn();

            if ($pacId) {
                $db->prepare(
                    'UPDATE pacientes SET nombre=?,apellido=?,telefono=?,email=?,obra_social=?,nro_afiliado=? WHERE id=?'
                )->execute([$nombre,$apellido,$telefono,$email,$obraSoc,$nroAfil,$pacId]);
            } else {
                $db->prepare(
                    'INSERT INTO pacientes (nombre,apellido,dni,fecha_nac,telefono,email,obra_social,nro_afiliado)
                     VALUES (?,?,?,?,?,?,?,?)'
                )->execute([$nombre,$apellido,$dni,$fechaNac ?: null,$telefono,$email,$obraSoc,$nroAfil]);
                $pacId = $db->lastInsertId();
            }

            // Insertar estudio
            $codigo = generarCodigo();
            $vence  = $diasVig > 0 ? date('Y-m-d', strtotime("+{$diasVig} days")) : null;
            $db->prepare(
                'INSERT INTO estudios (paciente_id,usuario_id,tipo,descripcion,medico_der,fecha_estudio,codigo_acceso,vence_en)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([$pacId, $u['id'], $tipo, $descripcion, $medicoDer, $fechaEst, $codigo, $vence]);
            $estId = $db->lastInsertId();

            // Subir imágenes
            $archivos = $_FILES['imagenes'];
            $total = count($archivos['name']);
            for ($i = 0; $i < $total; $i++) {
                if ($archivos['error'][$i] !== UPLOAD_ERR_OK) continue;
                $file = [
                    'name'     => $archivos['name'][$i],
                    'tmp_name' => $archivos['tmp_name'][$i],
                    'size'     => $archivos['size'][$i],
                    'type'     => $archivos['type'][$i],
                ];
                $fname = subirImagen($file);
                if ($fname) {
                    $db->prepare(
                        'INSERT INTO imagenes (estudio_id,filename,original,tipo_mime,orden) VALUES (?,?,?,?,?)'
                    )->execute([$estId, $fname, $archivos['name'][$i], $archivos['type'][$i], $i]);
                }
            }

            // Informe (opcional en carga inicial)
            $informe = trim($_POST['informe'] ?? '');
            if ($informe) {
                $db->prepare(
                    'INSERT INTO informes (estudio_id,usuario_id,cuerpo) VALUES (?,?,?)'
                )->execute([$estId, $u['id'], $informe]);
            }

            $db->commit();
            $codigoGenerado = $codigo;
            $estudioId = $estId;
            $ok = true;

            registrarAuditoria('crear', 'estudio', $estId, $apellido . ', ' . $nombre . ' · ' . $codigo);

            // Notificar al paciente por email con su código de acceso
            if ($email) {
                $urlPub = BASE_URL . '/ver/' . $codigo;
                $asunto = 'Tu estudio está disponible · ImagenMed';
                $cuerpo = "Hola {$nombre},\n\n"
                        . "Tu estudio de " . labelTipo($tipo) . " ya está disponible para consulta online.\n\n"
                        . "Código de acceso: {$codigo}\n"
                        . "Link directo: {$urlPub}\n\n"
                        . "Centro de Diagnóstico por Imágenes · ImagenMed";
                $emailEnviado = enviarEmail($email, $asunto, $cuerpo);
            }

        } catch (Throwable $ex) {
            $db->rollBack();
            $errores[] = 'Error interno: ' . $ex->getMessage();
        }
    }
}
?>

<?php if ($ok): ?>
<div class="card border-0 shadow-sm mb-4 mx-auto" style="max-width:520px;">
  <div class="card-body text-center py-4">
    <div class="mb-2 text-success fs-2"><i class="bi bi-check-circle-fill"></i></div>
    <h5 class="mb-1">Estudio guardado</h5>
    <p class="text-muted small mb-3">Código de acceso público:</p>
    <div class="display-6 fw-bold tracking-wide mb-3"><?= e($codigoGenerado) ?></div>
    <div id="qr-div" class="mb-3 d-flex justify-content-center"></div>

    <?php if ($emailEnviado === true): ?>
      <div class="alert alert-success small">
        <i class="bi bi-envelope-check"></i> Se envió el código de acceso al email del paciente.
      </div>
    <?php elseif ($emailEnviado === false): ?>
      <div class="alert alert-warning small">
        <i class="bi bi-envelope-exclamation"></i> No se pudo enviar el email al paciente (servidor de correo no disponible). Compartí el código manualmente.
      </div>
    <?php endif; ?>

    <?php
    $waMsg = "Hola {$nombre}, tu estudio de " . labelTipo($tipo) . " ya está disponible.\n"
           . "Código de acceso: {$codigoGenerado}\n"
           . "Link: " . BASE_URL . '/ver/' . $codigoGenerado;
    $waUrl = waLink($telefono, $waMsg);
    ?>
    <?php if ($waUrl): ?>
      <div class="mb-3">
        <a href="<?= e($waUrl) ?>" target="_blank" class="btn btn-sm btn-success">
          <i class="bi bi-whatsapp"></i> Enviar por WhatsApp
        </a>
      </div>
    <?php endif; ?>

    <div class="d-flex gap-2 justify-content-center">
      <a href="<?= BASE_URL ?>/admin/ver_estudio.php?id=<?= $estudioId ?>"
         class="btn btn-sm" style="background:var(--accent);color:#fff;">Ver estudio</a>
      <a href="<?= BASE_URL ?>/admin/imprimir_qr.php?id=<?= $estudioId ?>"
         target="_blank" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-printer"></i> Imprimir etiqueta
      </a>
      <a href="nuevo_estudio.php" class="btn btn-sm btn-outline-success">+ Nuevo</a>
    </div>
  </div>
</div>
<?php else: ?>

<?php if ($errores): ?>
  <div class="alert alert-danger small">
    <ul class="mb-0"><?php foreach ($errores as $e2): ?><li><?= e($e2) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">
<div class="row g-4">

  <!-- Datos del paciente -->
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white fw-semibold small">Paciente</div>
      <div class="card-body">
        <div class="row g-2">
          <div class="col-6">
            <label class="form-label small">Apellido *</label>
            <input type="text" name="apellido" class="form-control form-control-sm" required
                   value="<?= e($_POST['apellido'] ?? '') ?>">
          </div>
          <div class="col-6">
            <label class="form-label small">Nombre *</label>
            <input type="text" name="nombre" class="form-control form-control-sm" required
                   value="<?= e($_POST['nombre'] ?? '') ?>">
          </div>
          <div class="col-6">
            <label class="form-label small">DNI *</label>
            <input type="text" name="dni" class="form-control form-control-sm" required
                   value="<?= e($_POST['dni'] ?? '') ?>" id="inp-dni">
          </div>
          <div class="col-6">
            <label class="form-label small">Fecha de nacimiento</label>
            <input type="date" name="fecha_nac" class="form-control form-control-sm"
                   value="<?= e($_POST['fecha_nac'] ?? '') ?>">
          </div>
          <div class="col-6">
            <label class="form-label small">Teléfono</label>
            <input type="text" name="telefono" class="form-control form-control-sm"
                   value="<?= e($_POST['telefono'] ?? '') ?>">
          </div>
          <div class="col-6">
            <label class="form-label small">Email</label>
            <input type="email" name="email" class="form-control form-control-sm"
                   value="<?= e($_POST['email'] ?? '') ?>">
          </div>
          <div class="col-6">
            <label class="form-label small">Obra social</label>
            <input type="text" name="obra_social" class="form-control form-control-sm"
                   value="<?= e($_POST['obra_social'] ?? '') ?>">
          </div>
          <div class="col-6">
            <label class="form-label small">Nº afiliado</label>
            <input type="text" name="nro_afiliado" class="form-control form-control-sm"
                   value="<?= e($_POST['nro_afiliado'] ?? '') ?>">
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Datos del estudio -->
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold small">Estudio</div>
      <div class="card-body">
        <div class="mb-2">
          <label class="form-label small">Tipo *</label>
          <div class="d-flex flex-wrap gap-2">
            <?php foreach (TIPOS_ESTUDIO as $k => $label): ?>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="tipo" id="tipo_<?= $k ?>"
                       value="<?= $k ?>" <?= (($_POST['tipo'] ?? 'RX') === $k) ? 'checked' : '' ?>>
                <label class="form-check-label small" for="tipo_<?= $k ?>"><?= e($label) ?></label>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="mb-2">
          <label class="form-label small">Descripción / Región</label>
          <input type="text" name="descripcion" class="form-control form-control-sm"
                 placeholder="Ej: Tórax PA y lateral"
                 value="<?= e($_POST['descripcion'] ?? '') ?>">
        </div>
        <div class="mb-2">
          <label class="form-label small">Médico derivante</label>
          <input type="text" name="medico_der" class="form-control form-control-sm"
                 value="<?= e($_POST['medico_der'] ?? '') ?>">
        </div>
        <div class="mb-2">
          <label class="form-label small">Fecha del estudio *</label>
          <input type="date" name="fecha_estudio" class="form-control form-control-sm"
                 value="<?= e($_POST['fecha_estudio'] ?? date('Y-m-d')) ?>" required>
        </div>
        <div class="mb-2">
          <label class="form-label small">Vigencia del link público</label>
          <?php $diasVigSel = (int)($_POST['dias_vigencia'] ?? DIAS_VIGENCIA); ?>
          <select name="dias_vigencia" class="form-select form-select-sm">
            <option value="0" <?= $diasVigSel===0?'selected':'' ?>>Sin vencimiento</option>
            <option value="7" <?= $diasVigSel===7?'selected':'' ?>>7 días</option>
            <option value="15" <?= $diasVigSel===15?'selected':'' ?>>15 días</option>
            <option value="30" <?= $diasVigSel===30?'selected':'' ?>>30 días</option>
            <option value="60" <?= $diasVigSel===60?'selected':'' ?>>60 días</option>
            <option value="90" <?= $diasVigSel===90?'selected':'' ?>>90 días</option>
          </select>
        </div>
      </div>
    </div>

    <div class="card border-0 shadow-sm mt-3">
      <div class="card-header bg-white fw-semibold small">Imágenes *</div>
      <div class="card-body">
        <div id="drop-zone" class="border border-dashed rounded p-4 text-center text-muted small"
             style="border-style:dashed!important;cursor:pointer;">
          <i class="bi bi-cloud-upload fs-3 d-block mb-1"></i>
          Arrastrá las imágenes aquí o hacé clic<br>
          <span class="text-muted" style="font-size:.75rem;">JPG, PNG, DICOM — máx. 20 MB por archivo</span>
          <input type="file" name="imagenes[]" id="inp-files" multiple accept=".jpg,.jpeg,.png,.dcm"
                 class="d-none">
        </div>
        <div id="preview-strip" class="d-flex flex-wrap gap-2 mt-2"></div>
      </div>
    </div>
  </div>

  <!-- Informe -->
  <div class="col-12">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold small">Informe del radiólogo <span class="text-muted fw-normal">(opcional — puede cargarse después)</span></div>
      <div class="card-body">
        <textarea name="informe" class="form-control form-control-sm" rows="4"
                  placeholder="Hallazgos, conclusión..."><?= e($_POST['informe'] ?? '') ?></textarea>
      </div>
    </div>
  </div>

  <div class="col-12">
    <button type="submit" class="btn px-4" style="background:var(--accent);color:#fff;">
      <i class="bi bi-qr-code"></i> Guardar y generar QR
    </button>
  </div>

</div>
</form>
<?php endif; ?>

<?php
$extraJs = <<<JS
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
// Drop zone
var dz = document.getElementById('drop-zone');
var inp = document.getElementById('inp-files');
if (dz) {
  dz.addEventListener('click', () => inp.click());
  dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('bg-light'); });
  dz.addEventListener('dragleave', () => dz.classList.remove('bg-light'));
  dz.addEventListener('drop', e => {
    e.preventDefault(); dz.classList.remove('bg-light');
    inp.files = e.dataTransfer.files; renderPreviews(inp.files);
  });
  inp.addEventListener('change', () => renderPreviews(inp.files));
}
function renderPreviews(files) {
  var strip = document.getElementById('preview-strip');
  strip.innerHTML = '';
  Array.from(files).forEach((f, idx) => {
    var d = document.createElement('div');
    d.style.cssText = 'width:60px;height:60px;border-radius:6px;overflow:hidden;background:#0a0e1a;display:flex;align-items:center;justify-content:center;position:relative;';
    if (f.type.startsWith('image/')) {
      var img = document.createElement('img');
      img.src = URL.createObjectURL(f);
      img.style.cssText = 'width:100%;height:100%;object-fit:cover;';
      d.appendChild(img);
    } else {
      d.innerHTML = '<span style="color:#fff;font-size:10px;">DCM</span>';
    }
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.innerHTML = '&times;';
    btn.title = 'Quitar';
    btn.style.cssText = 'position:absolute;top:0;right:0;width:18px;height:18px;line-height:1;padding:0;border:0;border-radius:0 0 0 6px;background:rgba(220,53,69,.9);color:#fff;font-size:13px;cursor:pointer;';
    btn.addEventListener('click', () => removeFile(idx));
    d.appendChild(btn);
    strip.appendChild(d);
  });
}
function removeFile(idx) {
  var dt = new DataTransfer();
  Array.from(inp.files).forEach((f, i) => {
    if (i !== idx) dt.items.add(f);
  });
  inp.files = dt.files;
  renderPreviews(inp.files);
}

// QR al guardar
var qrDiv = document.getElementById('qr-div');
if (qrDiv) {
  new QRCode(qrDiv, {
    text: '<?= BASE_URL ?>/ver/<?= e($codigoGenerado) ?>',
    width: 150, height: 150
  });
}

// Autocompletar paciente por DNI
var dniInp = document.getElementById('inp-dni');
if (dniInp) {
  var t;
  dniInp.addEventListener('input', function() {
    clearTimeout(t);
    t = setTimeout(function() {
      var v = dniInp.value.trim();
      if (v.length < 6) return;
      fetch('<?= BASE_URL ?>/admin/ajax/buscar_paciente.php?dni=' + encodeURIComponent(v))
        .then(r => r.json()).then(d => {
          if (d.ok) {
            document.querySelector('[name=nombre]').value    = d.nombre;
            document.querySelector('[name=apellido]').value  = d.apellido;
            document.querySelector('[name=fecha_nac]').value = d.fecha_nac || '';
            document.querySelector('[name=telefono]').value  = d.telefono || '';
            document.querySelector('[name=email]').value     = d.email || '';
            document.querySelector('[name=obra_social]').value  = d.obra_social || '';
            document.querySelector('[name=nro_afiliado]').value = d.nro_afiliado || '';
          }
        }).catch(() => {});
    }, 500);
  });
}
</script>
JS;
require_once __DIR__ . '/_layout_end.php';
?>