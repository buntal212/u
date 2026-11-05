<?php

namespace App\Services\Catalog;

use App\Contracts\Catalog\SourceAdapterInterface;
use App\Services\Catalog\Adapters\MockFastSourceAdapter;
use App\Services\Catalog\Adapters\MockFlakySourceAdapter;
use App\Services\Catalog\Adapters\MockSlowSourceAdapter;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

class CatalogSearchCoordinator
{
    /**
     * @var array<string, SourceAdapterInterface>
     */
    protected array $adapters = [];

    /**
     * @param  array<int, SourceAdapterInterface>|null  $adapters
     */
    public function __construct(
        ?array $adapters = null,
        protected float $perSourceTimeoutSeconds = 2.0,
        protected float $globalTimeoutSeconds = 5.0,
        protected ?LoggerInterface $logger = null
    ) {
        if ($adapters === null) {
            $this->registerAdapter(new MockFastSourceAdapter);
            $this->registerAdapter(new MockSlowSourceAdapter);
            $this->registerAdapter(new MockFlakySourceAdapter);
        } else {
            foreach ($adapters as $adapter) {
                $this->registerAdapter($adapter);
            }
        }
    }

    public function registerAdapter(SourceAdapterInterface $adapter): self
    {
        $this->adapters[$adapter->getSourceCode()] = $adapter;

        return $this;
    }

    /**
     * @return array<string, SourceAdapterInterface>
     */
    public function getAdapters(): array
    {
        return $this->adapters;
    }

    /**
     * Execute progressive catalog search and emit SSE events.
     *
     * @param  callable(string $eventName, array<string, mixed> $payload): void  $eventEmitter
     */
    public function executeStream(
        string $searchId,
        string $query,
        int $limit,
        callable $eventEmitter
    ): void {
        $startTime = microtime(true);
        $targetCodes = array_keys($this->adapters);

        // 1. Emit search_init
        $eventEmitter('search_init', [
            'search_id' => $searchId,
            'targets' => $targetCodes,
        ]);

        $summary = [];
        $totalItems = 0;

        // 2. Iterate through adapters progressively
        foreach ($this->adapters as $code => $adapter) {
            // Check global timeout
            $elapsed = microtime(true) - $startTime;
            if ($elapsed >= $this->globalTimeoutSeconds) {
                $summary[$code] = 'failed';
                $eventEmitter('source_failed', [
                    'search_id' => $searchId,
                    'source' => $code,
                    'status' => 'failed',
                    'error_code' => 'GLOBAL_TIMEOUT',
                    'message' => 'Pencarian dihentikan karena batas waktu global tercapai.',
                ]);

                continue;
            }

            try {
                $sourceStartTime = microtime(true);
                $result = $adapter->search($query, $limit);
                $sourceDuration = microtime(true) - $sourceStartTime;

                if ($sourceDuration > $this->perSourceTimeoutSeconds) {
                    $summary[$code] = 'failed';
                    $eventEmitter('source_failed', [
                        'search_id' => $searchId,
                        'source' => $code,
                        'status' => 'failed',
                        'error_code' => 'SOURCE_TIMEOUT',
                        'message' => 'Aplikasi sumber melebihi batas waktu respon.',
                    ]);

                    continue;
                }

                if (($result['status'] ?? 'failed') === 'completed') {
                    $rawItems = $result['data'] ?? [];
                    $normalizedItems = [];

                    foreach ($rawItems as $rawItem) {
                        if (! is_array($rawItem)) {
                            $this->logWarning('Catalog raw item is not an array', [
                                'source' => $code,
                            ]);

                            continue;
                        }

                        $normalized = $this->normalizeItem($rawItem, $code);
                        if ($normalized !== null) {
                            $normalizedItems[] = $normalized;
                        }
                    }

                    // Jika adapter mengembalikan data namun seluruh item tidak valid
                    if (! empty($rawItems) && empty($normalizedItems)) {
                        $summary[$code] = 'failed';
                        $eventEmitter('source_failed', [
                            'search_id' => $searchId,
                            'source' => $code,
                            'status' => 'failed',
                            'error_code' => 'INVALID_SOURCE_DATA',
                            'message' => 'Seluruh item dari aplikasi sumber tidak valid.',
                        ]);

                        continue;
                    }

                    $summary[$code] = 'completed';
                    $totalItems += count($normalizedItems);

                    $eventEmitter('source_result', [
                        'search_id' => $searchId,
                        'status' => 'completed',
                        'source' => [
                            'code' => $code,
                            'name' => $adapter->getSourceName(),
                        ],
                        'data' => $normalizedItems,
                        'meta' => [
                            'total' => count($normalizedItems),
                        ],
                        'items_count' => count($normalizedItems),
                    ]);
                } else {
                    $summary[$code] = 'failed';
                    $eventEmitter('source_failed', [
                        'search_id' => $searchId,
                        'source' => $code,
                        'status' => 'failed',
                        'error_code' => $result['error_code'] ?? 'SOURCE_ERROR',
                        'message' => $result['message'] ?? 'Aplikasi sumber mengalami kegagalan.',
                    ]);
                }
            } catch (\Throwable $e) {
                $summary[$code] = 'failed';
                $eventEmitter('source_failed', [
                    'search_id' => $searchId,
                    'source' => $code,
                    'status' => 'failed',
                    'error_code' => 'SOURCE_EXCEPTION',
                    'message' => 'Terjadi kesalahan internal saat menghubungi aplikasi sumber.',
                ]);
            }
        }

        // 3. Emit search_done
        $eventEmitter('search_done', [
            'search_id' => $searchId,
            'total_items' => $totalItems,
            'summary' => $summary,
        ]);
    }

