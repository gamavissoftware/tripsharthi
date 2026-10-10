/* TripSarthi marketing site — small, dependency-free. */
(function () {
  'use strict';
  // Where the product lives. Local dev -> the Vite dev server; production -> app.tripsarthi.com. Change APP_URL if you host the app elsewhere.
  var local = /^(localhost|127\.0\.0\.1)$/.test(location.hostname);
  var APP_URL = window.TRIPSARTHI_APP_URL || (local ? 'http://localhost:5917' : 'https://app.tripsarthi.com');
  // Partner referral: remember ?ref=CODE for 90 days (first touch wins) and carry it to the app's sign-up link, because the website and the
  // app are different origins. The server decides whether the code counts.
  var REF_KEY = 'ts_ref', REF_OK = /^[A-Za-z0-9][A-Za-z0-9_-]{2,19}$/;
  function readRef() {
    try { var v = JSON.parse(localStorage.getItem(REF_KEY) || 'null'); return v && REF_OK.test(v.code) && Date.now() - v.at < 90 * 864e5 ? v.code : null; } catch (e) { return null; }
  }
  try {
    var qm = /[?&]ref=([A-Za-z0-9_-]{3,20})(?:&|$)/.exec(location.search);
    if (qm && REF_OK.test(qm[1]) && !readRef()) localStorage.setItem(REF_KEY, JSON.stringify({ code: qm[1].toUpperCase(), at: Date.now() }));
  } catch (e) { /* storage blocked: the referral is simply not remembered */ }
  var REF = readRef();
  document.querySelectorAll('[data-app]').forEach(function (a) {
    var signup = a.getAttribute('data-app') === 'signup';
    a.href = REF && signup ? APP_URL + '/?ref=' + encodeURIComponent(REF) + '#/register' : APP_URL + '/#/' + (signup ? 'register' : 'login');
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

/* contact form: posts to the TripSarthi API (stored + emailed to the team). If the API cannot be reached it falls back to the visitor's email app. */
(function () {
  'use strict';
  var form = document.getElementById('contact-form'); if (!form) return;
  var EMAIL = 'manglesh@gamavis.com', PHONE = '+91-9718991797', note = document.getElementById('cf-note'), btn = form.querySelector('button[type=submit]');
  var local = /^(localhost|127\.0\.0\.1)$/.test(location.hostname);
  var API = window.TRIPSARTHI_API_URL || (local ? 'http://localhost:8731/api/v1' : 'https://app.tripsarthi.com/api/v1');
  var loaded = Date.now();
  var topics = { demo: 'Demo request', pricing: 'Pricing enquiry', support: 'Help / support', partner: 'Partnership', other: 'Enquiry' };
  var hash = (location.hash || '').replace('#', ''); if (topics[hash]) form.topic.value = hash;
  function say(msg, cls) { note.textContent = msg; note.className = 'fine' + (cls ? ' ' + cls : ''); }
  function fallback(v) {
    var body = ['Name: ' + v.name, 'Email: ' + v.email, 'Phone: ' + (v.phone || '-'), 'Company: ' + (v.company || '-'), '', v.message].join('\n');
    location.href = 'mailto:' + EMAIL + '?subject=' + encodeURIComponent('TripSarthi — ' + topics[v.topic]) + '&body=' + encodeURIComponent(body);
    say('We could not reach our server, so your email app should have opened. If not, please write to ' + EMAIL + ' or call ' + PHONE + '.', 'err');
  }
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var v = { name: form.name.value.trim(), email: form.email.value.trim(), phone: form.phone.value.trim(), company: form.company.value.trim(), topic: form.topic.value, message: form.message.value.trim(), website: form.website.value, source: location.pathname.replace(/^\//, '') || 'contact.html', t: loaded };
    var ok = true;
    [['name', v.name.length < 2], ['email', !/^\S+@\S+\.\S+$/.test(v.email)]].forEach(function (c) { form[c[0]].classList.toggle('bad', c[1]); if (c[1]) ok = false; });
    if (!ok) { say('Please add your name and a valid email address.', 'err'); return; }
    btn.disabled = true; say('Sending…');
    fetch(API + '/public/contact', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(v) })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { status: r.status, body: j }; }); })
      .then(function (res) {
        if (res.status === 201 && res.body.success) { form.reset(); form.topic.value = v.topic; say(res.body.message || 'Thank you — we will be in touch shortly.', 'ok'); }
        else if (res.status === 422 || res.status === 429 || res.status === 413) { say(res.body.message || 'Please check the form and try again.', 'err'); }
        else { fallback(v); }
      })
      .catch(function () { fallback(v); })
      .then(function () { btn.disabled = false; });
  });
})();
