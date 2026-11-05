<?php

namespace Tests\Unit;

use App\Contracts\Catalog\SourceAdapterInterface;
use App\Services\Catalog\Adapters\MockFastSourceAdapter;
use App\Services\Catalog\Adapters\MockFlakySourceAdapter;
use App\Services\Catalog\Adapters\MockSlowSourceAdapter;
use App\Services\Catalog\CatalogSearchCoordinator;
use PHPUnit\Framework\TestCase;

class CatalogSearchCoordinatorTest extends TestCase
{
    public function test_executes_all_adapters_and_emits_expected_events(): void
    {
        $events = [];
        $eventEmitter = function (string $event, array $payload) use (&$events): void {
            $events[] = ['event' => $event, 'payload' => $payload];
        };

        // Pass 0 delay for fast deterministic tests
        $fastAdapter = new MockFastSourceAdapter(0);
        $slowAdapter = new MockSlowSourceAdapter(0);
        $flakyAdapter = new MockFlakySourceAdapter(0);

        $coordinator = new CatalogSearchCoordinator([$fastAdapter, $slowAdapter, $flakyAdapter]);
        $coordinator->executeStream('search_test_1', 'nasi', 10, $eventEmitter);

        $this->assertNotEmpty($events);

        // 1. First event must be search_init
        $this->assertSame('search_init', $events[0]['event']);
        $this->assertSame('search_test_1', $events[0]['payload']['search_id']);
        $this->assertSame(['mock_larissama', 'mock_laundry', 'mock_aset'], $events[0]['payload']['targets']);

        // 2. Middle events are source_result with canonical v1 format
        $sourceResultEvents = array_values(array_filter($events, fn ($e) => $e['event'] === 'source_result'));
        $this->assertNotEmpty($sourceResultEvents);

        $firstResult = $sourceResultEvents[0]['payload'];
        $this->assertSame('completed', $firstResult['status']);
        $this->assertIsArray($firstResult['source']);
        $this->assertSame('mock_larissama', $firstResult['source']['code']);
        $this->assertSame('Larissama F&B', $firstResult['source']['name']);
        $this->assertArrayHasKey('meta', $firstResult);
        $this->assertSame(2, $firstResult['meta']['total']);
        $this->assertSame(2, $firstResult['items_count']); // legacy alias check

        // Check canonical item fields on first item
        $item = $firstResult['data'][0];
        $this->assertSame('lrs-001', $item['id']);
        $this->assertSame('MNU-001', $item['code']);
        $this->assertStringContainsString('Nasi Goreng Spesial', $item['name']);
        $this->assertSame('Makanan Utama', $item['category']);
        $this->assertIsArray($item['price']);
        $this->assertSame(25000, $item['price']['amount']);
        $this->assertSame('IDR', $item['price']['currency']);
        $this->assertSame(['url' => 'https://images.unsplash.com/photo-1512058564366-18510be2db19?w=600&auto=format&fit=crop&q=80'], $item['image']);
        $this->assertSame('available', $item['availability']);
        $this->assertSame('tenant-lrs-10', $item['provider']['id']);
        $this->assertSame('Warung Makan Bu Rina', $item['provider']['name']);
        $this->assertSame('+6281234567890', $item['provider']['contact']['phone']);
        $this->assertSame('Jl. Kaliurang KM 5 No. 12, Sleman', $item['provider']['location']['address']);

        // 3. Last event must be search_done
        $lastEvent = end($events);
        $this->assertSame('search_done', $lastEvent['event']);
        $this->assertSame('search_test_1', $lastEvent['payload']['search_id']);
        $this->assertSame('completed', $lastEvent['payload']['summary']['mock_larissama']);
        $this->assertSame('completed', $lastEvent['payload']['summary']['mock_laundry']);
        $this->assertSame('completed', $lastEvent['payload']['summary']['mock_aset']);
    }

    public function test_handles_source_failure_without_stopping_other_sources(): void
    {
        $events = [];
        $eventEmitter = function (string $event, array $payload) use (&$events): void {
            $events[] = ['event' => $event, 'payload' => $payload];
        };

        $fastAdapter = new MockFastSourceAdapter(0);
        $slowAdapter = new MockSlowSourceAdapter(0);
        $flakyAdapter = new MockFlakySourceAdapter(0);

        $coordinator = new CatalogSearchCoordinator([$fastAdapter, $slowAdapter, $flakyAdapter]);
        // "error" triggers failure in MockFlakySourceAdapter
        $coordinator->executeStream('search_test_2', 'nasi error', 10, $eventEmitter);

        $eventNames = array_column($events, 'event');
        $this->assertContains('source_result', $eventNames);
        $this->assertContains('source_failed', $eventNames);

        $lastEvent = end($events);
        $this->assertSame('search_done', $lastEvent['event']);
        $this->assertSame('completed', $lastEvent['payload']['summary']['mock_larissama']);
        $this->assertSame('completed', $lastEvent['payload']['summary']['mock_laundry']);
        $this->assertSame('failed', $lastEvent['payload']['summary']['mock_aset']);
    }

