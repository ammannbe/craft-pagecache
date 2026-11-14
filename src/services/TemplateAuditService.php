<?php
/**
 * Page Cache plugin for Craft CMS
 *
 * @link      https://github.com/ammannbe/craft-pagecache
 * @copyright Copyright (c) 2022 Benjamin Ammann
 */

namespace suhype\pagecache\services;

use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use suhype\pagecache\PageCache;

/**
 * Finds template patterns that don't work well with a static page cache:
 * by scanning the site templates (file and line) and from what happened while pages were rendered (URL).
 *
 * @since 2.3.0
 */
class TemplateAuditService extends Component
{
    private const SCAN_CACHE_KEY = 'pagecache:templateScan';
    private const MAX_FILE_SIZE = 1024 * 1024;
    private const MAX_MATCHES = 20;

    /**
     * @var array<array>|null
     */
    private ?array $findings = null;

    /**
     * All findings, problems first
     *
     * @param bool $scan Scan the templates if there is no cached result (slow on big projects)
     * @return array<array{id: string, key: string, level: string, title: string, description: string, fix: string, files: array, urls: array, docs: ?string, prompt: string}>
     */
    public function getFindings(bool $scan = true): array
    {
        if ($this->findings !== null) {
            return $this->findings;
        }

        $matches = Craft::$app->getCache()->get(self::SCAN_CACHE_KEY);
        if ($matches === false) {
            if (!$scan) {
                return $this->buildFindings([]);
            }

            $matches = $this->scan();
            Craft::$app->getCache()->set(self::SCAN_CACHE_KEY, $matches, 3600);
        }

        return $this->findings = $this->buildFindings($matches);
    }

    /**
     * Scan the templates again (e.g. after fixing them)
     */
    public function rescan(): void
    {
        Craft::$app->getCache()->delete(self::SCAN_CACHE_KEY);
        $this->findings = null;
    }

    /**
     * One prompt for all findings, to paste into an AI coding assistant
     */
    public function getCombinedPrompt(): string
    {
        $findings = $this->getFindings();
        if (empty($findings)) {
            return '';
        }

        $parts = array_map(fn(array $finding, int $i) => '## ' . ($i + 1) . '. ' . $this->findingBrief($finding), $findings, array_keys($findings));

        return $this->promptIntro() . "\n\nFix the following findings, one after the other:\n\n" . implode("\n\n", $parts) . "\n\n" . $this->promptRules();
    }

    // Rules
    // =========================================================================

    /**
     * Template patterns: id => [regex, level]
     *
     * @return array<string, array{pattern: string, level: string}>
     */
    private function patterns(): array
    {
        return [
            // csrfInput() without async: true
            'csrfInput' => ['pattern' => '/csrfInput\((?![^)]*async\s*:\s*true)[^)]*\)/', 'level' => 'warning'],
            'csrfToken' => ['pattern' => '/\b(csrfToken|getCsrfToken\s*\()/', 'level' => 'warning'],
            'cacheTag' => ['pattern' => '/\{%-?\s*cache\b/', 'level' => 'info'],
            // Only inside Twig tags, so texts like "Book now." don't match
            'random' => ['pattern' => '/\{[{%][^}]*(RAND(OM)?\s*\(|\|\s*shuffle\b|\brandom\s*\()/i', 'level' => 'info'],
            'now' => ['pattern' => '/\{[{%][^}]*(\bnow\b|["\']now["\'])/', 'level' => 'info'],
            'paginate' => ['pattern' => '/\{%-?\s*paginate\b/', 'level' => 'info'],
            'cacheControl' => ['pattern' => '/\{%-?\s*header\s+["\']Cache-Control/i', 'level' => 'info'],
        ];
    }

