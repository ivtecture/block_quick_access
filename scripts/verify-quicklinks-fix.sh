#!/bin/bash
#
# E2E verification for the block_quicklinks config_ prefix fix.
# Emulates the browser flow: login -> enable editing -> open the block
# configuration form (bui_editid=9) -> submit links -> verify they are
# rendered on the course page.
#
# Run inside the web container:
#   docker compose exec web bash /scripts/verify-quicklinks-fix.sh
set -u

BASE="http://localhost:8080"
# Inside the container Moodle listens on port 80 but $CFG->wwwroot is
# http://localhost:8080 - remap the TCP connection, keep the original Host.
CURL="curl -s --connect-to localhost:8080:localhost:80"
COOKIES=/tmp/fix_cookies.txt

# HTML entity for ampersand, built at runtime (writing the literal entity
# into this file is fragile: some editors/tools decode it to a bare '&').
AMPENT="&""amp"";"

# Decode HTML ampersand entities in a string.
# NOTE: bash 5.2 patsub_replacement makes ${var//pat/&} expand '&' to the
# matched text (no-op here), so sed with an escaped replacement is used.
dec() { printf '%s' "$1" | sed "s/$AMPENT/\\&/g"; }

BLOCKID=9
COURSEID=2

rm -f "$COOKIES" /tmp/fix_*.html /tmp/fix_*.flat

echo "==> 1. Fetch login page (get logintoken)"
$CURL -c "$COOKIES" -o /tmp/fix_login.html "$BASE/login/index.php"
TOKEN=$(grep -oP 'name="logintoken" value="\K[^"]+' /tmp/fix_login.html | head -1)
echo "    logintoken: ${TOKEN:0:8}..."

echo "==> 2. Log in as admin"
CODE=$($CURL -b "$COOKIES" -c "$COOKIES" -o /dev/null -w "%{http_code}" \
    --data-urlencode "username=admin" \
    --data-urlencode "password=admin123" \
    --data-urlencode "logintoken=$TOKEN" \
    "$BASE/login/index.php")
echo "    login HTTP: $CODE"

echo "==> 3. Open course page, grab sesskey"
$CURL -b "$COOKIES" -o /tmp/fix_course.html "$BASE/course/view.php?id=$COURSEID"
SESSKEY=$(grep -oP '"sesskey":"\K[^"]+' /tmp/fix_course.html | head -1)
echo "    sesskey: ${SESSKEY:0:8}..."

echo "==> 4. Switch editing on"
$CURL -b "$COOKIES" -o /dev/null -w "    HTTP %{http_code} -> %{redirect_url}\n" \
    "$BASE/course/view.php?id=$COURSEID&edit=1&sesskey=$SESSKEY"

echo "==> 5. Open the block config form (bui_editid=$BLOCKID)"
$CURL -b "$COOKIES" -o /tmp/fix_form.html \
    "$BASE/course/view.php?id=$COURSEID&bui_editid=$BLOCKID"
echo "    form HTML size: $(wc -c < /tmp/fix_form.html)"
echo "    config_linktitle fields: $(grep -c 'name="config_linktitle' /tmp/fix_form.html || true)"
echo "    config_linkurl fields:   $(grep -c 'name="config_linkurl' /tmp/fix_form.html || true)"

# Flatten the HTML: Moodle renders <select> tags across multiple lines.
tr '\n' ' ' < /tmp/fix_form.html > /tmp/fix_form.flat

# The block config form is the one with class="mform" (NOT the editmode
# switch form which happens to be the first <form> on the page).
ACTION=$(grep -o '<form[^>]*class="mform"' /tmp/fix_form.flat | head -1 \
    | grep -oP 'action="\K[^"]+')
ACTION=$(dec "$ACTION")
echo "    mform action: $ACTION"
if [ -z "$ACTION" ]; then
    echo "ERROR: could not find the block config form action URL"; exit 1
fi
case "$ACTION" in
    *"$AMPENT"*)
        echo "ERROR: entity decoding failed for the form action URL"; exit 1
        ;;
esac
if [[ "$ACTION" == http* ]]; then
    FORMURL="$ACTION"
else
    FORMURL="$BASE$ACTION"
