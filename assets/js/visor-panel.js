/**
 * Panel de visor reutilizable (zoom/pan/W-L/rotar/invertir + herramientas de
 * medición y anotación de image-tools.js). Pensado para poder instanciar
 * varios visores independientes en una misma página (ej. comparación).
 *
 * Requiere que existan en el DOM, con el sufijo indicado, los elementos:
 *   #stage{sfx}  contenedor relativo (.visor-wrap)
 *   #img{sfx}, #canvas{sfx}, #overlay{sfx}, #msg{sfx}, #badge{sfx}
 *   #sl-br{sfx}, #sl-ct{sfx}, #lb-br{sfx}, #lb-ct{sfx},
 *   #lb-br-name{sfx}, #lb-ct-name{sfx}
 *   #frame-ctrl{sfx}, #sl-frame{sfx}, #lb-frame{sfx} (opcionales, multi-frame)
 */
(function (global) {

  function create(sfx, opts) {
    opts = opts || {};
    function $(id) { return document.getElementById(id + sfx); }

    var ts = 1, tr = 0, ti = false, dcmInfo = null, dcmFrame = 0, panX = 0, panY = 0;
    var currentTool = 'pan';
    var itools = null;
    var currentSrc = '';

    function calibBadge(mm) {
      var b = $('badge');
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

    function clearCalib() {
      if (itools && itools.getCalibration()) {
        if (global.confirm('¿Borrar la calibración de escala para esta imagen?')) {
          itools.clearCalibration();
        }
      } else {
        global.alert('Esta imagen todavía no tiene una escala calibrada. Usá la herramienta "Calibrar" (↔) para definirla.');
      }
    }

    function applyT(v1, v2) {
      var img = $('img');
      var canvas = $('canvas');
      if (!img) return;
      var transform = 'translate(' + panX + 'px,' + panY + 'px) scale(' + ts + ') rotate(' + tr + 'deg)';
      if (dcmInfo) {
        DicomViewer.draw(canvas, dcmInfo, Number(v1), Math.max(1, Number(v2)), ti, dcmFrame);
        canvas.style.filter = '';
        canvas.style.transform = transform;
      } else {
        var f = 'brightness(' + v1 / 100 + ') contrast(' + v2 / 100 + ')';
        if (ti) f += ' invert(1)';
        img.style.filter = f;
        img.style.transform = transform;
      }
      if (itools) itools.sync();
    }

    function filtroValores() {
      applyT($('sl-br').value, $('sl-ct').value);
    }

    function filtro() {
      var v1 = $('sl-br').value, v2 = $('sl-ct').value;
      if (dcmInfo) {
        $('lb-br').textContent = v1;
        $('lb-ct').textContent = v2;
      } else {
        $('lb-br').textContent = v1 + '%';
        $('lb-ct').textContent = v2 + '%';
      }
      applyT(v1, v2);
    }

    function zoom(f) { ts = Math.max(.3, Math.min(5, ts * f)); filtroValores(); }
    function rot() { tr = (tr + 90) % 360; filtroValores(); }
    function inv() { ti = !ti; filtroValores(); }

    function tool(name, btn) {
      currentTool = name;
      var stage = $('stage');
      if (stage) stage.querySelectorAll('.tbtn').forEach(function (b) { b.classList.remove('active'); });
      if (btn) btn.classList.add('active');
      if (itools) itools.setTool(name);
    }

    function clearAnotaciones() {
      if (itools) itools.clear();
    }

    function exportPng(filename) {
      if (!itools) return;
      itools.exportPng(filename || ('imagen-anotada-' + (dcmInfo ? (dcmFrame + 1) : 1) + '.png'));
    }

    function frame() {
      dcmFrame = Number($('sl-frame').value);
      $('lb-frame').textContent = (dcmFrame + 1) + '/' + dcmInfo.numFrames;
      filtroValores();
    }

    function reset() {
      ts = 1; tr = 0; ti = false; dcmFrame = 0; panX = 0; panY = 0;
      var frameCtrl = $('frame-ctrl');
      if (dcmInfo) {
        $('sl-br').min = dcmInfo.min;
        $('sl-br').max = dcmInfo.max;
        $('sl-br').value = Math.round(dcmInfo.defaultWC);
        $('sl-ct').min = 1;
        $('sl-ct').max = Math.round((dcmInfo.max - dcmInfo.min) * 2) || 1;
        $('sl-ct').value = Math.round(dcmInfo.defaultWW);
        $('lb-br-name').textContent = 'Centro';
        $('lb-ct-name').textContent = 'Ancho';
        $('lb-br').textContent = Math.round(dcmInfo.defaultWC);
        $('lb-ct').textContent = Math.round(dcmInfo.defaultWW);
        if (frameCtrl) {
          if (dcmInfo.numFrames > 1) {
            frameCtrl.classList.remove('d-none');
            $('sl-frame').max = dcmInfo.numFrames - 1;
            $('sl-frame').value = 0;
            $('lb-frame').textContent = '1/' + dcmInfo.numFrames;
          } else {
            frameCtrl.classList.add('d-none');
          }
        }
      } else {
        $('sl-br').min = 20;
        $('sl-br').max = 200;
        $('sl-br').value = 100;
        $('sl-ct').min = 20;
        $('sl-ct').max = 300;
        $('sl-ct').value = 100;
        $('lb-br-name').textContent = 'Brillo';
        $('lb-ct-name').textContent = 'Contraste';
        $('lb-br').textContent = '100%';
        $('lb-ct').textContent = '100%';
        if (frameCtrl) frameCtrl.classList.add('d-none');
      }
      filtroValores();
    }

    function cargar(src) {
      currentSrc = src;
      var img = $('img');
      var canvas = $('canvas');
      var msg = $('msg');
      if (!img) return;
      msg.classList.add('d-none');
      img.style.transform = ''; img.style.filter = '';
      canvas.style.transform = ''; canvas.style.filter = '';

      if (typeof DicomViewer !== 'undefined' && DicomViewer.isDicom(src)) {
        img.classList.add('d-none');
        canvas.classList.add('d-none');
        msg.classList.remove('d-none');
        msg.textContent = 'Cargando imagen DICOM...';
        DicomViewer.load(src).then(function (info) {
          dcmInfo = info;
          msg.classList.add('d-none');
          canvas.classList.remove('d-none');
          reset();
        }).catch(function () {
          dcmInfo = null;
          canvas.classList.add('d-none');
          msg.classList.remove('d-none');
          msg.textContent = 'No se pudo previsualizar este archivo DICOM.';
        });
      } else {
        dcmInfo = null;
        canvas.classList.add('d-none');
        img.classList.remove('d-none');
        img.onload = function () { if (itools) itools.sync(); };
        img.src = src;
        reset();
      }
    }

    // Inicialización de herramientas de medición/anotación + pan/W-L
    (function () {
      var stage = $('stage');
      var overlay = $('overlay');
      if (!stage || !overlay || typeof ImageTools === 'undefined') return;

      itools = ImageTools.create({
        overlay: overlay,
        getActive: function () {
          return dcmInfo ? $('canvas') : $('img');
        },
        getState: function () { return { tr: tr }; },
        getPixelSpacing: function () {
          return dcmInfo ? ImageTools.pixelSpacingFromImage(dcmInfo.image) : null;
        },
        getCalibKey: function () { return currentSrc; },
        onCalibrationChange: calibBadge,
        onWheel: function (dir) {
          if (dcmInfo && dcmInfo.numFrames > 1) {
            var slider = $('sl-frame');
            var max = Number(slider.max);
            var val = Math.min(max, Math.max(0, dcmFrame + dir));
            slider.value = val;
            frame();
          } else {
            zoom(dir > 0 ? 0.9 : 1.1);
          }
        }
      });
      itools.setTool('pan');

      var dragging = false, lastX = 0, lastY = 0;
      stage.addEventListener('pointerdown', function (e) {
        if (currentTool !== 'pan' && currentTool !== 'wl') return;
        if (e.target.closest('button, a, input, select')) return;
        dragging = true; lastX = e.clientX; lastY = e.clientY;
        stage.setPointerCapture(e.pointerId);
        stage.style.cursor = currentTool === 'pan' ? 'grabbing' : 'ns-resize';
      });
      stage.addEventListener('pointermove', function (e) {
        if (!dragging) return;
        var dx = e.clientX - lastX, dy = e.clientY - lastY;
        lastX = e.clientX; lastY = e.clientY;
        if (currentTool === 'pan') {
          panX += dx; panY += dy;
          filtroValores();
        } else if (currentTool === 'wl') {
          var br = $('sl-br'), ct = $('sl-ct');
          if (dcmInfo) {
            var range = (dcmInfo.max - dcmInfo.min) || 1;
            br.value = Math.round(Math.min(Number(br.max), Math.max(Number(br.min), Number(br.value) - dy * range / 256)));
            ct.value = Math.round(Math.min(Number(ct.max), Math.max(Number(ct.min), Number(ct.value) + dx * range / 128)));
          } else {
            br.value = Math.min(Number(br.max), Math.max(Number(br.min), Number(br.value) - dy));
            ct.value = Math.min(Number(ct.max), Math.max(Number(ct.min), Number(ct.value) + dx));
          }
          filtro();
        }
      });
      stage.addEventListener('pointerup', function () {
        dragging = false;
        stage.style.cursor = '';
      });
      window.addEventListener('resize', function () { if (itools) itools.sync(); });
    })();

    return {
      cargar: cargar,
      tool: tool,
      zoom: zoom,
      rot: rot,
      inv: inv,
      reset: reset,
      frame: frame,
      filtro: filtro,
      clearAnotaciones: clearAnotaciones,
      clearCalib: clearCalib,
      exportPng: exportPng
    };
  }

  global.VisorPanel = { create: create };

})(window);
