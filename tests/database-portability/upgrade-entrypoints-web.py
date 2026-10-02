#!/usr/bin/env python3
# SPDX-License-Identifier: GPL-2.0-or-later
"""Real authenticated and anonymous HTTP contracts for canonical upgrades."""
import argparse
import http.cookiejar
from html.parser import HTMLParser
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import urlencode
from urllib.request import HTTPCookieProcessor, Request, build_opener


class Inputs(HTMLParser):
    def __init__(self, html):
        super().__init__()
        self.fields = []
        self.feed(html)

    def handle_starttag(self, tag, attrs):
        if tag == 'input':
            self.fields.append(dict(attrs))


parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('url')
parser.add_argument('phase', choices=['login', 'preview', 'apply', 'missing-key', 'broken-ledger', 'unsupported'])
parser.add_argument('cookies', type=Path)
parser.add_argument('viewer')
args = parser.parse_args()
base = args.url.rstrip('/')


def client(name):
    jar = http.cookiejar.LWPCookieJar(str(args.cookies / name))
    if Path(jar.filename).exists():
        jar.load(ignore_discard=True)
    return build_opener(HTTPCookieProcessor(jar)), jar


def request(opener, path, data=None):
    query = Request(base + path, data=None if data is None else urlencode(data).encode(),
                    headers={'Referer': base + '/index.php'})
    try:
        response = opener.open(query, timeout=90)
    except HTTPError as error:
        response = error
    body = response.read().decode()
    for error in ('PostgreSQL query error', 'SQL error', 'Fatal error:', 'Uncaught ', 'glpisqllog.ERROR'):
        assert error not in body, (path, error, body)
    return response.status, response.url, body


def token(body):
    fields = [field['value'] for field in Inputs(body).fields if field.get('name') == '_glpi_csrf_token']
    assert fields, ('Missing CSRF form', body)
    return fields[0]


admin, admin_jar = client('admin')
viewer, viewer_jar = client('viewer')
anonymous, _ = client('anonymous')
if args.phase == 'login':
    for opener, name, password in [(admin, 'itsm', 'itsm'), (viewer, args.viewer, 'UpgradeFixturePassword')]:
        status, _, body = request(opener, '/index.php')
        assert status == 200, body
        fields = Inputs(body).fields
        data = {field['name']: field.get('value', '') for field in fields if field.get('type') == 'hidden'}
        for field in fields:
            if field.get('id') == 'login_name':
                data[field['name']] = name
            elif field.get('id') == 'login_password':
                data[field['name']] = password
        status, url, body = request(opener, '/front/login.php', data)
        assert status == 200 and url.endswith('/front/central.php'), (name, url, body)
elif args.phase == 'preview':
    for opener in [anonymous, viewer]:
        status, _, body = request(opener, '/index.php?donotcheckversion=1')
        assert status == 503 and 'Canonical database history is pending' in body, body
        assert 'name="from_update"' not in body, 'Readiness must not grant an upgrade capability'
        assert request(opener, '/install/update.php')[0] == 403
        assert request(opener, '/install/update.php', {'continuer': '1', '_glpi_csrf_token': 'forged'})[0] == 403
    status, _, body = request(admin, '/index.php?donotcheckversion=1')
    assert status == 503 and 'name="from_update"' in body, body
    assert request(admin, '/install/update.php', {'continuer': '1', '_glpi_csrf_token': 'forged'})[0] == 403
    status, _, body = request(admin, '/install/update.php', {'from_update': '1', '_glpi_csrf_token': token(body)})
    assert status == 200 and 'name="continuer"' in body and 'Update successful' not in body, body
elif args.phase == 'apply':
    status, _, body = request(admin, '/index.php')
    assert status == 503, body
    status, _, body = request(admin, '/install/update.php', {'from_update': '1', '_glpi_csrf_token': token(body)})
    assert status == 200, body
    status, _, body = request(admin, '/install/update.php', {'continuer': '1', '_glpi_csrf_token': token(body)})
    assert status == 200 and 'Update successful' in body, body
    assert request(admin, '/install/update.php')[0] == 403, 'Completion revokes the temporary upgrade capability'
elif args.phase == 'broken-ledger':
    for opener in [admin, anonymous]:
        status, _, body = request(opener, '/index.php')
        assert status == 503 and 'ledger could not be validated' in body, body
        assert 'name="from_update"' not in body
        assert request(opener, '/install/update.php')[0] == 403
else:
    status, _, body = request(admin, '/index.php')
    assert status == 503, body
    status, _, body = request(admin, '/install/update.php', {'from_update': '1', '_glpi_csrf_token': token(body)})
    assert status == 409 and 'name="continuer"' not in body and 'Update successful' not in body, body
    if args.phase == 'missing-key':
        assert 'Restore the original encryption key' in body, body
    else:
        assert 'Missing column:' in body and 'matching historical application' in body, body
admin_jar.save(ignore_discard=True)
viewer_jar.save(ignore_discard=True)
print(f'HTTP canonical upgrade {args.phase}: passed')
