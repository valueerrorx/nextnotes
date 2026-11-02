<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Nur Tests: spielt das Kern-Szenario M2a–M2c ab und speichert je Route den JSON-Schlüsselbaum (Werte → Typname).
// php capture.php upstream <out-dir>            Upstream auf 127.0.0.1:8090 (legt zwei Konten an)
// php capture.php nextnotes <out-dir> <base> <userA:pass> <userB:pass>   Next Notes (Basic-Auth)

if ($argc < 3) {
	fwrite(STDERR, "Aufruf: capture.php upstream|nextnotes <out-dir> [base userA:pass userB:pass]\n");
	exit(2);
}
[$_, $mode, $out] = $argv;

final class Client {
	public function __construct(private string $base, private string $user, private string $pass, private ?int $profile = null) {
	}

	/** @return array{0: int, 1: mixed} */
	public function call(string $method, string $path, ?array $json = null, ?array $form = null, array $extra = []): array {
		$headers = array_merge(['Accept: application/json', 'OCS-APIRequest: true'], $extra);
		if ($this->profile !== null) {
			$headers[] = 'X-User-ID: ' . $this->profile;
		}
		$ch = curl_init($this->base . $path);
		$opts = [
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_USERPWD => $this->user . ':' . $this->pass,
			CURLOPT_TIMEOUT => 30,
		];
		if ($json !== null) {
			$headers[] = 'Content-Type: application/json';
			$opts[CURLOPT_POSTFIELDS] = json_encode($json);
		}
		if ($form !== null) {
			$opts[CURLOPT_POSTFIELDS] = $form;
		}
		$opts[CURLOPT_HTTPHEADER] = $headers;
		curl_setopt_array($ch, $opts);
		$body = (string)curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		return [$status, json_decode($body)];
	}
}

// Schlüsselbaum: Objekte rekursiv, Listen als [erstes Element] bzw. [], Skalare als Typname
function shape(mixed $v): mixed {
	if ($v instanceof stdClass) {
		$o = [];
		foreach (get_object_vars($v) as $k => $x) {
			$o[$k] = shape($x);
		}
		ksort($o);
		return (object)$o;
	}
	if (is_array($v)) {
		return $v === [] ? [] : [shape($v[0])];
	}
	return get_debug_type($v);
}

$routes = [];
$names = [];
// Datenschlüssel (Wörterbuch, gespeicherte Einstellungen) sind kein Format: nur der Typ zählt
const DATA_KEYS = ['GET /system/i18n' => 'strings', 'GET /settings' => 'settings'];
function rec(string $route, array $res): mixed {
	global $routes;
	[$status, $body] = $res;
	if (isset(DATA_KEYS[$route]) && $body instanceof stdClass && isset($body->{DATA_KEYS[$route]})) {
		$body = clone $body;
		$body->{DATA_KEYS[$route]} = 'object';
	}
	if (!isset($routes[$route]) || ($routes[$route]['status'] >= 300 && $status < 300)) {
		$routes[$route] = ['route' => $route, 'status' => $status, 'body' => shape($body)];
	}
	if ($status >= 300) {
		fwrite(STDERR, "WARN $route → $status " . json_encode($body) . "\n");
	}
	return $body;
}

if ($mode === 'upstream') {
	$base = 'http://127.0.0.1:8090/api/v1';
	$admin = new Client($base, 'admin_change_me', 'admin', 1);
	$clients = [];
	foreach (['a', 'b'] as $p) {
		$name = 'fx' . $p . bin2hex(random_bytes(3));
		[$st, $u] = $admin->call('POST', '/admin/users', ['username' => $name]);
		$st === 201 || exit("Konto $name: $st\n");
		$admin->call('POST', "/admin/users/{$u->id}/reset-password", ['action' => 'set_password', 'new_password' => 'Fx-pass-2026!x']);
		$clients[$p] = new Client($base, $name, 'Fx-pass-2026!x', (int)$u->id);
		$names[$p] = $name;
	}
} elseif ($mode === 'nextnotes' && $argc === 6) {
	$base = rtrim($argv[3], '/') . '/index.php/apps/nextnotes/api/v1';
	foreach (['a' => $argv[4], 'b' => $argv[5]] as $p => $cred) {
		[$u, $pw] = explode(':', $cred, 2);
		$clients[$p] = new Client($base, $u, $pw);
		$names[$p] = $u;
	}
} else {
	exit("unbekannter Modus\n");
}

