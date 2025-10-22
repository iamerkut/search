<?php
declare(strict_types=1);

namespace Plugin\dynamic_search_bar;

use JTL\Plugin\Bootstrapper;
use JTL\Events\Dispatcher;
use JTL\Shop;
use JTL\ShopSetting;

class Bootstrap extends Bootstrapper
{
    private bool $logTableEnsured = false;
    private ?array $settingsCache = null;
    private array $stopWords = [
        'für', 'fuer', 'der', 'die', 'das', 'und', 'oder', 'im', 'in', 'an', 'am', 'auf', 'aus',
        'mit', 'ohne', 'von', 'vom', 'zum', 'zur', 'ab', 'bis', 'bj', 'baujahr', 'premium',
        'set', 'komplettset', 'schonbezüge', 'schonbezuege', 'farbe', 'farben', 'braun',
        'beige', 'schwarz', 'grau', 'rot', 'blau', 'weiß', 'weiss', 'inkl', 'kpl', 'paket'
    ];

    // JTL 5.x signature
    public function boot(Dispatcher $dispatcher): void
    {
        // Hook registration is automatic in JTL 5.x
        // hook213() will be called automatically when needed

        $this->ensureSearchLogTable();

        // Add custom route for search
        $this->addCustomRoute();
    }
    
    /**
     * Add custom route for search functionality
     */
    private function addCustomRoute(): void
    {
        // Check if we're in a search framework request
        if (isset($_GET['dsb_search']) && $_GET['dsb_search'] === '1') {
            $this->handleSearchRequest();
            exit;
        }

        // JS data requests (popular queries, suggestions etc.)
        if (isset($_GET['dsb_api']) && $_GET['dsb_api'] === '1') {
            $this->handleApiRequest();
            exit;
        }
    }
    