    /**
     * Title, explanation and fix per finding
     *
     * @return array{title: string, description: string, fix: string, docs: ?string}
     */
    private function texts(string $id): array
    {
        $readme = DiagnosticsService::README_URL;

        return match ($id) {
            'csrfInput', 'csrfToken', DiagnosticsService::NOTICE_CSRF => [
                'title' => Craft::t('pagecache', 'Static CSRF token'),
                'description' => Craft::t('pagecache', 'The page uses a CSRF token while rendering. The token belongs to the visitor who rendered the page, so forms would fail for everyone else. These pages are not cached.'),
                'fix' => Craft::t('pagecache', 'Use `{{ csrfInput({ async: true }) }}` (or `asyncCsrfInputs` in the general config). For JavaScript, fetch the token from `/actions/users/session-info` instead of `craft.app.request.csrfToken`.'),
                'docs' => $readme . '#excluding-pages-and-tags',
            ],
            'cacheTag' => [
                'title' => Craft::t('pagecache', '`{% cache %}` tag'),
                'description' => Craft::t('pagecache', 'Content from Craft’s template cache runs no element queries, so dependency tracking doesn’t see it. Pages showing it may not be refreshed when its entries change. The page cache makes the template cache unnecessary.'),
                'fix' => Craft::t('pagecache', 'Remove the `{% cache %}` tags on pages that are cached by the page cache, or refresh those pages by tag (`craft.pagecache.tags()`).'),
                'docs' => $readme . '#dependency-tracking',
            ],
            'random', DiagnosticsService::NOTICE_RANDOM => [
                'title' => Craft::t('pagecache', 'Random content'),
                'description' => Craft::t('pagecache', 'Random order or random values are frozen in the cached page: every visitor gets the same “random” result until the page is refreshed.'),
                'fix' => Craft::t('pagecache', 'Output all candidates and pick or shuffle them with JavaScript in the browser, or accept that the result only changes when the page is refreshed.'),
                'docs' => null,
            ],
            'now' => [
                'title' => Craft::t('pagecache', 'Current date or time'),
                'description' => Craft::t('pagecache', '`now` is frozen in the cached page (e.g. “open now”, countdowns, “new” badges). Fine for things like the copyright year.'),
                'fix' => Craft::t('pagecache', 'Calculate time dependent output with JavaScript in the browser, or use post and expiry dates on entries (refreshed automatically).'),
                'docs' => null,
            ],
            'paginate' => [
                'title' => Craft::t('pagecache', 'Pagination'),
                'description' => Craft::t('pagecache', 'Page 1 is cached, further pages (e.g. `news/p2` or `?page=2`) are always rendered by Craft.'),
                'fix' => Craft::t('pagecache', 'Nothing to fix. For many visits on further pages, consider “load more” with JavaScript, or enable caching of query strings with included URL’s for `?page=`.'),
                'docs' => null,
            ],
            'cacheControl' => [
                'title' => Craft::t('pagecache', '`Cache-Control` header'),
                'description' => Craft::t('pagecache', 'The header only controls browsers and proxies, the page is still cached. Cached pages are served without the headers of the template.'),
                'fix' => Craft::t('pagecache', 'Use `{% do craft.pagecache.exclude() %}` to exclude a page from the page cache. Set browser cache headers for cached pages in the web server configuration.'),
                'docs' => $readme . '#cache-control-headers',
            ],
            DiagnosticsService::NOTICE_TRANSFORMS => [
                'title' => Craft::t('pagecache', 'Image transforms not generated yet'),
                'description' => Craft::t('pagecache', 'These pages contained image transform URLs which were not generated yet, so they were not cached.'),
                'fix' => Craft::t('pagecache', 'Set `generateTransformsBeforePageLoad` to `true` in `config/general.php`, so transforms are generated while rendering, and make sure the queue is running.'),
                'docs' => 'https://craftcms.com/docs/5.x/reference/config/general.html#generatetransformsbeforepageload',
            ],
            DiagnosticsService::NOTICE_QUERIES => [
                'title' => Craft::t('pagecache', 'Many element queries'),
                'description' => Craft::t('pagecache', 'These pages run more than {count} element queries, usually because related elements are loaded in a loop. Every uncached render and every regeneration is slow.', ['count' => DiagnosticsService::QUERY_COUNT_THRESHOLD]),
                'fix' => Craft::t('pagecache', 'Eager load relations and nested entries with `.with([…])` on the query, or `.eagerly()` on fields used in loops.'),
                'docs' => 'https://craftcms.com/docs/5.x/development/eager-loading.html',
            ],
            default => ['title' => $id, 'description' => '', 'fix' => '', 'docs' => null],
        };
    }

    // Scanning
    // =========================================================================

    /**
     * @return array<string, array<array{file: string, line: int, code: string}>> rule => matches
     */
    private function scan(): array
    {
        $path = Craft::getAlias('@templates', false);
        if (!$path || !is_dir($path)) {
            return [];
        }

        $root = rtrim((string) Craft::getAlias('@root'), '/') . '/';
        $rules = $this->patterns();
        $matches = [];

        $files = FileHelper::findFiles($path, [
            'only' => ['*.twig', '*.html'],
            'except' => ['node_modules/', 'vendor/', '.git/'],
        ]);
        sort($files);

        foreach ($files as $file) {
            if (filesize($file) > self::MAX_FILE_SIZE) {
                continue;
            }

            $relative = str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
            $lines = preg_split('/\R/', (string) file_get_contents($file));

            foreach ($lines as $index => $line) {
                // Twig comments
                if (preg_match('/^\s*\{#.*#\}\s*$/', $line)) {
                    continue;
                }

                foreach ($rules as $id => $rule) {
                    if (count($matches[$id] ?? []) < self::MAX_MATCHES && preg_match($rule['pattern'], $line)) {
                        $matches[$id][] = [
                            'file' => $relative,
                            'line' => $index + 1,
                            'code' => mb_substr(trim($line), 0, 160),
                        ];
                    }
                }
            }
        }

        return $matches;
    }

