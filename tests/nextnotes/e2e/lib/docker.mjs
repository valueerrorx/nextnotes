// SPDX-FileCopyrightText: 2026 Thomas Weissel
// SPDX-License-Identifier: AGPL-3.0-or-later
// Testhilfen per docker exec im Nextcloud-Container der Instanz (über den Port der base-url).
// Alle Umgebungen teilen ein Compose-Projekt → Container nicht über das Projekt, sondern über den veröffentlichten Port
// bestimmen: Apache-Umgebungen = Web-Container selbst; nginx = PHP-FPM-Dienst, von dem der Web-Container abhängt.
import { execFileSync } from 'node:child_process';

const dk = (...a) => execFileSync('docker', a, { encoding: 'utf8' }).trim();
const hasOcc = (c) => { try { dk('exec', c, 'php', '-r', "exit(is_file('/var/www/html/occ') ? 0 : 1);"); return true; } catch { return false; } };

// Gesperrte Instanzen (Demo von Thomas, fremde Testinstanz): nie per docker exec ansprechen
const BLOCKED = ['nextnotes-demo', 'nc35-gftest'];
const blocked = (s) => BLOCKED.some((b) => String(s).includes(b));
function guard(port, name) {
	const project = name ? dk('inspect', '-f', '{{index .Config.Labels "com.docker.compose.project"}}', name) : '';
	if (port === '8090' || blocked(name) || blocked(project)) {
		throw new Error(`docker.mjs: gesperrte Instanz (Port ${port}, Container ${name || '-'}, Projekt ${project || '-'})`);
	}
	return name;
}

