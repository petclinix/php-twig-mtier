#!/usr/bin/env bash
#
# One-time setup (cannot be automated):
#   1. SONAR_STARTUP=1 ./sonar-analyze.sh   (starts SonarQube, then fails
#      at the scan step because .env has no token yet — that's expected)
#   2. Open http://localhost:9000, log in admin/admin, set a new password
#      when prompted.
#   3. My Account -> Security -> Generate Token -> paste into .env as
#      SONAR_TOKEN.
#   4. Re-run ./sonar-analyze.sh.
#
set -uo pipefail
# Deliberately NOT `set -e` — each step's failure is recorded, not fatal;
# exit non-zero only at the end if anything failed.

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$repo_root"

failed=()

step() { printf '\n\033[36m===> %s\033[0m\n' "$1"; }
ok()   { printf '\033[32mOK: %s\033[0m\n' "$1"; }
fail() { printf '\033[31mFAILED: %s\033[0m\n' "$1"; failed+=("$1"); }
assert_success() { if [[ "$2" -eq 0 ]]; then ok "$1"; else fail "$1 (exit code $2)"; fi; }

# --- Load .env ---
if [[ -f "$repo_root/.env" ]]; then
    step "Loading .env file..."
    set -a
    # shellcheck disable=SC1091
    source "$repo_root/.env"
    set +a
else
    echo "ERROR: .env not found. Copy .env.example to .env and set SONAR_TOKEN." >&2
    exit 1
fi

sonar_token="${SONAR_TOKEN:-}"
sonar_host_url="${SONAR_HOST_URL:-http://host.containers.internal:9000}"
sonar_startup="${SONAR_STARTUP:-}"

if [[ -z "$sonar_token" ]]; then
    echo "ERROR: SONAR_TOKEN is not set in .env" >&2
    exit 1
fi

analyze_compose=(docker compose -f docker-compose.analyze.yml)
main_compose=(docker compose -f docker-compose.yml)

# --- 1. Start SonarQube (if SONAR_STARTUP=1) ---
if [[ "$sonar_startup" == "1" ]]; then
    step "Starting SonarQube..."
    if "${analyze_compose[@]}" ps --status running --services 2>/dev/null | grep -qx sonarqube; then
        echo "SonarQube already running, skipping start."
    else
        "${analyze_compose[@]}" up -d sonarqube
        assert_success "SonarQube start" "$?"

        echo "Waiting for SonarQube to become ready..."
        max_wait=120; waited=0; interval=5; ready=false
        while (( waited < max_wait )); do
            sleep "$interval"; waited=$(( waited + interval ))
            status="$(curl -fsS --max-time 5 http://localhost:9000/api/system/status 2>/dev/null \
                | grep -o '"status":"[A-Z_]*"' | cut -d'"' -f4 || true)"
            [[ "$status" == "UP" ]] && { ready=true; break; }
            echo "  Status: ${status:-not reachable} (${waited}s elapsed)"
        done
        if $ready; then
            echo "SonarQube is UP!"
        else
            echo "Not ready within ${max_wait}s - continuing anyway."
        fi
    fi
fi

# --- 2. PHPUnit tests with coverage (needs db+php from the MAIN compose file) ---
step "Running PHPUnit tests with coverage..."
"${main_compose[@]}" up -d --wait db php
"${main_compose[@]}" exec -T php vendor/bin/phpunit \
    --coverage-clover coverage/clover.xml \
    --coverage-html coverage/html \
    --coverage-text
assert_success "PHPUnit tests" "$?"

# --- 3. SonarQube analysis ---
step "Running Sonar analysis..."
SONAR_HOST_URL="http://sonarqube:9000" SONAR_TOKEN="$sonar_token" \
    "${analyze_compose[@]}" run --rm sonar-scanner
assert_success "Sonar analysis" "$?"

# --- Summary ---
echo
echo "============================================================"
if [[ ${#failed[@]} -eq 0 ]]; then
    echo -e "\033[32mAll steps completed successfully!\033[0m"
    echo "Coverage HTML report: $repo_root/coverage/html/index.html"
    echo "SonarQube dashboard:  $sonar_host_url/dashboard?id=petclinix_php-twig-mtier"
else
    echo -e "\033[31mCompleted with failures:\033[0m"
    for name in "${failed[@]}"; do echo "  - $name"; done
    exit 1
fi
