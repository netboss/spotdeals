#!/usr/bin/env bash
set -euo pipefail

SOURCE_DIR="/var/www/html/spotdeals/tests/backstop/backstop_data"
TARGET_DIR="$HOME/spotdeals-backstop-report"

rm -rf "$TARGET_DIR"
mkdir -p "$TARGET_DIR"

cp -a "$SOURCE_DIR/." "$TARGET_DIR/"

firefox "$TARGET_DIR/html_report/index.html" >/dev/null 2>&1 &