$A = $clients['a'];
$B = $clients['b'];
$ws = 'Fixture ' . bin2hex(random_bytes(2));
$ws2 = $ws . ' neu';
$q = '?workspace=' . rawurlencode($ws2);

// B arbeitet parallel im gleichnamigen Workspace
$B->call('POST', '/workspaces', ['name' => $ws]);

rec('GET /workspaces', $A->call('GET', '/workspaces'));
rec('POST /workspaces', $A->call('POST', '/workspaces', ['name' => $ws]));
rec('PATCH /workspaces/{name}', $A->call('PATCH', '/workspaces/' . rawurlencode($ws), ['new_name' => $ws2]));

$f1 = rec('POST /folders', $A->call('POST', '/folders', ['folder_name' => 'Eins', 'workspace' => $ws2]))->folder_id;
$f2 = $A->call('POST', '/folders', ['folder_name' => 'Zwei', 'workspace' => $ws2])[1]->folder_id;
$f3 = $A->call('POST', '/folders', ['folder_name' => 'Drei', 'workspace' => $ws2])[1]->folder_id;
rec('GET /folders', $A->call('GET', '/folders' . $q . '&hierarchical=true'));
rec('GET /folders/{id}', $A->call('GET', "/folders/$f1$q"));

$n1 = rec('POST /notes', $A->call('POST', '/notes', ['heading' => 'Notiz eins', 'content' => '<p>Inhalt</p>', 'tags' => 'ta tb', 'workspace' => $ws2, 'folder_id' => $f1]))->note->id;
$n2 = $A->call('POST', '/notes', ['heading' => 'Notiz zwei', 'content' => '<p>zwei</p>', 'workspace' => $ws2])[1]->note->id;
$n3 = $A->call('POST', '/notes', ['heading' => 'Notiz drei', 'content' => '<p>drei</p>', 'workspace' => $ws2])[1]->note->id;
rec('GET /notes', $A->call('GET', '/notes' . $q));
rec('GET /notes/{id}', $A->call('GET', "/notes/$n1$q"));
rec('GET /notes/templates', $A->call('GET', '/notes/templates' . $q));
rec('PATCH /notes/{id}', $A->call('PATCH', "/notes/$n1", ['heading' => 'Notiz eins b', 'content' => '<p>neu</p>']));
rec('POST /notes/{id}/beacon', $A->call('POST', "/notes/$n1/beacon", null, ['content' => '<p>beacon</p>']));
rec('PUT /notes/{id}/tags', $A->call('PUT', "/notes/$n1/tags", ['tags' => 'ta tb']));
rec('PUT /notes/{id}/icon', $A->call('PUT', "/notes/$n1/icon", ['icon' => 'lucide-star', 'icon_color' => '#ff0000']));
rec('PUT /notes/{id}/color', $A->call('PUT', "/notes/$n1/color", ['color' => '#00ff00']));
rec('PUT /notes/{id}/pinned', $A->call('PUT', "/notes/$n1/pinned", ['pinned' => true]));
rec('PUT /notes/{id}/content-width', $A->call('PUT', "/notes/$n1/content-width", ['content_width' => 80]));
rec('PUT /notes/{id}/title-style', $A->call('PUT', "/notes/$n1/title-style", ['title_color' => '#112233', 'title_size' => 24]));
rec('POST /notes/{id}/favorite', $A->call('POST', "/notes/$n1/favorite" . $q));
rec('POST /notes/{id}/opened', $A->call('POST', "/notes/$n1/opened"));
rec('POST /notes/{id}/folder', $A->call('POST', "/notes/$n1/folder", ['folder_id' => $f2, 'workspace' => $ws2]));
rec('POST /notes/{id}/remove-folder', $A->call('POST', "/notes/$n1/remove-folder" . $q));
rec('POST /notes/{id}/kanban-completed', $A->call('POST', "/notes/$n1/kanban-completed", ['completed' => true]));
rec('POST /notes/reorder', $A->call('POST', '/notes/reorder', ['note_id' => $n2, 'target_note_id' => $n1, 'position' => 'before', 'workspace' => $ws2]));
$A->call('POST', "/notes/$n1/folder", ['folder_id' => $f2, 'workspace' => $ws2]);
rec('GET /folders/counts', $A->call('GET', '/folders/counts' . $q));
rec('GET /folders/suggested', $A->call('GET', '/folders/suggested' . $q));
rec('GET /folders/{id}/notes', $A->call('GET', "/folders/$f2/notes$q"));
rec('GET /folders/{id}/path', $A->call('GET', "/folders/$f2/path$q"));
rec('PATCH /folders/{id}', $A->call('PATCH', "/folders/$f1", ['name' => 'Eins neu', 'workspace' => $ws2]));
rec('PUT /folders/{id}/icon', $A->call('PUT', "/folders/$f1/icon", ['icon' => 'lucide-star', 'icon_color' => '#ff0000']));
rec('PUT /folders/{id}/color', $A->call('PUT', "/folders/$f1/color", ['color' => '#00ff00']));
rec('PUT /folders/{id}/pinned', $A->call('PUT', "/folders/$f1/pinned", ['pinned' => true]));
rec('PUT /folders/{id}/favorite', $A->call('PUT', "/folders/$f1/favorite", ['favorite' => true]));
rec('POST /folders/{id}/tags', $A->call('POST', "/folders/$f2/tags", ['tags' => ['tc'], 'workspace' => $ws2]));
rec('DELETE /folders/{id}/tags', $A->call('DELETE', "/folders/$f2/tags", ['tags' => ['tc'], 'workspace' => $ws2]));
rec('POST /folders/move-files', $A->call('POST', '/folders/move-files', ['source_folder_id' => $f2, 'target_folder_id' => $f1, 'workspace' => $ws2]));
rec('POST /folders/{id}/move', $A->call('POST', "/folders/$f2/move", ['new_parent_folder_id' => $f1, 'workspace' => $ws2]));
rec('POST /folders/reorder', $A->call('POST', '/folders/reorder', ['folder_id' => $f3, 'target_folder_id' => $f1, 'position' => 'before', 'workspace' => $ws2]));
rec('GET /tags', $A->call('GET', '/tags' . $q));
rec('PATCH /tags/{tag}', $A->call('PATCH', '/tags/ta', ['new_name' => 'tz', 'workspace' => $ws2]));
rec('DELETE /tags/{tag}', $A->call('DELETE', '/tags/tb' . $q));
rec('POST /notes/favorites/clear', $A->call('POST', '/notes/favorites/clear', ['workspace' => $ws2]));
rec('DELETE /notes/{id}', $A->call('DELETE', "/notes/$n2$q"));
rec('GET /trash', $A->call('GET', '/trash' . $q));
rec('POST /notes/{id}/restore', $A->call('POST', "/notes/$n2/restore$q"));
$A->call('DELETE', "/notes/$n2$q");
rec('DELETE /trash/{id}', $A->call('DELETE', "/trash/$n2$q"));
rec('POST /folders/{id}/empty', $A->call('POST', "/folders/$f1/empty$q"));
rec('DELETE /trash', $A->call('DELETE', '/trash' . $q));
$snap = rec('DELETE /folders/{id}', $A->call('DELETE', "/folders/$f3$q"));
if (isset($snap->restore_snapshot)) {
	rec('POST /folders/restore', $A->call('POST', '/folders/restore', json_decode(json_encode($snap->restore_snapshot), true)));
}
// M2b: Suche, Links, Anhänge, Sperre, Snapshots, Duplizieren
$m1 = $A->call('POST', '/notes', ['heading' => 'Mehr eins', 'content' => '<p>Suchwort Kaffee</p>', 'workspace' => $ws2, 'folder_id' => $f1])[1]->note->id;
$m2 = $A->call('POST', '/notes', ['heading' => 'Mehr zwei', 'content' => '<p><a href="index.php?note=' . $m1 . '">eins</a></p>', 'workspace' => $ws2])[1]->note->id;
rec('GET /notes/resolve', $A->call('GET', '/notes/resolve?reference=' . rawurlencode('Mehr eins') . '&workspace=' . rawurlencode($ws2)));
rec('GET /notes/search', $A->call('GET', '/notes/search?q=Suchwort&workspace=' . rawurlencode($ws2)));
rec('GET /notes/search/ids', $A->call('GET', '/notes/search/ids?q=Suchwort&workspace=' . rawurlencode($ws2)));
rec('GET /notes/{id}/backlinks', $A->call('GET', "/notes/$m1/backlinks$q"));
rec('GET /graph', $A->call('GET', '/graph' . $q));
$att = rec('POST /notes/{noteId}/attachments', $A->call('POST', "/notes/$m1/attachments$q", null, ['file' => new CURLStringFile("GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;", 'bild.gif', 'image/gif')]))->attachment_id ?? 0;
rec('GET /notes/{noteId}/attachments', $A->call('GET', "/notes/$m1/attachments$q"));
rec('GET /notes/{noteId}/attachments/{attachmentId}', $A->call('GET', "/notes/$m1/attachments/$att$q"));
rec('GET /notes/with-attachments', $A->call('GET', '/notes/with-attachments' . $q));
$moved = rec('POST /notes/{noteId}/attachments/{attachmentId}/move', $A->call('POST', "/notes/$m1/attachments/$att/move", ['target_note_id' => $m2]))->attachment_id ?? $att;
rec('DELETE /notes/{noteId}/attachments/{attachmentId}', $A->call('DELETE', "/notes/$m2/attachments/$moved$q"));
rec('GET /changes', $A->call('GET', '/changes?workspace=' . rawurlencode($ws2) . "&note_ids=$m1,$m2"));
$sess = ['X-Editor-Session-ID: fx-s1'];
rec('POST /notes/{id}/lock', $A->call('POST', "/notes/$m1/lock", ['editor_session_id' => 'fx-s1'], null, $sess));
rec('GET /notes/{id}/lock', $A->call('GET', "/notes/$m1/lock", null, null, $sess));
rec('POST /notes/{id}/lock/heartbeat', $A->call('POST', "/notes/$m1/lock/heartbeat", ['editor_session_id' => 'fx-s1'], null, $sess));
rec('POST /notes/{id}/lock/release', $A->call('POST', "/notes/$m1/lock/release", ['editor_session_id' => 'fx-s1'], null, $sess));
$key = rec('POST /notes/{id}/snapshot', $A->call('POST', "/notes/$m1/snapshot?manual=1"))->snapshot_key ?? '';
rec('GET /notes/{id}/snapshots', $A->call('GET', "/notes/$m1/snapshots"));
rec('GET /notes/{id}/snapshot', $A->call('GET', "/notes/$m1/snapshot?snapshot_key=" . rawurlencode($key)));
rec('POST /notes/{id}/snapshot/restore', $A->call('POST', "/notes/$m1/snapshot/restore?snapshot_key=" . rawurlencode($key)));
rec('DELETE /notes/{id}/snapshot', $A->call('DELETE', "/notes/$m1/snapshot?snapshot_key=" . rawurlencode($key)));
rec('POST /notes/{id}/duplicate', $A->call('POST', "/notes/$m1/duplicate", ['workspace' => $ws2]));
rec('POST /folders/{id}/duplicate', $A->call('POST', "/folders/$f1/duplicate$q", []));

