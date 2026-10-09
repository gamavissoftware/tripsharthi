/* TripSarthi website: WhatsApp button + live chat widget. Dependency-free. Talks to POST/GET /api/v1/public/chat/*.
   If the chat server cannot be reached it falls back to WhatsApp / phone / email, so a visitor is never left with a dead end. */
(function () {
  'use strict';
  var cfg = window.TRIPSARTHI_CHAT || {};
  var WA = cfg.whatsapp || '919718991797', PHONE = cfg.phone || '+91-9718991797', EMAIL = cfg.email || 'manglesh@gamavis.com';
  var local = /^(localhost|127\.0\.0\.1)$/.test(location.hostname);
  var API = window.TRIPSARTHI_API_URL || (local ? 'http://localhost:8731/api/v1' : 'https://app.tripsarthi.com/api/v1');
  var KEY = 'ts_chat_v1', loaded = Date.now();
  // asset + page links are relative to the site root, whichever folder the current page is in (e.g. /blog/)
  var BASE = (document.currentScript && document.currentScript.src) ? document.currentScript.src.replace(/assets\/js\/chat\.js.*$/, '') : '';
  var waUrl = 'https://wa.me/' + WA + '?text=' + encodeURIComponent('Hi TripSarthi, I would like to know more about your software for travel agencies.');

  function el(tag, attrs, html) { var e = document.createElement(tag); for (var k in (attrs || {})) { if (k === 'class') e.className = attrs[k]; else e.setAttribute(k, attrs[k]); } if (html) e.innerHTML = html; return e; }
  function store(v) { try { if (v === null) localStorage.removeItem(KEY); else if (v) localStorage.setItem(KEY, JSON.stringify(v)); else return JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) { return null; } return null; }
  function api(method, path, body) {
    return fetch(API + path, { method: method, headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: body ? JSON.stringify(body) : undefined })
      .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { j.__status = r.status; return j; }); });
  }
  var ICON_CHAT = '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.5 8.5 0 0 1-12.6 7.4L3 21l2.1-5.4A8.5 8.5 0 1 1 21 11.5z"/></svg>';
  var ICON_WA = '<svg viewBox="0 0 32 32" width="28" height="28" aria-hidden="true"><path fill="#fff" d="M16.02 3C9.4 3 4.03 8.37 4.03 14.98c0 2.11.55 4.17 1.6 5.99L4 28l7.2-1.89a11.95 11.95 0 0 0 4.82 1.02h.01c6.61 0 11.98-5.37 11.98-11.98C28 8.37 22.63 3 16.02 3zm0 21.9h-.01a9.9 9.9 0 0 1-5.04-1.38l-.36-.21-4.27 1.12 1.14-4.16-.24-.38a9.9 9.9 0 0 1-1.52-5.27c0-5.47 4.45-9.92 9.93-9.92 2.65 0 5.14 1.03 7.01 2.91a9.85 9.85 0 0 1 2.9 7.02c0 5.47-4.45 9.92-9.92 9.92zm5.44-7.43c-.3-.15-1.77-.87-2.04-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.17-.17.2-.35.22-.65.07-.3-.15-1.26-.46-2.4-1.48-.89-.79-1.49-1.77-1.66-2.07-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.07-.15-.67-1.62-.92-2.22-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.8.37-.27.3-1.04 1.02-1.04 2.48 0 1.46 1.07 2.88 1.22 3.08.15.2 2.1 3.2 5.08 4.49.71.31 1.26.49 1.7.63.71.23 1.36.2 1.87.12.57-.08 1.77-.72 2.02-1.42.25-.7.25-1.29.17-1.42-.07-.12-.27-.2-.57-.35z"/></svg>';

  // ---- launchers -------------------------------------------------------------------------------------------------------------------------
  var root = el('div', { id: 'ts-chat-root', class: 'tsc' });
  var wa = el('a', { class: 'tsc-wa', href: waUrl, target: '_blank', rel: 'noopener', 'aria-label': 'Chat with us on WhatsApp' }, ICON_WA + '<span class="tsc-tip">WhatsApp us</span>');
  var launch = el('button', { class: 'tsc-launch', type: 'button', 'aria-label': 'Open live chat', 'aria-expanded': 'false', 'aria-controls': 'ts-chat-panel' }, ICON_CHAT + '<span class="tsc-badge" hidden>1</span>');
  var panel = el('section', { class: 'tsc-panel', id: 'ts-chat-panel', role: 'dialog', 'aria-label': 'Live chat with TripSarthi', hidden: '' });
  panel.innerHTML =
    '<header class="tsc-head"><img src="' + BASE + 'assets/brand/mark-96.png" alt="" width="34" height="34"><div><b>TripSarthi support</b><small><i class="tsc-dot"></i><span class="tsc-status">We usually reply within minutes</span></small></div><button type="button" class="tsc-x" aria-label="Close chat">&times;</button></header>' +
    '<div class="tsc-body" aria-live="polite"></div>' +
    '<form class="tsc-start" novalidate><p>Hi 👋 Ask us anything about TripSarthi.</p><input name="name" placeholder="Your name (optional)" autocomplete="name"><input name="email" type="email" placeholder="Email (optional — so we can reply if you leave)" autocomplete="email"><div class="tsc-hp" aria-hidden="true"><input name="website" tabindex="-1" autocomplete="off"></div><textarea name="message" rows="3" placeholder="How can we help?" required></textarea><button type="submit" class="tsc-send">Start chat</button><p class="tsc-fine">Prefer WhatsApp? <a href="' + waUrl + '" target="_blank" rel="noopener">Message us there</a>. By chatting you agree to our <a href="' + BASE + 'privacy.html">privacy policy</a>.</p></form>' +
    '<form class="tsc-reply" hidden><input name="body" placeholder="Type a message…" autocomplete="off" maxlength="1000"><button type="submit" class="tsc-send" aria-label="Send">&#10148;</button></form>';
  root.appendChild(wa); root.appendChild(launch); root.appendChild(panel);

  var body = panel.querySelector('.tsc-body'), start = panel.querySelector('.tsc-start'), reply = panel.querySelector('.tsc-reply'),
      statusEl = panel.querySelector('.tsc-status'), dot = panel.querySelector('.tsc-dot'), badge = launch.querySelector('.tsc-badge');
  var session = store(), lastId = 0, timer = null, open = false, unseen = 0, ended = false;

  function bubble(m) {
    var b = el('div', { class: 'tsc-m tsc-' + m.sender }); b.textContent = m.body; body.appendChild(b); return b;
  }
  function scroll() { body.scrollTop = body.scrollHeight; }
  function setOnline(on) { statusEl.textContent = on ? 'Online — we’ll reply here' : 'Away — we’ll reply by email or WhatsApp'; dot.className = 'tsc-dot' + (on ? ' on' : ''); }
  function render(msgs) {
    msgs.forEach(function (m) {
      if (m.id <= lastId) return; lastId = m.id; bubble(m);
      if (!open && m.sender !== 'visitor') { unseen++; badge.textContent = unseen > 9 ? '9+' : String(unseen); badge.hidden = false; }
      if (m.sender === 'system' && /closed by our team/i.test(m.body)) endChat();
    });
    scroll();
  }
  function showChat() { start.hidden = true; reply.hidden = ended; }
  function endChat() {
    ended = true; reply.hidden = true; stop();
    var again = el('button', { type: 'button', class: 'tsc-again' }, 'Start a new chat'); again.onclick = function () { store(null); session = null; lastId = 0; ended = false; body.innerHTML = ''; start.hidden = false; again.remove(); };
    body.appendChild(again); scroll();
  }
  function fallbackMsg() {
    body.appendChild(el('div', { class: 'tsc-m tsc-system' }, 'We could not connect to chat right now. Please <a href="' + waUrl + '" target="_blank" rel="noopener">WhatsApp us</a>, call <a href="tel:' + PHONE.replace(/-/g, '') + '">' + PHONE + '</a> or email <a href="mailto:' + EMAIL + '">' + EMAIL + '</a>.')); scroll();
  }

  function poll() {
    if (!session) return;
    api('GET', '/public/chat/' + session.token + '/poll?after=' + lastId).then(function (r) {
      if (r.__status === 404) { store(null); session = null; stop(); return; }
      if (r.success) { setOnline(!!r.online); render(r.messages || []); if (r.status === 'closed' && !ended) endChat(); }
    }).catch(function () {});
  }
  function begin() { stop(); if (!session || ended) return; poll(); timer = setInterval(poll, open && !document.hidden ? 3000 : 20000); }
  function stop() { if (timer) { clearInterval(timer); timer = null; } }

  function toggle(force) {
    open = typeof force === 'boolean' ? force : !open;
    panel.hidden = !open; launch.setAttribute('aria-expanded', String(open)); launch.classList.toggle('on', open); root.classList.toggle('open', open);
    if (open) { unseen = 0; badge.hidden = true; if (session) { showChat(); } setTimeout(function () { (session ? reply.body : start.message).focus(); scroll(); }, 50); }
    begin();
  }
  launch.addEventListener('click', function () { toggle(); });
  panel.querySelector('.tsc-x').addEventListener('click', function () { toggle(false); launch.focus(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && open) toggle(false); });
  document.addEventListener('visibilitychange', begin);

  start.addEventListener('submit', function (e) {
    e.preventDefault();
    var msg = start.message.value.trim(), email = start.email.value.trim();
    if (!msg) { start.message.focus(); return; }
    if (email && !/^\S+@\S+\.\S+$/.test(email)) { start.email.focus(); return; }
    var btn = start.querySelector('.tsc-send'); btn.disabled = true; btn.textContent = 'Connecting…';
    api('POST', '/public/chat/start', { name: start.name.value.trim(), email: email, message: msg, website: start.website.value, page: location.pathname.replace(/^\//, '') || 'index.html', t: loaded })
      .then(function (r) {
        btn.disabled = false; btn.textContent = 'Start chat';
        if (r.__status === 201 && r.success) { session = { token: r.token, at: Date.now() }; store(session); lastId = 0; body.innerHTML = ''; showChat(); setOnline(!!r.online); render(r.messages || []); start.reset(); reply.body.focus(); begin(); }
        else if (r.message && r.__status && r.__status < 500) { var n = start.querySelector('.tsc-err') || start.insertBefore(el('p', { class: 'tsc-err', role: 'alert' }), start.querySelector('button')); n.textContent = r.message; }
        else { start.hidden = true; fallbackMsg(); }
      }).catch(function () { btn.disabled = false; btn.textContent = 'Start chat'; start.hidden = true; fallbackMsg(); });
  });

  reply.addEventListener('submit', function (e) {
    e.preventDefault(); var t = reply.body.value.trim(); if (!t || !session) return;
    reply.body.value = ''; var temp = bubble({ sender: 'visitor', body: t }); temp.classList.add('tsc-pending'); scroll();
    api('POST', '/public/chat/' + session.token + '/send', { body: t }).then(function (r) {
      temp.remove();
      if (r.success) { poll(); }
      else if (r.__status === 409) { endChat(); }
      else { bubble({ sender: 'system', body: r.message || 'Message not sent. Please try again.' }); }
    }).catch(function () { temp.remove(); fallbackMsg(); });
  });

  // returning visitor: restore the conversation, show unread agent replies on the launcher
  if (session && (Date.now() - (session.at || 0)) > 7 * 86400000) { store(null); session = null; }
  if (session) { showChat(); begin(); }

  function mount() { document.body.appendChild(root); }
  if (document.body) mount(); else document.addEventListener('DOMContentLoaded', mount);
})();
