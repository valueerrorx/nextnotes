<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service;

/**
 * Datumstitel der Tagebuch-Einträge (Port src/lib/diary.php Formate + src/lib/datetime.php Tages-/Monatsnamen).
 * Jedes eingebaute Format wird immer erkannt, dazu das eingestellte und früher benutzte eigene Muster.
 */
class DiaryDates {
	private const NAME_MONTH = "\x01";
	private const NAME_MONTH_SHORT = "\x02";
	private const NAME_WEEKDAY = "\x03";
	private const NAME_WEEKDAY_SHORT = "\x05";

	private const FORMATS = [
		'ymd' => ['pattern' => 'Y-m-d', 'regex' => '/^(?<y>\d{4})-(?<m>\d{2})-(?<d>\d{2})$/'],
		'dmy_slash' => ['pattern' => 'd/m/Y', 'regex' => '/^(?<d>\d{2})\/(?<m>\d{2})\/(?<y>\d{4})$/'],
		'mdy_slash' => ['pattern' => 'm/d/Y', 'regex' => '/^(?<m>\d{2})\/(?<d>\d{2})\/(?<y>\d{4})$/'],
		'dmy_dot' => ['pattern' => 'd.m.Y', 'regex' => '/^(?<d>\d{2})\.(?<m>\d{2})\.(?<y>\d{4})$/'],
		'ymd_slash' => ['pattern' => 'Y/m/d', 'regex' => '/^(?<y>\d{4})\/(?<m>\d{2})\/(?<d>\d{2})$/'],
		'long' => ['pattern' => null, 'long' => true, 'regex' => null],
	];

