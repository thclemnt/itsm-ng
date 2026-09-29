#!/usr/bin/env python3
# SPDX-License-Identifier: GPL-2.0-or-later
"""Authenticated preference controller contract against a disposable installation."""
import argparse
import http.cookiejar
import json
import re
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
        str(root / 'tests/database-portability/display-preferences-web-fixture.php'), args.config, action,
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

    def action(name, values, denied=False):
        _, page = request('/front/central.php')
        token = re.search(r'property="glpi:csrf_token" content="([^"]+)"', page).group(1)
        url, body = request('/front/displaypreference.form.php', {
            name: 1, 'itemtype': 'Computer', 'users_id': initial['user'], '_glpi_csrf_token': token, **values,
        })
        assert url.endswith('/front/displaypreference.form.php'), url
        assert ('permission to perform this action' in body) == denied, (name, denied)

    action('up', {'id': initial['personal'][1]['id']})
    state = fixture('read')
    assert [row['num'] for row in state['personal']] == [3, 2], state
    for name in ('up', 'purge'):
        action(name, {'id': initial['foreign'][0]['id']}, denied=True)
        action(name, {'id': initial['foreign'][0]['id'], 'users_id': initial['other']}, denied=True)
        action(name, {'id': initial['ticket'][0]['id']}, denied=True)
    assert fixture('read') == state, 'Rejected requests changed preferences'
    action('add', {'num': 4})
    assert [row['num'] for row in fixture('read')['personal']] == [3, 2, 4]
    action('disable', {})
    assert fixture('read')['personal'] == []
    action('activate', {})
    state = fixture('read')
    assert [row['num'] for row in state['personal']] == [row['num'] for row in initial['defaults']]
    action('activate', {})
    assert fixture('read') == state, 'Repeated activation changed the list'
    assert state['defaults'] == initial['defaults'] and state['foreign'] == initial['foreign']
    def modal(name, values=None):
        _, body = request('/ajax/v2/displaypreferences.php', {
            'action': name, 'itemtype': 'Computer', 'view': 'personal', **(values or {}),
        })
        return json.loads(body)

    loaded = modal('load')
    assert loaded['success'] and loaded['has_personal'] and loaded['view'] == 'personal'
    assert loaded['selected'] == [row['num'] for row in state['personal']]
    available = [entry['id'] for entry in loaded['available'] if entry['id'] not in loaded['locked']]
    requested = available[:2][::-1]
    assert len(requested) == 2
    expected = list(dict.fromkeys(loaded['locked'] + requested + [num for num in loaded['selected'] if num in loaded['noremove']]))
    assert modal('save', {'order[0]': requested[0], 'order[1]': requested[1], 'order[2]': requested[0], 'order[3]': 2147483647, 'users_id': initial['other']})['success']
    assert modal('load')['selected'] == expected
    assert fixture('read')['foreign'] == initial['foreign'], 'Modal accepted a forged user ID'
    assert modal('delete_personal')['success']
    loaded = modal('load')
    assert not loaded['has_personal'] and loaded['selected'] == [row['num'] for row in initial['defaults']]
    assert not modal('save', {'order[0]': 2})['success'], 'Modal saved without personal activation'
    assert modal('load', {'default_if_no_personal': 1})['view'] == 'global'
    assert modal('activate_personal')['success']
    assert modal('load')['selected'] == [row['num'] for row in initial['defaults']]
    assert fixture('read')['defaults'] == initial['defaults']
    print('HTTP: legacy and modal preferences, bulk saves, fallback/activation and forged owner/type rejection passed.')
finally:
    fixture('clean')
