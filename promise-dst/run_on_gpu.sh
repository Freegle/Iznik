#!/usr/bin/env bash
# Turnkey DST evaluation battery for a temporary GPU VM (e.g. Katapult T4/L4).
# Assumes Ubuntu + NVIDIA driver. Clone feat/promise-detection, copy the
# (PII-normalised) full.csv across, then run this from promise-dst/.
#
#   CSV=/path/to/full.csv ./run_on_gpu.sh
#
# Everything below is scored on the SAME conversation split + expected-cost
# metric (FP 20x worse than FN) so it's directly comparable to the linear
# baseline and the CPU probes.
set -euo pipefail
CSV="${CSV:-../iznik-batch/storage/promise/full.csv}"

echo "== environment =="
nvidia-smi --query-gpu=name,memory.total --format=csv,noheader || echo "WARNING: no GPU detected"
pip install -q --upgrade transformers 'torch' scikit-learn || true
python3 -c "import torch; print('cuda:', torch.cuda.is_available(), torch.cuda.get_device_name(0) if torch.cuda.is_available() else '')"

echo "== A. zero-shot Flan-T5 (no training) =="
python3 zeroshot_probe.py --csv "$CSV" --model google/flan-t5-large --sample 2000 || true

echo "== B. zero-shot instruct LLM via Ollama (no training) =="
if command -v ollama >/dev/null; then
  ollama pull qwen2.5:7b 2>/dev/null || true
  python3 ollama_probe.py --csv "$CSV" --model qwen2.5:7b --sample 2000 || \
  python3 ollama_probe.py --csv "$CSV" --model qwen2.5:3b --sample 2000 || true
else
  echo "ollama not installed; skipping instruct-LLM probe"
fi

echo "== C. fine-tune DistilBERT encoder (the real DST A/B) =="
python3 finetune_encoder.py --csv "$CSV" --model distilbert-base-uncased --epochs 3 --batch 32

echo
echo "Compare across A/B/C: ROC-AUC, PR-AUC, the high-precision regime, and"
echo "whether expected cost beats never-fire (needs precision >= ~95% under 20:1)."
