#!/usr/bin/env php
<?php
declare(strict_types=1);
ini_set('memory_limit', '512M');

// php sync.php          normale run (hervat als state.json bestaat)
// php sync.php --reset  opnieuw beginnen (wist state, cache en log)
// php sync.php --dump   print alle raw velden van het eerste Solar product

const STATE_FILE      = __DIR__ . '/state.json';
const CACHE_FILE      = __DIR__ . '/sku_cache.json';
const LOG_FILE        = __DIR__ . '/sync.log';
const SOLAR_TOKEN_URL = 'https://sgidp.b2clogin.com/sgidp.onmicrosoft.com/b2c_1a_client_credentials/oauth2/v2.0/token';
const SOLAR_API_BASE  = 'https://api.solar.eu/procurement/V1';
const PRICE_BATCH      = 50; 
const WC_BATCH         = 100;
const PARALLEL_BATCHES = 8;
const PAGE_LIMIT       = 1000;
const MAX_ERRORS       = 3;
const PRODUCTS_FILE   = __DIR__ . '/products.jsonl';

// ── Categorieën om te synchroniseren ─────────────────────────────────────────
// Solar category ID => naam die in WooCommerce wordt aangemaakt/gebruikt
const SYNC_CATEGORIES = [
    2385772 => 'Aluminiumkabel',
    1295840 => 'Luidsprekersnoer',
    // 9876543 => 'Verlichtingsarmaturen',
];

// ─────────────────────────────────────────────────────────────────────────────

class Logger
{
    private static function isTty(): bool
    {
        return function_exists('posix_isatty') && posix_isatty(STDOUT);
    }

    private static function ansi(string $code, string $text): string
    {
        return self::isTty() ? "\033[{$code}m{$text}\033[0m" : $text;
    }

    private static function write(string $level, string $ansiCode, string $msg): void
    {
        $ts = date('Y-m-d H:i:s');
        echo sprintf('[%s] %s %s', self::ansi('2', $ts), self::ansi($ansiCode, sprintf('%-9s', $level)), $msg) . PHP_EOL;
        file_put_contents(LOG_FILE, sprintf('[%s] %-9s %s', $ts, $level, $msg) . PHP_EOL, FILE_APPEND);
    }

    public static function info(string $msg): void  { self::write('INFO',    '2',  $msg); }
    public static function ok(string $msg): void    { self::write('OK',      '32', $msg); }
    public static function warn(string $msg): void  { self::write('WARNING', '33', $msg); }
    public static function error(string $msg): void { self::write('ERROR',   '31', $msg); }

    public static function section(string $title): void
    {
        $bar = '═══════════════════════════════════════════════════════';
        echo PHP_EOL . self::ansi('36', $bar) . PHP_EOL
           . self::ansi('1;36', "  $title") . PHP_EOL
           . self::ansi('36', $bar) . PHP_EOL . PHP_EOL;
        file_put_contents(LOG_FILE, PHP_EOL . "=== $title ===" . PHP_EOL, FILE_APPEND);
    }

    public static function divider(): void
    {
        echo self::ansi('2', '  ─────────────────────────────────────────────────') . PHP_EOL;
    }
}

// ─────────────────────────────────────────────────────────────────────────────

class Env
{
    public static function load(string $file): void
    {
        if (!file_exists($file)) {
            Logger::info(".env niet gevonden ($file) — omgevingsvariabelen worden gebruikt");
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            [$key, $val] = explode('=', $line, 2) + [1 => ''];
            $_ENV[trim($key)] = trim($val);
        }
    }

    public static function get(string $key, string $default = ''): string
    {
        return $_ENV[$key] ?? (getenv($key) ?: $default);
    }

    public static function require(string ...$keys): void
    {
        foreach ($keys as $key) {
            if (self::get($key) === '') {
                Logger::error("Verplichte .env variabele ontbreekt: $key");
                exit(1);
            }
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────

class Http
{
    public static function get(string $url, array $headers = [], int $timeout = 30): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);

        if ($body === false) {
            return ['ok' => false, 'error' => $error, 'status' => 0, 'body' => null, 'raw' => ''];
        }
        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => json_decode($body, true), 'raw' => $body];
    }

    public static function post(string $url, array $payload, array $headers = [], int $timeout = 30): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
        ]);
        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);

        if ($body === false) {
            return ['ok' => false, 'error' => $error, 'status' => 0, 'body' => null, 'raw' => ''];
        }
        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => json_decode($body, true), 'raw' => $body];
    }
}

// ─────────────────────────────────────────────────────────────────────────────

class SolarApi
{
    private static string $token           = '';
    private static int    $expires         = 0;
    private static int    $lastErrorStatus = 0;

    public static function getLastErrorStatus(): int { return self::$lastErrorStatus; }

    public static function token(): string
    {
        if (time() < self::$expires - 60) return self::$token;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            if ($attempt > 1) {
                Logger::warn("Solar token: poging $attempt/3 (10s wachten)...");
                sleep(10);
            } else {
                Logger::info('Solar: token ophalen...');
            }

            $ch = curl_init(SOLAR_TOKEN_URL);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query([
                    'grant_type'    => 'client_credentials',
                    'client_id'     => Env::get('CLIENT_ID'),
                    'client_secret' => Env::get('CLIENT_SECRET'),
                    'scope'         => 'https://sgidp.onmicrosoft.com/api.procurement/.default',
                ]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            ]);
            $body   = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $cerror = curl_error($ch);

