"""No database or real credentials required. Usage: python tests/security_smoke.py [php executable]"""
from pathlib import Path
import http.cookiejar
import json
import os
import re
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

root = Path(__file__).resolve().parents[1]
php = sys.argv[1] if len(sys.argv) > 1 else "php"
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None

with tempfile.TemporaryDirectory(prefix="pglife-check-") as tmp:
    config = Path(tmp) / "private.php"
    config.write_text("<?php return ['base_path'=>'/PGLife/', 'cookie_secure'=>false];", encoding="utf-8")
    router = Path(tmp) / "router.php"
    php_root = str(root).replace("\\", "/").replace("'", "\\'")
    router.write_text("<?php $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH); "
                      "if (!str_starts_with($path, '/PGLife/')) { http_response_code(404); exit; } "
                      "$target = '" + php_root + "' . substr($path, 7); "
                      "if (is_file($target)) { require $target; } else { http_response_code(404); }", encoding="utf-8")
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
    env = dict(os.environ, PGLIFE_CONFIG_FILE=str(config))
    with open(Path(tmp) / "server.log", "w") as log:
        server = subprocess.Popen([php, "-d", 'session.save_path="' + tmp.replace('\\', '/') + '"' , "-S", f"127.0.0.1:{port}", str(router)], cwd=root, env=env, stdout=log, stderr=log)
        try:
            opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())
            def request(path, data=None):
                req = urllib.request.Request(f"http://127.0.0.1:{port}/PGLife/" + path,
                    data=None if data is None else urllib.parse.urlencode(data, doseq=True).encode())
                try:
                    result = opener.open(req, timeout=5)
                except urllib.error.HTTPError as error:
                    result = error
                return result.code, result.headers, result.read().decode()
            for _ in range(50):
                try:
                    code, headers, body = request("index.php")
                    break
                except urllib.error.URLError:
                    time.sleep(0.1)
            assert code == 200 and '<base href="/PGLife/">' in body, (code, body, (Path(tmp) / "server.log").read_text())
            assert 'HttpOnly' in headers['Set-Cookie'] and 'SameSite=Lax' in headers['Set-Cookie']
            assert 'path=/PGLife/' in headers['Set-Cookie'] and 'domain=' not in headers['Set-Cookie'].lower()
            assert headers['Cache-Control'] == 'no-store, private'
            token = re.search(r'name="csrf-token" content="([a-f0-9]{64})"', body)[1]
            assert body.count('name="csrf_token" value="' + token + '"') == 2
            for endpoint in ["api/login_submit.php", "api/signup_submit.php", "api/toggle_interested.php", "logout.php"]:
                assert request(endpoint)[0] == 405, endpoint
                assert request(endpoint, {})[0] == 403, endpoint
                assert request(endpoint, {"csrf_token": "wrong"})[0] == 403, endpoint
                assert request(endpoint, {"csrf_token[]": token})[0] == 403, endpoint
            assert request('api/signup_submit.php', {'csrf_token': token, 'email[]': 'x'})[0] == 400
            assert request('api/login_submit.php', {'csrf_token': token, 'password[]': 'x'})[0] == 400
            assert request('api/toggle_interested.php', {'csrf_token': token, 'property_id': '1'})[0] == 401
            assert request('property_detail.php?property_id=1%20OR%201=1')[0] == 400
            assert request('property_list.php?city[]=Delhi')[0] == 400
            code, headers, _ = request('dashboard.php')
            assert code == 302 and headers['Location'] == '/PGLife/index.php'
            code, headers, _ = request('logout.php', {'csrf_token': token})
            assert code == 303 and headers['Location'] == '/PGLife/index.php'
            assert 'path=/PGLife/' in headers['Set-Cookie']
            assert request('api/signup_submit.php', {'csrf_token': token})[0] == 403
            # Production policy emits Secure without trusting forwarded headers.
            config.write_text("<?php return ['base_path'=>'/PGLife/', 'cookie_secure'=>true];", encoding="utf-8")
            opener = urllib.request.build_opener(NoRedirect())
            code, headers, _ = request('index.php')
            assert code == 200 and 'secure' in headers['Set-Cookie'].lower()
            # Missing private configuration fails closed with a generic response.
            config.unlink()
            code, _, body = request('index.php')
            assert code == 500 and body == 'Service temporarily unavailable.'
            code, headers, body = request('api/login_submit.php', {})
            assert code == 500 and json.loads(body)['success'] is False
            print('PASS: home rendering, cookie scope, CSRF/method guards, validation, auth guard, logout, safe configuration failure')
        finally:
            server.terminate()
            server.wait(timeout=10)


