#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Thomas Weissel
# SPDX-License-Identifier: AGPL-3.0-or-later
# update.sh: startet die Demo-Instanz (http://localhost:8090, admin/admin) und spielt den gebauten App-Stand ein.
# Daten bleiben erhalten. Nur nach einem Commit ausführen (Lead).
set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo="$(cd "$here/../.." && pwd)"
dc() { docker compose -f "$here/compose.yaml" "$@"; }
occ() { dc exec -T -u www-data nc php occ "$@"; }

make -C "$repo/nextnotes" build >/dev/null
dc up -d
for i in $(seq 1 150); do
	occ status --output=json 2>/dev/null | grep -q '"installed":true' && break
	[[ $i -eq 150 ]] && { echo "Nextcloud nicht bereit" >&2; exit 1; }
	sleep 2
done
dest=/var/www/html/custom_apps/nextnotes
tar -C "$repo/nextnotes" --exclude=.gitignore --exclude=./build --exclude=./Makefile --exclude=./js-src --exclude=./css-src -cf - . \
	| dc exec -T -u root nc sh -c "rm -rf '$dest' && mkdir -p '$dest' && tar -xf - -C '$dest' && chown -R www-data:www-data '$dest'"
occ app:enable nextnotes >/dev/null || true
occ upgrade >/dev/null || true
occ maintenance:repair >/dev/null 2>&1 || true
# Demo-Konten (nur anlegen, wenn sie fehlen)
add_user() {
	occ user:info "$1" >/dev/null 2>&1 && return 0
	dc exec -T -u www-data -e OC_PASS="$3" nc php occ user:add --password-from-env --display-name "$2" "$1" >/dev/null
}
add_user thomas "Thomas" thomas-demo-2026
add_user schueler1 "Schüler Eins" schueler-demo-2026
add_user schueler2 "Schülerin Zwei" schueler-demo-2026

