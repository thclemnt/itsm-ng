#!/usr/bin/env python3
# SPDX-License-Identifier: GPL-2.0-or-later
"""Actual dropdown controller tokens, form encoding and current domain authorization.

Start an isolated installation's private loopback PHP server with the companion
dropdown-choices-router-web-fixture.php router. Never enable this router on an
application server. The actual fixture control forms also require current CSRF.
"""
import argparse
import http.cookiejar
import json
import re
import subprocess
from html.parser import HTMLParser
from pathlib import Path
from urllib.error import HTTPError
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
args = parser.parse_args()
root = Path(__file__).resolve().parents[2]
base = args.url.rstrip('/')
client = build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()))
checks = 0


def fixture(action):
    output = subprocess.check_output([
        'php', str(root / 'tests/database-portability/dropdown-choices-web-fixture.php'), args.config, action,
    ], cwd=root)
    return json.loads(output) if action != 'clean' else None


def fields(data, prefix=''):
    result = []
    for key, value in data.items():
        name = f'{prefix}[{key}]' if prefix else str(key)
        if isinstance(value, dict):
            result.extend(fields(value, name))
        elif isinstance(value, list):
            result.extend((f'{name}[{index}]', item) for index, item in enumerate(value))
        elif isinstance(value, bool):
            # jQuery submits booleans as these strings, never PHP boolean values.
            result.append((name, 'true' if value else 'false'))
        elif value is not None:
            result.append((name, value))
    return result


def request(path, data=None, status=200):
    global checks
    req = Request(base + path, data=None if data is None else urlencode(fields(data)).encode(),
                  headers={'Referer': base + '/front/central.php'})
    try:
        response = client.open(req, timeout=90)
    except HTTPError as error:
        response = error
    body = response.read().decode()
    assert response.status == status, f'{path}: expected HTTP {status}, received {response.status}; location={response.headers.get("Location", "none")}'
    for error in ('PostgreSQL query error', 'SQL error', 'Fatal error:', 'Uncaught ', 'CSRF token is invalid'):
        assert error not in body, (path, error)
    checks += 1
    return response.url, body


def control(action, **values):
    _, central = request('/front/central.php')
    csrf = re.search(r'property="glpi:csrf_token" content="([^"]+)"', central).group(1)
    _, body = request('/front/__dropdown_fixture.php', {'action': action, '_glpi_csrf_token': csrf, **values})
    return json.loads(body)


def rendered(kind):
    data = control('render', kind=kind)
    params = re.search(r'var params = (\{[^\n]+\});', data['html'])
    assert params, 'Actual Dropdown::show component did not produce its AJAX parameters'
    return json.loads(params.group(1)), data


def choices(values, status=200):
    _, body = request('/ajax/getDropdownValue.php', {
        'searchText': '', 'page': 1, 'page_limit': 500, **values,
    }, status)
    return json.loads(body)


def ids(data):
    result = set()
    for row in data.get('results', []):
        if 'children' in row:
            result.update(ids({'results': row['children']}))
        elif str(row.get('id', '')).isdigit():
            result.add(int(row['id']))
    return result


seed = fixture('seed')
try:
    _, html = request('/index.php')
    inputs = Inputs(html).inputs
    login = {field['name']: field.get('value', '') for field in inputs if field.get('type') == 'hidden'}
    for field in inputs:
        if field.get('id') in ('login_name', 'login_password'):
            login[field['name']] = 'itsm'
    url, central = request('/front/login.php', login)
    assert url.endswith('/front/central.php'), 'Login failed'
    _, central = request('/front/central.php?active_entity=all&is_recursive=1')
    params, rendered_data = rendered('Computer')
    initial = ids(choices(params))
    assert {seed['computerA'], seed['computerB']} <= initial, f'Authorized component omitted fixture choices: ids={initial}, scope={rendered_data["scope"]}, seed={seed}'
    choices({key: value for key, value in params.items() if key != '_idor_token'}, 403)
    choices({**params, '_idor_token': 'invalid'}, 403)
    choices({**params, 'itemtype': 'User'}, 403)
    choices({**params, 'entity_restrict': [0]}, 403)
    choices({**params, 'condition': 'forged-condition'}, 403)
    choices({**params, 'displaywith': ['password']}, 403)
    choices({**params, 'permit_select_parent': True}, 403)
    choices({**params, 'right': 'interface'}, 403)
    choices({**params, 'inactive_deleted': 1}, 403)
    choices({**params, 'with_no_right': 1}, 403)
    assert seed['computerA'] not in ids(choices({**params, 'used': [seed['computerA']]})), 'Mutable exclusion was ignored'
    assert seed['computerB'] in ids(choices({**params, 'searchText': str(seed['computerB'])})), 'Typed numeric identifier search failed'
    # The token remains valid, but its old entity grant is intersected with current scope.
    control('restrict')
    scoped = ids(choices(params))
    assert seed['computerA'] in scoped and seed['computerB'] not in scoped, f'Old token failed current entity scope: ids={scoped}, seed={seed}'
    assert seed['computerB'] not in ids(choices({**params, '_one_id': seed['computerB']})), 'Default-value lookup bypassed current scope'
    control('restore')

    location, _ = rendered('Location')
    assert seed['location'] in ids(choices(location)), 'False parent option was not accepted through HTTP form encoding'
    assert seed['excludedLocation'] not in ids(choices({**location, '_one_id': seed['excludedLocation']})), 'Mutable selected ID bypassed the stored tree whitelist'
    # Integer grants become strings in form bodies; issuing integer and posting string must agree.
    choices({**rendered_data['user'], 'right': str(rendered_data['user']['right'])})
    project, _ = rendered('Project')
    assert seed['project'] in ids(choices(project)), 'Authorized project domain choice missing'
    control('revoke')
    assert seed['project'] not in ids(choices(project)), 'Old token bypassed revoked project permission'
    control('restore')
    assert seed['project'] in ids(choices(project)), 'Restoring permission did not restore project choices'
    print(f'HTTP dropdown contract passed: {checks} requests; actual component tokens, forged contexts, typed search, current scopes and revoked domain permission.')
finally:
    try:
        control('restore')
    finally:
        fixture('clean')
