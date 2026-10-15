#!/bin/sh
# Builds dist/tiny-backup.zip from the last commit, ready for Plugins → Add New → Upload Plugin.
set -e
cd "$(dirname "$0")/.."
mkdir -p dist
git archive --format=zip --prefix=tiny-backup/ -o dist/tiny-backup.zip HEAD
echo "dist/tiny-backup.zip"
