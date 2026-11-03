<?php

namespace App\Services\Catalog;

use App\Contracts\Catalog\SourceAdapterInterface;
use App\Services\Catalog\Adapters\MockFastSourceAdapter;
use App\Services\Catalog\Adapters\MockFlakySourceAdapter;
use App\Services\Catalog\Adapters\MockSlowSourceAdapter;

class CatalogSearchCoordinator
{
    /**
     * @var array<string, SourceAdapterInterface>
     */
    protected array $adapters = [];

    /**
     * @param array<int, SourceAdapterInterface>|null $adapters
     */
    public function __construct(
        ?array $adapters = null,
        protected float $perSourceTimeoutSeconds = 2.0,
        protected float $globalTimeoutSeconds = 5.0
    ) {
        if ($adapters === null) {
            $this->registerAdapter(new MockFastSourceAdapter());
            $this->registerAdapter(new MockSlowSourceAdapter());
            $this->registerAdapter(new MockFlakySourceAdapter());
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
     * @param callable(string $eventName, array<string, mixed> $payload): void $eventEmitter
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
                    $normalizedItems = array_map(fn ($item) => $this->normalizeItem($item, $code), $rawItems);

                    $summary[$code] = 'completed';
                    $totalItems += count($normalizedItems);

                    $eventEmitter('source_result', [
                        'search_id' => $searchId,
                        'source' => $code,
                        'status' => 'completed',
                        'items_count' => count($normalizedItems),
                        'data' => $normalizedItems,
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
     * Normalize an item into the UIH Canonical Catalog Model.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public function normalizeItem(array $item, string $fallbackSourceCode): array
    {
        return [
            'source' => [
                'code' => $item['source']['code'] ?? $fallbackSourceCode,
            ],
            'offer' => [
                'id' => (string) ($item['offer']['id'] ?? ''),
                'name' => (string) ($item['offer']['name'] ?? 'Tanpa Nama'),
                'type' => (string) ($item['offer']['type'] ?? 'product'),
                'summary' => isset($item['offer']['summary']) ? (string) $item['offer']['summary'] : null,
            ],
            'provider' => [
                'id' => (string) ($item['provider']['id'] ?? ''),
                'name' => (string) ($item['provider']['name'] ?? 'Tanpa Penyedia'),
            ],
            'image' => isset($item['image']) && is_string($item['image']) ? $item['image'] : null,
            'contact' => [
                'phone' => $item['contact']['phone'] ?? null,
                'whatsapp' => $item['contact']['whatsapp'] ?? null,
                'email' => $item['contact']['email'] ?? null,
            ],
            'location' => [
                'address' => $item['location']['address'] ?? null,
                'latitude' => isset($item['location']['latitude']) ? (float) $item['location']['latitude'] : null,
                'longitude' => isset($item['location']['longitude']) ? (float) $item['location']['longitude'] : null,
                'type' => $item['location']['type'] ?? null,
            ],
            'availability' => $item['availability'] ?? null,
            'operational_status' => $item['operational_status'] ?? null,
        ];
    }
}