            if ($body === false) {
                Logger::error("Solar token cURL fout: $cerror");
                continue;
            }

            $data = json_decode($body, true);
            if ($status === 200 && !empty($data['access_token'])) {
                self::$token   = $data['access_token'];
                self::$expires = time() + (int)($data['expires_in'] ?? 3600);
                Logger::ok('Solar: token ontvangen (geldig tot ' . date('H:i:s', self::$expires) . ')');
                return self::$token;
            }
            Logger::error("Solar token mislukt (HTTP $status): " . substr($body, 0, 300));
        }

        Logger::error('Solar token ophalen mislukt na 3 pogingen.');
        exit(1);
    }

    public static function headers(): array
    {
        return ['Authorization: Bearer ' . self::token(), 'Accept: application/json'];
    }

    public static function getPage(?string $nextpage): ?array
    {
        $url = SOLAR_API_BASE . '/catalogs/' . rawurlencode(Env::get('CATALOG_ID')) . '/products'
             . '?countrycode=' . Env::get('COUNTRY_CODE') . '&limit=' . PAGE_LIMIT
             . ($nextpage ? '&nextpage=' . urlencode($nextpage) : '');

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => self::headers(),
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $response    = curl_exec($ch);
        $status      = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $curlError   = curl_error($ch);

        if ($response === false) {
            Logger::error("Solar cURL fout: $curlError");
            return null;
        }

        $headersRaw = substr($response, 0, $headerSize);
        $bodyRaw    = substr($response, $headerSize);
        unset($response); // geef het grote response-object vrij

        if ($status < 200 || $status >= 300) {
            self::$lastErrorStatus = $status;
            Logger::error("Solar HTTP $status: " . substr($bodyRaw, 0, 300));
            return null;
        }
        self::$lastErrorStatus = 0;

        $next = null;
        if (preg_match('/[?&]nextpage=([^&>\s]+)/i', $headersRaw, $m)) {
            $next = $m[1];
        }
        unset($headersRaw);

        $body  = json_decode($bodyRaw, true) ?? [];
        unset($bodyRaw); // geef de raw JSON-string vrij na het decoderen
        $items = isset($body[0])
            ? $body
            : ($body['products']['product'] ?? $body['products'] ?? $body['items'] ?? []);

        $allowedCategories = array_keys(SYNC_CATEGORIES);

        $products = [];
        foreach ($items as $item) {
            if ((string)($item['ProductStatus'] ?? '') === '40') continue;

            if (!empty($allowedCategories)) {
                $catId = (int)($item['CategoryId'] ?? $item['categoryId'] ?? 0);
                if (!in_array($catId, $allowedCategories, true)) continue;
            }

            $sap = trim($item['SapMaterialNumber'] ?? '');
            $sku = trim($item['ElectricalNo'] ?? '');
            if ($sap === '' || $sku === '') continue;

            $imgRaw  = $item['ImageLinks'] ?? '';
            $imgList = is_array($imgRaw) ? $imgRaw : (json_decode($imgRaw, true) ?? []);
            usort($imgList, fn($a, $b) => ($a['LinkNumber'] ?? 0) <=> ($b['LinkNumber'] ?? 0));
            $images = [];
            foreach ($imgList as $img) {
                $url = trim($img['Url'] ?? $img['url'] ?? '');
                if ($url !== '') $images[] = preg_replace('/\.tiff?$/i', '.jpg', $url);
            }

            $etimRaw    = $item['EtimFeatures'] ?? '';
            $etim       = is_array($etimRaw) ? $etimRaw : (json_decode($etimRaw, true) ?? []);
            $attributes = [];
            foreach ($etim['Features'] ?? [] as $f) {
                $fname = trim((string)($f['Name'] ?? ''));
                $fval  = trim((string)($f['PrintedValue'] ?? $f['Value'] ?? ''));
                if ($fname !== '' && $fval !== '') $attributes[$fname] = $fval;
            }

            $products[] = [
                'sap'          => $sap,
                'sku'          => $sku,
                'name'         => trim((string)($item['MaterialDescription'] ?? $item['MaterialName'] ?? $sku)),
                'description'  => trim((string)($item['LongProductDescription'] ?? '')),
                'brand'        => trim((string)($item['BrandName'] ?? '')),
                'gtin'         => trim((string)($item['Gtin'] ?? '')),
                'mpn'          => trim((string)($item['ManufacturerPartNumber'] ?? '')),
                'weight'       => trim((string)($item['GrossWeight'] ?? '')),
                'series'       => trim((string)($item['Series'] ?? '')),
                'images'       => $images,
                'attributes'   => $attributes,
                'last_changed' => (int)($item['LastChanged'] ?? 0),
                'category_id'  => (int)($item['CategoryId'] ?? $item['categoryId'] ?? 0),
            ];
        }

        return [$products, $next];
    }

    public static function getPrices(array $sapNumbers): array
    {
        if (empty($sapNumbers)) return [];

        $chunks  = array_chunk($sapNumbers, PRICE_BATCH);
        $baseUrl = SOLAR_API_BASE . '/products/prices?accountId=' . Env::get('ACCOUNT_ID') . '&countrycode=' . Env::get('COUNTRY_CODE');
        $hdrs    = array_merge(['Content-Type: application/json', 'Accept: application/json'], self::headers());

        $mh      = curl_multi_init();
        $handles = [];

        foreach ($chunks as $i => $batch) {
            $ch = curl_init($baseUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode([
                    'productIdentifier' => 'SAPMaterialNumber',
                    'products'          => array_map(fn($id) => ['productId' => (string)$id], $batch),
                ]),
                CURLOPT_HTTPHEADER => $hdrs,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = ['ch' => $ch, 'count' => count($batch)];
        }

        do { curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh); } while ($running > 0);

        $priceMap = [];
        foreach ($handles as $h) {
            $body   = (string)curl_multi_getcontent($h['ch']);
            $status = curl_getinfo($h['ch'], CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $h['ch']);

            if ($status < 200 || $status >= 300) {
                Logger::warn("Prijzen HTTP $status voor batch van {$h['count']} producten — overgeslagen");
                continue;
            }

            foreach ((array)json_decode($body, true) as $item) {
                $sap   = (string)($item['ProductId'] ?? $item['productId'] ?? '');
                $price = self::resolvePrice($item['Prices'] ?? $item['prices'] ?? []);
                if ($sap !== '' && $price !== null) $priceMap[$sap] = $price;
            }
        }
        curl_multi_close($mh);

        return $priceMap;
    }

    private static function resolvePrice(array $prices): ?float
    {
        $typePref   = strtoupper(Env::get('PRICE_TYPE', 'NET'));
        $markup     = (float)Env::get('MARKUP_PCT', '50');
        $normalized = [];

        foreach ($prices as $p) {
            $raw   = strtolower((string)($p['PriceType'] ?? $p['priceType'] ?? ''));
            $type  = str_contains($raw, 'net') ? 'NET' : (str_contains($raw, 'list') || str_contains($raw, 'gross') ? 'LIST' : strtoupper($raw));
            $value = (float)($p['Price'] ?? $p['price'] ?? 0);
            if ($value > 0) $normalized[$type] = $value;
        }

        $net = $normalized[$typePref] ?? $normalized['NET'] ?? $normalized['LIST'] ?? null;
        if ($net === null) return null;

        return $markup > 0 ? round($net * (1 + $markup / 100), 2) : round($net, 2);
    }
}

