// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// Testhilfen per docker exec im Nextcloud-Container der Instanz (über den Port der base-url).
// Alle Umgebungen teilen ein Compose-Projekt → Container nicht über das Projekt, sondern über den veröffentlichten Port
// bestimmen: Apache-Umgebungen = Web-Container selbst; nginx = PHP-FPM-Dienst, von dem der Web-Container abhängt.
import { execFileSync } from 'node:child_process';

const dk = (...a) => execFileSync('docker', a, { encoding: 'utf8' }).trim();
const hasOcc = (c) => { try { dk('exec', c, 'php', '-r', "exit(is_file('/var/www/html/occ') ? 0 : 1);"); return true; } catch { return false; } };

export function ncContainer(base) {
	const port = new URL(base).port;
	const web = dk('ps', '--filter', `publish=${port}`, '--format', '{{.Names}}').split('\n').filter(Boolean);
	if (web.length !== 1) throw new Error(`ncContainer: ${web.length} Container auf Port ${port}`);
	if (hasOcc(web[0])) return web[0];
	const label = (k) => dk('inspect', '-f', `{{index .Config.Labels "${k}"}}`, web[0]);
	const project = label('com.docker.compose.project');
	// depends_on-Label: "nc-fpm:service_started:false,…"
	const deps = label('com.docker.compose.depends_on').split(',').map((d) => d.split(':')[0]).filter(Boolean);
	for (const svc of deps) {
		const c = dk('ps', '--filter', `label=com.docker.compose.project=${project}`, '--filter', `label=com.docker.compose.service=${svc}`, '--format', '{{.Names}}').split('\n')[0];
		if (c && hasOcc(c)) return c;
	}
	throw new Error(`ncContainer: kein Nextcloud-Container für Port ${port}`);
}

// Lesefreigabe hat keine HTTP-Route (Teams ab M5): Workspace <wsName> von <owner> lesend an <target>; gibt die ID zurück
export function shareRead(base, owner, wsName, target) {
	return share(base, owner, wsName, target, 'read');
}

// Freigabe mit Recht <perm> (read|write) über WorkspaceService::share
export function share(base, owner, wsName, target, perm) {
	const php = `require '/var/www/html/lib/base.php';
		$qb = \\OCP\\Server::get(\\OCP\\IDBConnection::class)->getQueryBuilder();
		$id = (int)$qb->select('id')->from('nextnotes_workspaces')->where($qb->expr()->eq('owner_type', $qb->createNamedParameter('user')))
			->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($argv[1])))->andWhere($qb->expr()->eq('name', $qb->createNamedParameter($argv[2])))
			->executeQuery()->fetchOne();
		\\OCP\\Server::get(\\OCA\\NextNotes\\Service\\WorkspaceService::class)->share($argv[1], $id, $argv[3], $argv[4]);
		echo $id;`;
	return dk('exec', '-u', 'www-data', ncContainer(base), 'php', '-r', php, '--', owner, wsName, target, perm);
}