    public function test_handles_empty_source_result_as_completed(): void
    {
        $events = [];
        $eventEmitter = function (string $event, array $payload) use (&$events): void {
            $events[] = ['event' => $event, 'payload' => $payload];
        };

        $flakyAdapter = new MockFlakySourceAdapter(0);
        $coordinator = new CatalogSearchCoordinator([$flakyAdapter]);

        // "kosong" triggers empty results
        $coordinator->executeStream('search_test_3', 'kosong', 10, $eventEmitter);

        $this->assertCount(3, $events); // search_init, source_result, search_done
        $this->assertSame('source_result', $events[1]['event']);
        $this->assertSame('completed', $events[1]['payload']['status']);
        $this->assertSame([], $events[1]['payload']['data']);
        $this->assertSame(0, $events[1]['payload']['meta']['total']);
        $this->assertSame(0, $events[1]['payload']['items_count']);
    }

    public function test_normalizes_items_to_canonical_catalog_model_v1(): void
    {
        $coordinator = new CatalogSearchCoordinator([]);

        $rawItem = [
            'id' => 'item-101',
            'code' => 'ITM-01',
            'name' => 'Produk Kanonik',
            'category' => 'Elektronik',
            'summary' => 'Deskripsi ringkas',
            'price' => [
                'amount' => 150000,
                'currency' => 'IDR',
            ],
            'image' => [
                'url' => 'https://example.com/item.jpg',
            ],
            'availability' => 'available',
            'provider' => [
                'id' => 'prov-1',
                'code' => 'PRV-01',
                'name' => 'Toko Elektronik Makmur',
                'image' => [
                    'url' => 'https://example.com/logo.jpg',
                ],
                'contact' => [
                    'phone' => '0812345678',
                    'whatsapp' => '0812345678',
                ],
                'location' => [
                    'address' => 'Jl. Magelang No. 1',
                    'latitude' => -7.75,
                    'longitude' => 110.36,
                ],
            ],
            'source' => [
                'code' => 'custom_source',
            ],
        ];

        $normalized = $coordinator->normalizeItem($rawItem, 'fallback_code');

        $this->assertNotNull($normalized);
        $this->assertSame('item-101', $normalized['id']);
        $this->assertSame('ITM-01', $normalized['code']);
        $this->assertSame('Produk Kanonik', $normalized['name']);
        $this->assertSame('Elektronik', $normalized['category']);
        $this->assertSame('Deskripsi ringkas', $normalized['summary']);
        $this->assertSame(150000, $normalized['price']['amount']);
        $this->assertSame('IDR', $normalized['price']['currency']);
        $this->assertSame(['url' => 'https://example.com/item.jpg'], $normalized['image']);
        $this->assertSame('available', $normalized['availability']);

        // Provider assertions
        $this->assertSame('prov-1', $normalized['provider']['id']);
        $this->assertSame('PRV-01', $normalized['provider']['code']);
        $this->assertSame('Toko Elektronik Makmur', $normalized['provider']['name']);
        $this->assertSame(['url' => 'https://example.com/logo.jpg'], $normalized['provider']['image']);
        $this->assertSame('0812345678', $normalized['provider']['contact']['phone']);
        $this->assertSame('0812345678', $normalized['provider']['contact']['whatsapp']);
        $this->assertSame('Jl. Magelang No. 1', $normalized['provider']['location']['address']);
        $this->assertSame(-7.75, $normalized['provider']['location']['latitude']);
        $this->assertSame(110.36, $normalized['provider']['location']['longitude']);

        // Source assertion
        $this->assertSame('custom_source', $normalized['source']['code']);
    }

    public function test_price_validation_converts_numeric_to_integer_rupiah(): void
    {
        $coordinator = new CatalogSearchCoordinator([]);

        // Direct float number rounded to integer
        $item1 = [
            'id' => '1',
            'name' => 'Menu 1',
            'price' => 25000.40,
        ];
        $norm1 = $coordinator->normalizeItem($item1, 'test');
        $this->assertNotNull($norm1);
        $this->assertSame(25000, $norm1['price']['amount']);
        $this->assertSame('IDR', $norm1['price']['currency']);

        // Direct numeric string
        $item2 = [
            'id' => '2',
            'name' => 'Menu 2',
            'price' => '30000',
        ];
        $norm2 = $coordinator->normalizeItem($item2, 'test');
        $this->assertNotNull($norm2);
        $this->assertSame(30000, $norm2['price']['amount']);

        // Zero price is valid if explicit
        $item3 = [
            'id' => '3',
            'name' => 'Menu Gratis',
            'price' => ['amount' => 0, 'currency' => 'IDR'],
        ];
        $norm3 = $coordinator->normalizeItem($item3, 'test');
        $this->assertNotNull($norm3);
        $this->assertSame(0, $norm3['price']['amount']);
    }