// ─────────────────────────────────────────────────────────────────────────────

class RemoteStore
{
    private const SKU = '__solar_sync__';
    private static ?int $id = null;

    public static function load(): void
    {
        $r = Http::get(
            WooCommerceApi::url('products') . '?sku=' . urlencode(self::SKU) . '&per_page=1&status=any',
            WooCommerceApi::headers(), 15
        );
        foreach ($r['body'] ?? [] as $p) {
            self::$id = (int)$p['id'];
        }
    }

    public static function loadState(): ?array
    {
        if (!self::$id) return null;
        $r = Http::get(WooCommerceApi::url('products/' . self::$id), WooCommerceApi::headers(), 15);
        foreach ($r['body']['meta_data'] ?? [] as $m) {
            if ($m['key'] === '_solar_state') {
                $decoded = json_decode($m['value'], true);
                return is_array($decoded) ? $decoded : null;
            }
        }
        return null;
    }

    public static function saveState(array $state): void
    {
        $meta = [['key' => '_solar_state', 'value' => json_encode($state)]];
        if (self::$id) {
            Http::post(WooCommerceApi::url('products/' . self::$id), ['meta_data' => $meta], WooCommerceApi::headers(), 15);
        } else {
            $r = Http::post(WooCommerceApi::url('products'), [
                'name'               => 'Solar Sync State',
                'sku'                => self::SKU,
                'status'             => 'private',
                'catalog_visibility' => 'hidden',
                'meta_data'          => $meta,
            ], WooCommerceApi::headers(), 30);
            self::$id = (int)($r['body']['id'] ?? 0) ?: null;
        }
    }

    public static function delete(): void
    {
        if (!self::$id) return;
        $ch = curl_init(WooCommerceApi::url('products/' . self::$id . '?force=true'));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_HTTPHEADER     => WooCommerceApi::headers(),
        ]);
        curl_exec($ch);
        curl_close($ch);
        self::$id = null;
    }
}

// ─────────────────────────────────────────────────────────────────────────────

class WooCommerceApi
{
    public static function headers(): array
    {
        return [
            'Authorization: Basic ' . base64_encode(Env::get('WC_KEY') . ':' . Env::get('WC_SECRET')),
            'Accept: application/json',
        ];
    }

    public static function url(string $path): string
    {
        return rtrim(Env::get('WC_URL'), '/') . '/wp-json/wc/v3/' . ltrim($path, '/');
    }

    public static function getIdBySku(string $sku): ?int
    {
        foreach (['any', 'publish', 'draft', 'private', 'pending', 'trash'] as $status) {
            $result = Http::get(
                self::url('products') . '?sku=' . urlencode($sku) . '&per_page=1&status=' . $status,
                self::headers(),
                15
            );
            $id = $result['body'][0]['id'] ?? null;
            if ($id) return (int)$id;
        }
        return null;
    }

