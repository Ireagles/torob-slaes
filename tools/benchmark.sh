#!/usr/bin/env bash
set -euo pipefail
cd /workspace/torob-slaes
VSP_WP=/workspace/vsp-runtime/bin/wp
# Setup creates 2000 LOCAL Woo products. Replay reuses them; no production data.
if ! "$VSP_WP" option get vsp_benchmark_metrics >/dev/null 2>&1; then
  "$VSP_WP" eval-file tests/Integration/benchmark-setup.php
else
  "$VSP_WP" eval-file tests/Integration/benchmark-replay.php
fi
"$VSP_WP" --require=/workspace/torob-slaes/tests/Integration/benchmark-worker.php action-scheduler run --hooks=vsp_benchmark_poll --batch-size=50 --batches=160 --free-memory-on=50
"$VSP_WP" eval-file tests/Integration/benchmark-verify.php
