<?php
declare(strict_types=1);

// Security check - only allow from same domain
$allowedReferers = [
    $_SERVER['HTTP_HOST'] ?? '',
    'bremer-sitzbezuege.de',
    'partsell.de'
];

$referer = $_SERVER['HTTP_REFERER'] ?? '';
$isAllowed = false;

foreach ($allowedReferers as $allowed) {
    if (strpos($referer, $allowed) !== false || empty($referer)) {
        $isAllowed = true;
        break;
    }
}

if (!$isAllowed) {
    http_response_code(403);
    echo json_encode(['error' => true, 'message' => 'Access denied'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Simple JTL-Shop detection and inclusion
$jtlRoot = null;
$possibleRoots = [
    dirname(__DIR__, 4), // 4 levels up from frontend/ajax/
    dirname(__DIR__, 3), // 3 levels up
    dirname(__DIR__, 2), // 2 levels up
    dirname(__DIR__, 1), // 1 level up
];

foreach ($possibleRoots as $root) {
    if (file_exists($root . '/includes/globalinclude.php')) {
        $jtlRoot = $root;
        break;
    }
}

if (!$jtlRoot) {
    http_response_code(500);
    echo json_encode(['error' => true, 'message' => 'JTL-Shop not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Include JTL-Shop
require_once $jtlRoot . '/includes/globalinclude.php';
require_once PFAD_ROOT . 'includes/config.JTL-Shop.ini.php';
require_once PFAD_ROOT . 'includes/defines.php';
require_once PFAD_ROOT . 'includes/defines_local.php';
require_once PFAD_ROOT . 'includes/cachen.php';
require_once PFAD_ROOT . 'includes/boxes.php';
require_once PFAD_ROOT . 'includes/tools/seo.php';

use JTL\Shop;
use JTL\DB\DbInterface;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$out = ['query' => $q, 'results' => []];

function tokenize_query(string $query): array
{
    $sanitized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $query);
    $parts = preg_split('/\s+/u', (string)$sanitized, -1, PREG_SPLIT_NO_EMPTY);
    $parts = array_filter($parts ?? [], function (string $token): bool {
        $token = mb_strtolower($token);
        if (mb_strlen($token) < 2) {
            return false;
        }
        return !in_array($token, get_stop_words(), true);
    });

    $unique = array_unique(array_map('mb_strtolower', $parts));
    return array_values($unique);
}

function build_token_conditions(array $tokens, array &$params, array $fields, string $prefix): string
{
    if (empty($tokens)) {
        return '1=1';
    }

    $conditions = [];

    foreach ($tokens as $idx => $token) {
        $variants = create_token_variants($token);
        if (empty($variants)) {
            $variants = [$token];
        }

        $variantConditions = [];

        foreach ($variants as $vIdx => $variant) {
            $placeholder = $prefix . 'token' . $idx . '_' . $vIdx;
            $params[$placeholder] = build_like_value($variant, 'both');

            $fieldConditions = [];
            foreach ($fields as $field) {
                $fieldConditions[] = '(' . $field . ') COLLATE utf8mb4_general_ci LIKE :' . $placeholder;
            }

            if (!empty($fieldConditions)) {
                $variantConditions[] = '(' . implode(' OR ', $fieldConditions) . ')';
            }
        }

        if (!empty($variantConditions)) {
            $conditions[] = '(' . implode(' OR ', $variantConditions) . ')';
        }
    }

    return !empty($conditions) ? implode(' AND ', $conditions) : '1=1';
}

function create_token_variants(string $token): array
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

function build_like_value(string $variant, string $mode = 'both'): string
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

function get_stop_words(): array
{
    return [
        'für','fuer','der','die','das','und','oder','im','in','an','am','auf','aus',
        'mit','ohne','von','vom','zum','zur','ab','bis','bj','baujahr','premium',
        'set','komplettset','schonbezüge','schonbezuege','farbe','farben','braun',
        'beige','schwarz','grau','rot','blau','weiß','weiss','inkl','kpl','paket'
    ];
}

function is_important_token(string $token): bool
{
    $token = mb_strtolower($token);
    if (is_numeric($token)) {
        return true;
    }

    $keywords = ['bmw','audi','mercedes','vw','volkswagen','i5','i3','e81','fs5','ot404','ot405'];
    if (in_array($token, $keywords, true)) {
        return true;
    }

    return mb_strlen($token) >= 3 && !in_array($token, get_stop_words(), true);
}

function fetch_seo_for_articles(DbInterface $db, array $ids): array
{
    if (empty($ids)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rows = $db->getObjects(
        "SELECT kKey, cSeo FROM tseo WHERE cKey = 'kArtikel' AND kKey IN ($placeholders)",
        $ids
    );

    $map = [];
    foreach ($rows as $row) {
        $map[(int)$row->kKey] = $row->cSeo;
    }

    return $map;
}

function fetch_seo_for_categories(DbInterface $db, array $ids): array
{
    if (empty($ids)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rows = $db->getObjects(
        "SELECT kKey, cSeo FROM tseo WHERE cKey = 'kKategorie' AND kKey IN ($placeholders)",
        $ids
    );

    $map = [];
    foreach ($rows as $row) {
        $map[(int)$row->kKey] = $row->cSeo;
    }

    return $map;
}

function fetch_seo_for_manufacturers(DbInterface $db, array $ids): array
{
    if (empty($ids)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rows = $db->getObjects(
        "SELECT kKey, cSeo FROM tseo WHERE cKey = 'kHersteller' AND kKey IN ($placeholders)",
        $ids
    );

    $map = [];
    foreach ($rows as $row) {
        $map[(int)$row->kKey] = $row->cSeo;
    }

    return $map;
}

function ensure_indexes(DbInterface $db): void
{
    static $done = false;
    if ($done) {
        return;
    }

    try {
        $rows = $db->getObjects("SHOW INDEX FROM tartikel");
        $map = [];
        foreach ($rows as $row) {
            $map[(string)$row->Key_name] = true;
        }

        if (empty($map['idx_dsb_name'])) {
            $db->query("ALTER TABLE tartikel ADD INDEX idx_dsb_name (cName(191))");
        }

        if (empty($map['idx_dsb_artnr'])) {
            $db->query("ALTER TABLE tartikel ADD INDEX idx_dsb_artnr (cArtNr)");
        }

        if (empty($map['idx_dsb_suchbegriffe'])) {
            $db->query("ALTER TABLE tartikel ADD INDEX idx_dsb_suchbegriffe (cSuchbegriffe(191))");
        }
    } catch (Throwable $e) {
        error_log('[DSB] ensure_indexes tartikel: ' . $e->getMessage());
    }

    try {
        $seo = $db->getObjects("SHOW INDEX FROM tseo WHERE Key_name = 'idx_dsb_tseo'");
        if (empty($seo)) {
            $db->query("ALTER TABLE tseo ADD INDEX idx_dsb_tseo (cKey, kKey)");
        }
    } catch (Throwable $e) {
        error_log('[DSB] ensure_indexes tseo: ' . $e->getMessage());
    }

    $done = true;
}

try {
    /** @var DbInterface $db */
    $db = Shop::Container()->getDB();
    $start = microtime(true);

    // settings defaults
    $settings = [
        'dsb_enable_products'      => 'Y',
        'dsb_enable_categories'    => 'Y',
        'dsb_enable_manufacturers' => 'Y',
        'dsb_min_chars'            => '4',
        'dsb_limit_products'       => '8',
        'dsb_limit_categories'     => '3',
        'dsb_limit_manufacturers'  => '3',
    ];

    $pluginID = 'dynamic_search_bar';
    $plug = $db->getSingleObject("SELECT kPlugin FROM tplugin WHERE cPluginID = :pid LIMIT 1", ['pid' => $pluginID]);
    $kPlugin = isset($plug->kPlugin) ? (int)$plug->kPlugin : 0;

    if ($kPlugin > 0) {
        $rows = $db->getObjects(
            "SELECT cName, cWert FROM tplugineinstellungen WHERE kPlugin = :k",
            ['k' => $kPlugin]
        );
        foreach ($rows as $r) {
            $n = (string)$r->cName;
            $v = (string)$r->cWert;
            if ($n !== '') { $settings[$n] = $v; }
        }
    }

    $min = max(0, (int)$settings['dsb_min_chars']);
    if ($q === '' || mb_strlen($q) < $min) {
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $tokens = tokenize_query($q);
    $primaryTokens = [];
    $secondaryTokens = [];
    foreach ($tokens as $token) {
        if (is_important_token($token)) {
            $primaryTokens[] = $token;
        } else {
            $secondaryTokens[] = $token;
        }
    }

    $out['meta'] = [
        'counts' => [
            'product' => 0,
            'category' => 0,
            'manufacturer' => 0
        ],
        'timestamp' => time()
    ];

    // PRODUCTS (tartikel) - ORDER BY name to be safe
    if ($settings['dsb_enable_products'] === 'Y') {
        $limit = max(1, (int)$settings['dsb_limit_products']);

        ensure_indexes($db);

        $productParams = [];
        $primaryConditions = [];
        foreach ($primaryTokens as $idx => $term) {
            $containsPlaceholder = 'prod_primary_contains' . $idx;
            $prefixPlaceholder = 'prod_primary_prefix' . $idx;
            $productParams[$containsPlaceholder] = build_like_value($term, 'both');
            $productParams[$prefixPlaceholder] = build_like_value($term, 'prefix');

            $primaryConditions[] = '(a.cName COLLATE utf8mb4_general_ci LIKE :' . $containsPlaceholder . '
                OR IFNULL(a.cSuchbegriffe, '') COLLATE utf8mb4_general_ci LIKE :' . $containsPlaceholder . '
                OR IFNULL(a.cArtNr, '') COLLATE utf8mb4_general_ci LIKE :' . $prefixPlaceholder . ')';
        }

        if (empty($primaryConditions)) {
            $primaryConditions[] = '(a.cName COLLATE utf8mb4_general_ci LIKE :prodFallback)';
            $productParams['prodFallback'] = build_like_value($q, 'both');
        }

        $secondaryConditions = [];
        foreach ($secondaryTokens as $idx => $term) {
            $containsPlaceholder = 'prod_secondary_contains' . $idx;
            $productParams[$containsPlaceholder] = build_like_value($term, 'both');
            $secondaryConditions[] = 'a.cName COLLATE utf8mb4_general_ci LIKE :' . $containsPlaceholder;
        }

        $whereClause = implode(' AND ', $primaryConditions);
        if (!empty($secondaryConditions)) {
            $whereClause .= ' AND (' . implode(' OR ', $secondaryConditions) . ')';
        }

        $productRows = $db->getObjects(
            "SELECT DISTINCT a.kArtikel AS id,
                    a.cName AS label
             FROM tartikel a
             WHERE $whereClause
             ORDER BY a.cName ASC
             LIMIT " . $limit,
            $productParams
        );

        if (empty($productRows)) {
            $productRows = $db->getObjects(
                "SELECT DISTINCT a.kArtikel AS id, a.cName AS label
                 FROM tartikel a
                 WHERE a.cName COLLATE utf8mb4_general_ci LIKE :q
                 ORDER BY a.cName ASC
                 LIMIT " . $limit,
                ['q' => build_like_value($q, 'both')]
            );
        }

        $seoMap = fetch_seo_for_articles($db, array_map(static fn($row) => (int)$row->id, $productRows));
        foreach ($productRows as $row) {
            $id = (int)$row->id;
            $label = $row->label;
            $seo = $seoMap[$id] ?? null;
            $url = !empty($seo)
                ? '/' . ltrim($seo, '/')
                : '/index.php?a=' . $id;

            $out['results'][] = [
                'type' => 'product',
                'id' => $id,
                'label' => $label,
                'url' => $url
            ];
            $out['meta']['counts']['product']++;
        }
    }

    // CATEGORIES (tkategorie) - some installs don't have dErstellt -> order by name
    if ($settings['dsb_enable_categories'] === 'Y') {
        $limit = max(1, (int)$settings['dsb_limit_categories']);
        $categoryParams = [];
        $categoryCondition = build_token_conditions(
            $tokens,
            $categoryParams,
            [
                'k.cName'
            ],
            'cat'
        );

        $cats = $db->getObjects(
            "SELECT k.kKategorie AS id, k.cName AS label
             FROM tkategorie k
             WHERE $categoryCondition
             ORDER BY k.cName ASC
             LIMIT " . $limit,
            $categoryParams
        );
        $categorySeo = fetch_seo_for_categories($db, array_map(static fn($row) => (int)$row->id, $cats));
        foreach ($cats as $c) {
            $id = (int)$c->id;
            $seo = $categorySeo[$id] ?? null;
            $url = !empty($seo) ? '/' . ltrim($seo, '/') : '/kategorie.php?k=' . $id;
            $out['results'][] = ['type' => 'category', 'id' => $id, 'label' => $c->label, 'url' => $url];
            $out['meta']['counts']['category']++;
        }
    }

    // MANUFACTURERS (thersteller)
    if ($settings['dsb_enable_manufacturers'] === 'Y') {
        $limit = max(1, (int)$settings['dsb_limit_manufacturers']);
        $manufacturerParams = [];
        $manufacturerCondition = build_token_conditions(
            $tokens,
            $manufacturerParams,
            [
                'h.cName'
            ],
            'man'
        );

        $mans = $db->getObjects(
            "SELECT h.kHersteller AS id, h.cName AS label
             FROM thersteller h
             WHERE $manufacturerCondition
             ORDER BY h.cName ASC
             LIMIT " . $limit,
            $manufacturerParams
        );
        $manufacturerSeo = fetch_seo_for_manufacturers($db, array_map(static fn($row) => (int)$row->id, $mans));
        foreach ($mans as $m) {
            $id = (int)$m->id;
            $seo = $manufacturerSeo[$id] ?? null;
            $url = !empty($seo) ? '/' . ltrim($seo, '/') : '/hersteller.php?h=' . $id;
            $out['results'][] = ['type' => 'manufacturer', 'id' => $id, 'label' => $m->label, 'url' => $url];
            $out['meta']['counts']['manufacturer']++;
        }
    }

    error_log(sprintf('[DSB] suggest.php term="%s" products=%d categories=%d manufacturers=%d total=%d time=%.2fms tokens=%d',
        $q,
        $out['meta']['counts']['product'],
        $out['meta']['counts']['category'],
        $out['meta']['counts']['manufacturer'],
        count($out['results']),
        (microtime(true) - $start) * 1000,
        count($tokens)
    ));

    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
} catch (\Throwable $e) {
    // Log error for debugging
    error_log('[DSB] Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    
    http_response_code(500);
    echo json_encode([
        'query' => $q, 
        'error' => true, 
        'message' => 'Search service temporarily unavailable',
        'debug' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
