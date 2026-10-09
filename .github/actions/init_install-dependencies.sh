#!/bin/bash
set -euo pipefail

# The repository is bind-mounted into the container.
git config --global --add safe.directory "$(pwd)"

composer validate --strict
bin/console dependencies install --composer-options="--prefer-dist --no-progress"
composer check-platform-reqs
