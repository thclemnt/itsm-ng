#!/bin/bash
set -euo pipefail

echo "Cleanup containers and volumes"
docker compose down --volumes