// M2c: Aufgaben, Checklisten, Erinnerungen, Mentions, Einstellungen, System, Benutzer, Konvertierung
$tl = $A->call('POST', '/notes', ['heading' => 'Aufgaben', 'type' => 'tasklist', 'content' => '[]', 'workspace' => $ws2])[1]->note->id;
$A->call('POST', '/notes', ['heading' => 'Check', 'type' => 'markdown', 'content' => "- [ ] eins\n- [x] zwei", 'workspace' => $ws2]);
$t1 = rec('POST /notes/{id}/tasks', $A->call('POST', "/notes/$tl/tasks", ['text' => 'Eins', 'due_at' => '2030-01-02', 'reminder' => true]))->task->id;
$t1s = json_encode($t1);
$t2s = json_encode($A->call('POST', "/notes/$tl/tasks", ['text' => 'Zwei'])[1]->task->id);
rec('PATCH /notes/{id}/tasks/{taskId}', $A->call('PATCH', "/notes/$tl/tasks/$t1s", ['text' => 'Eins b', 'important' => true]));
$s1 = rec('POST /notes/{id}/tasks/{taskId}/subtasks', $A->call('POST', "/notes/$tl/tasks/$t1s/subtasks", ['text' => 'Unter']))->subtask->id;
$s1s = json_encode($s1);
rec('PATCH /notes/{id}/tasks/{taskId}/subtasks/{subtaskId}', $A->call('PATCH', "/notes/$tl/tasks/$t1s/subtasks/$s1s", ['completed' => true]));
$A->call('POST', "/notes/$tl/tasks/$t1s/subtasks", ['text' => 'Unter zwei']);
rec('GET /notes/{id}/tasks', $A->call('GET', "/notes/$tl/tasks"));
rec('GET /tasks', $A->call('GET', '/tasks' . $q));
rec('DELETE /notes/{id}/tasks/{taskId}/subtasks/{subtaskId}', $A->call('DELETE', "/notes/$tl/tasks/$t1s/subtasks/$s1s"));
rec('DELETE /notes/{id}/tasks/{taskId}', $A->call('DELETE', "/notes/$tl/tasks/$t1s"));
rec('POST /notes/{id}/reminder', $A->call('POST', "/notes/$m1/reminder", ['reminder_at' => '2020-01-01T08:00:00Z']));
rec('GET /notes/{id}/reminder', $A->call('GET', "/notes/$m1/reminder"));
$list = rec('GET /reminders', $A->call('GET', '/reminders' . $q));
rec('GET /reminders/count', $A->call('GET', '/reminders/count' . $q));
$rid = $list->notifications[0]->id ?? 1;
rec('POST /reminders/{id}/read', $A->call('POST', "/reminders/$rid/read"));
rec('POST /reminders/{id}/dismiss', $A->call('POST', "/reminders/$rid/dismiss"));
rec('POST /reminders/dismiss-all', $A->call('POST', '/reminders/dismiss-all'));
$A->call('POST', "/notes/$m1/reminder", ['reminder_at' => '2030-01-01T08:00:00Z']);
rec('DELETE /notes/{id}/reminder', $A->call('DELETE', "/notes/$m1/reminder"));
rec('POST /notes/{id}/task-reminder', $A->call('POST', "/notes/$tl/task-reminder", ['task_id' => $t2s, 'reminder_at' => '2030-01-01T08:00:00Z']));
rec('DELETE /notes/{id}/task-reminder', $A->call('DELETE', "/notes/$tl/task-reminder?task_id=" . rawurlencode($t2s)));
rec('GET /mentions', $A->call('GET', '/mentions'));
rec('GET /mentions/members', $A->call('GET', '/mentions/members' . $q));
rec('POST /mentions/{ownerId}/{noteId}/handled', $A->call('POST', "/mentions/1/$m1/handled"));
rec('DELETE /mentions/{ownerId}/{noteId}/handled', $A->call('DELETE', "/mentions/1/$m1/handled"));
rec('GET /settings', $A->call('GET', '/settings'));
rec('PUT /settings/{key}', $A->call('PUT', '/settings/note_list_sort', ['value' => 'heading_asc']));
rec('GET /settings/{key}', $A->call('GET', '/settings/note_list_sort'));
rec('GET /system/version', $A->call('GET', '/system/version'));
rec('GET /system/whats-new', $A->call('GET', '/system/whats-new'));
rec('POST /system/whats-new/seen', $A->call('POST', '/system/whats-new/seen'));
rec('GET /system/i18n', $A->call('GET', '/system/i18n?lang=de'));
rec('GET /shared/with-me', $A->call('GET', '/shared/with-me'));
rec('GET /shared', $A->call('GET', '/shared' . $q));
rec('GET /users/me', $A->call('GET', '/users/me'));
// Upstream: nur Admin
rec('GET /users/lookup/{username}', ($admin ?? $A)->call('GET', '/users/lookup/' . rawurlencode($names['b'])));
rec('POST /notes/{id}/convert', $A->call('POST', "/notes/$m1/convert", ['target' => 'markdown']));
rec('POST /convert-html', $A->call('POST', '/convert-html', ['html' => '<p><b>x</b></p>']));
rec('POST /convert-markdown', $A->call('POST', '/convert-markdown', ['markdown' => '**x**']));

$n4 = $A->call('POST', '/notes', ['heading' => 'Archiv', 'content' => '<p>a</p>', 'workspace' => $ws2, 'folder_id' => $f1])[1]->note->id;
rec('POST /notes/{id}/archive', $A->call('POST', "/notes/$n4/archive"));
rec('POST /folders/{id}/archive', $A->call('POST', "/folders/$f2/archive"));
rec('DELETE /notes/{id}?permanent', $A->call('DELETE', "/notes/$n3?permanent=true$q"));
rec('DELETE /workspaces/{name}', $A->call('DELETE', '/workspaces/' . rawurlencode($ws2)));

@mkdir($out, 0775, true);
foreach (glob("$out/*.json") as $old) {
	unlink($old);
}
ksort($routes);
foreach ($routes as $route => $data) {
	$file = preg_replace('/[^A-Za-z0-9]+/', '_', $route);
	file_put_contents("$out/" . trim($file, '_') . '.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}
echo count($routes) . " Routen nach $out\n";
