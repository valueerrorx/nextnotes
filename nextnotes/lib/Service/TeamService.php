<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use OCA\Circles\CirclesManager;
use OCA\Circles\Events\CircleGenericEvent;
use OCA\Circles\Events\CircleResultGenericEvent;
use OCA\Circles\Events\MembershipsRemovedEvent;
use OCA\Circles\Exceptions\CircleNotFoundException;
use OCA\Circles\Exceptions\MembershipNotFoundException;
use OCA\Circles\Model\Circle;
use OCA\Circles\Model\FederatedUser;
use OCA\Circles\Model\Member;
use OCA\Circles\Model\Membership;
use OCA\Circles\Model\Probes\CircleProbe;
use OCA\NextNotes\Service\Exception\TeamUnavailableException;
use OCP\Teams\ITeamManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

// Einziger Circles-Zugriff (ADR 0003, Plan M5 §2.1); Ergebnisse nur je Request gecacht
class TeamService {
	// Circles 35: CircleRequestBuilder::getItemFromRequest ohne Treffer (geänderte Meldung → nie „fehlt“)
	private const CIRCLE_MISSING = 'Circle not found';
	// Kreise, die nie als Team zählen (Probe-Regel): Circle::CFG_SINGLE|CFG_PERSONAL|CFG_HIDDEN|CFG_BACKEND als Zahl, damit ohne Circles keine Klasse geladen wird
	private const EXCLUDED = 1 | 2 | 1024 | 2048;
	// Circles-Events als Namen (Registrierung ohne Klassenladen, M5b)
	public const EVENT_DESTROYING = 'OCA\\Circles\\Events\\DestroyingCircleEvent';
	public const EVENT_DESTROYED = 'OCA\\Circles\\Events\\CircleDestroyedEvent';
	public const EVENT_MEMBERSHIPS_REMOVED = 'OCA\\Circles\\Events\\MembershipsRemovedEvent';

	private ?bool $available = null;
	/** @var array<string, array<string, string>> uid → [teamId → Name] */
	private array $memberTeams = [];
	/** @var array<string, int> teamId|uid → effektives Level */
	private array $levels = [];
	/** @var array<string, bool> teamId → zählt als normales Team */
	private array $normal = [];

	public function __construct(
		private ITeamManager $teamManager,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}

	public function isAvailable(): bool {
		return $this->available ??= $this->teamManager->hasTeamSupport() && class_exists(CirclesManager::class);
	}

	// Nach Mitgliedsänderungen (Jobs, occ, Tests); kein Cache über den Request hinaus
	public function resetCache(): void {
		$this->available = null;
		$this->memberTeams = [];
		$this->levels = [];
		$this->normal = [];
	}

	/** @return array<string, string> teamId → Anzeigename, nur Status Member und nur Teams, die probe() zulässt */
	public function memberTeams(string $uid): array {
		if (isset($this->memberTeams[$uid])) {
			return $this->memberTeams[$uid];
		}
		$out = [];
		if ($this->isAvailable()) {
			$out = $this->guarded(function (CirclesManager $cm) use ($uid): array {
				$cm->startSession($cm->getLocalFederatedUser($uid));
				$teams = [];
				foreach ($cm->probeCircles($this->probe()) as $circle) {
					if ($this->counts($circle)) {
						$teams[$circle->getSingleId()] = $circle->getDisplayName();
					}
				}
				return $teams;
			}, [], 'memberTeams');
		}
		return $this->memberTeams[$uid] = $out;
	}

	// Effektives Level (Gruppen, verschachtelte Teams); 0 = kein Mitglied oder Team zählt nicht
	public function getLevel(string $teamId, string $uid): int {
		if (!isset($this->memberTeams($uid)[$teamId])) {
			return 0;
		}
		return $this->linkLevel($teamId, $uid);
	}

	/** @return array{level: int, counts: bool} Level ohne Probe-Regel und ob das Team zählt (Anlegen: 404 vs. 403) */
	public function membership(string $teamId, string $uid): array {
		if (!$this->isAvailable()) {
			return ['level' => 0, 'counts' => false];
		}
		return ['level' => $this->linkLevel($teamId, $uid), 'counts' => isset($this->memberTeams($uid)[$teamId])];
	}

