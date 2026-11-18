#!/usr/bin/env bash
# End-to-end migration test:
#   src: MySQL 8,  PHP 7.4, prefix src_, http://localhost:8081
#   dst: MariaDB 10.4 (max_allowed_packet 256 KB), PHP 8.3, prefix dst_, http://localhost:8082
# Every step is one HTTP request with a 0.3 s budget, forcing hundreds of resumes.
# Usage: bash test/e2e.sh [upload|croc]
set -euo pipefail
cd "$(dirname "$0")"
MODE="${1:-upload}"
SRC=http://localhost:8081
DST=http://localhost:8082
export TMPD="$(pwd)/.tmp"; mkdir -p "$TMPD"
j() { node "$(pwd)/j.js" "$1"; }

wait_http() { for i in $(seq 1 90); do curl -s -o /dev/null "$1/" && return 0; sleep 2; done; echo "timeout $1"; exit 1; }
wait_http "$SRC"; wait_http "$DST"
for s in "$SRC" "$DST"; do
  for i in $(seq 1 40); do out=$(curl -s "$s/runner.php?do=install&site=x&pass=pw${s: -1}" || true); [[ "$out" == *installed* ]] && break; sleep 3; done
  curl -s "$s/runner.php?do=activate" >/dev/null
done

if [[ "$(curl -s "$SRC/runner.php?do=fingerprint" | j 'd.posts')" != "1500" ]]; then
  echo "== seeding source"; curl -s "$SRC/runner.php?do=seed"; echo
fi

run_job() { # $1 base url, $2 first response
  local base="$1" resp="$2" n=0
  while true; do
    [[ "$(echo "$resp" | j 'd.success')" == "true" ]] || { echo "FAIL: ${resp:0:2000}"; exit 1; }
    local err; err=$(echo "$resp" | j 'd.data.error')
    if [[ -n "$err" ]]; then
      if [[ "$err" == *max_allowed_packet* && -z "${SKIPPED:-}" ]]; then
        echo "  expected oversize-row error -> Skip: ${err:0:160}"; SKIPPED=1
        curl -s "$base/runner.php?sub=import_resolve&decision=skip&id=$(echo "$resp" | j "d.data.id")" >/dev/null
        resp=$(curl -s "$base/runner.php?sub=step&id=$(echo "$resp" | j "d.data.id")"); continue
      fi
      echo "JOB ERROR: $err"; exit 1
    fi
    [[ "$(echo "$resp" | j 'd.data.done')" == "true" ]] && { echo "  done after $n requests"; LAST="$resp"; return 0; }
    local id; id=$(echo "$resp" | j 'd.data.id')
    n=$((n+1))
    (( n % 40 == 0 )) && echo "  [$n] $(echo "$resp" | j 'd.data.stage+" "+d.data.percent+"% "+d.data.label')"
    resp=$(curl -s "$base/runner.php?sub=step&id=$id&budget=${BUDGET:-0.05}")
  done
}

echo "== export on source"
first=$(curl -s -G "$SRC/runner.php" --data-urlencode 'sub=export_start' --data-urlencode 'opts={"database":1,"uploads":1,"plugins":1,"themes":1,"muplugins":1,"other":1,"no_spam":1,"no_revisions":0,"no_transients":1}')
run_job "$SRC" "$first"
FILE=$(echo "$LAST" | j 'd.data.file')
echo "  package: $FILE ($(echo "$LAST" | j 'd.data.size'))"

if [[ "$MODE" == "croc" ]]; then
  echo "== croc install on both sites"
  curl -s "$SRC/runner.php?sub=croc_install"; echo
  curl -s "$DST/runner.php?sub=croc_install"; echo
  send=$(curl -s "$SRC/runner.php?sub=croc_send&name=$FILE"); echo "  $send"
  CODE=$(echo "$send" | j 'd.data.code')
  recv=$(curl -s "$DST/runner.php?sub=croc_receive&code=$CODE"); echo "  $recv"
  RID=$(echo "$recv" | j 'd.data.id')
  NAME=""
  for i in $(seq 1 200); do
    p=$(curl -s "$DST/runner.php?sub=croc_poll&id=$RID")
    st=$(echo "$p" | j 'd.data.state')
    echo "  receive: $st $(echo "$p" | j 'd.data.percent')% $(echo "$p" | j 'd.data.message')"
    [[ "$st" == "done" ]] && { NAME=$(echo "$p" | j 'd.data.file'); break; }
    [[ "$st" == "error" ]] && { docker compose exec -T dst sh -c 'cat /var/www/html/wp-content/monarch-storage-*/croc/*.log' || true; exit 1; }
    sleep 3
  done
  echo "  sender: $(curl -s "$SRC/runner.php?sub=croc_poll&id=$(echo "$send" | j 'd.data.id')")"
