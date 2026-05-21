#!/usr/bin/env php
<?php
declare(strict_types=1);

// php sync.php          normale run (hervat als state.json bestaat)
// php sync.php --reset  opnieuw beginnen (wist state, cache en log)
// php sync.php --dump   print alle raw velden van het eerste Solar product

const STATE_FILE      = __DIR__ . '/state.json';
const CACHE_FILE      = __DIR__ . '/sku_cache.json';
const LOG_FILE        = __DIR__ . '/sync.log';
const SOLAR_TOKEN_URL = 'https://sgidp.b2clogin.com/sgidp.onmicrosoft.com/b2c_1a_client_credentials/oauth2/v2.0/token';
const SOLAR_API_BASE  = 'https://api.solar.eu/procurement/V1';
const PRICE_BATCH     = 50;
const WC_BATCH        = 50;
const PAGE_LIMIT      = 1000;
const MAX_ERRORS      = 3;
const PRODUCTS_FILE   = __DIR__ . '/products.json';

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

        $body  = json_decode($bodyRaw, true) ?? [];
        $items = isset($body[0])
            ? $body
            : ($body['products']['product'] ?? $body['products'] ?? $body['items'] ?? []);

        $products = [];
        foreach ($items as $item) {
            if ((string)($item['ProductStatus'] ?? '') === '40') continue;

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
            ];
        }

        return [$products, $next];
    }

    public static function getPrices(array $sapNumbers): array
    {
        if (empty($sapNumbers)) return [];

        $priceMap = [];
        foreach (array_chunk($sapNumbers, PRICE_BATCH) as $batch) {
            $result = Http::post(
                SOLAR_API_BASE . '/products/prices?accountId=' . Env::get('ACCOUNT_ID') . '&countrycode=' . Env::get('COUNTRY_CODE'),
                [
                    'productIdentifier' => 'SAPMaterialNumber',
                    'products'          => array_map(fn($id) => ['productId' => (string)$id], $batch),
                ],
                self::headers(),
                30
            );

            if (!$result['ok']) {
                Logger::warn("Prijzen HTTP {$result['status']} voor batch van " . count($batch) . ' producten — overgeslagen');
                continue;
            }

            foreach ((array)$result['body'] as $item) {
                $sap   = (string)($item['ProductId'] ?? $item['productId'] ?? '');
                $price = self::resolvePrice($item['Prices'] ?? $item['prices'] ?? []);
                if ($sap !== '' && $price !== null) $priceMap[$sap] = $price;
            }
        }

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

class WooCommerceApi
{
    private static function headers(): array
    {
        return [
            'Authorization: Basic ' . base64_encode(Env::get('WC_KEY') . ':' . Env::get('WC_SECRET')),
            'Accept: application/json',
        ];
    }

    private static function url(string $path): string
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
        if ($p['gtin'] !== '')        $payload['meta_data'][]  = ['key' => '_gtin', 'value' => $p['gtin']];
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

        return $payload;
    }

    public static function buildUpdatePayload(int $wcId, float $price): array
    {
        $prices = self::fakePrices($price);
        return [
            'id'            => $wcId,
            'regular_price' => $prices['regular'],
            'sale_price'    => $prices['sale'],
            'meta_data'     => [['key' => '_solar_last_sync', 'value' => date('Y-m-d H:i:s')]],
        ];
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
        $this->data = (!$fresh && file_exists(STATE_FILE))
            ? (json_decode(file_get_contents(STATE_FILE), true) ?? $this->defaults())
            : $this->defaults();
    }

    private function defaults(): array
    {
        return [
            'nextpage'           => null,
            'page'               => 0,
            'fetch_done'         => false,
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

    public function save(): void   { file_put_contents(STATE_FILE, json_encode($this->data, JSON_PRETTY_PRINT)); }
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

    // ── Fase 1: haal ALLE Solar pagina's op zonder enige WC-vertraging ─────────

    private function fetchAllProducts(): bool
    {
        Logger::section('Fase 1: Solar producten ophalen');

        $allProducts = [];

        if ($this->state->get('page') > 0 && file_exists(PRODUCTS_FILE)) {
            $allProducts = json_decode(file_get_contents(PRODUCTS_FILE), true) ?? [];
            Logger::ok('Hervatten vanaf pagina ' . ($this->state->get('page') + 1) . ' (' . count($allProducts) . ' producten al opgehaald)');
        }

        while (true) {
            $pageNum    = $this->state->get('page') + 1;
            $t          = microtime(true);
            Logger::info("Solar pagina $pageNum ophalen...");

            $pageResult = SolarApi::getPage($this->state->get('nextpage'));

            if ($pageResult === null) {
                $httpStatus = SolarApi::getLastErrorStatus();

                // Cursor verlopen (HTTP 400) → volledig opnieuw beginnen
                if ($httpStatus === 400 && $this->state->get('nextpage') !== null) {
                    Logger::warn('nextpage cursor verlopen — opnieuw beginnen vanaf pagina 1...');
                    $allProducts = [];
                    $this->state->set('page', 0);
                    $this->state->set('nextpage', null);
                    $this->state->set('consecutive_errors', 0);
                    $this->state->save();
                    @unlink(PRODUCTS_FILE);
                    sleep(3);
                    continue;
                }

                $this->state->inc('consecutive_errors');
                $this->state->save();
                $errors = $this->state->get('consecutive_errors');
                Logger::error("Pagina $pageNum mislukt ($errors/" . MAX_ERRORS . ')');

                if ($errors >= MAX_ERRORS) {
                    Logger::error('Solar niet bereikbaar — sync afgebroken.');
                    return false;
                }
                Logger::warn('60s wachten voor retry...');
                sleep(60);
                continue;
            }

            [$products, $nextpage] = $pageResult;
            $allProducts = array_merge($allProducts, $products);
            $elapsed     = round(microtime(true) - $t, 1);

            $this->state->set('page', $pageNum);
            $this->state->set('nextpage', $nextpage);
            $this->state->set('consecutive_errors', 0);
            $this->state->save();
            file_put_contents(PRODUCTS_FILE, json_encode($allProducts));

            Logger::ok(sprintf(
                'Pagina %d: %d producten in %.1fs (totaal: %d)%s',
                $pageNum, count($products), $elapsed, count($allProducts),
                $nextpage ? '' : '  ← laatste pagina'
            ));

            if (!$nextpage) break;
        }

        $this->state->set('fetch_done', true);
        $this->state->save();
        Logger::ok('Fase 1 klaar — ' . count($allProducts) . ' producten opgeslagen in ' . PRODUCTS_FILE);
        Logger::divider();
        return true;
    }

    // ── Fase 2: verwerk alle producten naar WooCommerce ───────────────────────

    private function processWooCommerce(): void
    {
        Logger::section('Fase 2: WooCommerce synchronisatie');

        $allProducts = json_decode(file_get_contents(PRODUCTS_FILE), true) ?? [];
        $total       = count($allProducts);
        $offset      = (int)$this->state->get('wc_offset');

        Logger::info("$total producten te verwerken" . ($offset > 0 ? " (hervatten vanaf #$offset)" : ''));

        $chunks   = array_chunk(array_slice($allProducts, $offset), PAGE_LIMIT);
        $chunkNum = 0;

        foreach ($chunks as $chunk) {
            $chunkNum++;
            $chunkOffset = $offset + ($chunkNum - 1) * PAGE_LIMIT;

            Logger::info(sprintf('── Groep %d: %d producten (offset %d/%d)', $chunkNum, count($chunk), $chunkOffset, $total));
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
                    $toUpdate[] = WooCommerceApi::buildUpdatePayload($wcId, $price);
                } else {
                    $toCreate[] = WooCommerceApi::buildCreatePayload($p, $price);
                }
            }

            Logger::info(sprintf('  Aan te maken: %d  Bij te werken: %d  Geen prijs: %d', count($toCreate), count($toUpdate), $skipped));

            if ($skipped > 0) {
                $this->state->inc('skipped', $skipped);
            }

            $chunkCreated = $chunkUpdated = $chunkErrors = 0;

            // Create batches
            $createTotal = (int)ceil(count($toCreate) / WC_BATCH);
            foreach (array_chunk($toCreate, WC_BATCH) as $i => $batch) {
                $t = microtime(true);
                Logger::info(sprintf('  [CREATE %d/%d] %d stuks verzenden...', $i + 1, $createTotal, count($batch)));
                $r = WooCommerceApi::createBatch($batch);

                foreach ($r['new_ids'] as $sku => $id) {
                    $sku = (string)$sku;
                    $this->cache->set($sku, $id, $productMap[$sku]['p']['last_changed'] ?? 0);
                }
                $chunkCreated += $r['created'];
                $chunkErrors  += count($r['errors']);
                foreach ($r['errors'] as $err) Logger::warn("    WC fout: $err");

                if (!empty($r['duplicate_skus'])) {
                    Logger::info('    ' . count($r['duplicate_skus']) . ' duplicates — WC-ID opzoeken...');
                    $retry = [];
                    foreach ($r['duplicate_skus'] as $sku) {
                        $id = WooCommerceApi::getIdBySku($sku);
                        if ($id) {
                            $this->cache->set($sku, $id, $productMap[$sku]['p']['last_changed'] ?? 0);
                            $retry[] = WooCommerceApi::buildUpdatePayload($id, $productMap[$sku]['price']);
                        } else {
                            $chunkErrors++;
                            Logger::warn("    SKU $sku: duplicate maar niet gevonden in WC");
                        }
                    }
                    foreach (array_chunk($retry, WC_BATCH) as $rb) {
                        $ru = WooCommerceApi::updateBatch($rb);
                        $chunkUpdated += $ru['updated'];
                        $chunkErrors  += count($ru['errors']);
                        foreach ($ru['errors'] as $err) Logger::warn("    WC fout: $err");
                    }
                }

                Logger::info(sprintf('    → aangemaakt: %d  fouten: %d  tijd: %.1fs', $r['created'], count($r['errors']), microtime(true) - $t));
            }

            // Update batches
            $updateTotal = (int)ceil(count($toUpdate) / WC_BATCH);
            foreach (array_chunk($toUpdate, WC_BATCH) as $i => $batch) {
                $t = microtime(true);
                Logger::info(sprintf('  [UPDATE %d/%d] %d stuks verzenden...', $i + 1, $updateTotal, count($batch)));
                $r = WooCommerceApi::updateBatch($batch);
                $chunkUpdated += $r['updated'];
                $chunkErrors  += count($r['errors']);
                foreach ($r['errors'] as $err) Logger::warn("    WC fout: $err");
                Logger::info(sprintf('    → bijgewerkt: %d  fouten: %d  tijd: %.1fs', $r['updated'], count($r['errors']), microtime(true) - $t));
            }

            $this->cache->save();
            $this->state->inc('created', $chunkCreated);
            $this->state->inc('updated', $chunkUpdated);
            $this->state->inc('wc_errors', $chunkErrors);
            $this->state->set('wc_offset', $chunkOffset + count($chunk));
            $this->state->save();

            Logger::ok(sprintf(
                'Groep %d klaar — aangemaakt: %d  bijgewerkt: %d%s',
                $chunkNum, $chunkCreated, $chunkUpdated,
                $chunkErrors > 0 ? "  fouten: $chunkErrors" : ''
            ));
            Logger::divider();
        }
    }

    // ── Hoofdflow ─────────────────────────────────────────────────────────────

    public function run(): void
    {
        Logger::info('Catalog : ' . Env::get('CATALOG_ID') . '  Land: ' . Env::get('COUNTRY_CODE'));
        Logger::info('WC URL  : ' . Env::get('WC_URL'));
        Logger::info('Cache   : ' . $this->cache->count() . ' bekende producten');

        SolarApi::token();

        if (!$this->state->get('fetch_done')) {
            if (!$this->fetchAllProducts()) {
                return;
            }
        } else {
            Logger::ok('Fase 1 al voltooid — direct naar WooCommerce');
        }

        $this->processWooCommerce();

        Logger::section('Sync klaar');
        $started  = $this->state->get('started_at') ?? date('Y-m-d H:i:s');
        $duration = time() - strtotime($started);
        Logger::ok(sprintf('Duur         : %02d:%02d:%02d', intdiv($duration, 3600), intdiv($duration % 3600, 60), $duration % 60));
        Logger::ok(sprintf('Aangemaakt   : %d', $this->state->get('created')));
        Logger::ok(sprintf('Bijgewerkt   : %d', $this->state->get('updated')));
        Logger::ok(sprintf('Overgeslagen : %d', $this->state->get('skipped')));
        if ($this->state->get('wc_errors') > 0) {
            Logger::warn(sprintf('WC fouten    : %d', $this->state->get('wc_errors')));
        }

        @unlink(PRODUCTS_FILE);
        $this->state->delete();
    }
}

