/* NOC – vmesnik: tema, meni, grafi (uPlot), drobni pripomočki */
(function () {
  'use strict';
  var T = (window.NOC && NOC.t) || {};
  var root = document.documentElement;
  var css = function (v) { return getComputedStyle(root).getPropertyValue(v).trim(); };

  // ---- tema
  document.querySelectorAll('[data-theme-toggle]').forEach(function (b) {
    b.addEventListener('click', function () {
      var dark = root.dataset.theme !== 'dark';
      root.dataset.theme = dark ? 'dark' : 'light';
      try { localStorage.setItem('noc-theme', root.dataset.theme); } catch (e) {}
      redrawAll();
    });
  });
  // ---- mobilni meni
  document.querySelectorAll('[data-toggle-nav]').forEach(function (b) {
    b.addEventListener('click', function (e) { e.stopPropagation(); document.body.classList.toggle('nav-open'); });
  });
  document.addEventListener('click', function (e) {
    if (document.body.classList.contains('nav-open') && !e.target.closest('.side')) document.body.classList.remove('nav-open');
  });
  // ---- samodejna oddaja filtrov
  document.querySelectorAll('[data-autosubmit]').forEach(function (s) { s.addEventListener('change', function () { s.form.submit(); }); });
  // ---- potrditev
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) { if (!confirm(f.dataset.confirm)) e.preventDefault(); });
  });
  // ---- kopiraj ukaz
  document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      navigator.clipboard.writeText(b.dataset.copy).then(function () {
        var old = b.innerHTML; b.textContent = '✓'; b.title = T.copied || 'OK';
        setTimeout(function () { b.innerHTML = old; }, 1200);
      });
    });
  });
  // ---- vpis vrednosti v polje (Telegram chat ID)
  document.querySelectorAll('[data-fill]').forEach(function (b) {
    b.addEventListener('click', function () { var i = document.querySelector('[name="' + b.dataset.fill + '"]'); if (i) { i.value = b.dataset.value; i.focus(); } });
  });
  // ---- samodejna osvežitev (ne med tipkanjem)
  var ar = parseInt(document.body.dataset.autorefresh || '0', 10);
  if (ar > 0) {
    var busy = false;
    document.addEventListener('focusin', function (e) { if (e.target.matches('input,textarea,select')) busy = true; });
    document.addEventListener('focusout', function () { busy = false; });
    setInterval(function () { if (!busy && !document.hidden) location.reload(); }, ar * 1000);
  }

  // ================================================================ grafi
  var charts = [];

  function fmtBps(v) {
    if (v == null) return '–';
    if (v >= 1e9) return (v / 1e9).toFixed(2).replace('.', ',') + ' Gb/s';
    if (v >= 1e6) return (v / 1e6).toFixed(1).replace('.', ',') + ' Mb/s';
    if (v >= 1e3) return Math.round(v / 1e3) + ' kb/s';
    return Math.round(v) + ' b/s';
  }
  function fmtNum(v, u, d) { return v == null ? '–' : (+v).toFixed(d || 0).replace('.', ',') + (u || ''); }
  function fmtTime(ts, long) {
    var d = new Date(ts * 1000), p = function (n) { return (n < 10 ? '0' : '') + n; };
    var t = p(d.getHours()) + ':' + p(d.getMinutes());
    return long ? p(d.getDate()) + '.' + p(d.getMonth() + 1) + '. ' + t : t;
  }

  function seriesFor(kind) {
    var aqua = css('--aqua'), violet = css('--violet'), yellow = css('--yellow'), down = css('--down');
    var fill = function (c, a) { return c + (a || '26'); };
    if (kind === 'traffic') return {
      series: [{ label: T.rx, stroke: aqua, fill: fill(aqua, '30'), width: 1.6, fmt: fmtBps }, { label: T.tx, stroke: violet, fill: fill(violet, '14'), width: 1.6, fmt: fmtBps }],
      axisFmt: fmtBps, scale: {}
    };
    if (kind === 'cpu') return {
      series: [{ label: T.cpu, stroke: violet, fill: fill(violet, '22'), width: 1.5, fmt: function (v) { return fmtNum(v, ' %'); } }, { label: T.mem, stroke: yellow, width: 1.5, fmt: function (v) { return fmtNum(v, ' %'); } }],
      axisFmt: function (v) { return v + ' %'; }, scale: { range: [0, 100] }
    };
    if (kind === 'temp') return { series: [{ label: T.temp, stroke: down, width: 1.6, fmt: function (v) { return fmtNum(v, ' °C', 1); } }], axisFmt: function (v) { return v + ' °'; }, scale: {} };
    if (kind === 'conns') return { series: [{ label: T.conns, stroke: violet, fill: fill(violet, '1c'), width: 1.5, fmt: function (v) { return fmtNum(v); } }], axisFmt: function (v) { return v; }, scale: {} };
    if (kind === 'ping') return {
      series: [
        { label: T.gw, stroke: violet, width: 1.5, fmt: function (v) { return fmtNum(v, ' ms', 1); } },
        { label: T.ext, stroke: aqua, width: 1.5, fmt: function (v) { return fmtNum(v, ' ms', 1); } },
        { label: T.gw + ' ' + T.loss, stroke: down, fill: down + '55', width: 0, scale: 'loss', bars: true, fmt: function (v) { return fmtNum(v, ' %'); } },
        { label: T.ext + ' ' + T.loss, stroke: down, fill: down + '30', width: 0, scale: 'loss', bars: true, fmt: function (v) { return fmtNum(v, ' %'); } },
        { label: T.tiger, stroke: yellow, width: 1.2, dash: [4, 3], fmt: function (v) { return fmtNum(v, ' ms', 1); } }
      ],
      axisFmt: function (v) { return v + ' ms'; }, scale: {}, loss: true
    };
    return { series: [], axisFmt: String, scale: {} };
  }

  function draw(box) {
    var d = box._data, kind = box.dataset.k;
    if (box._plot) { box._plot.destroy(); box._plot = null; }
    var empty = box.querySelector('.chart-empty');
    if (!d || !d[0] || d[0].length < 2) { if (empty) { empty.textContent = T.noData || '–'; empty.style.display = 'grid'; } return; }
    if (empty) empty.style.display = 'none';
    var cfg = seriesFor(kind), long = (d[0][d[0].length - 1] - d[0][0]) > 2 * 86400;
    var grid = { stroke: css('--line-2'), width: 1 }, axisCol = css('--faint');
    var ser = [{}];
    cfg.series.slice(0, d.length - 1).forEach(function (s) {
      var o = { label: s.label, stroke: s.stroke, width: s.width, fill: s.fill, scale: s.scale || 'y', spanGaps: false, points: { show: false }, dash: s.dash };
      if (s.bars) o.paths = uPlot.paths.bars({ size: [0.9, 6] });
      ser.push(o);
    });
    var axes = [
      { stroke: axisCol, grid: grid, ticks: { show: false }, font: '11px Plex, sans-serif', values: function (u, vals) { return vals.map(function (v) { return fmtTime(v, long); }); }, space: 70 },
      { stroke: axisCol, grid: grid, ticks: { show: false }, font: '11px Plex, sans-serif', size: 70, values: function (u, vals) { return vals.map(cfg.axisFmt); } }
    ];
    var scales = { x: { time: true }, y: cfg.scale.range ? { range: cfg.scale.range } : { range: function (u, mn, mx) { return [0, mx > 0 ? mx * 1.12 : 1]; } } };
    if (cfg.loss) { scales.loss = { range: [0, 100] }; }
    var tip = box.querySelector('.chart-tip');
    if (!tip) { tip = document.createElement('div'); tip.className = 'chart-tip'; box.appendChild(tip); }
    var opts = {
      width: box.clientWidth, height: parseInt(box.dataset.h || '220', 10), series: ser, axes: axes, scales: scales,
      legend: { show: false }, padding: [8, 8, 0, 0],
      cursor: { points: { size: 6 }, drag: { x: true, y: false } },
      hooks: { setCursor: [function (u) {
        var i = u.cursor.idx;
        if (i == null) { tip.style.display = 'none'; return; }
        var html = '<b>' + fmtTime(u.data[0][i], true) + '</b>';
        cfg.series.slice(0, u.data.length - 1).forEach(function (s, k) {
          var v = u.data[k + 1][i]; if (v == null && s.bars) return;
          html += '<div><span style="color:' + s.stroke + '">●</span> ' + s.label + ': ' + s.fmt(v) + '</div>';
        });
        tip.innerHTML = html; tip.style.display = 'block';
        var x = u.cursor.left + 60, w = tip.offsetWidth;
        tip.style.left = Math.min(x + 12, box.clientWidth - w - 4) + 'px'; tip.style.top = '6px';
      }] }
    };
    box._plot = new uPlot(opts, d, box);
  }

  function load(box) {
    var u = '/ui/chart?d=' + box.dataset.d + '&k=' + encodeURIComponent(box.dataset.k) + '&r=' + encodeURIComponent(box.dataset.r) + '&i=' + encodeURIComponent(box.dataset.i || '');
    fetch(u, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) { box._data = j.data; draw(box); })
      .catch(function () { var e = box.querySelector('.chart-empty'); if (e) e.textContent = '⚠'; });
  }
  function redrawAll() { charts.forEach(function (b) { if (b._data) draw(b); }); }

  document.querySelectorAll('[data-chart]').forEach(function (box) { charts.push(box); load(box); });
  document.querySelectorAll('[data-range-for]').forEach(function (seg) {
    seg.addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b) return;
      seg.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
      var grp = document.getElementById(seg.dataset.rangeFor); if (!grp) return;
      grp.querySelectorAll('[data-chart]').forEach(function (box) { box.dataset.r = b.dataset.r; load(box); });
    });
  });
  var rt; window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(redrawAll, 150); });
})();
