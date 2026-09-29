#!/usr/bin/env python3
# SPDX-License-Identifier: GPL-2.0-or-later
"""Authenticated history pagination contract against a disposable installation."""
import argparse
import http.cookiejar
import json
import subprocess
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
parser.add_argument('url')
parser.add_argument('--config', required=True)
parser.add_argument('--bootstrap', required=True)
parser.add_argument('--log-dir', required=True)
args = parser.parse_args()
root = Path(__file__).resolve().parents[2]
base = args.url.rstrip('/')
client = build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()))
logs = {Path(args.log_dir) / name: 0 for name in ('sql-errors.log', 'php-errors.log')}
logs = {path: path.stat().st_size if path.exists() else 0 for path in logs}


def fixture(action):
    result = subprocess.check_output([
        'php', '-d', f'auto_prepend_file={args.bootstrap}',
        str(root / 'tests/database-portability/history-web-fixture.php'), args.config, action,
    ], cwd=root)
    return json.loads(result) if action != 'clean' else None


def request(path, data=None):
    response = client.open(Request(base + path, data=None if data is None else urlencode(data).encode(),
                                   headers={'Referer': base + '/front/central.php'}), timeout=90)
    body = response.read().decode()
    for error in ('PostgreSQL query error', 'SQL error', 'Fatal error:', 'Uncaught ', 'CSRF token is invalid'):
        assert error not in body, (path, error)
    for log, offset in logs.items():
        if log.exists():
            with log.open('rb') as stream:
                stream.seek(offset)
                errors = stream.read()
                assert b'glpisqllog.ERROR' not in errors and b'glpiphplog.CRITICAL' not in errors, str(log)
    return response.url, body


initial = fixture('seed')
try:
    _, html = request('/index.php')
    inputs = Inputs(html).inputs
    login = {field['name']: field.get('value', '') for field in inputs if field.get('type') == 'hidden'}
    for field in inputs:
        if field.get('id') in ('login_name', 'login_password'):
            login[field['name']] = 'itsm'
    url, _ = request('/front/login.php', login)
    assert url.endswith('/front/central.php'), 'Login failed'

    def history(**options):
        params = {'itemtype': 'Computer', 'items_id': initial['id'], 'limit': 2, **options}
        _, body = request('/ajax/v2/log.php?' + urlencode(params))
        return json.loads(body)

    page = history()
    assert page['total'] == 3 and [row['id'] for row in page['rows']] == initial['logs'][1:][::-1], page
    page = history(offset=1)
    assert page['total'] == 3 and [row['id'] for row in page['rows']] == initial['logs'][:2][::-1], page
    page = history(sort='date_mod', order='ASC', limit=3)
    assert [row['id'] for row in page['rows']] == [initial['logs'][2], *initial['logs'][:2]], page
    assert all('<script>' not in row['change'] and '&lt;script&gt;' in row['change'] for row in page['rows']), page
    page = history(filters=json.dumps({'users_names': [initial['actor']], 'date': '2024-02-29'}))
    assert page['total'] == 2 and [row['id'] for row in page['rows']] == initial['logs'][:2][::-1], page
    assert all(row['user_name'] == initial['actor'] for row in page['rows']), page
    assert history(offset=9999) == {'total': 3, 'rows': []}
    assert history(itemtype='MissingHistoryType') == {'total': 0, 'rows': []}
    assert history(items_id=2147483647) == {'total': 0, 'rows': []}
    print('HTTP: authenticated history scope, pagination, NULL ordering, escaped filters and HTML output passed.')
finally:
    fixture('clean')
