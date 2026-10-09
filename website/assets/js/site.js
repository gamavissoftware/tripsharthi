/* TripSarthi marketing site — small, dependency-free. */
(function () {
  'use strict';
  // Where the product lives. Local dev -> the Vite dev server; production -> app.tripsarthi.com. Change APP_URL if you host the app elsewhere.
  var local = /^(localhost|127\.0\.0\.1)$/.test(location.hostname);
  var APP_URL = window.TRIPSARTHI_APP_URL || (local ? 'http://localhost:5917' : 'https://app.tripsarthi.com');
  document.querySelectorAll('[data-app]').forEach(function (a) {
    a.href = APP_URL + '/#/' + (a.getAttribute('data-app') === 'signup' ? 'register' : 'login');
  });

  var yr = document.getElementById('yr'); if (yr) yr.textContent = new Date().getFullYear();

  // sticky nav shadow + mobile menu
  var nav = document.querySelector('.nav');
  function onScroll() { nav && nav.classList.toggle('scrolled', window.scrollY > 8); }
  onScroll(); window.addEventListener('scroll', onScroll, { passive: true });
  var burger = document.getElementById('burger'), menu = document.getElementById('menu');
  if (burger && menu) {
    var setMenu = function (open) { menu.classList.toggle('open', open); burger.setAttribute('aria-expanded', String(open)); burger.querySelector('use').setAttribute('href', open ? '#i-x' : '#i-menu'); };
    burger.addEventListener('click', function () { setMenu(!menu.classList.contains('open')); });
    menu.addEventListener('click', function (e) { if (e.target.closest('a')) setMenu(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setMenu(false); });
  }

  // product tour tabs (arrow keys supported)
  var tabs = Array.prototype.slice.call(document.querySelectorAll('.tabs [role=tab]')), panes = document.querySelectorAll('.pane');
  function select(i, focus) {
    tabs.forEach(function (t, j) { t.setAttribute('aria-selected', String(i === j)); t.tabIndex = i === j ? 0 : -1; });
    panes.forEach(function (p) { p.classList.toggle('on', Number(p.getAttribute('data-pane')) === i); });
    if (focus) tabs[i].focus();
  }
  tabs.forEach(function (t, i) {
    t.addEventListener('click', function () { select(i); });
    t.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowRight') { e.preventDefault(); select((i + 1) % tabs.length, true); }
      if (e.key === 'ArrowLeft') { e.preventDefault(); select((i - 1 + tabs.length) % tabs.length, true); }
    });
  });

  // pricing toggle
  var bill = document.querySelectorAll('.toggle button');
  bill.forEach(function (b) {
    b.addEventListener('click', function () {
      var annual = b.getAttribute('data-bill') === 'annual';
      bill.forEach(function (x) { x.classList.toggle('on', x === b); });
      document.querySelectorAll('.price b').forEach(function (p) { p.textContent = p.getAttribute(annual ? 'data-a' : 'data-m'); });
    });
  });

  // reveal on scroll
  var items = document.querySelectorAll('.reveal');
  if ('IntersectionObserver' in window && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    var io = new IntersectionObserver(function (es) { es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } }); }, { threshold: .12, rootMargin: '0px 0px -40px 0px' });
    items.forEach(function (el) { io.observe(el); });
  } else { items.forEach(function (el) { el.classList.add('in'); }); }
})();

/* contact form -> opens the visitor's email app with the message ready (no server needed) */
(function () {
  'use strict';
  var form = document.getElementById('contact-form'); if (!form) return;
  var EMAIL = 'manglesh@gamavis.com', note = document.getElementById('cf-note');
  var topics = { demo: 'Demo request', pricing: 'Pricing enquiry', support: 'Help / support', partner: 'Partnership', other: 'Enquiry' };
  var hash = (location.hash || '').replace('#', ''); if (topics[hash]) form.topic.value = hash;
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var ok = true;
    ['name', 'email'].forEach(function (n) { var f = form[n], v = f.value.trim(), bad = !v || (n === 'email' && !/^\S+@\S+\.\S+$/.test(v)); f.classList.toggle('bad', bad); if (bad) ok = false; });
    if (!ok) { note.textContent = 'Please add your name and a valid email address.'; return; }
    var v = function (n) { return form[n].value.trim(); };
    var body = ['Name: ' + v('name'), 'Email: ' + v('email'), 'Phone: ' + (v('phone') || '-'), 'Company: ' + (v('company') || '-'), '', v('message')].join('\n');
    location.href = 'mailto:' + EMAIL + '?subject=' + encodeURIComponent('TripSarthi — ' + topics[form.topic.value]) + '&body=' + encodeURIComponent(body);
    note.textContent = 'Your email app should have opened. If not, please write to ' + EMAIL + ' or call +91-9718991797.';
  });
})();
