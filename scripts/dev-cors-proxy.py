#!/usr/bin/env python3
"""Developer tool: lets the phone app's BROWSER preview (expo web, :8082) talk to the dev API (:8731).
The real web apps use a same-origin proxy and the phone app is not subject to CORS, so the API has no preflight route for most paths;
this proxy answers preflights and adds the CORS headers. Local use only: it allows any origin.   python3 scripts/dev-cors-proxy.py
"""
import http.server, socketserver, urllib.request, urllib.error, sys

UP = 'http://localhost:8731'
PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8085

class H(http.server.BaseHTTPRequestHandler):
    def _cors(self):
        self.send_header('Access-Control-Allow-Origin', '*')
        self.send_header('Access-Control-Allow-Headers', 'Content-Type, Authorization, Accept, X-TS-Client')
        self.send_header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
    def do_OPTIONS(self):
        self.send_response(204); self._cors(); self.end_headers()
    def _go(self):
        n = int(self.headers.get('Content-Length') or 0); body = self.rfile.read(n) if n else None
        h = {k: v for k, v in self.headers.items() if k.lower() in ('content-type', 'authorization', 'accept')}
        req = urllib.request.Request(UP + self.path, data=body, headers=h, method=self.command)
        try: r = urllib.request.urlopen(req, timeout=120); code, data, ct = r.status, r.read(), r.headers.get('Content-Type', 'application/json')
        except urllib.error.HTTPError as e: code, data, ct = e.code, e.read(), e.headers.get('Content-Type', 'application/json')
        except Exception as e: code, data, ct = 502, str(e).encode(), 'text/plain'
        self.send_response(code); self._cors(); self.send_header('Content-Type', ct); self.send_header('Content-Length', str(len(data))); self.end_headers(); self.wfile.write(data)
    do_GET = do_POST = do_PUT = do_PATCH = do_DELETE = _go
    def log_message(self, *a): pass

class T(socketserver.ThreadingMixIn, http.server.HTTPServer): daemon_threads = True
T(('127.0.0.1', PORT), H).serve_forever()
