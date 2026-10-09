#!/bin/bash
set -euo pipefail

echo "Init app container home"
mkdir -p "$APP_CONTAINER_HOME"

echo "Build and start containers"
docker compose up --build --detach

if [[ "${UPDATE_FILES_ACL:-false}" = true ]]; then
  echo "Change files rights to give write access to app container user"
  sudo apt-get install --assume-yes --no-install-recommends --quiet acl
  setfacl --recursive --modify u:1000:rwx "$APPLICATION_ROOT"
  setfacl --recursive --modify u:1000:rwx "$APP_CONTAINER_HOME"
fi

echo "Check services health"
docker compose up --detach --no-build --no-recreate --wait --wait-timeout 100

# Always wait for 5 seconds, even when all services are considered as healthy,
# as they may respond even if their startup script is still running (should not take more than 5 seconds).
# This problem was encountered on mariadb:10.1 service.
sleep 5
