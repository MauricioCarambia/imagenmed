/**
 * Visor DICOM ligero basado en daikon.js.
 * Decodifica un archivo .dcm (incluyendo series multi-frame) y permite
 * renderizarlo en un <canvas> aplicando ventana (centro/ancho) real,
 * en lugar de filtros CSS.
 */
(function (global) {
  function isDicom(name) {
    return /\.dcm(\?.*)?$/i.test(name || '');
  }

  function load(url) {
    return fetch(url)
      .then(function (r) {
        if (!r.ok) throw new Error('No se pudo descargar el archivo.');
        return r.arrayBuffer();
      })
      .then(function (buf) {
        var image = daikon.Series.parseImage(new DataView(buf));
        if (!image || !image.hasPixelData()) {
          throw new Error('Archivo DICOM inválido o no soportado.');
        }
        var numFrames = image.getNumberOfFrames() || 1;
        var first = image.getInterpretedData(false, true, 0);
        var ww = image.getWindowWidth();
        var wc = image.getWindowCenter();
        if (!ww || ww <= 0) {
          ww = (first.max - first.min) || 1;
          wc = (first.max + first.min) / 2;
        }
        return {
          image: image,
          cols: first.numCols,
          rows: first.numRows,
          numFrames: numFrames,
          frames: [first],
          min: first.min,
          max: first.max,
          defaultWC: wc,
          defaultWW: ww
        };
      });
  }

  // Devuelve los datos de píxel del frame pedido (cachea el resultado).
  function getFrame(info, idx) {
    if (!info.frames[idx]) {
      info.frames[idx] = info.image.getInterpretedData(false, true, idx);
    }
    return info.frames[idx];
  }

  function draw(canvas, info, wc, ww, invert, frameIdx) {
    var frame = getFrame(info, frameIdx || 0);
    canvas.width = frame.numCols;
    canvas.height = frame.numRows;
    var ctx = canvas.getContext('2d');
    var imgData = ctx.createImageData(frame.numCols, frame.numRows);
    var lo = wc - ww / 2;
    var range = ww || 1;
    var px = frame.data, d = imgData.data;
    for (var i = 0, j = 0; i < px.length; i++, j += 4) {
      var g = (px[i] - lo) / range * 255;
      if (g < 0) g = 0; else if (g > 255) g = 255;
      if (invert) g = 255 - g;
      d[j] = d[j + 1] = d[j + 2] = g;
      d[j + 3] = 255;
    }
    ctx.putImageData(imgData, 0, 0);
  }

  global.DicomViewer = { isDicom: isDicom, load: load, draw: draw };
})(window);
