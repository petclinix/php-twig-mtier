#!/usr/bin/env bash
#
# Renders every docs/*.puml PlantUML source to an .svg alongside it, using the
# official plantuml/plantuml Docker image — no local Java/PlantUML install needed.
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# --userns=keep-id: rootless Docker/Podman remaps the container's root user to
# an unrelated host UID by default, which cannot write into this bind-mounted,
# host-owned docs/ directory. keep-id maps the container user to the *current*
# host user instead, so rendered .svg files land with normal ownership.
docker run --rm --userns=keep-id -v "${repo_root}/docs:/data" docker.io/plantuml/plantuml -tsvg /data

echo "Rendered SVGs:"
ls -1 "${repo_root}"/docs/*.svg
