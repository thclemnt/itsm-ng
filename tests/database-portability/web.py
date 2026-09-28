#!/usr/bin/env python3
# SPDX-License-Identifier: GPL-2.0-or-later
"""HTTP installer/login smoke test; use an isolated server and empty test DB."""

import argparse
import http.cookiejar
import os
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
        if tag == "input":
            self.inputs.append(dict(attrs))


parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("url", help="Isolated application URL")
parser.add_argument("--host", required=True)
parser.add_argument("--database", required=True)
parser.add_argument("--user", required=True)
parser.add_argument("--log-dir", default=str(Path(__file__).resolve().parents[2] / "files/_log"))
args = parser.parse_args()
if not args.database.startswith("itsm_port_"):
    parser.error("Use a disposable database named itsm_port_*.")

base = args.url.rstrip("/")
client = build_opener(HTTPCookieProcessor(http.cookiejar.CookieJar()))
sql_log = Path(args.log_dir) / "sql-errors.log"
sql_log_offset = sql_log.stat().st_size if sql_log.exists() else 0


def request(path, data=None):
    response = client.open(Request(
        base + path,
        data=None if data is None else urlencode(data).encode(),
        headers={"Referer": base + "/install/install.php?step=6"},
    ), timeout=90)
    html = response.read().decode()
    for error in ("PostgreSQL query error", "\nERROR:", "Fatal error:", "Uncaught "):
        if error in html:
            raise AssertionError(f"{path} returned {error}")
    if sql_log.exists():
        with sql_log.open("rb") as log:
            log.seek(sql_log_offset)
            assert b"glpisqllog.ERROR" not in log.read(), f"{path} logged an SQL error"
    return response.url, html


request("/install/install.php?step=1", {"language": "en_GB"})
request("/install/install.php?step=3", {"install": "1"})
_, html = request("/install/install.php?step=4")
assert 'value="pgsql"' in html, "PostgreSQL provider option is missing"
_, html = request("/install/install.php?step=5", {
    "db_type": "pgsql", "db_host": args.host, "db_name": args.database,
    "db_user": args.user, "db_pass": os.environ.get("PORT_TEST_DB_PASSWORD", ""),
})
assert "Database connection successful" in html, "PostgreSQL connection failed"
_, html = request("/install/install.php?step=6", {})
assert 'window.location.href = "install.php?step=7"' in html, "Configuration failed"
_, html = request("/install/install.php?step=7")
assert "OK - database was initialized" in html, "Web schema initialization failed"
_, html = request("/install/install.php?step=7")
assert "requires an empty schema" in html, "Reinstallation must be refused"

_, html = request("/index.php")
inputs = Inputs(html).inputs
login = {i["name"]: i.get("value", "") for i in inputs if i.get("type") == "hidden"}
for field in inputs:
    if field.get("id") in ("login_name", "login_password"):
        login[field["name"]] = "itsm"
url, html = request("/front/login.php", login)
assert url.endswith("/front/central.php"), "Login did not reach the dashboard"
for page in ("computer.php", "ticket.php", "user.php", "group.php"):
    url, html = request("/front/" + page)
    assert url.endswith("/front/" + page), f"Unexpected redirect for {page}"
    assert "searchcriteria" in html, f"Search list missing on {page}"

print("pgsql: HTTP installation, reinstall refusal, login and core lists passed.")
