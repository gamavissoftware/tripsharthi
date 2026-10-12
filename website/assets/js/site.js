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
  var qt = /[?&]topic=([a-z]+)/.exec(location.search); if (qt && topics[qt[1]]) form.topic.value = qt[1];
  function say(msg, cls) { note.textContent = msg; note.className = 'fine' + (cls ? ' ' + cls : ''); }
  function fallback(v) {
    var body = ['Name: ' + v.name, 'Email: ' + v.email, 'Phone: ' + (v.phone || '-'), 'Company: ' + (v.company || '-'), '', v.message].join('\n');
    location.href = 'mailto:' + EMAIL + '?subject=' + encodeURIComponent('TripSarthi — ' + topics[v.topic]) + '&body=' + encodeURIComponent(body);
    say('We could not reach our server, so your email app should have opened. If not, please write to ' + EMAIL + ' or call ' + PHONE + '.', 'err');
  }
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var v = { name: form.name.value.trim(), email: form.email.value.trim(), phone: form.phone.value.trim(), company: form.company.value.trim(), topic: form.topic.value, message: form.message.value.trim(), wa_opt_in: !!(form.wa_opt_in && form.wa_opt_in.checked), website: form.website.value, source: location.pathname.replace(/^\//, '') || 'contact.html', t: loaded };
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


/* partner earnings calculator + plan finder */
(function () {
  'use strict';
  var inr = function (n) { return '₹' + Math.round(n).toLocaleString('en-IN'); };
  var n = document.getElementById('pc-n'), plans = document.querySelectorAll('#pc-plan button');
  if (n && plans.length) {
    var price = 3999;
    var calc = function () {
      var c = Number(n.value), base = c * price, m = base * 0.2;
      document.getElementById('pc-n-o').textContent = c;
      document.getElementById('pc-month').textContent = inr(m); document.getElementById('pc-year').textContent = inr(m * 12); document.getElementById('pc-base').textContent = inr(base);
    };
    n.addEventListener('input', calc);
    plans.forEach(function (b) { b.addEventListener('click', function () { price = Number(b.getAttribute('data-price')); plans.forEach(function (x) { x.setAttribute('aria-pressed', String(x === b)); }); calc(); }); });
    calc();
  }
  var pf = document.getElementById('pf');
  if (pf) {
    var LIM = [['Free', 0, 200, 1, 1], ['Starter', 1499, 1000, 3, 1], ['Growth', 3999, 5000, 10, 2], ['Pro', 6999, 25000, 50, 5]];
    var inputs = { a: document.getElementById('pf-a'), c: document.getElementById('pf-c'), w: document.getElementById('pf-w') };
    var run = function () {
      var a = Number(inputs.a.value), c = Number(inputs.c.value), w = Number(inputs.w.value);
      document.getElementById('pf-a-o').textContent = a; document.getElementById('pf-c-o').textContent = c.toLocaleString('en-IN'); document.getElementById('pf-w-o').textContent = w;
      var hit = LIM.filter(function (p) { return a <= p[3] && c <= p[2] && w <= p[4]; })[0], out = document.getElementById('pf-out');
      if (!hit) { out.innerHTML = '<small>Your needs are above our standard plans</small><div class="amt" style="font-size:34px">Let’s talk</div><a class="btn btn-accent btn-lg" href="contact.html?topic=pricing#demo">Contact us</a>'; return; }
      out.innerHTML = '<small>We would suggest</small><div class="amt">' + hit[0] + '</div><small>' + (hit[1] ? inr(hit[1]) + ' / month + GST' : 'Free forever to explore') + '</small><hr><div class="two-col"><div><small>Contacts</small><b>' + hit[2].toLocaleString('en-IN') + '</b></div><div><small>Team agents · WhatsApp numbers</small><b>' + hit[3] + ' · ' + hit[4] + '</b></div></div><a class="btn btn-accent btn-lg" data-app="signup" href="#">Start free</a>';
      document.querySelectorAll('#pf-out [data-app]').forEach(function (el) { el.href = (window.TRIPSARTHI_APP_URL || (/^(localhost|127\.0\.0\.1)$/.test(location.hostname) ? 'http://localhost:5917' : 'https://app.tripsarthi.com')) + '/#/register'; });
    };
    Object.keys(inputs).forEach(function (k) { inputs[k].addEventListener('input', run); }); run();
  }
})();
