<?php
/**
 * Operator site config for the classic console chrome (lib/site_config.php).
 *
 * htdocs/config.json is a static file the operator edits directly in
 * the Apache docroot — the same document the SPA fetches at
 * /config.json (ui/src/siteConfig.js). It carries two keys:
 *
 *   logo  URL or path of a brand image (e.g. /assets/logo.png). Empty,
 *         missing or null keeps the text brand "wanportal".
 *   menu  the top-nav tree: [{label, to?, href?, children?}]. `to` is
 *         an in-app SPA hash route; `href` is a full path or external
 *         door; children nest the same shape.
 *
 * The file is operator input, so nothing here trusts it: every field
 * is type-checked and junk is dropped, and every failure path —
 * missing file, unreadable file, empty body, non-JSON body, JSON that
 * is not an object — lands on the same default {logo: '', menu: []}
 * the SPA's normalizeConfig() uses, so a broken config can never
 * fatal the console; the chrome just renders its built-in brand and
 * nav. This file defines exactly one function and is safe to include
 * unconditionally.
 */

/**
 * Defensive parse of the operator's site config (htdocs/config.json).
 *
 * Mirrors ui/src/siteConfig.js normalizeConfig(): a non-string or
 * whitespace-only logo is no logo; menu keeps only well-formed entries
 * (label required, to/href kept as trimmed strings, children recursed,
 * label-only entries kept as inert items); every failure — missing
 * file, unreadable file, empty body, non-JSON body, JSON that is not
 * an object — returns the default instead of throwing. Never throws.
 *
 * Candidate paths, in order: the config.json beside this file's
 * htdocs dir (__DIR__ . '/../../config.json' — resolves both in the
 * container's bind-mounted live tree at /srv/htdocs and in the host
 * checkout), then the host checkout absolute path.
 *
 * @param string|null $path Optional explicit config path (used by the
 *                          tests); null reads the standard
 *                          htdocs/config.json candidates.
 *
 * @return array{logo: string, menu: array} logo is '' when unset;
 *         menu is a list of normalized entries, each
 *         {label, to?, href?, children?}.
 */
function wanportal_site_config(?string $path = null): array
{
    $default = ['logo' => '', 'menu' => []];

    try {
        $raw = null;
        $candidates = $path !== null
            ? [$path]
            : [__DIR__ . '/../../config.json', '/srv/wanportal/htdocs/config.json'];
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || !is_file($candidate) || !is_readable($candidate)) {
                continue;
            }
            $body = @file_get_contents($candidate);
            if (is_string($body) && trim($body) !== '') {
                $raw = $body;
                break;
            }
        }
        if (!is_string($raw)) {
            return $default;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            // null (JSON null), a list, a scalar or garbage: default.
            return $default;
        }

        $logo = '';
        if (isset($data['logo']) && is_string($data['logo']) && trim($data['logo']) !== '') {
            $logo = trim($data['logo']);
        }

        // Normalizer: mirrors the SPA's normalizeMenu/normalizeEntry.
        // Bad fields are dropped, an entry survives on its label, and
        // children recurse through the same rules.
        $normMenu = null;
        $normMenu = static function ($rawMenu) use (&$normMenu): array {
            if (!is_array($rawMenu)) {
                return [];
            }
            $out = [];
            foreach ($rawMenu as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $label = (isset($entry['label']) && is_string($entry['label']) && trim($entry['label']) !== '')
                    ? trim($entry['label'])
                    : '';
                if ($label === '') {
                    continue; // entries without a label are ignored
                }
                $item = ['label' => $label];
                foreach (['to', 'href'] as $key) {
                    if (isset($entry[$key]) && is_string($entry[$key]) && trim($entry[$key]) !== '') {
                        $item[$key] = trim($entry[$key]);
                    }
                }
                $kids = $normMenu($entry['children'] ?? null);
                if ($kids !== []) {
                    $item['children'] = $kids;
                }
                $out[] = $item;
            }
            return $out;
        };

        return ['logo' => $logo, 'menu' => $normMenu($data['menu'] ?? null)];
    } catch (Throwable $e) {
        return $default;
    }
}