<!--
  - SPDX-FileCopyrightText: 2026 Thomas Weissel
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Next Notes

Next Notes is a native Nextcloud app for notes, folders, tags and workspaces – private, shared with people, or owned by a team. Teachers hand out assignments to the students of a team and collect them again.
Based on [Poznote](https://github.com/timothepoznanski/poznote) by Timothé Poznanski (MIT).

## Features
- Rich text, Markdown, task lists, Kanban boards and Excalidraw drawings; tags, attachments, revisions, trash, backlinks and a graph view.
- Workspaces: private, shared with other users (read only or edit), or team workspaces of a Nextcloud team.
- Material folders in team workspaces: only teachers (moderator or higher in the team) edit them, members read.
- Assignments in team workspaces: hand out a note, every student works on a copy, hands it in before the deadline, gets feedback and points back. Submissions are kept for the teachers when a student leaves the team or the account is deleted.
- To-dos across all notes, diary with calendar, reminders and @-mentions as Nextcloud notifications, Nextcloud unified search.
- Import from Microsoft OneNote: users import their notebooks (including images and files) into their own workspaces through Microsoft Graph; requires an app registration in the school's Microsoft Entra tenant ([setup](../docs/app/admin.md#13-onenote-import)).
- Export as files or ZIP (compatible with Poznote); light and dark mode follow the Nextcloud theme.

## Requirements
- Nextcloud 35 only, PHP 8.3–8.5.
- Background jobs via system cron (recommended; required for the OneNote import).
- Optional: Teams app (Circles) for team workspaces, material and assignments; Contacts app to create and manage teams (link “Manage teams”).

## Installation
- From the Nextcloud App Store: *Apps → Office & text → Next Notes → Download and enable*.
- From a release package: extract `nextnotes-<version>.tar.gz` into a writable apps directory (e.g. `custom_apps/`), then `occ app:enable nextnotes`.
- Updating, server configuration and admin commands: see the documentation below.

## Documentation (German)
- [Administration](../docs/app/admin.md): installation, groups, web server, cron, settings, `occ` commands, data protection, removal, OneNote import setup.
- [Update](../docs/app/upgrade.md): updating from test versions, backups, no downgrade.
- [User guide](../docs/app/user.md): workspaces, teams, material, assignments, to-dos, diary, OneNote import.

## License
- Next Notes: AGPL-3.0-or-later ([COPYING](COPYING)).
- Parts taken from Poznote: MIT ([LICENCE](LICENCE)).
- Bundled third-party libraries: [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).