    public static function createBatch(array $payloads): array
    {
        if (empty($payloads)) return ['created' => 0, 'new_ids' => [], 'duplicate_skus' => [], 'errors' => []];

        $result = Http::post(self::url('products/batch'), ['create' => $payloads], self::headers(), 120);

        if (!$result['ok']) {
            return ['created' => 0, 'new_ids' => [], 'duplicate_skus' => [], 'errors' => [
                "WC batch HTTP {$result['status']}: " . substr($result['raw'] ?? '', 0, 300),
            ]];
        }

        $newIds = $duplicateSkus = $errors = [];

        foreach (($result['body']['create'] ?? []) as $idx => $item) {
            $sku = (string)($payloads[$idx]['sku'] ?? '');
            if (!empty($item['id'])) {
                $newIds[$sku] = (int)$item['id'];
            } elseif (!empty($item['error'])) {
                $code = strtolower($item['error']['code'] ?? '');
                $msg  = strtolower($item['error']['message'] ?? '');
                if (str_contains($code, 'sku') || str_contains($code, 'duplicate')
                    || str_contains($msg, 'sku') || str_contains($msg, 'already')) {
                    $duplicateSkus[] = $sku;
                } else {
                    $errors[] = "Create $sku: " . ($item['error']['message'] ?? $code);
                }
            }
        }

        return ['created' => count($newIds), 'new_ids' => $newIds, 'duplicate_skus' => $duplicateSkus, 'errors' => $errors];
    }

    public static function updateBatch(array $payloads): array
    {
        if (empty($payloads)) return ['updated' => 0, 'errors' => []];

        $result = Http::post(self::url('products/batch'), ['update' => $payloads], self::headers(), 120);

        if (!$result['ok']) {
            return ['updated' => 0, 'errors' => [
                "WC batch HTTP {$result['status']}: " . substr($result['raw'] ?? '', 0, 300),
            ]];
        }

        $errors = [];
        foreach (($result['body']['update'] ?? []) as $item) {
            if (!empty($item['error'])) {
                $errors[] = 'Update ID ' . ($item['id'] ?? '?') . ': ' . ($item['error']['message'] ?? json_encode($item['error']));
            }
        }

        $succeeded = count(array_filter($result['body']['update'] ?? [], fn($i) => empty($i['error'])));
        return ['updated' => $succeeded, 'errors' => $errors];
    }

    private static function fakePrices(float $price): array
    {
        $discountPct  = (float)Env::get('FAKE_DISCOUNT_PCT', '10');
        $regularPrice = $discountPct > 0 ? round($price * (1 + $discountPct / 100), 2) : $price;
        return ['regular' => (string)$regularPrice, 'sale' => (string)$price];
    }

    public static function createBatchMulti(array $chunks): array
    {
        if (empty($chunks)) return [];
        $mh      = curl_multi_init();
        $handles = [];
        $url     = self::url('products/batch');
        $hdrs    = array_merge(['Content-Type: application/json', 'Accept: application/json'], self::headers());

        foreach ($chunks as $i => $chunk) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 300,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode(['create' => $chunk]),
                CURLOPT_HTTPHEADER     => $hdrs,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = ['ch' => $ch, 'chunk' => $chunk];
        }

