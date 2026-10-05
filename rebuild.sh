#!/bin/bash
# Weekly: rebuild on the latest Red Hat base image and errata, then recreate the container.
# All state is in ./vols and ./custom.env, so nothing is lost.
set -euo pipefail
cd "$(dirname "$0")"
podman build --pull=always --no-cache -t localhost/eso-letswifi:latest -f Dockerfile .
podman-compose down
# Start the container in its own systemd scope. Run from a systemd service, podman leaves its
# container monitor (conmon) in the service's cgroup, and systemd kills whatever is left there
# when this script exits, taking the container's monitor with it. A scope keeps it separate.
systemd-run --scope --quiet --collect podman-compose up -d
podman image prune -f
