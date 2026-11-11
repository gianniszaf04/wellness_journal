#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/../.."

DATA=experiments/distortion_negatives/output/train.csv
APP=runs/2026-11-02_131743
RUNS=experiments/distortion_negatives/output/runs.txt

trap 'cp "$APP/mtl_model.pt" "$APP/mtl_config.json" . && echo "[*] Η εφαρμογή γύρισε στο s43 ($APP)"' EXIT

.venv/bin/python experiments/distortion_negatives/make_data.py
: > "$RUNS"
for SEED in 42 43; do
  caffeinate -i .venv/bin/python training/train_mtl.py --data "$DATA" --seed "$SEED" --min-examples 200 \
    --note "Β42: αρνητικά GoEmotions/SentImp του train ως Όχι διαστρέβλωσης, seed $SEED"
  RUN=$(ls -td runs/*/ | head -1)
  RUN=${RUN%/}
  cp mtl_model.pt mtl_config.json "$RUN"/
  echo "n$SEED $RUN" | tee -a "$RUNS"
done

echo "[+] Για τη μέτρηση:"
echo ".venv/bin/python experiments/distortion_negatives/eval_negatives.py --n42 $(awk '$1=="n42"{print $2}' "$RUNS") --n43 $(awk '$1=="n43"{print $2}' "$RUNS")"
