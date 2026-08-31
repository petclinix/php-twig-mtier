#!/usr/bin/env bash
#
# Runs Composer inside the project's php container — no local PHP/Composer
# install needed. Composer itself never touches the database, so this skips
# starting the db service (--no-deps): faster, and avoids fighting over the
# host's 3306 port with any other project's db container.
#
# Usage:
#   ./composer.sh run-script cs-check   # php-cs-fixer, dry-run
#   ./composer.sh run-script cs-fix     # php-cs-fixer, applies fixes
#   ./composer.sh run-script phpstan
#   ./composer.sh run-script deptrac
#   ./composer.sh run-script lint       # all three, non-mutating
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$repo_root"

exec docker compose run --rm --no-deps php composer "$@"
