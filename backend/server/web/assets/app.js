/* Forex Signals Live website: small progressive enhancements (the site works without JavaScript). */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  // Menus
  $$('[data-toggle]').forEach(function (b) {
    b.addEventListener('click', function (e) {
      e.stopPropagation();
      var t = document.getElementById(b.getAttribute('data-toggle'));
      if (t) t.classList.toggle('open');
    });
  });
  document.addEventListener('click', function (e) {
    var side = $('#side');
    if (side && side.classList.contains('open') && !side.contains(e.target)) side.classList.remove('open');
    $$('details.filter[open]').forEach(function (d) { if (!d.contains(e.target)) d.removeAttribute('open'); });
  });

  // Copy buttons
  $$('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function (e) {
      e.preventDefault();
      var text = b.getAttribute('data-copy');
      var done = function () {
        var old = b.innerHTML;
        b.classList.add('copied');
        if (b.textContent.trim()) b.textContent = 'Copied';
        setTimeout(function () { b.innerHTML = old; b.classList.remove('copied'); }, 1400);
      };
      if (navigator.clipboard) navigator.clipboard.writeText(text).then(done, done);
      else { var i = document.createElement('input'); i.value = text; document.body.appendChild(i); i.select(); try { document.execCommand('copy'); } catch (x) {} i.remove(); done(); }
    });
  });

  // Confirm dangerous forms
  $$('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) { if (!window.confirm(f.getAttribute('data-confirm'))) e.preventDefault(); });
  });
  $$('select[data-autosubmit]').forEach(function (s) { s.addEventListener('change', function () { s.form.submit(); }); });

  // Calculators (USD account). Pip value of 1 standard lot.
  function pipSize(pair) {
    if (pair === 'BTC/USD') return 1;
    if (pair === 'ETH/USD' || pair.indexOf('XAU') === 0) return 0.1;
    if (pair.indexOf('JPY') >= 0) return 0.01;
    return 0.0001;
  }
  function pipValue(pair, price) {
    if (pair.indexOf('XAU') === 0) return 10;
    if (pair.indexOf('BTC') === 0) return 1;
    if (pair.indexOf('ETH') === 0) return 0.1;
    if (/\/USD$/.test(pair)) return 10;
    var fallback = { 'USD/JPY': 150, 'USD/CAD': 1.36, 'USD/CHF': 0.88 };
    var p = price || fallback[pair] || 1;
    return 100000 * pipSize(pair) / p;
  }
  var usd = function (v) { return (v < 0 ? '-$' : '$') + Math.abs(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var num = function (f, n) { var v = parseFloat((f.elements[n] || {}).value); return isFinite(v) ? v : NaN; };
  function pairOf(f) {
    var s = f.elements.pair, o = s.options[s.selectedIndex];
    return { pair: s.value, price: parseFloat(o.getAttribute('data-price')) || 0 };
  }
  function out(f, k, v) { var el = f.querySelector('[data-out="' + k + '"]'); if (el) el.textContent = v; }
  function calc(f) {
    var p = pairOf(f), kind = f.getAttribute('data-calc');
    if (kind === 'lot') {
      var bal = num(f, 'balance'), risk = num(f, 'risk'), sl = num(f, 'sl'), pv = pipValue(p.pair, p.price);
      if (!(bal > 0 && risk > 0 && sl > 0)) { out(f, 'lots', '—'); return; }
      var amt = bal * risk / 100, lots = Math.floor(amt / (sl * pv) * 100) / 100;
      out(f, 'lots', lots.toFixed(2) + ' lots'); out(f, 'risk', usd(amt)); out(f, 'pv', usd(pv));
    } else if (kind === 'pip') {
      var l = num(f, 'lots'), pips = num(f, 'pips'), v = pipValue(p.pair, p.price) * (l || 0);
      out(f, 'pv', usd(v)); out(f, 'pl', isFinite(pips) ? usd(v * pips) : '—');
    } else if (kind === 'rr') {
      var e = num(f, 'entry'), s = num(f, 'sl'), t = num(f, 'tp'), ps = pipSize(p.pair);
      if (!(e > 0 && s > 0 && t > 0) || e === s) { out(f, 'rr', '—'); out(f, 'riskp', '—'); out(f, 'rewp', '—'); return; }
      var rk = Math.abs(e - s) / ps, rw = Math.abs(t - e) / ps;
      out(f, 'rr', '1 : ' + (rw / rk).toFixed(2)); out(f, 'riskp', rk.toFixed(1) + ' pips'); out(f, 'rewp', rw.toFixed(1) + ' pips');
    }
  }
  var params = new URLSearchParams(location.search);
  $$('form[data-calc]').forEach(function (f) {
    if (f.getAttribute('data-calc') === 'lot') {
      if (params.get('pair')) f.elements.pair.value = params.get('pair');
      if (params.get('sl')) f.elements.sl.value = params.get('sl');
    }
    f.addEventListener('input', function () { calc(f); });
    f.addEventListener('change', function () { calc(f); });
    calc(f);
  });

  // Live floating pips on signal pages (every 30 s while the tab is visible)
  var live = $$('[data-live]');
  if (live.length && /^\/app/.test(location.pathname)) {
    var tick = function () {
      if (document.hidden) return;
      fetch('/app/live.json', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          if (!d) return;
          live.forEach(function (el) {
            var it = d.items[el.getAttribute('data-live')];
            if (!it) return;
            var b = el.querySelector('b') || el;
            var unit = b.querySelector('small');
            b.firstChild.nodeValue = it.text + (unit ? ' ' : '');
            el.classList.remove('up', 'down'); b.classList.remove('up', 'down');
            if (it.cls) (el.tagName === 'B' ? el : el).classList.add(it.cls);
          });
          var u = $('[data-updated]'); if (u) u.textContent = 'Updated ' + d.at + ' UTC';
        }).catch(function () {});
    };
    setInterval(tick, 30000);
  }
})();
