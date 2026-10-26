#!/usr/bin/env bash
# The plugin version lives in composer.json ("version"), which Shopware reads. The release tag must match it.
#   bin/version.sh show        print the version
#   bin/version.sh check 1.2.3 fail unless composer.json and CHANGELOG.md say 1.2.3
#   bin/version.sh set 1.2.3   write 1.2.3 into composer.json
set -euo pipefail
cd "$(dirname "$0")/.."

current() {
  python3 -c 'import json; print(json.load(open("composer.json"))["version"])'
}

case "${1:-show}" in
  show)
    current
    ;;
  check)
    want="${2:?usage: bin/version.sh check 1.2.3}"
    have="$(current)"
    if [ "$have" != "$want" ]; then
      echo "composer.json says $have, the release is $want. Run: bin/version.sh set $want" >&2
      exit 1
    fi
    for file in CHANGELOG.md CHANGELOG_de-DE.md; do
      if ! grep -q -E "^# $want( |$)" "$file"; then
        echo "$file has no entry for $want" >&2
        exit 1
      fi
    done
    echo "Version $want is consistent."
    ;;
  set)
    new="${2:?usage: bin/version.sh set 1.2.3}"
    NEW="$new" python3 - <<'PY'
import json, os
c = json.load(open("composer.json"))
c["version"] = os.environ["NEW"]
json.dump(c, open("composer.json", "w"), indent=4, ensure_ascii=False)
open("composer.json", "a").write("\n")
PY
    echo "composer.json is now $new. Add entries to CHANGELOG.md and CHANGELOG_de-DE.md, then commit and tag v$new."
    ;;
  *)
    echo "usage: bin/version.sh show | check X.Y.Z | set X.Y.Z" >&2
    exit 2
    ;;
esac
