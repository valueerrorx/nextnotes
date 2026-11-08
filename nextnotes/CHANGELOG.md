<!--
  - SPDX-FileCopyrightText: 2026 Thomas Weissel
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Changelog

All notable changes to Next Notes are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## 0.9.0 – 2026-11-08

First public beta. Earlier versions (0.1.x to 0.3.x) were internal test releases; they upgrade to 0.9.0 with `occ upgrade`.

### Added
- Native Nextcloud app based on the Poznote front end: notes, folders, tags, trash, attachments, revisions and search, all stored in the Nextcloud database and app data.
- Rich text, Markdown, task list and Excalidraw drawing notes; math (KaTeX), diagrams (mermaid) and code highlighting.
- Workspaces: private workspaces, workspaces shared with single users (read or write) and team workspaces based on Nextcloud Teams.
- Material folders in team workspaces: teachers publish read-only material for team members.
- Assignments: teachers hand out a note as an assignment with a deadline, students work on their own copy and submit it, teachers review and return it with feedback.
- Diary, task overview, mentions with notifications, reminders, dashboard, note graph and kanban view.
- Export of notes, folders and workspaces (HTML, Markdown, ZIP) and print view.
- Unified search integration, Nextcloud notifications and light/dark mode following the Nextcloud theme.
- Admin settings (allowed embed hosts, limits) in the Nextcloud administration.
- Admin commands `nextnotes:export-user`, `nextnotes:delete-user-data`, `nextnotes:purge`, `nextnotes:team:orphan-check` and `nextnotes:assignment:transfer`; account data is part of the Nextcloud user migration export.

### Changed
- Login, sessions and user accounts are handled by Nextcloud; every request is checked against the owner, share or team rights on the server.
- Fonts follow the Nextcloud font settings, including the dyslexia font.

### Removed
- Poznote features that do not fit a Nextcloud app: own login and two-factor setup, multiple accounts, public links, backup/restore, Git sync, webhooks, AI features, PWA/offline mode, custom CSS, speed dial.

### Security
- Fixed several upstream issues found during the port (stored and DOM-based XSS paths, missing rights checks), listed in the project specification.
- KaTeX updated to 0.16.47 (CVE-2024-28243, CVE-2024-28244).
