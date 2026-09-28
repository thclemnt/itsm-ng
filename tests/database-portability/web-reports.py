#!/usr/bin/env python3
# SPDX-License-Identifier: GPL-2.0-or-later
"""Report HTTP smoke contract for an isolated installed test server (seeded itsm login)."""
import argparse
import http.cookiejar
import re
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urlencode
from urllib.request import HTTPCookieProcessor, Request, build_opener

class Inputs(HTMLParser):
    def __init__(self, html):
        super().__init__()
        self.inputs = []
        self.feed(html)
    def handle_starttag(self, tag, attrs):
        if tag == 'input':
            self.inputs.append(dict(attrs))

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('url', help='Isolated application URL')
parser.add_argument('--log-dir', default=str(Path(__file__).resolve().parents[2] / 'files/_log'))
args = parser.parse_args()
base = args.url.rstrip('/')
client = build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()))
log = Path(args.log_dir) / 'sql-errors.log'
offset = log.stat().st_size if log.exists() else 0
php_log = Path(args.log_dir) / 'php-errors.log'
php_offset = php_log.stat().st_size if php_log.exists() else 0

def request(path, data=None):
    response = client.open(Request(base + path, data=None if data is None else urlencode(data).encode(), headers={'Referer': base + '/front/report.php'}), timeout=90)
    html = response.read().decode()
    for error in ('PostgreSQL query error', 'SQL error', 'Fatal error:', 'Uncaught ', 'CSRF token is invalid'):
        assert error not in html, f'{path}: {error}'
    if log.exists():
        with log.open('rb') as stream:
            stream.seek(offset)
            assert b'glpisqllog.ERROR' not in stream.read(), f'{path}: SQL log contains an error'
    if php_log.exists():
        with php_log.open('rb') as stream:
            stream.seek(php_offset)
            assert b'glpiphplog.CRITICAL' not in stream.read(), f'{path}: PHP log contains a fatal error'
    return response.url, html

_, html = request('/index.php')
inputs = Inputs(html).inputs
login = {field['name']: field.get('value', '') for field in inputs if field.get('type') == 'hidden'}
for field in inputs:
    if field.get('id') in ('login_name', 'login_password'):
        login[field['name']] = 'itsm'
url, html = request('/front/login.php', login)
assert url.endswith('/front/central.php'), 'Login failed'
for page, data in [
    ('report.default.php', None), ('report.state.php', None), ('report.reservation.php?id=2', None),
    ('report.year.list.php', {'year[0]': '2025', 'item_type[0]': '0'}),
    ('report.contract.list.php', {'year[0]': '2025', 'item_type[0]': '0'}),
    ('report.infocom.php', {'date1': '2025-01-01', 'date2': '2025-12-31'}),
    ('report.infocom.conso.php', {'date1': '2025-01-01', 'date2': '2025-12-31'}),
    ('report.switch.list.php', {'switch': 1}),
    ('report.location.list.php', {'locations_id': 1}),
    ('report.netpoint.list.php', {'prise': 1}),
]:
    if data is not None:
        _, form = request('/front/report.php')
        token = re.search(r'property="glpi:csrf_token" content="([^"]+)"', form)
        assert token, 'CSRF token missing'
        data['_glpi_csrf_token'] = token.group(1)
    url, html = request('/front/' + page, data)
    assert url.endswith('/front/' + page), f'{page}: unexpected redirect to {url}'
    assert '</html>' in html.lower(), f'{page}: incomplete response'
print('HTTP: default, state, reservation, year, contract, financial and network reports passed.')
