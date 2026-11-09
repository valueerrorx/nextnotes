#!/bin/sh
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# Installiert den Secret-Schutz (pre-commit) in dieses Repo; gilt für alle Worktrees. Einmal je Klon ausführen.
set -e
here="$(cd "$(dirname "$0")" && pwd)"
hooks="$(cd "$here" && cd "$(git rev-parse --git-common-dir)" && pwd)/hooks"
mkdir -p "$hooks"
cp "$here/pre-commit" "$here/secret-patterns.txt" "$hooks/"
chmod +x "$hooks/pre-commit"
echo "pre-commit-Hook installiert: $hooks/pre-commit"
