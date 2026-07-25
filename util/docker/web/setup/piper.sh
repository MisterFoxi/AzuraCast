#!/bin/bash
set -e
set -x

# Runtime deps for Piper TTS (OpenMP for ONNX Runtime)
apt-get install -y --no-install-recommends libgomp1 python3 python3-pip

# Install huggingface-hub for reliable downloads in CI/CD environments
pip3 install --break-system-packages huggingface-hub

# Per-architecture Piper install
ARCHITECTURE=x86_64
if [[ "$(uname -m)" = "aarch64" ]]; then
    ARCHITECTURE=aarch64
fi

PIPER_VERSION="2023.11.14-2"

wget -O /tmp/piper.tar.gz \
  "https://github.com/rhasspy/piper/releases/download/${PIPER_VERSION}/piper_linux_${ARCHITECTURE}.tar.gz"

mkdir -p /usr/local/share/piper
tar -xzf /tmp/piper.tar.gz -C /usr/local/share/piper/ --strip-components=1
ln -sf /usr/local/share/piper/piper /usr/local/bin/piper
chmod a+x /usr/local/share/piper/piper

# US + British English Piper voices only (keeps the image size reasonable).
# Used by AI Newscaster and as Piper fallback voices for AI DJ.
# Inference assets only (.onnx + sidecars + catalog) — skip WAV samples.
export HF_HOME=/tmp/hf_cache

hf download rhasspy/piper-voices \
  --include "en/en_US/**/*.onnx" \
  --include "en/en_US/**/*.onnx.json" \
  --include "en/en_GB/**/*.onnx" \
  --include "en/en_GB/**/*.onnx.json" \
  --include "voices.json" \
  --local-dir /usr/local/share/piper-voices

rm -f /tmp/piper.tar.gz
rm -rf /tmp/hf_cache