        do { curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh); } while ($running > 0);

        $results = [];
        foreach ($handles as $i => $h) {
            $body    = (string)curl_multi_getcontent($h['ch']);
            $status  = curl_getinfo($h['ch'], CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $h['ch']);

            if ($status < 200 || $status >= 300) {
                $results[$i] = ['created' => 0, 'new_ids' => [], 'duplicate_skus' => [], 'errors' => [
                    "WC batch HTTP $status: " . substr($body, 0, 200),
                ]];
                continue;
            }

            $decoded       = json_decode($body, true);
            $newIds        = $duplicateSkus = $errors = [];
            foreach (($decoded['create'] ?? []) as $idx => $item) {
                $sku = (string)($h['chunk'][$idx]['sku'] ?? '');
                if (!empty($item['id'])) {
                    $newIds[$sku] = (int)$item['id'];
                } elseif (!empty($item['error'])) {
                    $code = strtolower($item['error']['code'] ?? '');
                    $msg  = strtolower($item['error']['message'] ?? '');
                    if (str_contains($code, 'sku') || str_contains($code, 'duplicate') || str_contains($msg, 'sku') || str_contains($msg, 'already')) {
                        $duplicateSkus[] = $sku;
                    } else {
                        $errors[] = "Create $sku: " . ($item['error']['message'] ?? $code);
                    }
                }
            }
            $results[$i] = ['created' => count($newIds), 'new_ids' => $newIds, 'duplicate_skus' => $duplicateSkus, 'errors' => $errors];
        }
        curl_multi_close($mh);
        return $results;
    }

    public static function updateBatchMulti(array $chunks): array
    {
        if (empty($chunks)) return [];
        $mh      = curl_multi_init();
        $handles = [];
        $url     = self::url('products/batch');
        $hdrs    = array_merge(['Content-Type: application/json', 'Accept: application/json'], self::headers());

        foreach ($chunks as $i => $chunk) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 300,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode(['update' => $chunk]),
                CURLOPT_HTTPHEADER     => $hdrs,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = $ch;
        }

        do { curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh); } while ($running > 0);

        $results = [];
        foreach ($handles as $i => $ch) {
            $body    = (string)curl_multi_getcontent($ch);
            $status  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch);

            if ($status < 200 || $status >= 300) {
                $results[$i] = ['updated' => 0, 'errors' => ["WC batch HTTP $status: " . substr($body, 0, 200)]];
                continue;
            }

            $decoded   = json_decode($body, true);
            $errors    = [];
            foreach (($decoded['update'] ?? []) as $item) {
                if (!empty($item['error'])) {
                    $errors[] = 'Update ID ' . ($item['id'] ?? '?') . ': ' . ($item['error']['message'] ?? json_encode($item['error']));
                }
            }
            $succeeded = count(array_filter($decoded['update'] ?? [], fn($x) => empty($x['error'])));
            $results[$i] = ['updated' => $succeeded, 'errors' => $errors];
        }
        curl_multi_close($mh);
        return $results;
    }

    public static function buildCreatePayload(array $p, float $price): array
    {
        $prices  = self::fakePrices($price);
        $payload = [
            'name'               => $p['name'] !== '' ? $p['name'] : $p['sku'],
            'sku'                => $p['sku'],
            'status'             => 'draft',
            'catalog_visibility' => 'visible',
            'regular_price'      => $prices['regular'],
            'sale_price'         => $prices['sale'],
            'meta_data'          => [
                ['key' => '_solar_sap_number', 'value' => $p['sap']],
                ['key' => '_solar_last_sync',  'value' => date('Y-m-d H:i:s')],
            ],
        ];

        if ($p['description'] !== '') $payload['description']  = $p['description'];
        if ($p['gtin'] !== '')        $payload['meta_data'][]  = ['key' => '_global_unique_id', 'value' => $p['gtin']];
        if ($p['mpn'] !== '')         $payload['meta_data'][]  = ['key' => '_mpn',  'value' => $p['mpn']];
        if ($p['weight'] !== '' && $p['weight'] !== '0') $payload['weight'] = $p['weight'];

        $attrs = [];
        if ($p['brand'] !== '')  $attrs[] = ['name' => 'Merk',  'options' => [$p['brand']],  'visible' => true, 'variation' => false];
        if ($p['series'] !== '') $attrs[] = ['name' => 'Serie', 'options' => [$p['series']], 'visible' => true, 'variation' => false];
        foreach ($p['attributes'] as $name => $value) {
            $attrs[] = ['name' => $name, 'options' => [$value], 'visible' => true, 'variation' => false];
        }
        if (!empty($attrs)) $payload['attributes'] = $attrs;
        if (!empty($p['images'])) $payload['images'] = array_map(fn($url) => ['src' => $url], $p['images']);

        $catName = SYNC_CATEGORIES[$p['category_id'] ?? 0] ?? null;
        if ($catName !== null) {
            $wcCatId = self::ensureCategory($catName);
            if ($wcCatId > 0) $payload['categories'] = [['id' => $wcCatId]];
        }

        return $payload;
    }

    public static function buildUpdatePayload(int $wcId, float $price, array $p = []): array
    {
        $prices = self::fakePrices($price);
        $meta   = [['key' => '_solar_last_sync', 'value' => date('Y-m-d H:i:s')]];
        if (!empty($p['gtin'])) $meta[] = ['key' => '_global_unique_id', 'value' => $p['gtin']];
        return [
            'id'            => $wcId,
            'regular_price' => $prices['regular'],
            'sale_price'    => $prices['sale'],
            'meta_data'     => $meta,
        ];
    }

    private static array $catCache = [];

    public static function ensureCategory(string $name): int
    {
        if (isset(self::$catCache[$name])) return self::$catCache[$name];

        $r = Http::get(self::url('products/categories') . '?search=' . urlencode($name) . '&per_page=10', self::headers(), 15);
        foreach ($r['body'] ?? [] as $cat) {
            if (strtolower((string)($cat['name'] ?? '')) === strtolower($name)) {
                Logger::info("WC categorie gevonden: \"$name\" (ID: {$cat['id']})");
                return self::$catCache[$name] = (int)$cat['id'];
            }
        }

        $r = Http::post(self::url('products/categories'), ['name' => $name], self::headers(), 15);
        $id = (int)($r['body']['id'] ?? 0);
        if ($id > 0) {
            Logger::ok("WC categorie aangemaakt: \"$name\" (ID: $id)");
            return self::$catCache[$name] = $id;
        }

        Logger::warn("WC categorie aanmaken mislukt voor \"$name\"");
        return 0;
    }
}

// ─────────────────────────────────────────────────────────────────────────────

class SkuCache
{
    private array $data;

    public function __construct()
    {
        $this->data = file_exists(CACHE_FILE)
            ? (json_decode(file_get_contents(CACHE_FILE), true) ?? [])
            : [];
    }

    public function count(): int { return count($this->data); }

    public function getId(string $sku): ?int
    {
        $entry = $this->data[$sku] ?? null;
        if ($entry === null) return null;
        return is_array($entry) ? ($entry['id'] ?? null) : (int)$entry;
    }

    public function set(string $sku, int $wcId, int $lastChanged = 0): void
    {
        $this->data[$sku] = ['id' => $wcId, 'lc' => $lastChanged];
    }

    public function save(): void
    {
        file_put_contents(CACHE_FILE, json_encode($this->data));
    }
}

// ─────────────────────────────────────────────────────────────────────────────

class SyncState
{
    private array $data;

    public function __construct(bool $fresh = false)
    {
        if ($fresh) { $this->data = $this->defaults(); return; }

        if (file_exists(STATE_FILE)) {
            $this->data = json_decode(file_get_contents(STATE_FILE), true) ?? $this->defaults();
        } else {
            $remote = RemoteStore::loadState();
            $this->data = $remote ?? $this->defaults();
            if ($remote) Logger::ok('State hersteld vanuit WooCommerce (nieuwe container)');
        }
    }

