/**
 * Herramientas de visor estilo Cornerstone (medición, ángulo, ROI, texto)
 * sobre un canvas overlay transparente. Funciona con cualquier elemento
 * activo (img o canvas) que tenga aplicado un transform CSS
 * "translate(...) scale(...) rotate(...)".
 */
(function (global) {

  var MIN_CALIB_MM = 10; // referencia mínima recomendada (1 cm) para una calibración precisa

  function create(opts) {
    var overlay = opts.overlay;
    var octx = overlay.getContext('2d');
    var tool = 'pan';
    var shapes = [];
    var drawing = null;
    var dragMode = null;

    var calibration = null; // mm por px, definido manualmente cuando no hay PixelSpacing del DICOM
    var calibKey = null;
    var ruler = null; // {p0:{x,y}, p1:{x,y}} regla de calibración arrastrable
    var densityLine = null; // {p0, p1} línea de densidad dibujada
    var rulerDrag = null; // 'p0' | 'p1' | 'move'
    var rulerPanel = null;
    var rulerMoveOffset = null;

    function getActive() { return opts.getActive(); }
    function getState() { return opts.getState(); }
    function getPixelSpacing() { return opts.getPixelSpacing ? opts.getPixelSpacing() : null; }
    function getScale() {
      var sp = getPixelSpacing();
      return sp || calibration;
    }

    function calibStorageKey(key) { return 'imgtools_calib:' + key; }

    function notifyCalibration() {
      if (opts.onCalibrationChange) opts.onCalibrationChange(calibration);
    }

    function setCalibration(mmPerPx) {
      calibration = mmPerPx || null;
      var key = opts.getCalibKey ? opts.getCalibKey() : null;
      if (key) {
        try {
          if (calibration) localStorage.setItem(calibStorageKey(key), String(calibration));
          else localStorage.removeItem(calibStorageKey(key));
        } catch (e) { /* localStorage no disponible */ }
      }
      notifyCalibration();
      redraw();
    }

    function checkCalibKey() {
      var key = opts.getCalibKey ? opts.getCalibKey() : null;
      if (key === calibKey) return;
      calibKey = key;
      calibration = null;
      if (key) {
        try {
          var v = localStorage.getItem(calibStorageKey(key));
          calibration = v ? parseFloat(v) : null;
        } catch (e) { /* localStorage no disponible */ }
      }
      notifyCalibration();
    }

    function sync() {
      checkCalibKey();
      var el = getActive();
      if (!el) return;
      var w, h;
      if (el.tagName === 'IMG') {
        w = el.naturalWidth || el.width;
        h = el.naturalHeight || el.height;
      } else {
        w = el.width;
        h = el.height;
      }
      if (!w || !h) return;
      if (overlay.width !== w) overlay.width = w;
      if (overlay.height !== h) overlay.height = h;
      overlay.style.width = el.offsetWidth + 'px';
      overlay.style.height = el.offsetHeight + 'px';
      overlay.style.left = el.offsetLeft + 'px';
      overlay.style.top = el.offsetTop + 'px';
      overlay.style.transform = el.style.transform || '';
      redraw();
    }

    function pxRatio() {
      return overlay.width / (overlay.offsetWidth || overlay.width);
    }

    function toCanvasCoords(clientX, clientY) {
      var rect = overlay.getBoundingClientRect();
      var fx = (clientX - rect.left) / rect.width;
      var fy = (clientY - rect.top) / rect.height;
      var st = getState();
      var rot = ((st.tr % 360) + 360) % 360;
      var px, py;
      if (rot === 90) { px = fy * overlay.width; py = (1 - fx) * overlay.height; }
      else if (rot === 180) { px = (1 - fx) * overlay.width; py = (1 - fy) * overlay.height; }
      else if (rot === 270) { px = (1 - fy) * overlay.width; py = fx * overlay.height; }
      else { px = fx * overlay.width; py = fy * overlay.height; }
      return { x: px, y: py };
    }

    function dist(a, b) { return Math.hypot(b.x - a.x, b.y - a.y); }

    function formatLength(pxDist) {
      var sp = getScale();
      if (sp) return (pxDist * sp).toFixed(1) + ' mm';
      return pxDist.toFixed(0) + ' px (sin calibrar)';
    }

    function formatArea(w, h) {
      var sp = getScale();
      if (sp) return (w * sp * (h * sp)).toFixed(1) + ' mm²';
      return (w * h).toFixed(0) + ' px² (sin calibrar)';
    }

    function angleBetween(a, b, c) {
      var ab = { x: a.x - b.x, y: a.y - b.y };
      var cb = { x: c.x - b.x, y: c.y - b.y };
      var mag = Math.hypot(ab.x, ab.y) * Math.hypot(cb.x, cb.y);
      var cos = mag ? (ab.x * cb.x + ab.y * cb.y) / mag : 0;
      cos = Math.max(-1, Math.min(1, cos));
      return Math.acos(cos) * 180 / Math.PI;
    }

    function drawHandle(p, r) {
      octx.beginPath();
      octx.arc(p.x, p.y, 3 * r, 0, Math.PI * 2);
      octx.fill();
    }

    function drawShape(s, r) {
      if (s.type === 'density') {
        octx.save();
        octx.strokeStyle = '#4ade80';
        octx.fillStyle   = '#4ade80';
        octx.lineWidth = 2 * r;
        octx.beginPath();
        octx.moveTo(s.p[0].x, s.p[0].y);
        if (s.p[1]) octx.lineTo(s.p[1].x, s.p[1].y);
        octx.stroke();
        drawHandle(s.p[0], r);
        if (s.p[1]) drawHandle(s.p[1], r);
        octx.restore();
        return;
      }
      octx.beginPath();
      if (s.type === 'length') {
        octx.moveTo(s.p[0].x, s.p[0].y);
        octx.lineTo(s.p[1].x, s.p[1].y);
        octx.stroke();
        drawHandle(s.p[0], r); drawHandle(s.p[1], r);
        var mid = { x: (s.p[0].x + s.p[1].x) / 2, y: (s.p[0].y + s.p[1].y) / 2 };
        octx.fillText(formatLength(dist(s.p[0], s.p[1])), mid.x + 6 * r, mid.y - 6 * r);
      } else if (s.type === 'angle') {
        octx.moveTo(s.p[0].x, s.p[0].y);
        if (s.p[1]) octx.lineTo(s.p[1].x, s.p[1].y);
        if (s.p[2]) octx.lineTo(s.p[2].x, s.p[2].y);
        octx.stroke();
        s.p.forEach(function (p) { drawHandle(p, r); });
        if (s.p.length === 3) {
          octx.fillText(angleBetween(s.p[0], s.p[1], s.p[2]).toFixed(1) + '°', s.p[1].x + 8 * r, s.p[1].y - 8 * r);
        }
      } else if (s.type === 'rect') {
        var x = Math.min(s.p[0].x, s.p[1].x), y = Math.min(s.p[0].y, s.p[1].y);
        var w = Math.abs(s.p[1].x - s.p[0].x), h = Math.abs(s.p[1].y - s.p[0].y);
        octx.strokeRect(x, y, w, h);
        var ly = (y - 6 * r > 0) ? y - 6 * r : y + 14 * r;
        octx.fillText(formatArea(w, h), x + 4 * r, ly);
      } else if (s.type === 'ellipse') {
        if (!s.p[1]) { drawHandle(s.p[0], r); return; }
        var ex = (s.p[0].x + s.p[1].x) / 2;
        var ey = (s.p[0].y + s.p[1].y) / 2;
        var erx = Math.abs(s.p[1].x - s.p[0].x) / 2;
        var ery = Math.abs(s.p[1].y - s.p[0].y) / 2;
        if (erx < 1 || ery < 1) { drawHandle(s.p[0], r); return; }
        octx.beginPath();
        octx.ellipse(ex, ey, erx, ery, 0, 0, Math.PI * 2);
        octx.stroke();
        drawHandle(s.p[0], r);
        drawHandle(s.p[1], r);
        var ely = ey - ery - 8 * r;
        if (ely < 0) ely = ey + ery + 14 * r;
        var sp2 = getScale();
        var areaLabel = sp2
          ? (Math.PI * erx * ery * sp2 * sp2).toFixed(1) + ' mm²'
          : (Math.PI * erx * ery).toFixed(0) + ' px² (sin calibrar)';
        octx.fillText(areaLabel, ex - erx, ely);
      } else if (s.type === 'text') {
        octx.fillText(s.text, s.p[0].x, s.p[0].y);
      }
      octx.closePath();
    }

    function drawRuler(r) {
      if (!ruler) return;
      var p0 = ruler.p0, p1 = ruler.p1;
      var dx = p1.x - p0.x, dy = p1.y - p0.y;
      var len = Math.hypot(dx, dy) || 1;
      var ux = dx / len, uy = dy / len;
      var nx = -uy, ny = ux;
      octx.save();
      octx.strokeStyle = '#22d3ee';
      octx.fillStyle = '#22d3ee';
      octx.lineWidth = 2 * r;
      octx.beginPath();
      octx.moveTo(p0.x, p0.y);
      octx.lineTo(p1.x, p1.y);
      octx.stroke();
      // marcas cada 10% de la longitud, marca mayor cada 50%
      var n = 10;
      for (var i = 0; i <= n; i++) {
        var t = i / n;
        var x = p0.x + dx * t, y = p0.y + dy * t;
        var tickLen = (i % 5 === 0) ? 12 * r : 6 * r;
        octx.beginPath();
        octx.moveTo(x - nx * tickLen / 2, y - ny * tickLen / 2);
        octx.lineTo(x + nx * tickLen / 2, y + ny * tickLen / 2);
        octx.stroke();
      }
      // manijas en los extremos
      octx.beginPath(); octx.arc(p0.x, p0.y, 5 * r, 0, Math.PI * 2); octx.fill();
      octx.beginPath(); octx.arc(p1.x, p1.y, 5 * r, 0, Math.PI * 2); octx.fill();
      // etiqueta con la longitud
      var mid = { x: (p0.x + p1.x) / 2, y: (p0.y + p1.y) / 2 };
      octx.font = (14 * r) + 'px sans-serif';
      var label = len.toFixed(0) + ' px';
      var sp = getScale();
      if (sp) label += ' (' + (len * sp).toFixed(1) + ' mm)';
      octx.fillText(label, mid.x + 6 * r, mid.y - 10 * r);
      octx.restore();
    }

    function redraw() {
      octx.clearRect(0, 0, overlay.width, overlay.height);
      var r = pxRatio();
      octx.lineWidth = 2 * r;
      octx.strokeStyle = '#facc15';
      octx.fillStyle = '#facc15';
      octx.font = (14 * r) + 'px sans-serif';
      shapes.forEach(function (s) { drawShape(s, r); });
      if (drawing) drawShape(drawing, r);
      if (tool === 'calibrate') drawRuler(r);
      updateRulerPanel();
    }

    function initRuler() {
      if (ruler) return;
      var w = overlay.width || 400, h = overlay.height || 400;
      var len = w * 0.35;
      var cx = w / 2, cy = h / 2;
      ruler = {
        p0: { x: cx - len / 2, y: cy },
        p1: { x: cx + len / 2, y: cy }
      };
    }

    function ensurePanel() {
      if (rulerPanel) return rulerPanel;
      var parent = overlay.parentNode;
      if (!parent) return null;
      var panel = global.document.createElement('div');
      panel.style.cssText = 'position:absolute;top:10px;right:10px;z-index:6;background:rgba(0,0,0,.75);' +
        'color:#fff;padding:8px 10px;border-radius:8px;font-size:12px;max-width:220px;display:none;' +
        'font-family:inherit;line-height:1.4;';
      panel.innerHTML =
        '<div style="font-weight:600;margin-bottom:4px;">Regla de calibración</div>' +
        '<div style="opacity:.85;margin-bottom:6px;">Arrastrá los extremos (círculos celestes) para alinearlos con un objeto de tamaño real conocido. Por defecto representa 3 cm.</div>' +
        '<div style="margin-bottom:6px;">Longitud: <span data-role="px">-</span></div>' +
        '<div style="display:flex;gap:4px;align-items:center;">' +
        '<input data-role="mm" type="number" min="0.1" step="0.1" value="30" style="width:70px;border-radius:4px;border:1px solid #555;background:#16181d;color:#fff;padding:2px 4px;"> ' +
        '<span>mm =</span>' +
        '</div>' +
        '<button data-role="apply" type="button" class="btn btn-sm mt-2 w-100" style="font-size:12px;background:#5b8def;color:#fff;border:none;">Calibrar con esta regla</button>';
      parent.appendChild(panel);
      panel.querySelector('[data-role="apply"]').addEventListener('click', function () {
        var input = panel.querySelector('[data-role="mm"]');
        var mm = parseFloat(String(input.value).replace(',', '.'));
        if (isNaN(mm) || mm <= 0) {
          global.alert('Ingresá una longitud real válida en milímetros.');
          return;
        }
        var pxDist = dist(ruler.p0, ruler.p1);
        if (pxDist < 1) return;
        var ok = mm >= MIN_CALIB_MM ||
          global.confirm('La referencia indicada (' + mm + ' mm) es menor a 1 cm y puede dar una calibración poco precisa.\n¿Querés usarla de todos modos?');
        if (ok) setCalibration(mm / pxDist);
      });
      rulerPanel = panel;
      return panel;
    }

    function updateRulerPanel() {
      if (!rulerPanel || tool !== 'calibrate' || !ruler) return;
      var px = rulerPanel.querySelector('[data-role="px"]');
      if (px) px.textContent = dist(ruler.p0, ruler.p1).toFixed(0) + ' px';
    }

    overlay.addEventListener('pointerdown', function (e) {
      if (tool === 'pan' || tool === 'wl') return;
      e.preventDefault();
      var p = toCanvasCoords(e.clientX, e.clientY);
      if (tool === 'calibrate') {
        if (!ruler) initRuler();
        var hr = 12 * pxRatio();
        if (dist(p, ruler.p0) <= hr) {
          rulerDrag = 'p0';
        } else if (dist(p, ruler.p1) <= hr) {
          rulerDrag = 'p1';
        } else {
          rulerDrag = 'move';
          rulerMoveOffset = {
            dx0: p.x - ruler.p0.x, dy0: p.y - ruler.p0.y,
            dx1: p.x - ruler.p1.x, dy1: p.y - ruler.p1.y
          };
        }
        overlay.setPointerCapture(e.pointerId);
        redraw();
        return;
      }
      if (tool === 'length' || tool === 'rect' || tool === 'ellipse') {
        drawing = { type: tool, p: [p, p] };
        dragMode = tool;
        overlay.setPointerCapture(e.pointerId);
        redraw();
      } else if (tool === 'density') {
        drawing = { type: 'density', p: [p, null] };
        dragMode = tool;
        overlay.setPointerCapture(e.pointerId);
        redraw();
      } else if (tool === 'angle') {
        if (!drawing || drawing.p.length === 3) {
          drawing = { type: 'angle', p: [p] };
        } else {
          drawing.p.push(p);
          if (drawing.p.length === 3) { shapes.push(drawing); drawing = null; if (opts.onChange) opts.onChange(); }
        }
        redraw();
      } else if (tool === 'text') {
        var txt = global.prompt('Texto de la anotación:');
        if (txt) { shapes.push({ type: 'text', p: [p], text: txt }); if (opts.onChange) opts.onChange(); }
        redraw();
      }
    });

    overlay.addEventListener('pointermove', function (e) {
      if (tool === 'calibrate' && rulerDrag && ruler) {
        var p = toCanvasCoords(e.clientX, e.clientY);
        if (rulerDrag === 'p0') {
          ruler.p0 = p;
        } else if (rulerDrag === 'p1') {
          ruler.p1 = p;
        } else if (rulerDrag === 'move') {
          ruler.p0 = { x: p.x - rulerMoveOffset.dx0, y: p.y - rulerMoveOffset.dy0 };
          ruler.p1 = { x: p.x - rulerMoveOffset.dx1, y: p.y - rulerMoveOffset.dy1 };
        }
        redraw();
        return;
      }
      if (!drawing || !dragMode) return;
      drawing.p[1] = toCanvasCoords(e.clientX, e.clientY);
      redraw();
    });

    overlay.addEventListener('pointerup', function () {
      if (rulerDrag) {
        rulerDrag = null;
        rulerMoveOffset = null;
        return;
      }
      if (drawing && dragMode) {
        var finished = drawing;
        shapes.push(finished);
        drawing = null;
        dragMode = null;
        redraw();
        if (finished.type === 'density' && finished.p[1] && opts.onDensityLine) {
          opts.onDensityLine(finished.p[0], finished.p[1]);
        }
        if (opts.onChange) opts.onChange();
      }
    });

    if (opts.onWheel) {
      overlay.addEventListener('wheel', function (e) {
        e.preventDefault();
        opts.onWheel(e.deltaY > 0 ? 1 : -1);
      }, { passive: false });
    }

    function exportPng(filename) {
      var el = getActive();
      if (!el) return false;
      var w = overlay.width, h = overlay.height;
      if (!w || !h) return false;

      var base = global.document.createElement('canvas');
      base.width = w;
      base.height = h;
      var bctx = base.getContext('2d');
      try { bctx.filter = (el.style && el.style.filter) || 'none'; } catch (e) { /* navegador sin soporte */ }
      bctx.drawImage(el, 0, 0, w, h);
      bctx.filter = 'none';
      bctx.drawImage(overlay, 0, 0);

      var st = getState();
      var rot = ((st.tr % 360) + 360) % 360;
      var out = base;
      if (rot === 90 || rot === 180 || rot === 270) {
        out = global.document.createElement('canvas');
        if (rot === 90 || rot === 270) { out.width = h; out.height = w; }
        else { out.width = w; out.height = h; }
        var octx2 = out.getContext('2d');
        octx2.translate(out.width / 2, out.height / 2);
        octx2.rotate(rot * Math.PI / 180);
        octx2.drawImage(base, -w / 2, -h / 2);
      }

      out.toBlob(function (blob) {
        if (!blob) return;
        var a = global.document.createElement('a');
        var url = URL.createObjectURL(blob);
        a.href = url;
        a.download = filename || 'imagen-anotada.png';
        global.document.body.appendChild(a);
        a.click();
        global.document.body.removeChild(a);
        setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
      }, 'image/png');
      return true;
    }

    return {
      setTool: function (t) {
        tool = t;
        drawing = null;
        dragMode = null;
        rulerDrag = null;
        overlay.style.pointerEvents = (t === 'pan' || t === 'wl') ? 'none' : 'auto';
        overlay.style.cursor = t === 'length' || t === 'angle' || t === 'rect' || t === 'ellipse' || t === 'text' || t === 'density' ? 'crosshair' : 'default';
        if (t === 'calibrate') {
          if (!ruler) initRuler();
          var panel = ensurePanel();
          if (panel) panel.style.display = 'block';
        } else if (rulerPanel) {
          rulerPanel.style.display = 'none';
        }
        redraw();
      },
      clear: function () { shapes = []; drawing = null; redraw(); if (opts.onChange) opts.onChange(); },
      getShapes: function () { return shapes.slice(); },
      setShapes: function (arr) { shapes = Array.isArray(arr) ? arr.slice() : []; redraw(); },
      setCalibration: setCalibration,
      getCalibration: function () { return calibration; },
      clearCalibration: function () { setCalibration(null); },
      exportPng: exportPng,
      sync: sync,
      redraw: redraw
    };
  }

  function readDicomDecimal(tag) {
    if (!tag || tag.value === null || typeof tag.value === 'undefined') return null;
    var raw = tag.value;
    if (Array.isArray(raw)) raw = raw[0];
    var num = parseFloat(String(raw).split('\\')[0].replace(',', '.'));
    return (num > 0) ? num : null;
  }

  // Calibración automática a partir de los metadatos DICOM, sin intervención humana.
  // Usa Pixel Spacing (0028,0030) si está presente; si no, recurre a Imager Pixel
  // Spacing (0018,1164) -típico en equipos de RX/angiografía- corrigiendo por el
  // factor de magnificación geométrica (0018,1114) cuando está disponible.
  function pixelSpacingFromDicomImage(image) {
    if (!image) return null;
    try {
      if (image.getPixelSpacing) {
        var sp = image.getPixelSpacing();
        if (sp && sp[0]) return sp[0];
      }
      if (image.getTag) {
        var ips = readDicomDecimal(image.getTag(0x0018, 0x1164));
        if (ips) {
          var mag = readDicomDecimal(image.getTag(0x0018, 0x1114));
          return mag ? ips / mag : ips;
        }
      }
    } catch (e) { /* tag no disponible */ }
    return null;
  }

  global.ImageTools = { create: create, pixelSpacingFromImage: pixelSpacingFromDicomImage };

})(window);