export function ncContainer(base) {
	const port = new URL(base).port;
	guard(port, '');
	const web = dk('ps', '--filter', `publish=${port}`, '--format', '{{.Names}}').split('\n').filter(Boolean);
	if (web.length !== 1) throw new Error(`ncContainer: ${web.length} Container auf Port ${port}`);
	guard(port, web[0]);
	if (hasOcc(web[0])) return web[0];
	const label = (k) => dk('inspect', '-f', `{{index .Config.Labels "${k}"}}`, web[0]);
	const project = label('com.docker.compose.project');
	// depends_on-Label: "nc-fpm:service_started:false,…"
	const deps = label('com.docker.compose.depends_on').split(',').map((d) => d.split(':')[0]).filter(Boolean);
	for (const svc of deps) {
		const c = dk('ps', '--filter', `label=com.docker.compose.project=${project}`, '--filter', `label=com.docker.compose.service=${svc}`, '--format', '{{.Names}}').split('\n')[0];
		if (c && hasOcc(guard(port, c))) return c;
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

// occ im Nextcloud-Container (www-data); Ausgabe als Text
export function occ(base, ...args) {
	return dk('exec', '-u', 'www-data', ncContainer(base), 'php', 'occ', ...args);
}

// PHP mit geladenem Nextcloud (lib/base.php); $argv[1…] = args
function ncPhp(base, code, ...args) {
	return dk('exec', '-u', 'www-data', ncContainer(base), 'php', '-r', `require '/var/www/html/lib/base.php';\n${code}`, '--', ...args);
}

// Konto anlegen, falls es fehlt; Anzeigename setzen (M5d)
export function ensureUser(base, uid, displayName, pass) {
	ncPhp(base, `$um = \\OCP\\Server::get(\\OCP\\IUserManager::class);
		$u = $um->get($argv[1]) ?? $um->createUser($argv[1], $argv[3]);
		$u->setDisplayName($argv[2]);`, uid, displayName, pass);
}

// Team über CirclesManager: Owner + Mitglieder (uid=level, 1 Mitglied, 4 Moderator); liefert die Team-ID (M5d)
export function createTeam(base, owner, name, members = {}) {
	return ncPhp(base, `$cm = \\OCP\\Server::get(\\OCA\\Circles\\CirclesManager::class);
		$cm->startSession($cm->getLocalFederatedUser($argv[1]));
		try {
			$circle = $cm->createCircle($argv[2], $cm->getLocalFederatedUser($argv[1]), false, false, false);
			foreach (json_decode($argv[3], true) as $uid => $level) {
				$m = $cm->addMember($circle->getSingleId(), $cm->getLocalFederatedUser((string)$uid));
				if ((int)$level !== 1) { $cm->levelMember($m->getId(), (int)$level); }
			}
			echo $circle->getSingleId();
		} finally { $cm->stopSession(); }`, owner, name, JSON.stringify(members));
}

// Team zerstören (Team-Workspaces bleiben) und Zustand verwaist setzen, wie nach dem Lösch-Event (M5d)
export function orphanTeam(base, owner, teamId) {
	ncPhp(base, `$cm = \\OCP\\Server::get(\\OCA\\Circles\\CirclesManager::class);
		$cm->startSession($cm->getLocalFederatedUser($argv[1]));
		try { $cm->destroyCircle($argv[2]); } finally { $cm->stopSession(); }
		\\OCP\\Server::get(\\OCA\\NextNotes\\Service\\TeamStateService::class)->markOrphaned($argv[2], null);`, owner, teamId);
}

// Mitglied <uid> aus Team entfernen (als Owner; M5e smoke)
export function removeTeamMember(base, owner, teamId, uid) {
	ncPhp(base, `$cm = \\OCP\\Server::get(\\OCA\\Circles\\CirclesManager::class);
		$cm->startSession($cm->getLocalFederatedUser($argv[1]));
		try {
			$single = $cm->getLocalFederatedUser($argv[3])->getSingleId();
			foreach ($cm->getCircle($argv[2])->getMembers() as $m) {
				if ($m->getSingleId() === $single) { $cm->removeMember($m->getId()); }
			}
		} finally { $cm->stopSession(); }`, owner, teamId, uid);
}

// Budget M5e: <n> Teams (Owner nn-budget-owner, <member> Level 1) mit je <per> Team-Workspaces; liefert die Team-IDs
export function seedBudgetTeams(base, member, n, per) {
	return JSON.parse(ncPhp(base, `$um = \\OCP\\Server::get(\\OCP\\IUserManager::class);
		$owner = 'nn-budget-owner';
		$um->get($owner) ?? $um->createUser($owner, bin2hex(random_bytes(16)) . 'Aa1!');
		$cm = \\OCP\\Server::get(\\OCA\\Circles\\CirclesManager::class);
		$ws = \\OCP\\Server::get(\\OCA\\NextNotes\\Service\\WorkspaceService::class);
		$ids = [];
		for ($i = 0; $i < (int)$argv[2]; $i++) {
			$cm->startSession($cm->getLocalFederatedUser($owner));
			try {
				$c = $cm->createCircle('Budget ' . $i . ' ' . bin2hex(random_bytes(3)), $cm->getLocalFederatedUser($owner), false, false, false);
				$cm->addMember($c->getSingleId(), $cm->getLocalFederatedUser($argv[1]));
				$ids[] = $c->getSingleId();
			} finally { $cm->stopSession(); }
			\\OCP\\Server::get(\\OCA\\NextNotes\\Service\\AccessService::class)->resetCache();
			for ($j = 0; $j < (int)$argv[3]; $j++) { $ws->create($owner, 'BW ' . $i . '-' . $j, [], false, null, end($ids)); }
		}
		echo json_encode($ids);`, member, String(n), String(per)));
}

// Teams entfernen (als <owner>): Team zerstören, Team-Workspaces und Team-Zeile löschen
export function dropTeams(base, owner, ids) {
	ncPhp(base, `$cm = \\OCP\\Server::get(\\OCA\\Circles\\CirclesManager::class);
		$db = \\OCP\\Server::get(\\OCP\\IDBConnection::class);
		$del = \\OCP\\Server::get(\\OCA\\NextNotes\\Service\\WorkspaceDeletion::class);
		foreach (json_decode($argv[1], true) as $id) {
			$cm->startSession($cm->getLocalFederatedUser($argv[2]));
			try { $cm->destroyCircle($id); } catch (\\Throwable $e) {} finally { $cm->stopSession(); }
			$qb = $db->getQueryBuilder();
			$qb->select('id')->from('nextnotes_workspaces')->where($qb->expr()->eq('owner_type', $qb->createNamedParameter('team')))
				->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($id)));
			foreach ($qb->executeQuery()->fetchFirstColumn() as $w) { $del->purge((int)$w); }
			$qb = $db->getQueryBuilder();
			$qb->delete('nextnotes_teams')->where($qb->expr()->eq('team_id', $qb->createNamedParameter($id)))->executeStatement();
		}`, JSON.stringify(ids), owner);
}

// Test-Hook (M6g): Frist einer Aufgabe direkt setzen (Fristablauf ohne Warten auf die Minute; API erlaubt nur Verlängern)
export function setAssignmentDue(base, id, ts) {
	ncPhp(base, `$qb = \\OCP\\Server::get(\\OCP\\IDBConnection::class)->getQueryBuilder();
		$qb->update('nextnotes_assignments')->set('due_at', $qb->createNamedParameter((int)$argv[2], \\OCP\\DB\\QueryBuilder\\IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$argv[1], \\OCP\\DB\\QueryBuilder\\IQueryBuilder::PARAM_INT)))->executeStatement();`, String(id), String(ts));
}