    private function defaults(): array
    {
        return [
            'nextpage'           => null,
            'page'               => 0,
            'fetch_done'         => false,
            'total_fetched'      => 0,
            'wc_offset'          => 0,
            'created'            => 0,
            'updated'            => 0,
            'skipped'            => 0,
            'wc_errors'          => 0,
            'consecutive_errors' => 0,
            'started_at'         => date('Y-m-d H:i:s'),
        ];
    }

    public function get(string $key): mixed     { return $this->data[$key] ?? null; }
    public function set(string $key, mixed $v): void { $this->data[$key] = $v; }
    public function inc(string $key, int $by = 1): void { $this->data[$key] = ($this->data[$key] ?? 0) + $by; }

    public function save(bool $remote = false): void
    {
        file_put_contents(STATE_FILE, json_encode($this->data, JSON_PRETTY_PRINT));
        if ($remote) RemoteStore::saveState($this->data);
    }

    public function delete(): void { @unlink(STATE_FILE); }
}

// ─────────────────────────────────────────────────────────────────────────────

class Sync
{
    private SkuCache  $cache;
    private SyncState $state;

    public function __construct()
    {
        $this->cache = new SkuCache();
        $this->state = new SyncState();
    }

    // ── Fase 1: alle Solar pagina's streamen naar disk (geen RAM ophoping) ─────

    private function fetchAllProducts(): bool
    {
        Logger::section('Fase 1: Solar producten ophalen');

        $append = $this->state->get('page') > 0 && file_exists(PRODUCTS_FILE);
        $fh     = fopen(PRODUCTS_FILE, $append ? 'a' : 'w');
        if (!$fh) { Logger::error('Kan ' . PRODUCTS_FILE . ' niet openen voor schrijven'); return false; }

        if ($append) {
            Logger::ok('Hervatten vanaf pagina ' . ($this->state->get('page') + 1) . ' (' . $this->state->get('total_fetched') . ' al opgehaald)');
        }

        $totalFetched = (int)$this->state->get('total_fetched');

        while (true) {
            $pageNum    = $this->state->get('page') + 1;
            $t          = microtime(true);
            Logger::info("Solar pagina $pageNum ophalen...");

            $pageResult = SolarApi::getPage($this->state->get('nextpage'));

            if ($pageResult === null) {
                $httpStatus = SolarApi::getLastErrorStatus();

                // Cursor verlopen → bestand leegmaken en opnieuw beginnen
                if ($httpStatus === 400 && $this->state->get('nextpage') !== null) {
                    Logger::warn('nextpage cursor verlopen — opnieuw beginnen vanaf pagina 1...');
                    fclose($fh);
                    $fh = fopen(PRODUCTS_FILE, 'w');
                    $totalFetched = 0;
                    $this->state->set('page', 0);
                    $this->state->set('nextpage', null);
                    $this->state->set('total_fetched', 0);
                    $this->state->set('consecutive_errors', 0);
                    $this->state->save();
                    sleep(3);
                    continue;
                }

                $this->state->inc('consecutive_errors');
                $this->state->save();
                $errors = $this->state->get('consecutive_errors');
                Logger::error("Pagina $pageNum mislukt ($errors/" . MAX_ERRORS . ')');

                if ($errors >= MAX_ERRORS) {
                    fclose($fh);
                    Logger::error('Solar niet bereikbaar — sync afgebroken.');
                    return false;
                }
                Logger::warn('60s wachten voor retry...');
                sleep(60);
                continue;
            }

            [$products, $nextpage] = $pageResult;

            // Schrijf elk product als één JSON-regel (JSONL) — geen RAM ophoping
            foreach ($products as $p) {
                fwrite($fh, json_encode($p) . "\n");
            }

            $totalFetched += count($products);
            $elapsed       = round(microtime(true) - $t, 1);

            $this->state->set('page', $pageNum);
            $this->state->set('nextpage', $nextpage);
            $this->state->set('total_fetched', $totalFetched);
            $this->state->set('consecutive_errors', 0);
            $this->state->save();

            Logger::ok(sprintf(
                'Pagina %d: %d producten in %.1fs (totaal: %d)%s',
                $pageNum, count($products), $elapsed, $totalFetched,
                $nextpage ? '' : '  ← laatste pagina'
            ));

            if (!$nextpage) break;
        }

        fclose($fh);
        $this->state->set('fetch_done', true);
        $this->state->save();
        Logger::ok("Fase 1 klaar — $totalFetched producten opgeslagen");
        Logger::divider();
        return true;
    }

    // ── Fase 2: verwerk regel voor regel uit disk, nooit alles in RAM ─────────

