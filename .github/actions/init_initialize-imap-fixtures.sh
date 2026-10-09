#!/bin/bash
set -euo pipefail

ROOT_DIR=$(readlink -f "$(dirname "$0")/../..")
COMPOSE_CMD="$ROOT_DIR/.github/actions/docker-compose.sh"

echo "Initialize email fixtures"
"$COMPOSE_CMD" exec -T --user root dovecot doveadm expunge -u testuser mailbox 'INBOX' all
"$COMPOSE_CMD" exec -T --user root dovecot doveadm purge -u testuser
for f in "$ROOT_DIR"/tests/emails-tests/*.eml; do
  "$COMPOSE_CMD" exec -T --user root dovecot doveadm save -u testuser -m INBOX < "$f"
done
