// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// Testdaten über die App-API (Basic-Auth, OCS-APIRequest wie PHPUnit-ApiClient). Läuft über page.request (eigene Cookies egal).
export function apiClient(ctx, request, user = process.env.NN_USER, pass = process.env.NN_PASS) {
	const auth = `Basic ${Buffer.from(`${user}:${pass}`).toString('base64')}`;
	const call = async (method, path, data) => {
		const resp = await request.fetch(`${ctx.base}/index.php/apps/nextnotes/api/v1${path}`, {
			method,
			headers: { Authorization: auth, 'OCS-APIRequest': 'true', Accept: 'application/json', ...(data ? { 'Content-Type': 'application/json' } : {}) },
			data: data ? JSON.stringify(data) : undefined,
			failOnStatusCode: false,
		});
		let body = null;
		try { body = await resp.json(); } catch { body = await resp.text().catch(() => null); }
		return { status: resp.status(), body };
	};
	const must = async (method, path, data) => {
		const r = await call(method, path, data);
		if (r.status >= 300) throw new Error(`API ${method} ${path} → ${r.status} ${JSON.stringify(r.body).slice(0, 300)}`);
		return r.body;
	};
	return {
		call,
		must,
		/** Workspace anlegen (409 = existiert schon) */
		async workspace(name) {
			const r = await call('POST', '/workspaces', { name });
			if (r.status >= 300 && r.status !== 409) throw new Error(`POST /workspaces ${name} → ${r.status} ${JSON.stringify(r.body)}`);
			return name;
		},
		async workspaces() {
			const b = await must('GET', '/workspaces');
			return (b.workspaces ?? b).map((w) => w.name ?? w);
		},
		/** Standard-Workspace (Name hängt von der Sprache beim ersten Zugriff ab: „Notizen“ bzw. „Notes“) */
		async defaultWorkspace() {
			const names = await this.workspaces();
			return names.find((n) => n === 'Notizen') ?? names.find((n) => n === 'Notes') ?? names[0];
		},
		async folderPath(workspace, path) {
			const b = await must('POST', '/folders', { folder_path: path, create_parents: true, workspace });
			return b.folder_id;
		},
		async folder(workspace, name) {
			const b = await must('POST', '/folders', { folder_name: name, workspace });
			return b.folder_id;
		},
		async note(workspace, heading, folderId = null, content = '<p>x</p>') {
			const b = await must('POST', '/notes', { heading, content, workspace, ...(folderId ? { folder_id: folderId } : {}) });
			return b.note.id;
		},
		async setting(key) {
			const r = await call('GET', `/settings/${encodeURIComponent(key)}`);
			return r;
		},
	};
}
