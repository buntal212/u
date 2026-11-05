<?php

namespace Tests\Unit;

use App\Services\Catalog\Adapters\HttpSourceAdapter;
use App\Services\Catalog\Adapters\MockFastSourceAdapter;
use App\Services\Catalog\CatalogSearchCoordinator;
use App\Services\Catalog\CatalogSourceConfigParser;
use App\Services\Catalog\CatalogSourceRegistry;
use App\Services\Catalog\Exceptions\InvalidCatalogSourceException;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class CatalogSourceRegistryTest extends TestCase
{
    protected CatalogSourceConfigParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new CatalogSourceConfigParser;
    }

    // 1. Mock mode explicitly returns mock adapters
    public function test_mock_mode_returns_standard_mock_adapters(): void
    {
        $registry = new CatalogSourceRegistry(
            configParser: $this->parser,
            rawSourcesJson: '{"larissama": {"url": "http://larissama-api:8000"}}',
            mode: 'mock'
        );

        $adapters = $registry->getAdapters();

        $this->assertCount(3, $adapters);
        $this->assertArrayHasKey('mock_larissama', $adapters);
        $this->assertArrayHasKey('mock_laundry', $adapters);
        $this->assertArrayHasKey('mock_aset', $adapters);
        $this->assertInstanceOf(MockFastSourceAdapter::class, $adapters['mock_larissama']);
    }

    // 2. Auto mode falls back to mock adapters when configuration is empty
    public function test_auto_mode_falls_back_to_mock_when_configuration_is_empty(): void
    {
        // Case a: null
        $registryNull = new CatalogSourceRegistry($this->parser, null, 'auto');
        $adaptersNull = $registryNull->getAdapters();
        $this->assertCount(3, $adaptersNull);
        $this->assertArrayHasKey('mock_larissama', $adaptersNull);

        // Case b: empty string
        $registryEmpty = new CatalogSourceRegistry($this->parser, '', 'auto');
        $this->assertArrayHasKey('mock_larissama', $registryEmpty->getAdapters());

        // Case c: empty json object
        $registryJson = new CatalogSourceRegistry($this->parser, '{}', 'auto');
        $this->assertArrayHasKey('mock_larissama', $registryJson->getAdapters());
    }

    // 3. Auto mode builds HttpSourceAdapter instances when ASU_SOURCES is configured
    public function test_auto_mode_builds_http_adapters_when_asu_sources_configured(): void
    {
        $json = json_encode([
            'larissama' => [
                'name' => 'Larissama Catalog',
                'url' => 'http://larissama-api:8000',
                'token' => 'test-s2s-token-1',
                'timeout' => 2.5,
                'connect_timeout' => 1.5,
                'search_path' => '/api/v1/s2s/catalog/search',
            ],
            'laundry' => [
                'url' => 'http://laundry-api:8000',
                'timeout' => 3.0,
            ],
        ]);

        $registry = new CatalogSourceRegistry($this->parser, $json, 'auto');
        $adapters = $registry->getAdapters();

        $this->assertCount(2, $adapters);
        $this->assertArrayHasKey('larissama', $adapters);
        $this->assertArrayHasKey('laundry', $adapters);

        $this->assertInstanceOf(HttpSourceAdapter::class, $adapters['larissama']);
        $this->assertSame('larissama', $adapters['larissama']->getSourceCode());
        $this->assertSame('Larissama Catalog', $adapters['larissama']->getSourceName());

        $this->assertInstanceOf(HttpSourceAdapter::class, $adapters['laundry']);
        $this->assertSame('laundry', $adapters['laundry']->getSourceCode());
        $this->assertSame('Laundry', $adapters['laundry']->getSourceName());
    }

    // 4. Dynamic mode returns empty array when configuration is empty
    public function test_dynamic_mode_returns_empty_when_config_is_empty(): void
    {
        $registry = new CatalogSourceRegistry($this->parser, '{}', 'dynamic');
        $this->assertSame([], $registry->getAdapters());
    }

    // 5. Dynamic mode builds HttpSourceAdapter instances
    public function test_dynamic_mode_builds_http_adapters_when_configured(): void
    {
        $json = json_encode([
            'larissama' => [
                'url' => 'http://larissama-api:8000',
            ],
        ]);

        $registry = new CatalogSourceRegistry($this->parser, $json, 'dynamic');
        $adapters = $registry->getAdapters();

        $this->assertCount(1, $adapters);
        $this->assertArrayHasKey('larissama', $adapters);
        $this->assertInstanceOf(HttpSourceAdapter::class, $adapters['larissama']);
    }

    // 6. Penambahan ASU baru tanpa perubahan kode inti UIH
    public function test_adding_new_arbitrary_asu_without_code_changes(): void
    {
        $json = json_encode([
            'catering' => [
                'name' => 'Catering Berkah',
                'url' => 'http://catering-api:8000',
                'token' => 'cat-token-999',
            ],
            'asset' => [
                'url' => 'http://asset-api:8000',
            ],
            'pharmacy' => [
                'name' => 'Farmasi Sehat',
                'url' => 'http://pharmacy-api:8000',
            ],
        ]);

        $registry = new CatalogSourceRegistry($this->parser, $json, 'dynamic');
        $adapters = $registry->getAdapters();

        $this->assertCount(3, $adapters);
        $this->assertArrayHasKey('catering', $adapters);
        $this->assertArrayHasKey('asset', $adapters);
        $this->assertArrayHasKey('pharmacy', $adapters);

        $this->assertSame('catering', $adapters['catering']->getSourceCode());
        $this->assertSame('Catering Berkah', $adapters['catering']->getSourceName());

        $this->assertSame('asset', $adapters['asset']->getSourceCode());
        $this->assertSame('Asset', $adapters['asset']->getSourceName());

        $this->assertSame('pharmacy', $adapters['pharmacy']->getSourceCode());
        $this->assertSame('Farmasi Sehat', $adapters['pharmacy']->getSourceName());
    }

    // 7. Invalid configuration throws exception and does not leak token
    public function test_invalid_configuration_throws_exception_without_leaking_token(): void
    {
        $secretToken = 'super-secret-token-do-not-leak-999';

        $invalidJson = json_encode([
            'larissama' => [
                'url' => 'http://example.com',
                'token' => $secretToken,
                'timeout' => 'not-a-number',
            ],
        ]);

        $registry = new CatalogSourceRegistry($this->parser, $invalidJson, 'auto');

        try {
            $registry->getAdapters();
            $this->fail('Expected InvalidCatalogSourceException was not thrown.');
        } catch (InvalidCatalogSourceException $e) {
            $this->assertStringNotContainsString($secretToken, $e->getMessage());
        }
    }

    // 8. Token is never logged when errors occur
    public function test_token_is_not_logged_when_errors_occur(): void
    {
        $loggedContexts = [];
        $mockLogger = $this->createMock(LoggerInterface::class);
        $mockLogger->expects($this->any())
            ->method('error')
            ->willReturnCallback(function (string $message, array $context) use (&$loggedContexts) {
                $loggedContexts[] = ['message' => $message, 'context' => $context];
            });

        $secretToken = 'sensitive-token-should-never-be-in-logs';
        $invalidJson = json_encode([
            'larissama' => [
                'token' => $secretToken,
                // Missing URL intentionally
            ],
        ]);

        $registry = new CatalogSourceRegistry(
            configParser: $this->parser,
            rawSourcesJson: $invalidJson,
            mode: 'auto',
            logger: $mockLogger
        );

        try {
            $registry->getAdapters();
        } catch (InvalidCatalogSourceException) {
            // Expected
        }

        $this->assertNotEmpty($loggedContexts);
        foreach ($loggedContexts as $entry) {
            $fullLogString = json_encode($entry);
            $this->assertStringNotContainsString($secretToken, $fullLogString);
        }
    }

    // 9. Coordinator executes seamlessly with adapters supplied by registry
    public function test_coordinator_executes_seamlessly_with_registry_adapters(): void
    {
        $json = json_encode([
            'larissama' => [
                'name' => 'Larissama S2S',
                'url' => 'http://larissama-test.internal:8000',
            ],
        ]);

        Http::fake([
            'http://larissama-test.internal:8000/*' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'id' => 'lrs-1',
                        'name' => 'Ayam Penyet',
                        'price' => ['amount' => 25000, 'currency' => 'IDR'],
                    ],
                ],
            ], 200),
        ]);

        $registry = new CatalogSourceRegistry($this->parser, $json, 'dynamic');
        $adapters = $registry->getAdapters();

        $coordinator = new CatalogSearchCoordinator(adapters: $adapters);

        $events = [];
        $eventEmitter = function (string $event, array $payload) use (&$events) {
            $events[] = ['event' => $event, 'payload' => $payload];
        };

        $coordinator->executeStream('search_reg_1', 'ayam', 10, $eventEmitter);

        $this->assertNotEmpty($events);
        $this->assertSame('search_init', $events[0]['event']);
        $this->assertSame(['larissama'], $events[0]['payload']['targets']);

        $resultEvents = array_values(array_filter($events, fn ($e) => $e['event'] === 'source_result'));
        $this->assertCount(1, $resultEvents);
        $this->assertSame('larissama', $resultEvents[0]['payload']['source']['code']);
        $this->assertSame('Larissama S2S', $resultEvents[0]['payload']['source']['name']);
        $this->assertSame('Ayam Penyet', $resultEvents[0]['payload']['data'][0]['name']);
    }
}