    public function test_rejects_items_with_missing_negative_or_invalid_price(): void
    {
        $coordinator = new CatalogSearchCoordinator([]);

        // Missing price key -> rejected (null), NOT 0
        $itemMissing = [
            'id' => '1',
            'name' => 'Menu Tanpa Harga',
        ];
        $this->assertNull($coordinator->normalizeItem($itemMissing, 'test'));

        // Price null -> rejected
        $itemNull = [
            'id' => '2',
            'name' => 'Menu Harga Null',
            'price' => null,
        ];
        $this->assertNull($coordinator->normalizeItem($itemNull, 'test'));

        // Price negative -> rejected
        $itemNegative = [
            'id' => '3',
            'name' => 'Menu Harga Negatif',
            'price' => -1000,
        ];
        $this->assertNull($coordinator->normalizeItem($itemNegative, 'test'));

        // Price non-numeric string -> rejected
        $itemNonNumeric = [
            'id' => '4',
            'name' => 'Menu Harga String',
            'price' => 'gratis',
        ];
        $this->assertNull($coordinator->normalizeItem($itemNonNumeric, 'test'));

        // Price object with negative amount -> rejected
        $itemObjNegative = [
            'id' => '5',
            'name' => 'Menu Amount Negatif',
            'price' => ['amount' => -50, 'currency' => 'IDR'],
        ];
        $this->assertNull($coordinator->normalizeItem($itemObjNegative, 'test'));
    }

    public function test_processes_valid_items_when_some_items_are_invalid(): void
    {
        $events = [];
        $eventEmitter = function (string $event, array $payload) use (&$events): void {
            $events[] = ['event' => $event, 'payload' => $payload];
        };

        // Adapter returns 2 items: 1 valid, 1 with negative price
        $mixedAdapter = new class implements SourceAdapterInterface
        {
            public function getSourceCode(): string
            {
                return 'mixed_source';
            }

            public function getSourceName(): string
            {
                return 'Mixed Source';
            }

            public function search(string $query, int $limit = 20): array
            {
                return [
                    'status' => 'completed',
                    'data' => [
                        [
                            'id' => 'valid-01',
                            'name' => 'Item Valid',
                            'price' => 20000,
                        ],
                        [
                            'id' => 'invalid-02',
                            'name' => 'Item Harga Negatif',
                            'price' => -500,
                        ],
                    ],
                ];
            }
        };

        $coordinator = new CatalogSearchCoordinator([$mixedAdapter]);
        $coordinator->executeStream('search_mixed', 'test', 10, $eventEmitter);

        $sourceResultEvents = array_values(array_filter($events, fn ($e) => $e['event'] === 'source_result'));
        $this->assertCount(1, $sourceResultEvents);

        $payload = $sourceResultEvents[0]['payload'];
        $this->assertSame('completed', $payload['status']);
        $this->assertCount(1, $payload['data']);
        $this->assertSame('valid-01', $payload['data'][0]['id']);
        $this->assertSame(1, $payload['meta']['total']);
        $this->assertSame(1, $payload['items_count']);
    }

    public function test_fails_source_when_all_returned_items_are_invalid(): void
    {
        $events = [];
        $eventEmitter = function (string $event, array $payload) use (&$events): void {
            $events[] = ['event' => $event, 'payload' => $payload];
        };

        // Adapter returns items, but all have invalid price
        $allInvalidAdapter = new class implements SourceAdapterInterface
        {
            public function getSourceCode(): string
            {
                return 'invalid_source';
            }

            public function getSourceName(): string
            {
                return 'Invalid Source';
            }

            public function search(string $query, int $limit = 20): array
            {
                return [
                    'status' => 'completed',
                    'data' => [
                        [
                            'id' => 'bad-01',
                            'name' => 'Item Tanpa Harga',
                        ],
                        [
                            'id' => 'bad-02',
                            'name' => 'Item Harga Negatif',
                            'price' => -100,
                        ],
                    ],
                ];
            }
        };

        $coordinator = new CatalogSearchCoordinator([$allInvalidAdapter]);
        $coordinator->executeStream('search_all_invalid', 'test', 10, $eventEmitter);

        $failedEvents = array_values(array_filter($events, fn ($e) => $e['event'] === 'source_failed'));
        $this->assertCount(1, $failedEvents);
        $this->assertSame('INVALID_SOURCE_DATA', $failedEvents[0]['payload']['error_code']);
        $this->assertSame('invalid_source', $failedEvents[0]['payload']['source']);

        $lastEvent = end($events);
        $this->assertSame('failed', $lastEvent['payload']['summary']['invalid_source']);
        $this->assertSame(0, $lastEvent['payload']['total_items']);
    }

