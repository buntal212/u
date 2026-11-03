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

        // 2. Middle events are source_result
        $eventNames = array_column($events, 'event');
        $this->assertContains('source_result', $eventNames);

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
        $this->assertSame(0, $events[1]['payload']['items_count']);
    }

    public function test_normalizes_items_to_canonical_catalog_model(): void
    {
        $coordinator = new CatalogSearchCoordinator([]);

        $rawItem = [
            'source' => ['code' => 'custom_source'],
            'offer' => [
                'id' => '123',
                'name' => 'Produk Uji',
            ],
            'provider' => [
                'id' => 'prov-1',
                'name' => 'Penyedia Uji',
            ],
            'contact' => [
                'phone' => '081234',
            ],
        ];

        $normalized = $coordinator->normalizeItem($rawItem, 'fallback_code');

        $this->assertSame('custom_source', $normalized['source']['code']);
        $this->assertSame('123', $normalized['offer']['id']);
        $this->assertSame('Produk Uji', $normalized['offer']['name']);
        $this->assertSame('prov-1', $normalized['provider']['id']);
        $this->assertSame('081234', $normalized['contact']['phone']);
        $this->assertNull($normalized['contact']['whatsapp']);
        $this->assertNull($normalized['image']);
        $this->assertNull($normalized['availability']);
    }

    public function test_handles_per_source_timeout(): void
    {
        $events = [];
        $eventEmitter = function (string $event, array $payload) use (&$events): void {
            $events[] = ['event' => $event, 'payload' => $payload];
        };

        // Create a custom adapter that sleeps 50ms, while perSourceTimeout is set to 0.01s (10ms)
        $timeoutAdapter = new class implements SourceAdapterInterface {
            public function getSourceCode(): string { return 'timeout_source'; }
            public function getSourceName(): string { return 'Timeout Source'; }
            public function search(string $query, int $limit = 20): array {
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