else
  echo "== chunked upload to destination (1 MB chunks)"
  SRC_STORE=$(curl -s "$SRC/runner.php?do=fingerprint" | j 'd.storage')
  rm -rf "$TMPD/up" && mkdir -p "$TMPD/up"
  docker compose cp "src:$SRC_STORE/backups/$FILE" "$TMPD/up/$FILE"
  TOTAL=$(wc -c < "$TMPD/up/$FILE" | tr -d ' ')
  (cd "$TMPD/up" && split -b 1048576 -d -a 4 "$FILE" part.)
  OFF=0
  for part in "$TMPD"/up/part.*; do
    r=$(curl -s -F "sub=upload_chunk" -F "name=$FILE" -F "offset=$OFF" -F "total=$TOTAL" -F "chunk=@$part" "$DST/runner.php")
    OFF=$(echo "$r" | j 'd.data.received')
  done
  echo "  $r"
  NAME=$(echo "$r" | j 'd.data.name')
fi

echo "== inspect + import on destination"
curl -s "$DST/runner.php?sub=import_inspect&name=$NAME"; echo
first=$(curl -s "$DST/runner.php?sub=import_start&name=$NAME")
run_job "$DST" "$first"

echo "== compare"; export SKIPPED="${SKIPPED:-}"
curl -s "$SRC/runner.php?do=fingerprint" > "$TMPD/src.json"
curl -s "$DST/runner.php?do=fingerprint" > "$TMPD/dst.json"
node - <<'JS'
const fs = require('fs');
const s = JSON.parse(fs.readFileSync(process.env.TMPD + '/src.json'));
const d = JSON.parse(fs.readFileSync(process.env.TMPD + '/dst.json'));
const checks = {
  'destination URL kept': d.home === 'http://localhost:8082',
  'posts (1500)': s.posts === 1500 && d.posts === 1500,
  'postmeta': s.meta === d.meta,
  'unicode/emoji title': s.title_sample === d.title_sample,
  'serialized data valid': d.ser_valid,
  'serialized URL replaced': d.ser_sample.includes('localhost:8082') && !d.ser_sample.includes('localhost:8081'),
  'JSON-escaped URL replaced': d.json_sample.includes('localhost:8082') && !d.json_sample.includes('8081'),
  '200 KB row via split INSERTs': d.big_len === s.big_len && d.big_len > 150000 && d.big_has_home,
  'oversize row skipped (not crashed)': s.huge === 700000 && d.huge === 0,
  'oversize error was raised': process.env.SKIPPED === "1",
  'serialized object of missing class': d.obj_valid && d.obj.includes('8082'),
  'table without PK + 0900 collation': d.nopk_rows === 300,
  'URL replaced in keyless table': d.nopk_sample === 'http://localhost:8082/nopk/5',
  'binary BLOB bytes': d.blob_len === s.blob_len,
  'admin password from source': d.admin_hash === s.admin_hash,
  'capabilities re-prefixed': d.caps_key >= 1,
  'user_roles re-prefixed': d.roles,
  'Monarch still active': (d.active || []).includes('monarch-site-migration/monarch-site-migration.php'),
  '5 MB file identical': !!d.big_file_md5 && d.big_file_md5 === s.big_file_md5,
  '200 small files': d.small_files === 200,
  'other wp-content dirs': d.lang,
  'no temp tables left': d.leftover_tmp.length === 0,
};
let bad = 0;
for (const [k, v] of Object.entries(checks)) { console.log((v ? 'PASS ' : 'FAIL ') + k); if (!v) bad++; }
console.log('no-PK url note: ' + d.nopk_sample);
if (bad) { console.log(JSON.stringify(d, null, 1).slice(0, 3000)); process.exit(1); }
console.log('ALL CHECKS PASSED');
JS