# Beispiel-Teams (M5d) über CirclesManager; idempotent: Team je Owner und Name einmal, fehlende Mitglieder ergänzen (Level 1 Mitglied, 4 Moderator)
seed_team='require "/var/www/html/lib/base.php";
[, $owner, $name, $members] = $argv;
$cm = \OCP\Server::get(\OCA\Circles\CirclesManager::class);
$cm->startSession($cm->getLocalFederatedUser($owner));
try {
	$probe = new \OCA\Circles\Model\Probes\CircleProbe();
	$probe->mustBeOwner();
	$circle = null;
	foreach ($cm->getCircles($probe) as $c) {
		if ($c->getDisplayName() === $name) { $circle = $c; break; }
	}
	$circle ??= $cm->createCircle($name, null, false, false, false);
	foreach (json_decode($members, true) as $uid => $level) {
		$fed = $cm->getLocalFederatedUser((string)$uid);
		try {
			$cm->getLink($circle->getSingleId(), $fed->getSingleId());
		} catch (\Throwable) {
			$m = $cm->addMember($circle->getSingleId(), $fed);
			if ((int)$level > 1) { $cm->levelMember($m->getId(), (int)$level); }
		}
	}
} finally {
	$cm->stopSession();
}'
# Ausgabe erst sammeln: grep -q unter pipefail kann per SIGPIPE falsch-negativ sein
apps=$(occ app:list --enabled --output=json 2>/dev/null || true)
# Material-Ordner (M6b) über die App-API als thomas; idempotent: Team-Workspace, Ordner „Material“, Beispielnotizen je Titel einmal, Kennzeichen setzen
seed_material='[, $user, $pass, $team, $wsName, $folderName] = $argv;
$call = function (string $method, string $path, ?array $data = null) use ($user, $pass): array {
	$ch = curl_init("http://localhost/index.php/apps/nextnotes/api/v1" . $path);
	curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => "$user:$pass",
		CURLOPT_HTTPHEADER => ["OCS-APIRequest: true", "Accept: application/json", "Content-Type: application/json"]]);
	if ($data !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data)); }
	$body = curl_exec($ch);
	$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	return [$status, json_decode((string)$body, true) ?: []];
};
$teamId = null;
foreach ($call("GET", "/teams")[1]["teams"] ?? [] as $t) { if ($t["name"] === $team) { $teamId = $t["id"]; } }
if ($teamId === null) { fwrite(STDERR, "Hinweis: Team $team nicht gefunden, Material übersprungen\n"); exit(0); }
$findWs = function () use ($call, $team, $wsName): ?string {
	foreach ($call("GET", "/workspaces")[1]["workspaces"] ?? [] as $w) {
		if (($w["team"] ?? null) === $team && str_starts_with((string)$w["name"], $wsName)) { return (string)$w["name"]; }
	}
	return null;
};
$ws = $findWs();
if ($ws === null) { $call("POST", "/workspaces", ["name" => $wsName, "team" => $teamId]); $ws = $findWs(); }
if ($ws === null) { fwrite(STDERR, "Hinweis: Team-Workspace $wsName nicht angelegt\n"); exit(0); }
$q = "?workspace=" . rawurlencode($ws);
$folderId = null;
foreach ($call("GET", "/folders" . $q)[1]["folders"] ?? [] as $f) {
	if ($f["name"] === $folderName && $f["parent_id"] === null) { $folderId = (int)$f["id"]; }
}
$folderId ??= (int)($call("POST", "/folders", ["folder_name" => $folderName, "workspace" => $ws])[1]["folder_id"] ?? 0);
if ($folderId === 0) { fwrite(STDERR, "Hinweis: Ordner $folderName nicht angelegt\n"); exit(0); }
$have = array_map(static fn ($n) => $n["heading"], $call("GET", "/notes" . $q . "&folder_id=" . $folderId)[1]["notes"] ?? []);
$samples = [
	"Arbeitsblatt Brüche" => "<h2>Brüche addieren</h2><p>Bringe die Brüche auf einen gemeinsamen Nenner und addiere die Zähler.</p><ul><li>1/2 + 1/4 = ?</li><li>2/3 + 1/6 = ?</li></ul>",
	"Leseliste Deutsch" => "<p>Bitte bis zu den Ferien lesen:</p><ol><li>Die Vorstadtkrokodile</li><li>Das doppelte Lottchen</li></ol><p>Kopiere dir die Liste mit „Duplizieren“ in deinen eigenen Bereich.</p>",
];
foreach ($samples as $heading => $content) {
	if (!in_array($heading, $have, true)) { $call("POST", "/notes", ["heading" => $heading, "content" => $content, "workspace" => $ws, "folder_id" => $folderId]); }
}
[$status] = $call("PUT", "/folders/$folderId/material", ["material" => true]);
echo "Material: Ordner $folderName in $ws gekennzeichnet ($status)\n";'
if [[ $apps == *'"circles"'* ]]; then
	dc exec -T -u www-data nc php -r "$seed_team" -- thomas "Klasse 4a" '{"schueler1":1,"schueler2":1}'
	dc exec -T -u www-data nc php -r "$seed_team" -- admin "Fachgruppe Mathe" '{"thomas":4}'
	dc exec -T -u www-data nc php -r "$seed_material" -- thomas thomas-demo-2026 "Klasse 4a" "Klasse 4a" "Material"
else
	echo "Hinweis: Teams-App (circles) nicht aktiv, Beispiel-Teams übersprungen" >&2
fi
echo "Demo bereit: http://localhost:8090  (admin/admin, thomas/thomas-demo-2026, schueler1 und schueler2/schueler-demo-2026), Stand $(git -C "$repo" log --oneline -1)"
echo "Teams: „Klasse 4a“ (Owner thomas, Mitglieder schueler1, schueler2; Team-Workspace „Klasse 4a“ mit Material-Ordner), „Fachgruppe Mathe“ (Owner admin, thomas Moderator)"
