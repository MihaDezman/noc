/* NOC – vmesnik: tema, meni, grafi (uPlot), drobni pripomočki */
(function () {
  'use strict';
  var T = (window.NOC && NOC.t) || {};
  var root = document.documentElement;
  var css = function (v) { return getComputedStyle(root).getPropertyValue(v).trim(); };

  // ---- obrazec naprave: polja za router / stikalo
  document.querySelectorAll('form').forEach(function (fm) {
    var k = fm.querySelector('[name="kind"]'); if (!k) return;
    var apply = function () { fm.querySelectorAll('[data-kind]').forEach(function (el) { el.hidden = el.dataset.kind !== k.value; }); };
    k.addEventListener('change', apply); apply();
  });

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
  document.querySelectorAll('select[data-nav-select]').forEach(function (s) { s.addEventListener('change', function () { if (s.value.charAt(0) === '/') location.href = s.value; }); });
  // ---- potrditev
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) { if (!confirm(f.dataset.confirm)) e.preventDefault(); });
  });
  // ---- kopiraj ukaz
  document.querySelectorAll('[data-copy]').forEach(function (b) {
    if (!b.title) { b.title = T.copy || 'Copy'; b.setAttribute('aria-label', b.title); }
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

  // ================================================================ okno ob portu (sprednja plošča)
  var tip = null;
  function showTip(w) {
    var t = w.querySelector('template'); if (!t) return;
    if (!tip) { tip = document.createElement('div'); tip.className = 'port-pop'; document.body.appendChild(tip); }
    tip.innerHTML = t.innerHTML; tip.style.display = 'block';
    var r = w.getBoundingClientRect(), tw = tip.offsetWidth, th = tip.offsetHeight;
    var x = Math.min(Math.max(8, r.left + r.width / 2 - tw / 2), window.innerWidth - tw - 8);
    var y = r.bottom + 10; if (y + th > window.innerHeight - 8) y = Math.max(8, r.top - th - 10);
    tip.style.left = x + 'px'; tip.style.top = y + 'px';
  }
  function hideTip() { if (tip) tip.style.display = 'none'; }
  document.querySelectorAll('[data-port-tip]').forEach(function (w) {
    w.addEventListener('mouseenter', function () { showTip(w); });
    w.addEventListener('mouseleave', hideTip);
    w.addEventListener('focus', function () { showTip(w); });   // dotik na telefonu
    w.addEventListener('blur', hideTip);
  });
  window.addEventListener('scroll', hideTip, { passive: true });

  // ================================================================ grafi
  var charts = [];

  function fmtBps(v) {
    if (v == null) return '–';
    if (v >= 1e9) return (v / 1e9).toFixed(2).replace('.', ',') + ' Gb/s';
    if (v >= 1e6) return (v / 1e6).toFixed(1).replace('.', ',') + ' Mb/s';
    if (v >= 1e3) return Math.round(v / 1e3) + ' kb/s';
    return Math.round(v) + ' b/s';
  }
  var DEC = (window.NOC && NOC.lang === 'en') ? '.' : ',';
  function dec(v) { return String(v).replace('.', DEC); }
  function fmtNum(v, u, d) { return v == null ? '–' : dec((+v).toFixed(d || 0)) + (u || ''); }
  function fmtTime(ts, long) {
    var d = new Date(ts * 1000), p = function (n) { return (n < 10 ? '0' : '') + n; };
    var t = p(d.getHours()) + ':' + p(d.getMinutes());
    return long ? p(d.getDate()) + '.' + p(d.getMonth() + 1) + '. ' + t : t;
  }

  function seriesFor(kind) {
    var aqua = css('--aqua'), violet = css('--violet'), yellow = css('--yellow'), down = css('--down');
    // mehak preliv pod črto: od barve pri vrhu do prosojne pri osi
    var fill = function (c, a) {
      return function (u) {
        var g = u.ctx.createLinearGradient(0, u.bbox.top, 0, u.bbox.top + u.bbox.height);
        g.addColorStop(0, c + (a || '40')); g.addColorStop(1, c + '00'); return g;
      };
    };
    if (kind === 'traffic') return {
      series: [{ label: T.rx, stroke: aqua, fill: fill(aqua, '55'), width: 1.8, fmt: fmtBps }, { label: T.tx, stroke: violet, fill: fill(violet, '30'), width: 1.8, fmt: fmtBps }],
      axisFmt: fmtBps, scale: {}
    };
    if (kind === 'cpu') return {
      series: [{ label: T.cpu, stroke: violet, fill: fill(violet, '45'), width: 1.6, fmt: function (v) { return fmtNum(v, ' %'); } }, { label: T.mem, stroke: yellow, width: 1.5, fmt: function (v) { return fmtNum(v, ' %'); } }],
      axisFmt: function (v) { return dec(v) + ' %'; }, scale: { range: [0, 100] }
    };
    if (kind === 'sfp') return {
      series: [{ label: 'RX', stroke: aqua, width: 1.8, fmt: function (v) { return fmtNum(v, ' dBm', 2); } }, { label: 'TX', stroke: violet, width: 1.5, fmt: function (v) { return fmtNum(v, ' dBm', 2); } }],
      axisFmt: function (v) { return dec(v) + ' dBm'; }, scale: { range: function (u, mn, mx) { return [Math.floor(mn - 2), Math.ceil(mx + 2)]; } }
    };
    if (kind === 'temp') return { series: [{ label: T.temp, stroke: down, width: 1.6, fmt: function (v) { return fmtNum(v, ' °C', 1); } }], axisFmt: function (v) { return dec(v) + ' °'; }, scale: {} };
    if (kind === 'conns') return { series: [{ label: T.conns, stroke: violet, fill: fill(violet, '35'), width: 1.5, fmt: function (v) { return fmtNum(v); } }], axisFmt: function (v) { return v; }, scale: {} };
    if (kind === 'ping') return {
      series: [
        { label: T.gw, stroke: violet, width: 1.5, fmt: function (v) { return fmtNum(v, ' ms', 1); } },
        { label: T.ext, stroke: aqua, width: 1.5, fmt: function (v) { return fmtNum(v, ' ms', 1); } },
        { label: T.gw + ' ' + T.loss, stroke: down, fill: down + '55', width: 0, scale: 'loss', bars: true, fmt: function (v) { return fmtNum(v, ' %'); } },
        { label: T.ext + ' ' + T.loss, stroke: down, fill: down + '30', width: 0, scale: 'loss', bars: true, fmt: function (v) { return fmtNum(v, ' %'); } },
        { label: T.tiger, stroke: yellow, width: 1.2, dash: [4, 3], fmt: function (v) { return fmtNum(v, ' ms', 1); } }
      ],
      axisFmt: function (v) { return dec(v) + ' ms'; }, scale: {}, loss: true
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
    if (typeof cfg.scale.range === 'function') scales.y = { range: cfg.scale.range };
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

  // leno nalaganje (podstran Grafi): graf se naloži, ko pride blizu vidnega dela strani
  var lazyObs = 'IntersectionObserver' in window ? new IntersectionObserver(function (ents) {
    ents.forEach(function (e) { if (e.isIntersecting) { lazyObs.unobserve(e.target); e.target._seen = true; load(e.target); } });
  }, { rootMargin: '300px 0px' }) : null;
  function initCharts(root) {
    root.querySelectorAll('[data-chart]').forEach(function (box) {
      charts.push(box);
      if (box.hasAttribute('data-lazy') && lazyObs) lazyObs.observe(box); else { box._seen = true; load(box); }
    });
    root.querySelectorAll('[data-range-for]').forEach(function (seg) {
      seg.addEventListener('click', function (e) {
        var b = e.target.closest('button'); if (!b) return;
        seg.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
        var grp = document.getElementById(seg.dataset.rangeFor); if (!grp) return;
        grp.querySelectorAll('[data-chart]').forEach(function (box) { box.dataset.r = b.dataset.r; if (box._seen) load(box); });
      });
    });
  }
  initCharts(document);
  window.NOC = window.NOC || {}; window.NOC.initCharts = initCharts;
  window.NOC.dropCharts = function (root) { charts = charts.filter(function (b) { return !root.contains(b); }); };
  var rt; window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(redrawAll, 150); });

  // ================================================================ stikalo: stranski panel porta
  var drw = null, drwPort = null;
  var csrf = function () { if (window.NOC && NOC.csrf) return NOC.csrf; var i = document.querySelector('input[name="_csrf"]'); return i ? i.value : ''; };
  function closeDrw() {
    if (!drw) return;
    drw.classList.remove('open'); document.body.classList.remove('drw-open'); drwPort = null;
    document.querySelectorAll('.swp.sel').forEach(function (b) { b.classList.remove('sel'); });
  }
  function openDrw(dev, port) {
    if (!drw) {
      drw = document.createElement('aside'); drw.className = 'drawer'; drw.setAttribute('aria-label', 'Port'); document.body.appendChild(drw);
      drw.addEventListener('click', function (e) { if (e.target.closest('[data-drw-close]')) closeDrw(); });
    }
    if (tip) tip.style.display = 'none';
    drwPort = dev + '/' + port;
    document.querySelectorAll('.swp').forEach(function (b) { b.classList.toggle('sel', b.dataset.dev === String(dev) && b.dataset.swPort === port); });
    fetch('/devices/' + dev + '/swport?p=' + encodeURIComponent(port), { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.text(); })
      .then(function (html) {
        if (drwPort !== dev + '/' + port) return;   // medtem kliknjen drug port
        if (window.NOC.dropCharts) window.NOC.dropCharts(drw);
        drw.innerHTML = html; drw.classList.add('open'); document.body.classList.add('drw-open'); drw.scrollTop = 0;
        initCharts(drw); initSwForm(drw, dev);
      })
      .catch(function () { drw.innerHTML = '<div class="drw-head"><h2>⚠</h2><button type="button" class="drw-x" data-drw-close>✕</button></div>'; drw.classList.add('open'); });
  }
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-sw-port]');
    if (t && !e.target.closest('a')) { e.preventDefault(); openDrw(t.dataset.dev, t.dataset.swPort); }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeDrw();
    if ((e.key === 'Enter' || e.key === ' ') && e.target.matches && e.target.matches('tr[data-sw-port]')) { e.preventDefault(); openDrw(e.target.dataset.dev, e.target.dataset.swPort); }
  });
  function post(url, data) {
    var fd = data instanceof FormData ? data : new FormData(); if (!(data instanceof FormData)) Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    fd.append('_csrf', csrf());
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  function initSwForm(root, dev) {
    var body = root.querySelector('.drw-body'); if (!body) return;
    var port = body.dataset.port;
    var al = root.querySelector('[data-sw-alert]');
    if (al) al.addEventListener('change', function () { post('/devices/' + dev + '/swport/alert', { p: port, on: al.checked ? '1' : '' }).catch(function () { al.checked = !al.checked; }); });
    var f = root.querySelector('[data-sw-form]'); if (!f) return;
    var mode = f.querySelector('[data-sw-mode]');
    var showMode = function () { var m = mode ? mode.value : ''; f.querySelectorAll('[data-sw-show]').forEach(function (el) { el.hidden = el.dataset.swShow !== m; }); };
    if (mode) { mode.addEventListener('change', showMode); showMode(); }
    var out = f.querySelector('[data-sw-out]');
    var render = function (j, sent) {
      out.hidden = false; out.innerHTML = '';
      var add = function (tag, cls, txt) { var x = document.createElement(tag); if (cls) x.className = cls; x.textContent = txt; out.appendChild(x); return x; };
      if (j.err) { add('div', 'note warn', j.err); return; }
      if (sent) { add('div', 'note', j.msg || ''); return; }
      if (!j.cmds || !j.cmds.length) { add('div', 'small muted', T.swNoChange || 'Ni sprememb.'); return; }
      add('div', 'small muted', T.swPreview || 'Ukazi za stikalo:');
      add('pre', 'mono sw-cmds', j.cmds.join('\n'));
      (j.warn || []).forEach(function (w) { add('div', 'note warn', w); });
      add('div', 'small muted', T.swSafety || '');
      var b = add('button', 'btn primary', (j.warn && j.warn.length) ? (T.swSendAnyway || 'Razumem, pošlji') : (T.swSend || 'Pošlji na stikalo'));
      b.type = 'button';
      b.addEventListener('click', function () {
        b.disabled = true;
        var fd = new FormData(f); if (j.warn && j.warn.length) fd.append('confirm', '1');
        post('/devices/' + dev + '/swport/save', fd).then(function (r) { render(r, !r.err); if (!r.err) setTimeout(function () { openDrw(dev, port); }, 1500); }).catch(function () { b.disabled = false; });
      });
    };
    f.querySelector('[data-sw-preview]').addEventListener('click', function () { post('/devices/' + dev + '/swport/preview', new FormData(f)).then(function (j) { render(j, false); }); });
  }
  // prva konfiguracija praznega stikala: vrstice portov, predogled, pošiljanje
  var si = document.querySelector('[data-sw-init]');
  if (si) {
    var sdev = si.dataset.swInit, rows = si.querySelector('[data-swi-rows]'), sout = si.querySelector('[data-sw-out]');
    si.querySelector('[data-swi-add]').addEventListener('click', function () {
      var all = rows.querySelectorAll('[data-swi-row]'), c = all[all.length - 1].cloneNode(true);
      c.querySelectorAll('input').forEach(function (i) { i.value = ''; }); rows.appendChild(c); c.querySelector('input').focus();
    });
    rows.addEventListener('click', function (e) {
      var d = e.target.closest('[data-swi-del]'); if (!d) return;
      var r = d.closest('[data-swi-row]');
      if (rows.querySelectorAll('[data-swi-row]').length > 1) r.remove(); else r.querySelectorAll('input').forEach(function (i) { i.value = ''; });
    });
    si.addEventListener('input', function () { sout.hidden = true; });
    var srender = function (j, sent) {
      sout.hidden = false; sout.innerHTML = '';
      var add = function (tag, cls, txt) { var x = document.createElement(tag); if (cls) x.className = cls; x.textContent = txt; sout.appendChild(x); return x; };
      if (j.err) { add('div', 'note warn', j.err); return; }
      if (sent) { add('div', 'note', j.msg || ''); if (j.go) setTimeout(function () { location.href = j.go; }, 1500); return; }
      add('div', 'small muted', T.swPreview || '');
      add('pre', 'mono sw-cmds', (j.cmds || []).join('\n'));
      (j.warn || []).forEach(function (w) { add('div', 'note warn', w); });
      add('div', 'small muted', T.swSafety || '');
      var b = add('button', 'btn primary', T.swSendAnyway || 'Razumem, pošlji'); b.type = 'button';
      b.addEventListener('click', function () {
        b.disabled = true;
        var fd = new FormData(si); fd.append('confirm', '1');
        post('/devices/' + sdev + '/swinit/save', fd).then(function (r) { srender(r, !r.err); if (r.err) b.disabled = false; }).catch(function () { b.disabled = false; srender({ err: T.swErr || 'Napaka – osveži stran.' }, false); });
      });
    };
    si.querySelector('[data-swi-preview]').addEventListener('click', function () { post('/devices/' + sdev + '/swinit/preview', new FormData(si)).then(function (j) { srender(j, false); }).catch(function () { srender({ err: T.swErr || 'Napaka – osveži stran.' }, false); }); });
  }
})();
