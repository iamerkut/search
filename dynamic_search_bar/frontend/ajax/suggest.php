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

try {
    /** @var \JTL\DB\DbInterface $db */
    $db = Shop::Container()->getDB();

    // settings defaults
    $settings = [
        'dsb_enable_products'      => 'Y',
        'dsb_enable_categories'    => 'Y',
        'dsb_enable_manufacturers' => 'Y',
        'dsb_min_chars'            => '3',
        'dsb_limit_products'       => '5',
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

    // PRODUCTS (tartikel) - ORDER BY name to be safe
    if ($settings['dsb_enable_products'] === 'Y') {
        $limit = max(1, (int)$settings['dsb_limit_products']);
        $customerGroupId = Shop::Customer() !== null && Shop::Customer()->getGroupID() > 0
            ? (int)Shop::Customer()->getGroupID()
            : (int)(Shop::getSettingValue('global', 'kundengruppe_standard') ?? 1);

        $productParams = [];
        $primaryConditions = [];
        foreach ($primaryTokens as $idx => $term) {
            $containsPlaceholder = 'prod_primary_contains' . $idx;
            $prefixPlaceholder = 'prod_primary_prefix' . $idx;
            $productParams[$containsPlaceholder] = build_like_value($term, 'both');
            $productParams[$prefixPlaceholder] = build_like_value($term, 'prefix');

            $primaryConditions[] = '(a.cName COLLATE utf8mb4_general_ci LIKE :' . $containsPlaceholder . '
                OR IFNULL(a.cSuchbegriffe, '') COLLATE utf8mb4_general_ci LIKE :' . $containsPlaceholder . '
                OR IFNULL(a.cArtNr, '') COLLATE utf8mb4_general_ci LIKE :' . $prefixPlaceholder . '
                OR IFNULL(s.cSeo, '') COLLATE utf8mb4_general_ci LIKE :' . $prefixPlaceholder . ')';
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
                    a.cName AS label,
                    s.cSeo AS seo
             FROM tartikel a
             LEFT JOIN tseo s ON s.cKey = 'kArtikel' AND s.kKey = a.kArtikel
             WHERE $whereClause
             ORDER BY a.cName ASC
             LIMIT " . $limit,
            $productParams
        );

        foreach ($productRows as $row) {
            $label = $row->label;
            $url = !empty($row->seo)
                ? '/' . ltrim($row->seo, '/')
                : '/index.php?a=' . (int)$row->id;

            $out['results'][] = [
                'type' => 'product',
                'id' => (int)$row->id,
                'label' => $label,
                'url' => $url
            ];
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
                'k.cName',
                "IFNULL(s.cSeo, '')"
            ],
            'cat'
        );

        $cats = $db->getObjects(
            "SELECT k.kKategorie AS id, k.cName AS label, s.cSeo AS seo
             FROM tkategorie k
             LEFT JOIN tseo s ON s.cKey = 'kKategorie' AND s.kKey = k.kKategorie
             WHERE $categoryCondition
             ORDER BY k.cName ASC
             LIMIT " . $limit,
            $categoryParams
        );
        foreach ($cats as $c) {
            $url = !empty($c->seo) ? '/' . ltrim($c->seo, '/') : '/kategorie.php?k=' . (int)$c->id;
            $out['results'][] = ['type' => 'category', 'id' => (int)$c->id, 'label' => $c->label, 'url' => $url];
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
                'h.cName',
                "IFNULL(s.cSeo, '')"
            ],
            'man'
        );

        $mans = $db->getObjects(
            "SELECT h.kHersteller AS id, h.cName AS label, s.cSeo AS seo
             FROM thersteller h
             LEFT JOIN tseo s ON s.cKey = 'kHersteller' AND s.kKey = h.kHersteller
             WHERE $manufacturerCondition
             ORDER BY h.cName ASC
             LIMIT " . $limit,
            $manufacturerParams
        );
        foreach ($mans as $m) {
            $url = !empty($m->seo) ? '/' . ltrim($m->seo, '/') : '/hersteller.php?h=' . (int)$m->id;
            $out['results'][] = ['type' => 'manufacturer', 'id' => (int)$m->id, 'label' => $m->label, 'url' => $url];
        }
    }

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