// ─────────────────────────────────────────────────────────────────────────────

$args = array_slice($argv, 1);

if (in_array('--reset', $args, true)) {
    @unlink(STATE_FILE);
    @unlink(LOG_FILE);
    @unlink(CACHE_FILE);
    @unlink(PRODUCTS_FILE);
}

file_put_contents(LOG_FILE, '');

if (in_array('--dump', $args, true)) {
    Env::load(__DIR__ . '/.env');
    Env::require('CLIENT_ID', 'CLIENT_SECRET', 'ACCOUNT_ID', 'COUNTRY_CODE', 'CATALOG_ID');
    SolarApi::token();

    $nextpage = null;
    $product  = null;
    do {
        $result   = SolarApi::getPage($nextpage);
        $nextpage = $result[1] ?? null;
        if (!empty($result[0])) { $product = $result[0][0]; break; }
    } while ($nextpage);

    echo PHP_EOL;
    if ($product) {
        foreach ($product as $key => $value) {
            printf("  %-20s %s\n", $key, substr(is_array($value) ? json_encode($value) : (string)$value, 0, 120));
        }
    } else {
        echo 'Geen actief product gevonden.' . PHP_EOL;
    }
    exit(0);
}

Env::load(__DIR__ . '/.env');
Env::require('CLIENT_ID', 'CLIENT_SECRET', 'ACCOUNT_ID', 'COUNTRY_CODE', 'CATALOG_ID', 'WC_URL', 'WC_KEY', 'WC_SECRET');

Logger::section('Solar → WooCommerce Sync');
(new Sync())->run();
