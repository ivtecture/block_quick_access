#!/bin/bash
#
# E2E check for the "quick jump to block settings" shortcut in block_quicklinks.
# Emulates the browser flow: login -> course page -> shortcut click (edit=1,
# redirect to notifyeditingon=1) -> continue to bui_editid -> config form.
set -u

BASE="http://localhost:8080"
# Inside the container Moodle listens on port 80 but $CFG->wwwroot is
# http://localhost:8080 - remap the TCP connection, keep the original Host.
CURL="curl -s --connect-to localhost:8080:localhost:80"
COOKIES=/tmp/e2e_cookies.txt

rm -f "$COOKIES" /tmp/e2e_*.html

echo "==> 1. Fetch login page (get logintoken)"
$CURL -c "$COOKIES" -o /tmp/e2e_login.html "$BASE/login/index.php"
TOKEN=$(grep -oP 'name="logintoken" value="\K[^"]+' /tmp/e2e_login.html | head -1)
echo "    logintoken: ${TOKEN:0:8}..."

echo "==> 2. Log in as admin"
CODE=$($CURL -b "$COOKIES" -c "$COOKIES" -o /dev/null -w "%{http_code}" \
    --data-urlencode "username=admin" \
    --data-urlencode "password=admin123" \
    --data-urlencode "logintoken=$TOKEN" \
    "$BASE/login/index.php")
echo "    login HTTP: $CODE"

echo "==> 3. Open course page (id=2), look for the shortcut link"
$CURL -b "$COOKIES" -o /tmp/e2e_course.html "$BASE/course/view.php?id=2"
echo "    quicklinks-manage-link occurrences: $(grep -c 'quicklinks-manage-link' /tmp/e2e_course.html || true)"
HREF=$(grep -o 'href="[^"]*bui_editid=9[^"]*"' /tmp/e2e_course.html | head -1 | sed 's/href="//;s/"$//;s/&/\&/g')
echo "    shortcut href: $HREF"
echo "    shortcut JS included: $(grep -c 'block_quicklinks/shortcut' /tmp/e2e_course.html || true)"

echo "==> 4. Click the shortcut (GET href): expect 303 to notifyeditingon=1"
$CURL -b "$COOKIES" -o /dev/null -w "    HTTP %{http_code} -> %{redirect_url}\n" "$HREF"

echo "==> 5. Landing page after redirect (notifyeditingon=1)"
$CURL -b "$COOKIES" -o /tmp/e2e_landing.html "$BASE/course/view.php?id=2&notifyeditingon=1"
echo "    body editing class: $(grep -c 'class="editing' /tmp/e2e_landing.html || true)"

echo "==> 6. Continue to the config form (bui_editid=9, editing already on)"
$CURL -b "$COOKIES" -o /tmp/e2e_form.html "$BASE/course/view.php?id=2&bui_editid=9"
echo "    form HTML size: $(wc -c < /tmp/e2e_form.html)"
echo "    linktitle fields: $(grep -c 'name="config_linktitle' /tmp/e2e_form.html || true)"
echo "    linkurl fields:   $(grep -c 'name="config_linkurl' /tmp/e2e_form.html || true)"

echo "==> 7. Turn editing back off"
SESSKEY=$(grep -oP '"sesskey":"\K[^"]+' /tmp/e2e_form.html | head -1)
$CURL -b "$COOKIES" -o /dev/null -w "    HTTP %{http_code}\n" "$BASE/course/view.php?id=2&edit=0&sesskey=$SESSKEY"