fi

echo "==> 6. Collect hidden inputs and selected select values"
POSTARGS=()
while IFS= read -r line; do
    NAME=$(echo "$line" | grep -oP 'name="\K[^"]+' | head -1)
    VALUE=$(echo "$line" | grep -oP 'value="\K[^"]*' | head -1)
    if [ -n "$NAME" ]; then
        VALUE=$(dec "$VALUE")
        POSTARGS+=(--data-urlencode "$NAME=$VALUE")
        echo "    hidden: $NAME=${VALUE:0:40}"
    fi
done < <(grep -oP '<input[^>]*type="hidden"[^>]*>' /tmp/fix_form.flat)

for SELNAME in bui_pagetypepattern bui_subpagepattern bui_contexts \
               bui_defaultregion bui_defaultweight bui_visible bui_region bui_weight; do
    SELBLOCK=$(grep -oP "<select[^>]*name=\"$SELNAME\"[^>]*>.*?</select>" /tmp/fix_form.flat | head -1)
    [ -z "$SELBLOCK" ] && continue
    SELVAL=$(echo "$SELBLOCK" | grep -oP '<option[^>]*value="[^"]*"[^>]*selected' | head -1 \
        | grep -oP 'value="\K[^"]*')
    if [ -n "$SELVAL" ]; then
        SELVAL=$(dec "$SELVAL")
        POSTARGS+=(--data-urlencode "$SELNAME=$SELVAL")
        echo "    select: $SELNAME=$SELVAL"
    fi
done

echo "==> 7. Submit the form with three links"
CODE=$($CURL -b "$COOKIES" -o /tmp/fix_resp.html -w "%{http_code}" \
    "${POSTARGS[@]}" \
    --data-urlencode "config_linktitle[0]=Moodle Docs" \
    --data-urlencode "config_linkurl[0]=https://docs.moodle.org" \
    --data-urlencode "config_linktitle[1]=Moodle Community" \
    --data-urlencode "config_linkurl[1]=https://moodle.org" \
    --data-urlencode "config_linktitle[2]=Example" \
    --data-urlencode "config_linkurl[2]=https://example.com" \
    --data-urlencode "links_repeats=3" \
    --data-urlencode "submitbutton=Save changes" \
    "$FORMURL")
echo "    submit HTTP: $CODE (303 = saved + redirect expected)"
echo "    response is course page: $(grep -c 'course-view' /tmp/fix_resp.html || true)"

echo "==> 8. Switch editing off and open the course page as a viewer"
$CURL -b "$COOKIES" -o /dev/null \
    "$BASE/course/view.php?id=$COURSEID&edit=0&sesskey=$SESSKEY"
$CURL -b "$COOKIES" -o /tmp/fix_view.html "$BASE/course/view.php?id=$COURSEID"
echo "    rendered list markup: $(grep -c 'quicklinks-links' /tmp/fix_view.html || true)"
echo "    link title Moodle Docs: $(grep -c 'Moodle Docs' /tmp/fix_view.html || true)"
echo "    link href docs.moodle.org: $(grep -c 'docs.moodle.org' /tmp/fix_view.html || true)"
echo "    link title Moodle Community: $(grep -c 'Moodle Community' /tmp/fix_view.html || true)"
echo "    link href example.com: $(grep -c 'example.com' /tmp/fix_view.html || true)"

echo "==> 9. Reopen the config form to verify pre-fill of saved values"
$CURL -b "$COOKIES" -o /dev/null \
    "$BASE/course/view.php?id=$COURSEID&edit=1&sesskey=$SESSKEY"
$CURL -b "$COOKIES" -o /tmp/fix_form2.html \
    "$BASE/course/view.php?id=$COURSEID&bui_editid=$BLOCKID"
echo "    prefilled title (Moodle Docs): $(grep -c 'value="Moodle Docs"' /tmp/fix_form2.html || true)"
echo "    prefilled url (moodle.org): $(grep -c 'value="https://moodle.org"' /tmp/fix_form2.html || true)"
$CURL -b "$COOKIES" -o /dev/null \
    "$BASE/course/view.php?id=$COURSEID&edit=0&sesskey=$SESSKEY"

echo "==> done"
