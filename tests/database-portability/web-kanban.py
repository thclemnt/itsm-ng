#!/usr/bin/env python3
# SPDX-License-Identifier: GPL-2.0-or-later
"""Kanban AJAX contract; writes the seeded itsm user's board in a disposable installation."""
import argparse
import http.cookiejar
import json
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
parser.add_argument('--log-dir', required=True)
args = parser.parse_args()
base = args.url.rstrip('/')
client = build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()))
logs = {Path(args.log_dir) / name: None for name in ('sql-errors.log', 'php-errors.log')}
logs = {path: path.stat().st_size if path.exists() else 0 for path in logs}


def request(path, data=None):
    response = client.open(Request(
        base + path,
        data=None if data is None else urlencode(data).encode(),
        headers={'Referer': base + '/front/central.php'},
    ), timeout=90)
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


_, html = request('/index.php')
inputs = Inputs(html).inputs
login = {field['name']: field.get('value', '') for field in inputs if field.get('type') == 'hidden'}
for field in inputs:
    if field.get('id') in ('login_name', 'login_password'):
        login[field['name']] = 'itsm'
url, _ = request('/front/login.php', login)
assert url.endswith('/front/central.php'), 'Login failed'


def action(name, values):
    url, body = request('/ajax/kanban.php', {'action': name, 'itemtype': 'Project', **values})
    assert url.endswith('/ajax/kanban.php'), (name, url)
    return body


def state():
    response = json.loads(action('load_column_state', {'items_id': -1, 'last_load': '1970-01-01'}))
    return list(response['state'].values())


label = "O'Reilly C:\\new 日本語 <span>"
safe_label = label.replace('<', '&lt;').replace('>', '&gt;')
card = "Card's C:\\path 日本語"
action('save_column_state', {
    'items_id': -1,
    'state[0][column]': label, 'state[0][cards][0]': card,
    'state[0][visible]': '1', 'state[0][folded]': '0',
    'state[1][column]': 'second', 'state[1][visible]': '1', 'state[1][folded]': '0',
    'state[1][cards][0]': 'other',
})
loaded = state()
assert loaded[0]['column'] == safe_label and loaded[0]['cards']['0'] == card, loaded
board = {'kanban[itemtype]': 'Project', 'kanban[items_id]': -1}
action('move_column', {**board, 'column': label, 'position': 1})
assert [column['column'] for column in state()] == ['second', safe_label]
action('collapse_column', {**board, 'column': label})
action('hide_column', {**board, 'column': label})
assert state()[1]['folded'] is True and state()[1]['visible'] is False
action('expand_column', {**board, 'column': label})
action('show_column', {**board, 'column': label})
action('move_item', {**board, 'column': 'second', 'card': card, 'position': 0})
loaded = state()
assert loaded[0]['cards']['0'] == card and loaded[1]['cards'] == {}, loaded
assert loaded[1]['visible'] is True and loaded[1]['folded'] is False
print('HTTP: Kanban save/load, literal values, HTML sanitizing, column ordering/flags and card moves passed.')