    public function test_preserves_nullable_fields_as_null(): void
    {
        $coordinator = new CatalogSearchCoordinator([]);

        $minimalItem = [
            'id' => 'item-min',
            'name' => 'Item Minimal',
            'price' => 10000,
        ];

        $normalized = $coordinator->normalizeItem($minimalItem, 'test');

        $this->assertNotNull($normalized);
        $this->assertArrayHasKey('code', $normalized);
        $this->assertNull($normalized['code']);
        $this->assertArrayHasKey('category', $normalized);
        $this->assertNull($normalized['category']);
        $this->assertArrayHasKey('summary', $normalized);
        $this->assertNull($normalized['summary']);
        $this->assertArrayHasKey('image', $normalized);
        $this->assertNull($normalized['image']);

        $provider = $normalized['provider'];
        $this->assertArrayHasKey('code', $provider);
        $this->assertNull($provider['code']);
        $this->assertArrayHasKey('image', $provider);
        $this->assertNull($provider['image']);
        $this->assertArrayHasKey('phone', $provider['contact']);
        $this->assertNull($provider['contact']['phone']);
        $this->assertArrayHasKey('whatsapp', $provider['contact']);
        $this->assertNull($provider['contact']['whatsapp']);
        $this->assertArrayHasKey('address', $provider['location']);
        $this->assertNull($provider['location']['address']);
        $this->assertArrayHasKey('latitude', $provider['location']);
        $this->assertNull($provider['location']['latitude']);
        $this->assertArrayHasKey('longitude', $provider['location']);
        $this->assertNull($provider['location']['longitude']);
    }

    public function test_normalizes_legacy_adapter_format_gracefully(): void
    {
        $coordinator = new CatalogSearchCoordinator([]);

        $legacyItem = [
            'source' => ['code' => 'legacy_app'],
            'offer' => [
                'id' => 'leg-01',
                'name' => 'Menu Warisan',
                'code' => 'LEG-01',
                'summary' => 'Deskripsi lama',
            ],
            'price' => 12500,
            'image' => 'https://example.com/legacy.jpg',
            'provider' => [
                'id' => 'leg-prov',
                'name' => 'Penyedia Lama',
            ],
            'contact' => [
                'phone' => '089999',
                'whatsapp' => '089999',
            ],
            'location' => [
                'address' => 'Jl. Kenangan No. 5',
                'latitude' => -7.1,
                'longitude' => 110.2,
            ],
            'availability' => 'available',
        ];

        $normalized = $coordinator->normalizeItem($legacyItem, 'fallback');

        $this->assertNotNull($normalized);
        $this->assertSame('leg-01', $normalized['id']);
        $this->assertSame('LEG-01', $normalized['code']);
        $this->assertSame('Menu Warisan', $normalized['name']);
        $this->assertSame('Deskripsi lama', $normalized['summary']);
        $this->assertSame(12500, $normalized['price']['amount']);
        $this->assertSame(['url' => 'https://example.com/legacy.jpg'], $normalized['image']);
        $this->assertSame('089999', $normalized['provider']['contact']['phone']);
        $this->assertSame('Jl. Kenangan No. 5', $normalized['provider']['location']['address']);
        $this->assertSame(-7.1, $normalized['provider']['location']['latitude']);
    }

    public function test_handles_per_source_timeout(): void
    {
        $events = [];
        $eventEmitter = function (string $event, array $payload) use (&$events): void {
            $events[] = ['event' => $event, 'payload' => $payload];
        };

        // Create a custom adapter that sleeps 50ms, while perSourceTimeout is set to 0.01s (10ms)
        $timeoutAdapter = new class implements SourceAdapterInterface
        {
            public function getSourceCode(): string
            {
                return 'timeout_source';
            }

            public function getSourceName(): string
            {
                return 'Timeout Source';
            }

            public function search(string $query, int $limit = 20): array
            {
                usleep(20000); // 20ms

                return ['status' => 'completed', 'data' => []];
            }
        };

        $coordinator = new CatalogSearchCoordinator([$timeoutAdapter], perSourceTimeoutSeconds: 0.01);
        $coordinator->executeStream('search_test_timeout', 'test', 10, $eventEmitter);

        $failedEvents = array_filter($events, fn ($e) => $e['event'] === 'source_failed');
        $this->assertNotEmpty($failedEvents);
        $failed = reset($failedEvents);
        $this->assertSame('SOURCE_TIMEOUT', $failed['payload']['error_code']);
    }
}