    private function processWooCommerce(): void
    {
        Logger::section('Fase 2: WooCommerce synchronisatie');

        $total    = (int)$this->state->get('total_fetched');
        $offset   = (int)$this->state->get('wc_offset');
        $chunkNum = (int)floor($offset / PAGE_LIMIT) + 1;

        Logger::info("$total producten te verwerken" . ($offset > 0 ? " (hervatten vanaf #$offset)" : ''));

        $fh = fopen(PRODUCTS_FILE, 'r');
        if (!$fh) { Logger::error('products.jsonl niet gevonden — run met --reset'); return; }

        // Sla al verwerkte regels over
        for ($i = 0; $i < $offset; $i++) fgets($fh);

        $batch   = [];
        $lineNum = $offset;

        $flush = function () use (&$batch, &$chunkNum, &$lineNum, $total): void {
            if (empty($batch)) return;
            $chunkOffset = $lineNum - count($batch);
            $this->processChunk($batch, $chunkNum++, $chunkOffset, $total);
            $batch = [];
        };

        while (($line = fgets($fh)) !== false) {
            $p = json_decode(trim($line), true);
            if ($p) $batch[] = $p;
            $lineNum++;
            if (count($batch) >= PAGE_LIMIT) $flush();
        }
        $flush();

        fclose($fh);
    }

    private function processChunk(array $chunk, int $chunkNum, int $chunkOffset, int $total): void
    {
        Logger::info(sprintf('── Groep %d: %d producten (#%d–%d van %d)',
            $chunkNum, count($chunk), $chunkOffset + 1, $chunkOffset + count($chunk), $total));

        Logger::info('  Prijzen ophalen...');
        $priceMap = SolarApi::getPrices(array_column($chunk, 'sap'));
        Logger::info('  Prijzen ontvangen: ' . count($priceMap) . ' / ' . count($chunk));

        $productMap = $toCreate = $toUpdate = [];
        $skipped    = 0;

        foreach ($chunk as $p) {
            $price = $priceMap[$p['sap']] ?? null;
            if ($price === null) { $skipped++; continue; }
            $productMap[$p['sku']] = ['p' => $p, 'price' => $price];
            $wcId = $this->cache->getId($p['sku']);
            if ($wcId) {
                $toUpdate[] = WooCommerceApi::buildUpdatePayload($wcId, $price, $p);
            } else {
                $toCreate[] = WooCommerceApi::buildCreatePayload($p, $price);
            }
        }

        Logger::info(sprintf('  Nieuw: %d  Update: %d  Geen prijs: %d', count($toCreate), count($toUpdate), $skipped));
        if ($skipped > 0) $this->state->inc('skipped', $skipped);

        $created = $updated = $errors = 0;

        $createChunks = array_chunk($toCreate, WC_BATCH);
        $createTotal  = count($createChunks);

        foreach (array_chunk($createChunks, PARALLEL_BATCHES) as $gIdx => $group) {
            $from = $gIdx * PARALLEL_BATCHES + 1;
            $to   = min($from + count($group) - 1, $createTotal);
            $t    = microtime(true);
            Logger::info(sprintf('  [CREATE %d-%d/%d] %d×%d stuks parallel...',
                $from, $to, $createTotal, count($group), WC_BATCH));

            $results = WooCommerceApi::createBatchMulti($group);
            $gCreated = $gErrors = 0;
            $retryPayloads = [];

            foreach ($results as $r) {
                foreach ($r['new_ids'] as $sku => $id) {
                    $sku = (string)$sku;
                    $this->cache->set($sku, $id, $productMap[$sku]['p']['last_changed'] ?? 0);
                }
                $gCreated += $r['created'];
                $gErrors  += count($r['errors']);
                foreach ($r['errors'] as $err) Logger::warn("    fout: $err");

                foreach ($r['duplicate_skus'] as $sku) {
                    $id = WooCommerceApi::getIdBySku($sku);
                    if ($id) {
                        $this->cache->set($sku, $id, $productMap[$sku]['p']['last_changed'] ?? 0);
                        $retryPayloads[] = WooCommerceApi::buildUpdatePayload($id, $productMap[$sku]['price'], $productMap[$sku]['p']);
                    } else {
                        $gErrors++;
                        Logger::warn("    SKU $sku: duplicate maar niet gevonden");
                    }
                }
            }

            if (!empty($retryPayloads)) {
                $retryResults = WooCommerceApi::updateBatchMulti(array_chunk($retryPayloads, WC_BATCH));
                foreach ($retryResults as $ru) {
                    $updated += $ru['updated'];
                    $gErrors += count($ru['errors']);
                    foreach ($ru['errors'] as $err) Logger::warn("    fout: $err");
                }
            }

            $created += $gCreated;
            $errors  += $gErrors;
            Logger::info(sprintf('    → aangemaakt: %d  fouten: %d  %.1fs', $gCreated, $gErrors, microtime(true) - $t));
        }

        $updateChunks = array_chunk($toUpdate, WC_BATCH);
        $updateTotal  = count($updateChunks);

        foreach (array_chunk($updateChunks, PARALLEL_BATCHES) as $gIdx => $group) {
            $from = $gIdx * PARALLEL_BATCHES + 1;
            $to   = min($from + count($group) - 1, $updateTotal);
            $t    = microtime(true);
            Logger::info(sprintf('  [UPDATE %d-%d/%d] %d×%d stuks parallel...',
                $from, $to, $updateTotal, count($group), WC_BATCH));

            $results = WooCommerceApi::updateBatchMulti($group);
            $gUpdated = $gErrors = 0;
            foreach ($results as $r) {
                $gUpdated += $r['updated'];
                $gErrors  += count($r['errors']);
                foreach ($r['errors'] as $err) Logger::warn("    fout: $err");
            }
            $updated += $gUpdated;
            $errors  += $gErrors;
            Logger::info(sprintf('    → bijgewerkt: %d  fouten: %d  %.1fs', $gUpdated, $gErrors, microtime(true) - $t));
        }

        $this->cache->save();
        $this->state->inc('created', $created);
        $this->state->inc('updated', $updated);
        $this->state->inc('wc_errors', $errors);
        $this->state->set('wc_offset', $chunkOffset + count($chunk));
        $this->state->save(true); // ook remote opslaan zodat timeout geen voortgang verliest

        Logger::ok(sprintf('Groep %d klaar — aangemaakt: %d  bijgewerkt: %d%s',
            $chunkNum, $created, $updated, $errors > 0 ? "  fouten: $errors" : ''));
        Logger::divider();
    }

