#!/bin/bash
#
# One-shot initialiser for the Moodle dev stack.
set -euo pipefail

# One-shot initialiser for the Moodle dev stack.
# - Clones Moodle 4.5 (MOODLE_405_STABLE) into /var/www/html if not present yet.
# - Prepares moodledata with permissions suitable for LOCAL DEVELOPMENT only.
#
# Runs as root (see user: root in docker-compose.yml).

MOODLE_CODE_DIR="/var/www/html"
MOODLE_DATA_DIR="/var/www/moodledata"
MOODLE_BRANCH="MOODLE_405_STABLE"
MOODLE_GIT_URL="https://github.com/moodle/moodle.git"

echo "==> [moodle-init] started"

if [ ! -f "${MOODLE_CODE_DIR}/version.php" ]; then
    echo "==> [moodle-init] Core Moodle code not found, cloning branch ${MOODLE_BRANCH} (shallow clone)..."
    # Clean the target directory completely, including hidden files
    # (leftovers copied from the base image into the fresh volume).
    find "${MOODLE_CODE_DIR}" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
    git clone --depth 1 --branch "${MOODLE_BRANCH}" "${MOODLE_GIT_URL}" "${MOODLE_CODE_DIR}"
    echo "==> [moodle-init] Moodle core cloned successfully."
else
    echo "==> [moodle-init] Core Moodle code already present, skipping clone."
fi

echo "==> [moodle-init] Preparing moodledata directory..."
mkdir -p "${MOODLE_DATA_DIR}"

echo "==> [moodle-init] Fixing ownership (www-data:www-data)..."
chown -R www-data:www-data "${MOODLE_CODE_DIR}" "${MOODLE_DATA_DIR}"

# Dev-only loose permissions for moodledata. NOT suitable for production!
echo "==> [moodle-init] Setting permissive permissions on moodledata (dev only)..."
chmod -R 0777 "${MOODLE_DATA_DIR}"

echo "==> [moodle-init] Done. Moodle code: ${MOODLE_CODE_DIR}, data dir: ${MOODLE_DATA_DIR}"
