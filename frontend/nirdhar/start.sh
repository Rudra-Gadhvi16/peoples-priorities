#!/usr/bin/env bash
# One command to run the whole thing — backend and frontend are merged
# in the root directory.
set -e
cd "$(dirname "$0")"
pip install -r requirements.txt --quiet
echo "Starting Nirdhar at http://localhost:5000 ..."
python3 app.py