    // Findings
    // =========================================================================

    /**
     * @param array<string, array> $matches
     * @return array<array>
     */
    private function buildFindings(array $matches): array
    {
        $rules = $this->patterns();
        $stored = PageCache::$plugin->diagnosticsService->getStoredNotices();
        $generalConfig = Craft::$app->getConfig()->getGeneral();
        $findings = [];

        // CSRF: template matches and pages found while rendering in one finding
        $csrfFiles = array_merge($matches['csrfInput'] ?? [], $matches['csrfToken'] ?? []);
        $csrfUrls = array_keys($stored[DiagnosticsService::NOTICE_CSRF] ?? []);
        if (!empty($csrfFiles) || !empty($csrfUrls)) {
            // Templates with async inputs by default are fine
            if (!empty($csrfUrls) || !$generalConfig->asyncCsrfInputs || !empty($matches['csrfToken'])) {
                $findings[] = $this->finding('csrf', 'warning', $csrfFiles, $csrfUrls, DiagnosticsService::NOTICE_CSRF);
            }
        }

        if (!empty($stored[DiagnosticsService::NOTICE_TRANSFORMS]) && !$generalConfig->generateTransformsBeforePageLoad) {
            $findings[] = $this->finding(DiagnosticsService::NOTICE_TRANSFORMS, 'warning', [], array_keys($stored[DiagnosticsService::NOTICE_TRANSFORMS]));
        }

        if (!empty($stored[DiagnosticsService::NOTICE_QUERIES])) {
            $urls = [];
            foreach ($stored[DiagnosticsService::NOTICE_QUERIES] as $url => $count) {
                $urls[] = "{$url} ({$count})";
            }
            $findings[] = $this->finding(DiagnosticsService::NOTICE_QUERIES, 'info', [], $urls);
        }

        $randomUrls = array_keys($stored[DiagnosticsService::NOTICE_RANDOM] ?? []);
        if (!empty($matches['random']) || !empty($randomUrls)) {
            $findings[] = $this->finding('random', 'info', $matches['random'] ?? [], $randomUrls);
        }

        foreach (['cacheTag', 'now', 'paginate', 'cacheControl'] as $id) {
            if (!empty($matches[$id])) {
                $findings[] = $this->finding($id, $rules[$id]['level'], $matches[$id], []);
            }
        }

        usort($findings, fn($a, $b) => ($a['level'] === 'warning' ? 0 : 1) <=> ($b['level'] === 'warning' ? 0 : 1));

        return $findings;
    }

    private function finding(string $id, string $level, array $files, array $urls, ?string $textId = null): array
    {
        $finding = array_merge($this->texts($textId ?? $id), [
            'id' => $id,
            'level' => $level,
            'files' => $files,
            'urls' => array_slice($urls, 0, self::MAX_MATCHES),
        ]);

        $finding['key'] = $id . ':' . md5(serialize([$files, $urls]));
        $finding['prompt'] = $this->promptIntro() . "\n\n" . $this->findingBrief($finding) . "\n\n" . $this->promptRules();

        return $finding;
    }

    // AI prompts
    // =========================================================================

    private function promptIntro(): string
    {
        return 'You are working on a Craft CMS ' . Craft::$app->getVersion() . ' project. It uses the "Page Cache" plugin (suhype/craft-pagecache): '
            . 'pages are stored as static HTML files and served to guests without running Craft, logged-in users get the live pages. '
            . 'While a page renders, the plugin tracks its elements and element queries and refreshes the page when one of them changes. '
            . 'Templates are in `templates/`.';
    }

    private function findingBrief(array $finding): string
    {
        $text = "**{$finding['title']}**\n\nProblem: " . $this->plain($finding['description']) . "\n\nHow to fix: " . $this->plain($finding['fix']);

        if (!empty($finding['files'])) {
            $text .= "\n\nAffected template lines:\n" . implode("\n", array_map(
                fn(array $match) => "- `{$match['file']}:{$match['line']}`: `{$match['code']}`",
                $finding['files']
            ));
        }

        if (!empty($finding['urls'])) {
            $text .= "\n\nAffected pages (find the templates that render them):\n" . implode("\n", array_map(fn($url) => "- {$url}", $finding['urls']));
        }

        if (!empty($finding['docs'])) {
            $text .= "\n\nDocumentation: {$finding['docs']}";
        }

        return $text;
    }

    private function promptRules(): string
    {
        return "Rules:\n"
            . "- Check each location first; leave it unchanged if it is not a problem (e.g. `now` for a copyright year) and say why.\n"
            . "- Keep the output for visitors the same, change nothing unrelated.\n"
            . "- Explain each change briefly and show the diff. Don't commit.";
    }

    private function plain(string $markdown): string
    {
        return trim(preg_replace('/\s+/', ' ', $markdown));
    }
}
