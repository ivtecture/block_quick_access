#!/bin/bash
#
# Repair tool for the Moodle dev stack: resets ownership of the Moodle code
# and moodledata to www-data. Use it when Moodle CLI scripts were run without
# `-u www-data` (e.g. `docker compose exec web php admin/cli/install.php ...`)
# and the web server started getting "Permission denied".
#
# Run from the project root on the host (the script is mounted read-only):
#   docker compose exec -u root web bash /scripts/fix-permissions.sh
set -euo pipefail

MOODLE_CODE_DIR="/var/www/html"
MOODLE_DATA_DIR="/var/www/moodledata"
PLUGIN_BIND_DIR="${MOODLE_CODE_DIR}/blocks/quick_access"
PLUGIN_BIND_DIR2="${MOODLE_CODE_DIR}/blocks/task_analytics"

echo "==> [fix-permissions] chown -R www-data:www-data ${MOODLE_DATA_DIR}"
chown -R www-data:www-data "${MOODLE_DATA_DIR}"

# /var/www/html contains bind mounts of the plugins from the host; ownership
# there is managed by Docker Desktop, so chown on them is pointless and slow -
# skip those subtrees and fix only the rest of the code dir.
echo "==> [fix-permissions] chown www-data:www-data ${MOODLE_CODE_DIR} (skipping ${PLUGIN_BIND_DIR}, ${PLUGIN_BIND_DIR2})"
find "${MOODLE_CODE_DIR}" \( -path "${PLUGIN_BIND_DIR}" -o -path "${PLUGIN_BIND_DIR2}" \) -prune -o -not -user www-data -exec chown www-data:www-data {} +

# config.php must be readable by the Apache (www-data) workers.
if [ -f "${MOODLE_CODE_DIR}/config.php" ]; then
    echo "==> [fix-permissions] chmod 0644 ${MOODLE_CODE_DIR}/config.php"
    chmod 0644 "${MOODLE_CODE_DIR}/config.php"
fi

echo "==> [fix-permissions] Done."