    /**
     * Handle search request directly
     */
    private function handleSearchRequest(): void
    {
        try {
            $this->ensureSearchLogTable();

            $qRaw = trim((string)($_GET['q'] ?? ''));
            $q = preg_replace('/\s+/u', ' ', $qRaw);
            $this->dsbCleanJsonOutput();
            
            if (empty($q) || mb_strlen($q) < 2) {
                $this->logQuery($q, 0, 'short');
                echo json_encode(['query' => $q, 'results' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                return;
            }
            
            /** @var \JTL\DB\DbInterface $db */
            $db = Shop::Container()->getDB();
            $settings = $this->getSettings();
            $results = [];
            $tokens = $this->tokenizeQuery($q);
            
            $counts = [
                'product' => 0,
                'category' => 0,
                'manufacturer' => 0
            ];

            // Products
            $customerGroupId = $this->getCustomerGroupId();
            $languageId = Shop::getLanguageID();

            $primaryTokens = [];
            $secondaryTokens = [];
            foreach ($tokens as $token) {
                if ($this->isImportantToken($token)) {
                    $primaryTokens[] = $token;
                } else {
                    $secondaryTokens[] = $token;
                }
            }

            $productParams = [];
            $primaryConditions = [];
            foreach ($primaryTokens as $idx => $term) {
                $containsPlaceholder = 'p_primary_contains' . $idx;
                $prefixPlaceholder = 'p_primary_prefix' . $idx;
                $productParams[$containsPlaceholder] = $this->buildLikeValue($term, 'both');
                $productParams[$prefixPlaceholder] = $this->buildLikeValue($term, 'prefix');

                $primaryConditions[] = '(a.cName COLLATE utf8mb4_general_ci LIKE :' . $containsPlaceholder . '
                    OR IFNULL(a.cSuchbegriffe, \'\') COLLATE utf8mb4_general_ci LIKE :' . $containsPlaceholder . '
                    OR IFNULL(a.cArtNr, \'\') COLLATE utf8mb4_general_ci LIKE :' . $prefixPlaceholder . '
                    OR IFNULL(s.cSeo, \'\') COLLATE utf8mb4_general_ci LIKE :' . $prefixPlaceholder . ')';
            }

            if (empty($primaryConditions)) {
                $primaryConditions[] = '(a.cName COLLATE utf8mb4_general_ci LIKE :primaryFallback)';
                $productParams['primaryFallback'] = $this->buildLikeValue($q, 'both');
            }

            $secondaryConditions = [];
            foreach ($secondaryTokens as $idx => $term) {
                $containsPlaceholder = 'p_secondary_contains' . $idx;
                $productParams[$containsPlaceholder] = $this->buildLikeValue($term, 'both');
                $secondaryConditions[] = 'a.cName COLLATE utf8mb4_general_ci LIKE :' . $containsPlaceholder;
            }

            $whereClause = implode(' AND ', $primaryConditions);
            if (!empty($secondaryConditions)) {
                $whereClause .= ' AND (' . implode(' OR ', $secondaryConditions) . ')';
            }

            $productSql = "SELECT DISTINCT a.kArtikel AS id,
                        a.cName AS label,
                        s.cSeo AS seo
                 FROM tartikel a
                 LEFT JOIN tseo s ON s.cKey = 'kArtikel' AND s.kKey = a.kArtikel
                 WHERE $whereClause
                 ORDER BY a.cName ASC
                 LIMIT 25";

            $rows = $db->getObjects($productSql, $productParams);

            foreach ($rows as $r) {
                $label = $r->label;
                $url = !empty($r->seo)
                    ? '/' . ltrim($r->seo, '/')
                    : '/index.php?a=' . (int)$r->id;

                $results[] = [
                    'type' => 'product',
                    'id' => (int)$r->id,
                    'label' => $label,
                    'url' => $url
                ];
                $counts['product']++;

                if ($counts['product'] >= 5) {
                    break;
                }
            }

            // Fallback for products if no results
            if ($counts['product'] === 0) {
                $fallbackRows = $db->getObjects(
                    "SELECT a.kArtikel AS id, a.cName AS label, s.cSeo AS seo
                     FROM tartikel a
                     LEFT JOIN tseo s ON s.cKey = 'kArtikel' AND s.kKey = a.kArtikel
                     WHERE a.cName COLLATE utf8mb4_general_ci LIKE :q
                     ORDER BY a.cName ASC
                     LIMIT 5",
                    ['q' => $this->buildLikeValue($q, 'both')]
                );
                foreach ($fallbackRows as $r) {
                    $url = !empty($r->seo) ? '/' . ltrim($r->seo, '/') : '/index.php?a=' . (int)$r->id;
                    $results[] = [
                        'type' => 'product',
                        'id' => (int)$r->id,
                        'label' => $r->label,
                        'url' => $url
                    ];
                    $counts['product']++;
                }
            }

            // Categories
            $categoryParams = [];
            $categoryCondition = $this->buildTokenConditions(
                $tokens,
                $categoryParams,
                [
                    'k.cName',
                    "IFNULL(s.cSeo, '')"
                ],
                'c'
            );

            $rows = $db->getObjects(
                "SELECT k.kKategorie AS id, k.cName AS label, s.cSeo AS seo
                 FROM tkategorie k
                 LEFT JOIN tseo s ON s.cKey = 'kKategorie' AND s.kKey = k.kKategorie
                 WHERE $categoryCondition
                 ORDER BY k.cName ASC
                 LIMIT 3",
                $categoryParams
            );
            foreach ($rows as $r) {
                $url = !empty($r->seo) ? '/' . ltrim($r->seo, '/') : '/kategorie.php?k=' . (int)$r->id;
                $results[] = [
                    'type' => 'category',
                    'id' => (int)$r->id,
                    'label' => $r->label,
                    'url' => $url
                ];
                $counts['category']++;
            }
            
            // Manufacturers
            $manufacturerParams = [];
            $manufacturerCondition = $this->buildTokenConditions(
                $tokens,
                $manufacturerParams,
                [
                    'h.cName',
                    "IFNULL(s.cSeo, '')"
                ],
                'm'
            );

            $rows = $db->getObjects(
                "SELECT h.kHersteller AS id, h.cName AS label, s.cSeo AS seo
                 FROM thersteller h
                 LEFT JOIN tseo s ON s.cKey = 'kHersteller' AND s.kKey = h.kHersteller
                 WHERE $manufacturerCondition
                 ORDER BY h.cName ASC
                 LIMIT 3",
                $manufacturerParams
            );
            foreach ($rows as $r) {
                $url = !empty($r->seo) ? '/' . ltrim($r->seo, '/') : '/hersteller.php?h=' . (int)$r->id;
                $results[] = [
                    'type' => 'manufacturer',
                    'id' => (int)$r->id,
                    'label' => $r->label,
                    'url' => $url
                ];
                $counts['manufacturer']++;
            }

            $payload = [
                'query' => $q,
                'results' => $results,
                'meta' => [
                    'counts' => $counts,
                    'timestamp' => time()
                ]
            ];

            $status = count($results) > 0 ? 'ok' : 'empty';
            $this->logQuery($q, count($results), $status);
            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            
        } catch (\Throwable $e) {
            error_log('[DSB] Search error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => true, 'message' => 'Search failed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (isset($q)) {
                $this->logQuery($q, 0, 'error');
            }
        }
    }

    private function handleApiRequest(): void
    {
        $this->dsbCleanJsonOutput();
        $this->ensureSearchLogTable();

        $action = $_GET['action'] ?? '';
        switch ($action) {
            case 'popular':
                $limit = max(1, (int)($_GET['limit'] ?? 8));
                $popular = $this->getPopularQueries($limit);
                echo json_encode(['popular' => $popular], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                break;
            case 'suggestions':
                $prefix = trim((string)($_GET['prefix'] ?? ''));
                $limit = max(1, (int)($_GET['limit'] ?? 5));
                $suggestions = $this->getSuggestions($prefix, $limit);
                echo json_encode(['suggestions' => $suggestions], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                break;
            default:
                http_response_code(400);
                echo json_encode(['error' => true, 'message' => 'Unknown action'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    private function getPopularQueries(int $limit = 8): array
    {
        try {
            /** @var \JTL\DB\DbInterface $db */
            $db = Shop::Container()->getDB();
            $limit = max(1, min(50, $limit));
            $rows = $db->getObjects(
                "SELECT cQueryDisplay AS query, nHits AS hits, dLastSearch AS lastSearch
                 FROM xplugin_dsb_search_log
                 ORDER BY nHits DESC, dLastSearch DESC
                 LIMIT " . $limit
            );

            return array_map(static function($row){
                return [
                    'query' => $row->query,
                    'hits' => (int)$row->hits,
                    'lastSearch' => $row->lastSearch
                ];
            }, $rows);
        } catch (\Throwable $e) {
            error_log('[DSB] getPopularQueries failed: ' . $e->getMessage());
            return [];
        }
    }

    private function getSuggestions(string $prefix, int $limit = 5): array
    {
        if ($prefix === '') {
            return [];
        }

        try {
            /** @var \JTL\DB\DbInterface $db */
            $db = Shop::Container()->getDB();
            $limit = max(1, min(50, $limit));
            $rows = $db->getObjects(
                "SELECT cQueryDisplay AS query, nHits AS hits
                 FROM xplugin_dsb_search_log
                 WHERE cQuery LIKE :prefix
                 ORDER BY nHits DESC
                 LIMIT " . $limit,
                ['prefix' => $prefix . '%']
            );

            return array_map(static function($row){
                return [
                    'query' => $row->query,
                    'hits' => (int)$row->hits
                ];
            }, $rows);
        } catch (\Throwable $e) {
            error_log('[DSB] getSuggestions failed: ' . $e->getMessage());
            return [];
        }
    }

    private function logQuery(string $query, int $resultCount = 0, string $status = 'ok'): void
    {
        try {
            if ($query === '') {
                return;
            }

            $this->ensureSearchLogTable();

            /** @var \JTL\DB\DbInterface $db */
            $db = Shop::Container()->getDB();
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $queryLower = mb_strtolower($query);
            if (mb_strlen($queryLower) > 255) {
                $queryLower = mb_substr($queryLower, 0, 255);
            }

            $queryDisplay = $query;
            if (mb_strlen($queryDisplay) > 255) {
                $queryDisplay = mb_substr($queryDisplay, 0, 255);
            }

            $db->queryPrepared(
                "INSERT INTO xplugin_dsb_search_log (cQuery, cQueryDisplay, nHits, nResults, dLastSearch, cStatus)
                 VALUES (:query, :display, 1, :results, :now, :status)
                 ON DUPLICATE KEY UPDATE
                   nHits = nHits + 1,
                   nResults = :results,
                   dLastSearch = :now,
                   cStatus = :status,
                   cQueryDisplay = :display",
                [
                    'query' => $queryLower,
                    'display' => $queryDisplay,
                    'results' => $resultCount,
                    'now' => $now,
                    'status' => $status
                ]
            );
        } catch (\Throwable $e) {
            error_log('[DSB] logQuery failed: ' . $e->getMessage());
        }
    }

    private function getCustomerGroupId(): int
    {
        try {
            $customer = Shop::Customer();
            if ($customer !== null && $customer->getGroupID() > 0) {
                return (int)$customer->getGroupID();
            }

            $defaultGroup = Shop::getSettingValue('global', 'kundengruppe_standard');
            return $defaultGroup !== null ? (int)$defaultGroup : 1;
        } catch (\Throwable $e) {
            error_log('[DSB] getCustomerGroupId failed: ' . $e->getMessage());
            return 1;
        }
    }

    private function ensureSearchLogTable(): void
    {
        if ($this->logTableEnsured) {
            return;
        }

        try {
            /** @var \JTL\DB\DbInterface $db */
            $db = Shop::Container()->getDB();
            $db->query(
                "CREATE TABLE IF NOT EXISTS xplugin_dsb_search_log (
                    cQuery VARCHAR(255) NOT NULL,
                    cQueryDisplay VARCHAR(255) NOT NULL,
                    nHits INT UNSIGNED NOT NULL DEFAULT 1,
                    nResults INT UNSIGNED NOT NULL DEFAULT 0,
                    dLastSearch DATETIME NOT NULL,
                    cStatus VARCHAR(32) NOT NULL DEFAULT 'ok',
                    PRIMARY KEY (cQuery),
                    KEY idx_hits (nHits),
                    KEY idx_lastSearch (dLastSearch)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            $this->logTableEnsured = true;
        } catch (\Throwable $e) {
            error_log('[DSB] ensureSearchLogTable failed: ' . $e->getMessage());
        }
    }

    private function tokenizeQuery(string $query): array
    {
        $sanitized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $query);
        $parts = preg_split('/\s+/u', (string)$sanitized, -1, PREG_SPLIT_NO_EMPTY);
        $parts = array_filter($parts ?? [], function (string $token): bool {
            $token = mb_strtolower($token);
            if (mb_strlen($token) < 2) {
                return false;
            }
            return !in_array($token, $this->stopWords, true);
        });

        $unique = array_unique(array_map('mb_strtolower', $parts));
        return array_values($unique);
    }

    private function buildTokenConditions(array $tokens, array &$params, array $fields, string $prefix): string
    {
        if (empty($tokens)) {
            return '1=1';
        }

        $conditions = [];

        foreach ($tokens as $idx => $token) {
            $variant = $this->createTokenVariants($token)[0] ?? $token;
            $placeholder = $prefix . 'token' . $idx;
            $params[$placeholder] = $this->buildLikeValue($variant, 'both');

            $fieldConditions = [];
            foreach ($fields as $field) {
                $fieldConditions[] = $field . ' COLLATE utf8mb4_general_ci LIKE :' . $placeholder;
            }

            if (!empty($fieldConditions)) {
                $conditions[] = '(' . implode(' OR ', $fieldConditions) . ')';
            }
        }

        return !empty($conditions) ? implode(' AND ', $conditions) : '1=1';
    }

    private function createTokenVariants(string $token): array
    {
        $base = trim($token);
        if ($base === '') {
            return [];
        }

        $variants = [$base];

        $transliterated = strtr($base, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        if ($transliterated !== $base) {
            $variants[] = $transliterated;
        }

        $variants = array_filter(array_unique($variants), static function (string $variant): bool {
            $clean = str_replace(['%', '_'], '', $variant);
            return $variant !== '' && mb_strlen($clean) >= 2;
        });

        return array_values($variants);
    }

    private function isImportantToken(string $token): bool
    {
        $token = mb_strtolower($token);
        if (is_numeric($token)) {
            return true;
        }

        $keywords = ['bmw', 'audi', 'mercedes', 'vw', 'volkswagen', 'i5', 'i3', 'e81', 'fs5', 'ot404', 'ot405'];
        if (in_array($token, $keywords, true)) {
            return true;
        }

        return mb_strlen($token) >= 3 && !in_array($token, $this->stopWords, true);
    }

    /**
     * @param list<string> $chars
     * @return string[]
     */
    private function generatePermutations(array $chars): array
    {
        $count = count($chars);
        if ($count <= 1) {
            return [implode('', $chars)];
        }

        $result = [];
        foreach ($chars as $index => $char) {
            $remaining = $chars;
            unset($remaining[$index]);
            $remaining = array_values($remaining);

            foreach ($this->generatePermutations($remaining) as $perm) {
                $result[] = $char . $perm;
            }
        }

        return array_values(array_unique($result));
    }

    private function buildLikeValue(string $variant, string $mode = 'both'): string
    {
        $value = trim($variant);
        if ($value === '') {
            return '%';
        }

        if (str_contains($value, '%') || str_contains($value, '_')) {
            return $value;
        }

        return match ($mode) {
            'prefix' => $value . '%',
            'suffix' => '%' . $value,
            default => '%' . $value . '%',
        };
    }

    /**
     * Ensure clean JSON output (no BOM/whitespace)
     */
    private function dsbCleanJsonOutput(): void
    {
        // Clean all output buffers
        while (ob_get_level() > 0) { 
            @ob_end_clean(); 
        }
        
        // Clear any existing output
        if (ob_get_contents()) {
            ob_clean();
        }
        
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('X-DSB: ok');
        }
    }

    /**
     * HOOK_IO_HANDLE_REQUEST (213)
     * Handles /io.php?io=dsb_ping and /io.php?io=dsb_suggest&q=...
     *
     * @param array $args
     * @return void|null
     */
    public function hook213(array $args)
    {
        try {
            // Check if this is our request
            $io = $_GET['io'] ?? null;
            if ($io !== 'dsb_ping' && $io !== 'dsb_suggest') {
                return null; // Not our request, let other handlers process it
            }
            
            // Debug: Log hook calls
            error_log('[DSB] Hook213 called with io=' . ($io ?? 'null'));
            
            // Force output buffering cleanup
            if (ob_get_level()) {
                ob_end_clean();
            }

            if ($io === 'dsb_ping') {
                $this->dsbCleanJsonOutput();
                echo json_encode(['ok' => true, 'ts' => time()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }

            if ($io === 'dsb_suggest') {
                $this->handleSearchRequest();
                exit;
            }

            return null;
        } catch (\Throwable $e) {
            $this->dsbCleanJsonOutput();
            http_response_code(500);
            echo json_encode(['error' => true, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }

    private function getSettings(): array
    {
        if ($this->settingsCache !== null) {
            return $this->settingsCache;
        }

        $settings = [
            'dsb_enable_products'      => 'Y',
            'dsb_enable_categories'    => 'Y',
            'dsb_enable_manufacturers' => 'Y',
            'dsb_min_chars'            => '3',
            'dsb_limit_products'       => '5',
            'dsb_limit_categories'     => '3',
            'dsb_limit_manufacturers'  => '3',
        ];

        try {
            /** @var \JTL\DB\DbInterface $db */
            $db = Shop::Container()->getDB();
            $rows = $db->getObjects(
                "SELECT cName, cWert FROM tplugineinstellungen WHERE kPlugin = :pid",
                ['pid' => $this->getPlugin()->getID()]
            );
            foreach ($rows as $row) {
                $name = (string)$row->cName;
                if ($name !== '') {
                    $settings[$name] = (string)$row->cWert;
                }
            }
        } catch (\Throwable $e) {
            error_log('[DSB] getSettings failed: ' . $e->getMessage());
        }

        return $this->settingsCache = $settings;
    }
}
