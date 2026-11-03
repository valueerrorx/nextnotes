<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

use InvalidArgumentException;
use OCA\NextNotes\AppInfo\Application;
use OCA\NextNotes\Service\Exception\BadRequestException;
use OCP\Exceptions\AppConfigException;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/** Einzige Quelle der erlaubten Embed-Hosts für Sanitizer, CSP und JS (B41, M4-Plan §1.4); exakte Hosts, keine Subdomains. */
class EmbedDomainService {
	public const KEY = 'allowed_iframe_domains';
	public const MAX_ENTRIES = 100;
	private const MAX_HOST = 253;

	/** @var list<string>|null */
	private ?array $domains = null;

	public function __construct(
		private IAppConfig $appConfig,
		private ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
	) {
	}

	/** @return list<string> normalisierte Hosts (Lesen tolerant, verworfene Einträge einmal als Warnung) */
	public function domains(): array {
		if ($this->domains !== null) {
			return $this->domains;
		}
		try {
			$raw = $this->appConfig->getValueArray(Application::APP_ID, self::KEY, []);
		} catch (AppConfigException $e) {
			// falscher Typ (occ config:app:set ohne --type=array): keine Embeds statt HTTP 500
			$this->warnOnce('type', 'nextnotes: allowed_iframe_domains ist keine Liste, ignoriert', ['exception' => $e]);
			return $this->domains = [];
		}
		[$ok, $errors] = $this->normalizeList(array_values($raw));
		if ($errors !== []) {
			$this->warnOnce(md5((string)json_encode($raw)), 'nextnotes: ungültige Einträge in allowed_iframe_domains verworfen', ['errors' => $errors]);
		}
		return $this->domains = array_slice($ok, 0, self::MAX_ENTRIES);
	}

	public function isAllowedHost(string $host): bool {
		return in_array(strtolower($host), $this->domains(), true);
	}

	/**
	 * Speichern aus dem Admin-Formular: alles oder nichts
	 * @param list<mixed> $entries
	 * @return list<string>
	 */
	public function save(array $entries): array {
		[$ok, $errors] = $this->normalizeList($entries);
		if (count($ok) > self::MAX_ENTRIES) {
			$errors[] = ['entry' => '', 'error' => 'too_many'];
		}
		if ($errors !== []) {
			throw new BadRequestException('Invalid embed domains', ['errors' => $errors]);
		}
		$this->appConfig->setValueArray(Application::APP_ID, self::KEY, $ok);
		return $this->domains = $ok;
	}

	/**
	 * @param list<mixed> $entries
	 * @return array{0: list<string>, 1: list<array{entry: string, error: string}>}
	 */
	public function normalizeList(array $entries): array {
		$ok = [];
		$errors = [];
		foreach ($entries as $entry) {
			if (!is_string($entry)) {
				$errors[] = ['entry' => is_scalar($entry) ? (string)$entry : '', 'error' => 'invalid'];
				continue;
			}
			if (trim($entry) === '') {
				continue;
			}
			try {
				$ok[] = self::normalize($entry);
			} catch (InvalidArgumentException $e) {
				$errors[] = ['entry' => mb_substr($entry, 0, 300), 'error' => $e->getMessage()];
			}
		}
		return [array_values(array_unique($ok)), $errors];
	}

	/** Host aus Eingabe wie „youtube.com“, „https://www.youtube.com/embed/x“; Fehlergrund als Code in der Ausnahme */
	public static function normalize(string $entry): string {
		$s = trim($entry);
		if ($s === '') {
			throw new InvalidArgumentException('empty');
		}
		if (str_contains($s, '*')) {
			throw new InvalidArgumentException('wildcard');
		}
		if (Pcre::match('#^([a-z][a-z0-9+.\-]*)://#i', $s, $m)) {
			if (!in_array(strtolower($m[1]), ['http', 'https'], true)) {
				throw new InvalidArgumentException('scheme');
			}
			$s = substr($s, strlen($m[0]));
		}
		$host = Pcre::split('#[/?\#\\\\]#', $s)[0];
		if (str_contains($host, '@')) {
			throw new InvalidArgumentException('userinfo');
		}
		if (str_starts_with($host, '[')) {
			throw new InvalidArgumentException('ip');
		}
		if (str_contains($host, ':')) {
			throw new InvalidArgumentException('port');
		}
		if (str_ends_with($host, '.')) {
			$host = substr($host, 0, -1);
		}
		if ($host === '') {
			throw new InvalidArgumentException('empty');
		}
		if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
			throw new InvalidArgumentException('ip');
		}
		$host = mb_strtolower($host, 'UTF-8');
		if (Pcre::match('/[^\x00-\x7F]/', $host)) {
			if (!function_exists('idn_to_ascii')) {
				throw new InvalidArgumentException('ascii_only');
			}
			$ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ, INTL_IDNA_VARIANT_UTS46);
			if (!is_string($ascii) || $ascii === '') {
				throw new InvalidArgumentException('invalid');
			}
			$host = $ascii;
		}
		if (strlen($host) > self::MAX_HOST) {
			throw new InvalidArgumentException('too_long');
		}
		$labels = explode('.', $host);
		if (count($labels) < 2) {
			throw new InvalidArgumentException('single_label');
		}
		foreach ($labels as $label) {
			if (!Pcre::match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
				throw new InvalidArgumentException('invalid');
			}
		}
		// rein numerische Endung: IPv4-Kurzformen (1.2.3, 0x7f.1) und ungültige TLDs
		if (Pcre::match('/^(?:\d+|0x[0-9a-f]*)$/', end($labels))) {
			throw new InvalidArgumentException('ip');
		}
		return $host;
	}

	private function warnOnce(string $key, string $message, array $context): void {
		$cache = $this->cacheFactory->createLocal(Application::APP_ID . '-embed');
		if ($cache->get('warned-' . $key) !== null) {
			return;
		}
		$cache->set('warned-' . $key, 1, 86400);
		$this->logger->warning($message, $context);
	}
}
