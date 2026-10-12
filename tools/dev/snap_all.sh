#!/bin/bash
# Pixel snapshots of every page on the TEST server (light, dark, phone) for before/after comparisons.
#   bash tools/dev/snap_all.sh <outdir>      (needs the preview account: php tools/dev/seed_preview.php)
cd "$(dirname "$0")/../.."
OUT=$1; P=$(sed -n 's/^Mật khẩu: //p' .local/preview-login.txt)
rm -f .local/snap-jar
curl -s -c .local/snap-jar -H 'Content-Type: application/json' -d "{\"action\":\"teacher_login\",\"username\":\"xemtruoc\",\"password\":\"$P\"}" http://127.0.0.1:8099/clicker/api.php >/dev/null
C=$(awk '/clicker_teacher/{print $7}' .local/snap-jar)
# freeze animations so two runs give identical pixels
FREEZE='document.querySelector(".ai-hello")?.remove(); document.querySelectorAll(".fx").forEach(e=>{e.classList.add("in");e.style.transition="none"}); const st=document.createElement("style"); st.textContent="*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}"; document.head.append(st);'
J="new Promise(r=>setTimeout(()=>{${FREEZE} r()},2500))"
D="new Promise(r=>setTimeout(()=>{document.documentElement.dataset.theme=\"dark\"; ${FREEZE} r()},2500))"
LIST=$(python3 - "$J" "$D" <<'PY'
import json,sys
j,d=sys.argv[1],sys.argv[2]
tabs=['overview','classes','prepare','room','live','analysis-section','results-section','connection','admin-section']
out=[[t,'#'+t,1440,0,j] for t in tabs]+[[t+'-dark','#'+t,1440,0,d] for t in tabs]+[[t+'-m','#'+t,390,0,j] for t in ['overview','prepare','room','live']]
print(json.dumps(out))
PY
)
node tools/dev/shot.mjs "$C" "$OUT" "$LIST" | tail -1
node tools/dev/shot.mjs - "$OUT" '[["landing","landing/",1440,0,"new Promise(r=>setTimeout(r,2000))"],["login","landing/login.html",1440,900,"new Promise(r=>setTimeout(r,1500))"],["phone","phone.html",390,844,"new Promise(r=>setTimeout(r,2000))"]]' | tail -1
rm -f .local/snap-jar