    /**
     * Normalize an item into the UIH Canonical Catalog Model v1.
     * Returns null if the item is invalid (e.g. invalid or missing price).
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    public function normalizeItem(array $item, string $fallbackSourceCode): ?array
    {
        $id = trim((string) ($item['id'] ?? $item['offer']['id'] ?? ''));
        if ($id === '') {
            $this->logWarning('Catalog item rejected: missing id', [
                'source' => $fallbackSourceCode,
            ]);

            return null;
        }

        $name = trim((string) ($item['name'] ?? $item['offer']['name'] ?? ''));
        if ($name === '') {
            $this->logWarning('Catalog item rejected: missing name', [
                'source' => $fallbackSourceCode,
                'item_id' => $id,
            ]);

            return null;
        }

        // Validasi Harga (Price Validation)
        $rawPrice = $item['price'] ?? null;
        $rawAmount = null;
        $currency = 'IDR';

        if (is_array($rawPrice)) {
            if (array_key_exists('amount', $rawPrice) && is_numeric($rawPrice['amount'])) {
                $rawAmount = $rawPrice['amount'];
            }
            if (isset($rawPrice['currency']) && is_string($rawPrice['currency']) && trim($rawPrice['currency']) !== '') {
                $currency = strtoupper(trim($rawPrice['currency']));
            }
        } elseif (is_numeric($rawPrice)) {
            $rawAmount = $rawPrice;
        }

        if ($rawAmount === null || ! is_numeric($rawAmount) || (float) $rawAmount < 0) {
            $this->logWarning('Catalog item rejected: invalid or missing price', [
                'source' => $fallbackSourceCode,
                'item_id' => $id,
            ]);

            return null;
        }

        $priceAmount = (int) round((float) $rawAmount);

        // Provider data extraction
        $rawProvider = is_array($item['provider'] ?? null) ? $item['provider'] : [];
        $providerId = (string) ($rawProvider['id'] ?? '');
        $providerCode = isset($rawProvider['code']) && is_string($rawProvider['code']) ? $rawProvider['code'] : null;
        $providerName = (string) ($rawProvider['name'] ?? 'Tanpa Penyedia');
        $providerImage = $this->normalizeImage($rawProvider['image'] ?? null);

        // Contact normalization (provider.contact preferred, fallback to root contact)
        $rawContact = is_array($rawProvider['contact'] ?? null)
            ? $rawProvider['contact']
            : (is_array($item['contact'] ?? null) ? $item['contact'] : []);

        $phone = isset($rawContact['phone']) && is_string($rawContact['phone']) ? $rawContact['phone'] : null;
        $whatsapp = isset($rawContact['whatsapp']) && is_string($rawContact['whatsapp']) ? $rawContact['whatsapp'] : null;

        // Location normalization (provider.location preferred, fallback to root location)
        $rawLocation = is_array($rawProvider['location'] ?? null)
            ? $rawProvider['location']
            : (is_array($item['location'] ?? null) ? $item['location'] : []);

        $address = isset($rawLocation['address']) && is_string($rawLocation['address']) ? $rawLocation['address'] : null;
        $latitude = isset($rawLocation['latitude']) && is_numeric($rawLocation['latitude']) ? (float) $rawLocation['latitude'] : null;
        $longitude = isset($rawLocation['longitude']) && is_numeric($rawLocation['longitude']) ? (float) $rawLocation['longitude'] : null;

        // Availability normalization
        $rawAvailability = $item['availability'] ?? null;
        $availability = ($rawAvailability === 'available' || $rawAvailability === true || ($item['operational_status'] ?? null) === 'open')
            ? 'available'
            : 'unavailable';

        return [
            'id' => $id,
            'code' => isset($item['code']) && is_string($item['code']) ? $item['code'] : (isset($item['offer']['code']) && is_string($item['offer']['code']) ? $item['offer']['code'] : null),
            'name' => $name,
            'category' => isset($item['category']) && is_string($item['category']) ? $item['category'] : null,
            'summary' => isset($item['summary']) && is_string($item['summary']) ? $item['summary'] : (isset($item['offer']['summary']) && is_string($item['offer']['summary']) ? $item['offer']['summary'] : null),
            'price' => [
                'amount' => $priceAmount,
                'currency' => $currency,
            ],
            'image' => $this->normalizeImage($item['image'] ?? null),
            'availability' => $availability,
            'provider' => [
                'id' => $providerId,
                'code' => $providerCode,
                'name' => $providerName,
                'image' => $providerImage,
                'contact' => [
                    'phone' => $phone,
                    'whatsapp' => $whatsapp,
                ],
                'location' => [
                    'address' => $address,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                ],
            ],
            'source' => [
                'code' => (string) ($item['source']['code'] ?? $fallbackSourceCode),
            ],
        ];
    }

    /**
     * Normalize an image representation to `{ url: string }|null`.
     *
     * @return array{url: string}|null
     */
    private function normalizeImage(mixed $image): ?array
    {
        if (is_string($image) && trim($image) !== '') {
            return ['url' => trim($image)];
        }

        if (is_array($image) && isset($image['url']) && is_string($image['url']) && trim($image['url']) !== '') {
            return ['url' => trim($image['url'])];
        }

        return null;
    }

    /**
     * Safely log a warning without throwing if the Laravel container is not booted.
     *
     * @param  array<string, mixed>  $context
     */
    private function logWarning(string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            $this->logger->warning($message, $context);

            return;
        }

        if (class_exists(Log::class) && Facade::getFacadeApplication() !== null) {
            Log::warning($message, $context);
        }
    }
}
