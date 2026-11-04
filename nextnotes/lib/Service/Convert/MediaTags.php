<?php

/**
 * SPDX-FileCopyrightText: 2026 Thomas Weissel, Timothé Poznanski (Upstream, MIT)
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextNotes\Service\Convert;

// Port aus src/lib/html-sanitize.php (nur poznoteIframeSrcIsTrusted, poznoteMediaSrcIsTrusted, poznoteRebuildMediaTag; M4b).
// Abweichung: iframe-Hosts über setIframeHostCheck (EmbedDomainService, exakt, B41) statt ALLOWED_IFRAME_DOMAINS.
final class MediaTags {
	use \OCA\NextNotes\Service\PcreCalls;

	/** @var (callable(string): bool)|null */
	private static $iframeHostCheck = null;

	/** @param (callable(string): bool)|null $check Host (klein) erlaubt? */
	public static function setIframeHostCheck(?callable $check): void {
		self::$iframeHostCheck = $check;
	}

	public static function poznoteIframeSrcIsTrusted($src): bool {
	    $src = trim((string) $src);
	    if ($src === '') {
	        return false;
	    }

	    // Reject any explicit scheme that is not http/https (javascript:, data:, ...).
	    if (self::pregMatch('#^([a-z][a-z0-9+.\-]*):#i', $src, $schemeMatch)) {
	        if (!in_array(strtolower($schemeMatch[1]), ['http', 'https'], true)) {
	            return false;
	        }
	    }

	    $host = parse_url($src, PHP_URL_HOST);
	    if ($host === null || $host === false || $host === '') {
	        // No host -> relative/local path only. A leading "//" with no resolvable
	        // host is treated as untrusted.
	        if (strpos($src, '//') === 0) {
	            return false;
	        }
	        return $src[0] === '/'
	            || strpos($src, './') === 0
	            || self::pregMatch('~^audio_player\.php(?:[?#]|$)~i', $src) === 1;
	    }

	    $host = strtolower($host);
	    // B41: Admin-Liste, exakter Host (Upstream: feste Liste mit Subdomains)
	    if (self::$iframeHostCheck !== null && (self::$iframeHostCheck)($host)) {
	        return true;
	    }

	    return false;
	}
	public static function poznoteMediaSrcIsTrusted($src): bool {
	    $src = trim((string) $src);
	    if ($src === '') {
	        return false;
	    }
	    if (self::pregMatch('#^([a-z][a-z0-9+.\-]*):#i', $src, $schemeMatch)) {
	        return in_array(strtolower($schemeMatch[1]), ['http', 'https'], true);
	    }
	    return $src[0] === '/' || strpos($src, './') === 0 || strpos($src, '../') === 0;
	}
	public static function poznoteRebuildMediaTag(string $tagName, string $attrs): ?string {
	    static $allowedAttrs = [
	        'iframe' => ['src', 'width', 'height', 'frameborder', 'allow', 'allowfullscreen', 'allowtransparency', 'title', 'sandbox', 'loading', 'referrerpolicy', 'style', 'class', 'scrolling', 'contenteditable', 'data-is-audio', 'data-audio-src', 'data-converted-from-audio'],
	        'video' => ['src', 'width', 'height', 'preload', 'poster', 'class', 'style', 'controls', 'muted', 'playsinline', 'loop', 'autoplay'],
	        'audio' => ['src', 'preload', 'class', 'style', 'controls', 'muted', 'loop', 'autoplay'],
	    ];
	    static $booleanAttrs = ['allowfullscreen', 'allowtransparency', 'controls', 'muted', 'playsinline', 'loop', 'autoplay'];

	    $tagName = strtolower($tagName);
	    if (!isset($allowedAttrs[$tagName])) {
	        return null;
	    }

	    // Tokenize name[=value] pairs the way a browser would (double-quoted,
	    // single-quoted or bare values). Anything that does not parse is dropped.
	    self::pregMatchAll('/([a-zA-Z][\w:.-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/', $attrs, $matches, PREG_SET_ORDER);

	    $safeAttrs = [];
	    $src = null;
	    foreach ($matches as $m) {
	        $name = strtolower($m[1]);
	        if (!in_array($name, $allowedAttrs[$tagName], true) || isset($safeAttrs[$name])) {
	            continue;
	        }
	        $value = ($m[2] ?? '') !== '' ? $m[2] : ((($m[3] ?? '') !== '') ? $m[3] : ($m[4] ?? ''));

	        if (in_array($name, $booleanAttrs, true)) {
	            $safeAttrs[$name] = $name;
	            continue;
	        }
	        if ($name === 'src') {
	            $src = $value;
	        } elseif ($name === 'poster' || $name === 'data-audio-src') {
	            if (!self::poznoteMediaSrcIsTrusted($value)) {
	                continue;
	            }
	        } elseif ($name === 'style') {
	            if (self::pregMatch('/expression\s*\(|javascript:|behavior\s*:|@import/i', $value)) {
	                continue;
	            }
	        }
	        $safeAttrs[$name] = $name . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
	    }

	    $srcTrusted = $src !== null && ($tagName === 'iframe'
	        ? self::poznoteIframeSrcIsTrusted($src)
	        : self::poznoteMediaSrcIsTrusted($src));
	    if (!$srcTrusted) {
	        return null;
	    }

	    return '<' . $tagName . ' ' . implode(' ', $safeAttrs) . '></' . $tagName . '>';
	}
}