    // ── Hoofdflow ─────────────────────────────────────────────────────────────

    public function run(): void
    {
        Logger::info('Catalog    : ' . Env::get('CATALOG_ID') . '  Land: ' . Env::get('COUNTRY_CODE'));
        Logger::info('WC URL     : ' . Env::get('WC_URL'));
        Logger::info('Cache      : ' . $this->cache->count() . ' bekende producten');
        foreach (SYNC_CATEGORIES as $id => $name) {
            Logger::info("Categorie  : $name (Solar ID: $id)");
        }

        SolarApi::token();

        // Fase 1: producten ophalen van Solar
        // Als state hersteld is vanuit WooCommerce maar products.jsonl ontbreekt: opnieuw fetchen
        $needsFetch = !$this->state->get('fetch_done')
            || ($this->state->get('fetch_done') && !file_exists(PRODUCTS_FILE));

        if ($needsFetch) {
            if ($this->state->get('fetch_done') && !file_exists(PRODUCTS_FILE)) {
                Logger::warn('products.jsonl ontbreekt (nieuwe container) — opnieuw ophalen van Solar...');
                $this->state->set('fetch_done', false);
                $this->state->set('page', 0);
                $this->state->set('nextpage', null);
                $this->state->set('total_fetched', 0);
            }
            if (!$this->fetchAllProducts()) return;
        } else {
            Logger::ok('Fase 1 al voltooid — overgeslagen');
        }

        // Fase 2: producten aanmaken/bijwerken in WooCommerce (inclusief afbeeldingen)
        if ((int)$this->state->get('wc_offset') < (int)$this->state->get('total_fetched')) {
            $this->processWooCommerce();
        } else {
            Logger::ok('Fase 2 al voltooid — overgeslagen');
        }

        Logger::section('Sync klaar');
        Logger::ok(sprintf('Aangemaakt   : %d', $this->state->get('created')));
        Logger::ok(sprintf('Bijgewerkt   : %d', $this->state->get('updated')));
        Logger::ok(sprintf('Overgeslagen : %d', $this->state->get('skipped')));
        if ($this->state->get('wc_errors') > 0) {
            Logger::warn(sprintf('WC fouten    : %d', $this->state->get('wc_errors')));
        }

        @unlink(PRODUCTS_FILE);
        $this->state->delete();
        RemoteStore::delete();
    }
}

// ─────────────────────────────────────────────────────────────────────────────

Env::load(__DIR__ . '/.env');

$args = array_slice($argv, 1);

if (in_array('--reset', $args, true)) {
    @unlink(STATE_FILE);
    @unlink(LOG_FILE);
    @unlink(CACHE_FILE);
    @unlink(PRODUCTS_FILE);
    Env::require('WC_URL', 'WC_KEY', 'WC_SECRET');
    RemoteStore::load();
    RemoteStore::delete();
    Logger::info('Reset voltooid (inclusief remote state in WooCommerce)');
}

file_put_contents(LOG_FILE, '');

if (in_array('--dump', $args, true)) {
    Env::require('CLIENT_ID', 'CLIENT_SECRET', 'ACCOUNT_ID', 'COUNTRY_CODE', 'CATALOG_ID');
    SolarApi::token();

    $url = SOLAR_API_BASE . '/catalogs/' . rawurlencode(Env::get('CATALOG_ID')) . '/products'
         . '?countrycode=' . Env::get('COUNTRY_CODE') . '&limit=1000';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . SolarApi::token(), 'Accept: application/json'],
    ]);
    $items = json_decode(curl_exec($ch), true) ?? [];

    // Tel producten per categoryId
    $counts = [];
    foreach ($items as $item) {
        $catId = (string)($item['CategoryId'] ?? $item['categoryId'] ?? '0');
        $counts[$catId] = ($counts[$catId] ?? 0) + 1;
    }
    arsort($counts);

    echo PHP_EOL . sprintf("  %d producten op pagina 1 — categoryId verdeling:" . PHP_EOL, count($items));
    foreach ($counts as $catId => $count) {
        $marker = isset(SYNC_CATEGORIES[(int)$catId]) ? '  ← ' . SYNC_CATEGORIES[(int)$catId] : '';
        printf("  categoryId %-12s  %d producten%s\n", $catId, $count, $marker);
    }
    exit(0);
}

Env::require('CLIENT_ID', 'CLIENT_SECRET', 'ACCOUNT_ID', 'COUNTRY_CODE', 'CATALOG_ID', 'WC_URL', 'WC_KEY', 'WC_SECRET');

// State laden vanuit WooCommerce (overleeft Render container restarts)
RemoteStore::load();

Logger::section('Solar → WooCommerce Sync');
(new Sync())->run();