	private function linkLevel(string $teamId, string $uid): int {
		$key = $teamId . '|' . $uid;
		if (!isset($this->levels[$key])) {
			$this->levels[$key] = $this->guarded(static function (CirclesManager $cm) use ($teamId, $uid): int {
				try {
					$singleId = $cm->getLocalFederatedUser($uid)->getSingleId();
					return $cm->getLink($teamId, $singleId)->getLevel();
				} catch (MembershipNotFoundException) {
					return 0;
				}
			}, 0, 'getLevel');
		}
		return $this->levels[$key];
	}

	// Team ohne Benutzerbezug als normales Team (Provider, Mitgliederliste); unbekannt → false
	public function isNormalTeam(string $teamId): bool {
		if (!isset($this->normal[$teamId])) {
			$this->normal[$teamId] = $this->isAvailable() && $this->guarded(function (CirclesManager $cm) use ($teamId): bool {
				return $this->counts($this->circleAsOwner($cm, $teamId));
			}, false, 'isNormalTeam');
		}
		return $this->normal[$teamId];
	}

	/**
	 * Lokale Benutzer mit Status Member (geerbt, ohne Gruppen-Einträge, R2); optional nur ein effektives Level (M6)
	 * @return array<string, string> uid → Anzeigename
	 */
	public function members(string $teamId, ?int $level = null): array {
		if (!$this->isNormalTeam($teamId)) {
			return [];
		}
		return $this->guarded(function (CirclesManager $cm) use ($teamId, $level): array {
			$out = [];
			foreach ($this->circleAsOwner($cm, $teamId)->getInheritedMembers() as $m) {
				if ($m->getUserType() !== Member::TYPE_USER || !$m->isLocal() || $m->getStatus() !== Member::STATUS_MEMBER) {
					continue;
				}
				if ($level !== null) {
					try {
						if ($cm->getLink($teamId, $m->getSingleId())->getLevel() !== $level) {
							continue;
						}
					} catch (MembershipNotFoundException) {
						continue;
					}
				}
				$out[$m->getUserId()] = $m->getDisplayName();
			}
			return $out;
		}, [], 'members');
	}

	/**
	 * Existenz ohne Benutzer und ohne Probe-Regel (CleanupJob, Rückweg, orphan-check); null = fehlt
	 * @return array{id: string, name: string}|null
	 * @throws TeamUnavailableException Circles nicht verfügbar oder Fehler (nie „fehlt“)
	 */
	public function exists(string $teamId): ?array {
		if (!$this->isAvailable()) {
			throw new TeamUnavailableException('Teams not available');
		}
		try {
			$cm = $this->container->get(CirclesManager::class);
		} catch (\Throwable $e) {
			throw new TeamUnavailableException('Teams not available', 0, $e);
		}
		$previous = $this->current($cm);
		try {
			$circle = $this->circleAsOwner($cm, $teamId);
			return ['id' => $circle->getSingleId(), 'name' => $circle->getDisplayName()];
		} catch (CircleNotFoundException $e) {
			// nur die Meldung „keine Zeile“ aus CircleRequestBuilder heißt gelöscht; föderiert/fremd verwaltet und Unbekanntes → Fehler (nie verwaisen)
			if ($e->getMessage() === self::CIRCLE_MISSING) {
				return null;
			}
			$this->logger->warning('nextnotes: team lookup ambiguous', ['team' => $teamId, 'exception' => $e]);
			throw new TeamUnavailableException('Team lookup ambiguous', 0, $e);
		} catch (\Throwable $e) {
			$this->logger->warning('nextnotes: team lookup failed', ['team' => $teamId, 'exception' => $e]);
			throw new TeamUnavailableException('Team lookup failed', 0, $e);
		} finally {
			$this->restore($cm, $previous);
		}
	}

	// Kreis mit dem Owner als Initiator laden: unabhängig von einer offenen App-Sitzung (Circles setzt sie z. B. beim Anlegen eines Gruppen-Kreises; die Super-Session von ITeamManager::getTeam fände den Kreis dann nicht)
	private function circleAsOwner(CirclesManager $cm, string $teamId): Circle {
		$cm->startOccSession('', Member::TYPE_SINGLE, $teamId);
		return $cm->getCircle($teamId);
	}

