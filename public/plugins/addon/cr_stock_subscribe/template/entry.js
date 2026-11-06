(function () {
  'use strict';
  var script = document.currentScript, path = script && script.getAttribute('data-member-url');
  if (!/^\/plugin\/[1-9][0-9]*\/stock_subscribe\.htm$/.test(path || '')) return;
  if (window.__crStockEntry) return;
  window.__crStockEntry = true;
  function clicked(e) {
    var token = ''; try { token = localStorage.getItem('jwt') || ''; } catch (err) {}
    if (!token) { e.preventDefault(); try { sessionStorage.setItem('redirectUrl', path); } catch (err) {} location.assign('/login.htm'); }
  }
  if (!script.hasAttribute('data-stock-cart')) return;
  // Vue may replace this DOM while mounting the cart. Delegation survives it.
  document.addEventListener('click', function (e) { if (e.target.closest && e.target.closest('#cr-stock-subscribe-entry a')) clicked(e); });
  var css = document.createElement('link'); css.rel = 'stylesheet'; css.href = '/plugins/addon/cr_stock_subscribe/template/entry.css?v=0.1.1&rev=20261103c'; document.head.appendChild(css);
  var frame = 0;
  function mount() {
    frame = 0;
    if (document.getElementById('cr-stock-subscribe-entry')) return;
    var heading = document.querySelector('.cart-page .main-title, .goods-list .main-title, .goodsList .main-title');
    var host = document.querySelector('.cart-page .main-content-box, .goods-list .main-card, .goodsList .main-card, .goods .main-card, .main-card');
    if (!heading && !host) return;
    var bar = document.createElement('div'); bar.id = 'cr-stock-subscribe-entry'; bar.className = 'cr-stock-entry';
    var note = document.createElement('span'); note.textContent = '缺货商品到货后通知我';
    var link = document.createElement('a'); link.href = path; link.textContent = '补货订阅';
    bar.appendChild(note); bar.appendChild(link);
    if (heading) heading.insertAdjacentElement('afterend', bar); else host.insertBefore(bar, host.firstChild);
  }
  function schedule() { if (!frame) frame = requestAnimationFrame(mount); }
  var observer = new MutationObserver(schedule); observer.observe(document.body, { childList: true, subtree: true }); mount();
  window.addEventListener('pagehide', function () { observer.disconnect(); if (frame) cancelAnimationFrame(frame); });
})();