	// Upstream getDateNameLocales (gespiegelt in js/date-time-format.js)
	private const LOCALES = [
		'en' => ['layout' => '{wd}, {month} {d}, {y}',
			'days' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
			'days_short' => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
			'months' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
			'short' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']],
		'fr' => ['layout' => '{wd} {d} {month} {y}',
			'days' => ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'],
			'days_short' => ['Dim.', 'Lun.', 'Mar.', 'Mer.', 'Jeu.', 'Ven.', 'Sam.'],
			'months' => ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'],
			'short' => ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.']],
		'de' => ['layout' => '{wd}, {d}. {month} {y}',
			'days' => ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'],
			'days_short' => ['So.', 'Mo.', 'Di.', 'Mi.', 'Do.', 'Fr.', 'Sa.'],
			'months' => ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'],
			'short' => ['Jan.', 'Feb.', 'März', 'Apr.', 'Mai', 'Juni', 'Juli', 'Aug.', 'Sept.', 'Okt.', 'Nov.', 'Dez.']],
		'es' => ['layout' => '{wd}, {d} de {month} de {y}',
			'days' => ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'],
			'days_short' => ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'],
			'months' => ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'],
			'short' => ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sept', 'oct', 'nov', 'dic']],
		'pt' => ['layout' => '{wd}, {d} de {month} de {y}',
			'days' => ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'],
			'days_short' => ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'],
			'months' => ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'],
			'short' => ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez']],
		'ru' => ['layout' => '{wd}, {d} {month} {y} г.',
			'days' => ['Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота'],
			'days_short' => ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'],
			'months' => ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'],
			'short' => ['янв.', 'февр.', 'мар.', 'апр.', 'мая', 'июн.', 'июл.', 'авг.', 'сент.', 'окт.', 'нояб.', 'дек.']],
		'ko' => ['layout' => '{y}년 {m}월 {d}일 {wd}',
			'days' => ['일요일', '월요일', '화요일', '수요일', '목요일', '금요일', '토요일'],
			'days_short' => ['일', '월', '화', '수', '목', '금', '토'],
			'months' => ['1월', '2월', '3월', '4월', '5월', '6월', '7월', '8월', '9월', '10월', '11월', '12월'],
			'short' => ['1월', '2월', '3월', '4월', '5월', '6월', '7월', '8월', '9월', '10월', '11월', '12월']],
		'zh-cn' => ['layout' => '{y}年{m}月{d}日{wd}',
			'days' => ['星期日', '星期一', '星期二', '星期三', '星期四', '星期五', '星期六'],
			'days_short' => ['周日', '周一', '周二', '周三', '周四', '周五', '周六'],
			'months' => ['一月', '二月', '三月', '四月', '五月', '六月', '七月', '八月', '九月', '十月', '十一月', '十二月'],
			'short' => ['1月', '2月', '3月', '4月', '5月', '6月', '7月', '8月', '9月', '10月', '11月', '12月']],
		'ja' => ['layout' => '{y}年{m}月{d}日 {wd}',
			'days' => ['日曜日', '月曜日', '火曜日', '水曜日', '木曜日', '金曜日', '土曜日'],
			'days_short' => ['日', '月', '火', '水', '木', '金', '土'],
			'months' => ['1月', '2月', '3月', '4月', '5月', '6月', '7月', '8月', '9月', '10月', '11月', '12月'],
			'short' => ['1月', '2月', '3月', '4月', '5月', '6月', '7月', '8月', '9月', '10月', '11月', '12月']],
		'ca' => ['layout' => '{wd}, {d} {month} de {y}',
			'days' => ['Diumenge', 'Dilluns', 'Dimarts', 'Dimecres', 'Dijous', 'Divendres', 'Dissabte'],
			'days_short' => ['Dg.', 'Dl.', 'Dt.', 'Dc.', 'Dj.', 'Dv.', 'Ds.'],
			'months' => ['gener', 'febrer', 'març', 'abril', 'maig', 'juny', 'juliol', 'agost', 'setembre', 'octubre', 'novembre', 'desembre'],
			'short' => ['gen.', 'febr.', 'març', 'abr.', 'maig', 'juny', 'jul.', 'ag.', 'set.', 'oct.', 'nov.', 'des.'],
			'months_in_date' => ['de gener', 'de febrer', 'de març', "d'abril", 'de maig', 'de juny', 'de juliol', "d'agost", 'de setembre', "d'octubre", 'de novembre', 'de desembre']],
	];

	/** @var array<string, string> */
	private array $alternations = [];
	/** @var ?list<string> */
	private ?array $longRegexes = null;
	/** @var array<string, array{spec: array, ordered: list<array>, lang: string}> */
	private array $perUser = [];

	public function __construct(
		private SettingsService $settings,
	) {
	}

	// Upstream getDiaryDefaultNoteType
	public function defaultNoteType(string $uid): string {
		return trim($this->settings->get($uid, 'diary_default_note_type', '')) === 'markdown' ? 'markdown' : 'note';
	}

	/** Titel eines Tages (YYYY-MM-DD) im eingestellten Format und in der Sprache des Benutzers */
	public function formatTitle(string $uid, string $iso): string {
		$date = \DateTimeImmutable::createFromFormat('!Y-m-d', $iso);
		if ($date === false) {
			return $iso;
		}
		$user = $this->forUser($uid);
		$spec = $user['spec'];
		if (!empty($spec['long'])) {
			return $this->longDate($date, $user['lang']);
		}
		return $this->applyNames($date->format($spec['pattern']), $date, $user['lang']);
	}

	/** Tag (YYYY-MM-DD), den ein Titel bezeichnet, sonst null; eingestelltes Format gewinnt bei Mehrdeutigkeit */
	public function parseTitle(string $uid, string $heading): ?string {
		$heading = trim($heading);
		// Datumstitel sind kurz: lange Titel ohne Regex abweisen
		if ($heading === '' || strlen($heading) > 200) {
			return null;
		}
		foreach ($this->forUser($uid)['ordered'] as $format) {
			if (!empty($format['long'])) {
				$long = $this->parseLong($heading);
				if ($long !== null) {
					return $long;
				}
				continue;
			}
			if (!Pcre::match($format['regex'], $heading, $m)) {
				continue;
			}
			if (($m['y'] ?? '') !== '') {
				$year = (int)$m['y'];
			} elseif (($m['y2'] ?? '') !== '') {
				$year = (int)$m['y2'];
				$year += $year >= 70 ? 1900 : 2000;
			} else {
				continue;
			}
			if (($m['m'] ?? '') !== '') {
				$month = (int)$m['m'];
			} elseif (($m['mn'] ?? '') !== '') {
				$month = $this->monthNumber($m['mn']);
			} elseif (($m['ms'] ?? '') !== '') {
				$month = $this->monthNumber($m['ms']);
			} else {
				continue;
			}
			$day = (int)($m['d'] ?? 0);
			if ($month > 0 && checkdate($month, $day, $year)) {
				return sprintf('%04d-%02d-%02d', $year, $month, $day);
			}
		}
		return null;
	}

	/** Upstream compileDiaryDateCustomFormat: eigenes Muster mit genau einem Jahr, Monat und Tag, sonst null */
	public function compileCustom(string $pattern): ?array {
		$pattern = trim($pattern);
		if ($pattern === '' || strlen($pattern) > 80 || !Pcre::match('/^[A-Za-z0-9\s\/.,_\-()]+$/', $pattern)) {
			return null;
		}
		$tokens = $this->customTokens();
		$php = '';
		$regex = '';
		$seen = [];
		$length = strlen($pattern);
		for ($i = 0; $i < $length; $i++) {
			$matched = false;
			foreach ($tokens as $token => $spec) {
				$tokenLength = strlen($token);
				if (substr($pattern, $i, $tokenLength) === $token) {
					if (isset($seen[$spec['part']])) {
						return null;
					}
					$seen[$spec['part']] = true;
					$php .= $spec['php'];
					$regex .= $spec['regex'];
					$i += $tokenLength - 1;
					$matched = true;
					break;
				}
			}
			if ($matched) {
				continue;
			}
			$char = $pattern[$i];
			$php .= ctype_alpha($char) ? '\\' . $char : $char;
			$regex .= preg_quote($char, '/');
		}
		if (!isset($seen['y'], $seen['m'], $seen['d'])) {
			return null;
		}
		return ['pattern' => $php, 'regex' => '/^' . $regex . '$/u'];
	}

	/** @return array{spec: array, ordered: list<array>, lang: string} */
	private function forUser(string $uid): array {
		if (isset($this->perUser[$uid])) {
			return $this->perUser[$uid];
		}
		$format = trim($this->settings->get($uid, 'diary_date_format', ''));
		$spec = null;
		if (str_starts_with($format, 'custom:')) {
			$spec = $this->compileCustom(substr($format, 7));
		}
		$spec ??= self::FORMATS[$format] ?? self::FORMATS['ymd'];
		$ordered = [$spec, ...array_values(self::FORMATS)];
		$history = json_decode($this->settings->get($uid, 'diary_date_format_history', ''), true);
		foreach (is_array($history) ? array_slice($history, 0, 10) : [] as $pattern) {
			$compiled = is_string($pattern) ? $this->compileCustom($pattern) : null;
			if ($compiled !== null) {
				$ordered[] = $compiled;
			}
		}
		$lang = strtolower(str_replace('_', '-', $this->settings->read($uid, 'language')));
		return $this->perUser[$uid] = ['spec' => $spec, 'ordered' => $ordered, 'lang' => $lang];
	}

	/** @return array<string, array{php: string, regex: string, part: string}> längstes Token zuerst */
	private function customTokens(): array {
		return [
			'YYYY' => ['php' => 'Y', 'regex' => '(?<y>\d{4})', 'part' => 'y'],
			'YY' => ['php' => 'y', 'regex' => '(?<y2>\d{2})', 'part' => 'y'],
			'MMMM' => ['php' => self::NAME_MONTH, 'regex' => '(?<mn>' . $this->alternation('months') . ')', 'part' => 'm'],
			'MMM' => ['php' => self::NAME_MONTH_SHORT, 'regex' => '(?<ms>' . $this->alternation('short') . ')', 'part' => 'm'],
			'MM' => ['php' => 'm', 'regex' => '(?<m>\d{2})', 'part' => 'm'],
			'dddd' => ['php' => self::NAME_WEEKDAY, 'regex' => $this->alternation('days'), 'part' => 'w'],
			'ddd' => ['php' => self::NAME_WEEKDAY_SHORT, 'regex' => $this->alternation('days_short'), 'part' => 'w'],
			'DD' => ['php' => 'd', 'regex' => '(?<d>\d{2})', 'part' => 'd'],
			'D' => ['php' => 'j', 'regex' => '(?<d>\d{1,2})', 'part' => 'd'],
		];
	}

	/** @return array{layout: string, days: list<string>, days_short: list<string>, months: list<string>, short: list<string>} */
	private static function locale(string $lang): array {
		return self::LOCALES[$lang] ?? self::LOCALES[explode('-', $lang)[0]] ?? self::LOCALES['en'];
	}

	// Namen aller Sprachen als Alternation, längste zuerst („sept.“ vor „sept“)
	private function alternation(string $kind): string {
		if (!isset($this->alternations[$kind])) {
			$names = [];
			foreach (self::LOCALES as $locale) {
				foreach ($locale[$kind] as $name) {
					$names[$name] = true;
				}
			}
			$names = array_map('strval', array_keys($names));
			usort($names, static fn (string $a, string $b) => mb_strlen($b) - mb_strlen($a));
			$this->alternations[$kind] = '(?i:' . implode('|', array_map(static fn (string $n) => preg_quote($n, '/'), $names)) . ')';
		}
		return $this->alternations[$kind];
	}

	private function longDate(\DateTimeInterface $date, string $lang): string {
		$locale = self::locale($lang);
		return strtr($locale['layout'], [
			'{wd}' => $locale['days'][(int)$date->format('w')],
			'{month}' => ($locale['months_in_date'] ?? $locale['months'])[(int)$date->format('n') - 1],
			'{m}' => $date->format('n'),
			'{d}' => $date->format('j'),
			'{y}' => $date->format('Y'),
		]);
	}

	private function applyNames(string $formatted, \DateTimeInterface $date, string $lang): string {
		$locale = self::locale($lang);
		return strtr($formatted, [
			self::NAME_MONTH => $locale['months'][(int)$date->format('n') - 1],
			self::NAME_MONTH_SHORT => $locale['short'][(int)$date->format('n') - 1],
			self::NAME_WEEKDAY => $locale['days'][(int)$date->format('w')],
			self::NAME_WEEKDAY_SHORT => $locale['days_short'][(int)$date->format('w')],
		]);
	}

	// Upstream parseDiaryLongDateTitle: Langformat in jeder Sprache
	private function parseLong(string $heading): ?string {
		if ($this->longRegexes === null) {
			$this->longRegexes = [];
			foreach (self::LOCALES as $lang => $locale) {
				$inDate = $locale['months_in_date'] ?? $locale['months'];
				$alt = static fn (array $names): string => implode('|', array_map(static fn (string $n) => preg_quote($n, '/'), $names));
				$regex = '';
				foreach (Pcre::split('/(\{wd\}|\{month\}|\{m\}|\{d\}|\{y\})/', $locale['layout'], PREG_SPLIT_DELIM_CAPTURE) as $piece) {
					$regex .= match ($piece) {
						'{wd}' => '(?:' . $alt($locale['days']) . ')',
						'{month}' => '(?<mn>' . $alt($inDate) . ')',
						'{m}' => '(?<m>\d{1,2})',
						'{d}' => '(?<d>\d{1,2})',
						'{y}' => '(?<y>\d{4})',
						default => preg_quote($piece, '/'),
					};
				}
				$this->longRegexes[$lang] = '/^' . $regex . '$/iu';
			}
		}
		foreach ($this->longRegexes as $lang => $regex) {
			if (!Pcre::match($regex, $heading, $m)) {
				continue;
			}
			if (($m['mn'] ?? '') !== '') {
				$month = 0;
				foreach (self::LOCALES[$lang]['months_in_date'] ?? self::LOCALES[$lang]['months'] as $i => $name) {
					if (mb_strtolower($name) === mb_strtolower($m['mn'])) {
						$month = $i + 1;
					}
				}
			} else {
				$month = (int)($m['m'] ?? 0);
			}
			if ($month > 0 && checkdate($month, (int)$m['d'], (int)$m['y'])) {
				return sprintf('%04d-%02d-%02d', (int)$m['y'], $month, (int)$m['d']);
			}
		}
		return null;
	}

	// Upstream diaryMonthNameToNumber: volle und kurze Namen aller Sprachen
	private function monthNumber(string $name): int {
		$name = mb_strtolower(trim($name));
		if ($name === '') {
			return 0;
		}
		foreach (self::LOCALES as $locale) {
			foreach (['months', 'short'] as $kind) {
				foreach ($locale[$kind] as $i => $known) {
					if (mb_strtolower($known) === $name) {
						return $i + 1;
					}
				}
			}
		}
		return 0;
	}
}