	/**
	 * Effektives Level ohne Cache und ohne Probe-Regel; Fehler werfen statt 0 (Austritt löscht nur bei sicherem „kein Mitglied“)
	 * @throws TeamUnavailableException
	 */
	public function currentLevel(string $teamId, string $uid): int {
		if (!$this->isAvailable()) {
			throw new TeamUnavailableException('Teams not available');
		}
		try {
			$cm = $this->container->get(CirclesManager::class);
		} catch (\Throwable $e) {
			throw new TeamUnavailableException('Teams not available', 0, $e);
		}
		$previous = $this->current($cm);
		try {
			$singleId = $cm->getLocalFederatedUser($uid)->getSingleId();
			return $cm->getLink($teamId, $singleId)->getLevel();
		} catch (MembershipNotFoundException) {
			return 0;
		} catch (\Throwable $e) {
			throw new TeamUnavailableException('Team level lookup failed', 0, $e);
		} finally {
			$this->restore($cm, $previous);
		}
	}

	/** @return array{id: string, name: string}|null gelöschtes Team aus DestroyingCircleEvent/CircleDestroyedEvent */
	public function destroyedTeam(object $event): ?array {
		if (!$event instanceof CircleGenericEvent && !$event instanceof CircleResultGenericEvent) {
			return null;
		}
		$circle = $event->getCircle();
		return ['id' => $circle->getSingleId(), 'name' => $circle->getDisplayName()];
	}

	/** @return list<array{team: string, single: string}> entfernte Mitgliedschaften aus MembershipsRemovedEvent (direkt und geerbt) */
	public function removedMemberships(object $event): array {
		if (!$event instanceof MembershipsRemovedEvent) {
			return [];
		}
		return array_values(array_map(static fn (Membership $m) => ['team' => $m->getCircleId(), 'single' => $m->getSingleId()], $event->getMemberships()));
	}

	/** Für MembershipsRemovedEvent (M5b) */
	public function uidForSingleId(string $singleId): ?string {
		if (!$this->isAvailable()) {
			return null;
		}
		return $this->guarded(static function (CirclesManager $cm) use ($singleId): ?string {
			$user = $cm->getFederatedUser($singleId);
			return $user->getUserType() === Member::TYPE_USER && $user->isLocal() ? $user->getUserId() : null;
		}, null, 'uidForSingleId');
	}

	// Probe-Regel: versteckte, Backend-, persönliche und Einzel-Kreise zählen nie
	private function probe(): CircleProbe {
		$probe = new CircleProbe();
		$probe->filterSingleCircles()->filterPersonalCircles()->filterHiddenCircles()->filterBackendCircles()->mustBeMember();
		return $probe;
	}

	// Konfiguration zusätzlich am geladenen Kreis prüfen
	private function counts(Circle $circle): bool {
		return ($circle->getConfig() & self::EXCLUDED) === 0;
	}

	/**
	 * Eigene Circles-Sitzung je Aufruf; jeder Fehler → Rückfall für diesen Request, Log warning
	 * @template T
	 * @param callable(CirclesManager): T $fn
	 * @param T $fallback
	 * @return T
	 */
	private function guarded(callable $fn, mixed $fallback, string $what): mixed {
		$cm = null;
		$previous = null;
		try {
			$cm = $this->container->get(CirclesManager::class);
			$previous = $this->current($cm);
			return $fn($cm);
		} catch (\Throwable $e) {
			$this->logger->warning('nextnotes: teams lookup failed (' . $what . ')', ['exception' => $e]);
			return $fallback;
		} finally {
			if ($cm !== null) {
				$this->restore($cm, $previous);
			}
		}
	}

	// Sitzung des Aufrufers (z. B. Kern-TeamManager vor dem Provider-Aufruf) merken und danach wiederherstellen
	private function current(CirclesManager $cm): ?FederatedUser {
		try {
			$user = $cm->getCurrentFederatedUser();
			return $user instanceof FederatedUser ? $user : null;
		} catch (\Throwable) {
			return null;
		}
	}

	private function restore(CirclesManager $cm, ?FederatedUser $previous): void {
		try {
			$cm->stopSession();
			if ($previous !== null) {
				$cm->startSession($previous);
			}
		} catch (\Throwable) {
		}
	}
}
