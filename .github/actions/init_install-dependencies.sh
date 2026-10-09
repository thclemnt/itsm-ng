#!/bin/bash
set -euo pipefail

composer validate --strict
bin/console dependencies install --composer-options="--prefer-dist --no-progress"
composer check-platform-reqs
