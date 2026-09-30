#!/usr/bin/env python3
# SPDX-License-Identifier: GPL-2.0-or-later
"""Run every CLI contract sequentially against one disposable installed database."""

import argparse
import os
from pathlib import Path
import subprocess
import sys


parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("config", type=Path)
parser.add_argument("--php", default="php")
parser.add_argument("--list", action="store_true", help="List contracts without running them")
args = parser.parse_args()
directory = Path(__file__).resolve().parent
# These are support files or have their own workflow invocation, not CLI contracts.
support = {"FixtureRecords.php", "sql-inventory.php", "search-benchmark.php", "seed-report-web.php"}
contracts = sorted(path for path in directory.glob("*.php")
                   if path.name not in support and not path.name.endswith("-web-fixture.php"))
# Check the untouched installation before contracts that exercise schema upgrades.
first = [directory / "run.php", directory / "initial-data.php"]
contracts = first + [path for path in contracts if path not in first]
if args.list:
    print("\n".join(path.name for path in contracts))
    sys.exit(0)
config = args.config.resolve()
if not (config / "config_db.php").is_file():
    parser.error("config must contain config_db.php for a dedicated itsm_port_* database")
failed = []
for path in contracts:
    print(f"::group::{path.name}" if os.getenv("GITHUB_ACTIONS") else f"\n=== {path.name} ===", flush=True)
    try:
        result = subprocess.run([args.php, str(path), str(config)], timeout=300)
        if result.returncode:
            failed.append(path.name)
    except subprocess.TimeoutExpired:
        failed.append(path.name)
        print("Contract exceeded 300 seconds", flush=True)
    finally:
        if os.getenv("GITHUB_ACTIONS"):
            print("::endgroup::", flush=True)
print(f"{len(contracts) - len(failed)}/{len(contracts)} contracts passed", flush=True)
if failed:
    print("Failed: " + ", ".join(failed), file=sys.stderr)
sys.exit(bool(failed))
