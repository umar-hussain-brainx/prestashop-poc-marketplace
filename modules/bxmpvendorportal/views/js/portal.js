/* BrainX vendor portal — progressive enhancement only (every action works without JS). */
(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState !== 'loading') { fn(); } else { document.addEventListener('DOMContentLoaded', fn); }
  }

  function money(v, sign) {
    return (sign || '') + ' ' + Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function svgEl(name, attrs) {
    var el = document.createElementNS('http://www.w3.org/2000/svg', name);
    Object.keys(attrs || {}).forEach(function (k) { el.setAttribute(k, attrs[k]); });
    return el;
  }

  /** Inline-SVG combo chart: GMV bars, net-sales line, order dots (own axis). No external dependency. */
  function drawTrend(host) {
    var data;
    try { data = JSON.parse(host.getAttribute('data-series')); } catch (e) { return; }
    if (!data || !data.labels || !data.labels.length) { return; }
    var W = Math.max(host.clientWidth, 320), H = 280, padL = 56, padR = 40, padT = 14, padB = 34;
    var n = data.labels.length;
    var maxMoney = Math.max.apply(null, data.gmv.concat(data.net, [1]));
    var maxOrders = Math.max.apply(null, data.orders.concat([1]));
    var niceMax = function (m) { var p = Math.pow(10, Math.floor(Math.log10(m))); return Math.ceil(m / p) * p; };
    maxMoney = niceMax(maxMoney);
    maxOrders = Math.max(4, niceMax(maxOrders));
    var iw = W - padL - padR, ih = H - padT - padB, step = iw / n;
    var y = function (v) { return padT + ih - (v / maxMoney) * ih; };
    var yo = function (v) { return padT + ih - (v / maxOrders) * ih; };
    var svg = svgEl('svg', { viewBox: '0 0 ' + W + ' ' + H, preserveAspectRatio: 'none' });
    for (var g = 0; g <= 4; g++) {
      var gv = maxMoney * g / 4, gy = y(gv);
      svg.appendChild(svgEl('line', { x1: padL, x2: W - padR, y1: gy, y2: gy, 'class': 'grid' }));
      var t = svgEl('text', { x: padL - 6, y: gy + 4, 'text-anchor': 'end' }); t.textContent = Math.round(gv); svg.appendChild(t);
      var to = svgEl('text', { x: W - padR + 6, y: gy + 4 }); to.textContent = Math.round(maxOrders * g / 4); svg.appendChild(to);
    }
    var tip = document.createElement('div'); tip.className = 'bxmpv-chart__tip is-hidden'; host.appendChild(tip);
    var labelEvery = Math.ceil(n / Math.floor(iw / 64));
    var path = '';
    data.labels.forEach(function (label, i) {
      var cx = padL + step * i + step / 2, bw = Math.max(2, step * 0.62);
      var bar = svgEl('rect', { x: cx - bw / 2, y: y(data.gmv[i]), width: bw, height: Math.max(0, padT + ih - y(data.gmv[i])), 'class': 'bar', rx: 2 });
      bar.addEventListener('mouseenter', function () {
        tip.innerHTML = '<b>' + label + '</b><br>GMV ' + money(data.gmv[i], data.currency) + '<br>Net ' + money(data.net[i], data.currency) + '<br>Orders ' + data.orders[i];
        tip.style.left = (cx / W * 100) + '%'; tip.style.top = (y(data.gmv[i]) / H * 100) + '%';
        tip.classList.remove('is-hidden');
      });
      bar.addEventListener('mouseleave', function () { tip.classList.add('is-hidden'); });
      svg.appendChild(bar);
      path += (i ? 'L' : 'M') + cx.toFixed(1) + ' ' + y(data.net[i]).toFixed(1);
      if (data.orders[i] > 0) { svg.appendChild(svgEl('circle', { cx: cx, cy: yo(data.orders[i]), r: 3, 'class': 'dot' })); }
      if (i % labelEvery === 0) {
        var lt = svgEl('text', { x: cx, y: H - 12, 'text-anchor': 'middle' }); lt.textContent = label.length === 10 ? label.slice(5) : label; svg.appendChild(lt);
      }
    });
    svg.appendChild(svgEl('path', { d: path, 'class': 'line' }));
    host.insertBefore(svg, tip);
  }

  ready(function () {
    document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
      el.addEventListener('change', function () {
        if (el.hasAttribute('data-toggle-custom') && el.value === 'custom') {
          document.querySelectorAll('.bxmpv-custom').forEach(function (c) { c.classList.remove('is-hidden'); });
          return;
        }
        el.form.submit();
      });
    });
    document.querySelectorAll('[data-toggle-row]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var row = document.getElementById(btn.getAttribute('data-toggle-row'));
        if (row) { row.classList.toggle('is-hidden'); var f = row.querySelector('input[name=price]'); if (f && !row.classList.contains('is-hidden')) { f.focus(); } }
      });
    });
    document.querySelectorAll('[data-fill-price]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var f = btn.closest('form').querySelector('input[name=price]');
        if (f) { f.value = btn.getAttribute('data-fill-price'); f.focus(); }
      });
    });
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
      form.addEventListener('submit', function (e) { if (!window.confirm(form.getAttribute('data-confirm'))) { e.preventDefault(); } });
    });
    document.querySelectorAll('[data-toggle-excluded]').forEach(function (cb) {
      var sync = function () { document.querySelectorAll('.bxmpv-costs').forEach(function (l) { l.classList.toggle('is-hidden', cb.checked); }); };
      cb.addEventListener('change', sync); sync();
    });
    var chart = document.getElementById('bxmpv-trend');
    if (chart) { drawTrend(chart); }
  });
})();
