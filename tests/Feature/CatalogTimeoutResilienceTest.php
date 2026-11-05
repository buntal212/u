<?php

namespace Tests\Feature;

use App\Services\Catalog\Adapters\HttpSourceAdapter;
use App\Services\Catalog\CatalogSearchCoordinator;
use App\Services\Catalog\CatalogSourceConfigParser;
use App\Services\Catalog\CatalogSourceRegistry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CatalogTimeoutResilienceTest extends TestCase
{
    // 1. Satu ASU timeout tidak menghentikan hasil dari ASU lain yang berhasil
    public function test_one_asu_timeout_does_not_stop_other_successful_asu(): void
    {
        $adapterTimeout = new HttpSourceAdapter(
            sourceCode: 'slow_asu',
            sourceName: 'Slow ASU',
            baseUrl: 'http://slow-asu.internal:8000',
            token: 'secret-slow-token-111',
            timeoutSeconds: 1.0,
            connectTimeoutSeconds: 0.5
        );

        $adapterSuccess = new HttpSourceAdapter(
            sourceCode: 'fast_asu',
            sourceName: 'Fast ASU',
            baseUrl: 'http://fast-asu.internal:8000',
            token: 'secret-fast-token-222',
            timeoutSeconds: 2.0,
            connectTimeoutSeconds: 1.0
        );

        Http::fake([
            'http://slow-asu.internal:8000/*' => function () {
                throw new ConnectionException('cURL error 28: Operation timed out after 1000 milliseconds with 0 bytes received');
            },
            'http://fast-asu.internal:8000/*' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'id' => 'item-fast-1',
                        'name' => 'Fast Service',
                        'price' => ['amount' => 50000, 'currency' => 'IDR'],
                    ],
                ],
            ], 200),
        ]);

        $events = [];
        $eventEmitter = function (string $event, array $payload) use (&$events) {
            $events[] = ['event' => $event, 'payload' => $payload];
        };

        $coordinator = new CatalogSearchCoordinator([$adapterTimeout, $adapterSuccess]);
        $coordinator->executeStream('search_resilience_1', 'service', 10, $eventEmitter);

        $this->assertNotEmpty($events);

        // search_init target berisikan kedua sumber
        $this->assertSame('search_init', $events[0]['event']);
        $this->assertSame(['slow_asu', 'fast_asu'], $events[0]['payload']['targets']);

        // slow_asu menghasilkan source_failed dengan SOURCE_TIMEOUT
        $failedEvents = array_values(array_filter($events, fn ($e) => $e['event'] === 'source_failed'));
        $this->assertCount(1, $failedEvents);
        $this->assertSame('slow_asu', $failedEvents[0]['payload']['source']);
        $this->assertSame('SOURCE_TIMEOUT', $failedEvents[0]['payload']['error_code']);
        $this->assertSame('Koneksi ke aplikasi sumber melebihi batas waktu.', $failedEvents[0]['payload']['message']);

        // fast_asu tetap berhasil dan menghasilkan source_result
        $resultEvents = array_values(array_filter($events, fn ($e) => $e['event'] === 'source_result'));
        $this->assertCount(1, $resultEvents);
        $this->assertSame('fast_asu', $resultEvents[0]['payload']['source']['code']);
        $this->assertSame('Fast Service', $resultEvents[0]['payload']['data'][0]['name']);
        $this->assertSame(50000, $resultEvents[0]['payload']['data'][0]['price']['amount']);

        // search_done merangkum status kedua ASU dengan akurat
        $doneEvents = array_values(array_filter($events, fn ($e) => $e['event'] === 'search_done'));
        $this->assertCount(1, $doneEvents);
        $this->assertSame(1, $doneEvents[0]['payload']['total_items']);
        $this->assertSame([
            'slow_asu' => 'failed',
            'fast_asu' => 'completed',
        ], $doneEvents[0]['payload']['summary']);
    }

    // 2. Beberapa ASU mengalami error berbeda (timeout, connection refused) dan streaming tetap tuntas
    public function test_multiple_asus_with_different_errors_stream_independently(): void
    {
        $asu1 = new HttpSourceAdapter('asu_timeout', 'ASU Timeout', 'http://asu1.internal:8000');
        $asu2 = new HttpSourceAdapter('asu_refused', 'ASU Refused', 'http://asu2.internal:8000');
        $asu3 = new HttpSourceAdapter('asu_success', 'ASU Success', 'http://asu3.internal:8000');

        Http::fake([
            'http://asu1.internal:8000/*' => function () {
                throw new ConnectionException('cURL error 28: Connection timed out after 1000 milliseconds');
            },
            'http://asu2.internal:8000/*' => function () {
                throw new ConnectionException('cURL error 7: Failed to connect to asu2.internal port 8000: Connection refused');
            },
            'http://asu3.internal:8000/*' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'id' => 'item-asu3',
                        'name' => 'Produk ASU 3',
                        'price' => ['amount' => 12000, 'currency' => 'IDR'],
                    ],
                ],
            ], 200),
        ]);

        $events = [];
        $eventEmitter = function (string $event, array $payload) use (&$events) {
            $events[] = ['event' => $event, 'payload' => $payload];
        };

        $coordinator = new CatalogSearchCoordinator([$asu1, $asu2, $asu3]);
        $coordinator->executeStream('search_mixed_1', 'produk', 10, $eventEmitter);

        $failedEvents = array_values(array_filter($events, fn ($e) => $e['event'] === 'source_failed'));
        $this->assertCount(2, $failedEvents);

        $this->assertSame('asu_timeout', $failedEvents[0]['payload']['source']);
        $this->assertSame('SOURCE_TIMEOUT', $failedEvents[0]['payload']['error_code']);

        $this->assertSame('asu_refused', $failedEvents[1]['payload']['source']);
        $this->assertSame('SOURCE_CONNECTION_ERROR', $failedEvents[1]['payload']['error_code']);

        $resultEvents = array_values(array_filter($events, fn ($e) => $e['event'] === 'source_result'));
        $this->assertCount(1, $resultEvents);
        $this->assertSame('asu_success', $resultEvents[0]['payload']['source']['code']);

        $doneEvents = array_values(array_filter($events, fn ($e) => $e['event'] === 'search_done'));
        $this->assertSame([
            'asu_timeout' => 'failed',
            'asu_refused' => 'failed',
            'asu_success' => 'completed',
        ], $doneEvents[0]['payload']['summary']);
    }

    // 3. Seluruh ASU gagal tidak menyebabkan exception liar; search_done tetap terbit dengan total_items 0
    public function test_all_asus_failing_still_completes_search_done_stream(): void
    {
        $asu1 = new HttpSourceAdapter('asu_fail_1', 'Fail 1', 'http://fail1.internal:8000');
        $asu2 = new HttpSourceAdapter('asu_fail_2', 'Fail 2', 'http://fail2.internal:8000');

        Http::fake([
            'http://fail1.internal:8000/*' => function () {
                throw new ConnectionException('cURL error 28: Operation timed out');
            },
            'http://fail2.internal:8000/*' => Http::response('Server Error', 503),
        ]);

        $events = [];
        $eventEmitter = function (string $event, array $payload) use (&$events) {
            $events[] = ['event' => $event, 'payload' => $payload];
        };

        $coordinator = new CatalogSearchCoordinator([$asu1, $asu2]);
        $coordinator->executeStream('search_all_failed', 'item', 10, $eventEmitter);

        $doneEvents = array_values(array_filter($events, fn ($e) => $e['event'] === 'search_done'));
        $this->assertCount(1, $doneEvents);
        $this->assertSame(0, $doneEvents[0]['payload']['total_items']);
        $this->assertSame([
            'asu_fail_1' => 'failed',
            'asu_fail_2' => 'failed',
        ], $doneEvents[0]['payload']['summary']);
    }

    // 4. Correlation ID tetap konsisten di seluruh permintaan outbound meskipun sebagian mengalami timeout
    public function test_correlation_id_is_consistent_across_all_adapters_on_failure_and_success(): void
    {
        $customCorrelationId = 'cid-resilience-trace-999';

        $httpRequest = \Illuminate\Http\Request::create('/catalog/search', 'GET');
        $httpRequest->headers->set('X-Correlation-ID', $customCorrelationId);
        $this->app->instance('request', $httpRequest);

        $asu1 = new HttpSourceAdapter('asu_timeout', 'ASU Timeout', 'http://asu-timeout.internal:8000');
        $asu2 = new HttpSourceAdapter('asu_ok', 'ASU OK', 'http://asu-ok.internal:8000');

        $sentRequests = [];
        Http::fake([
            'http://asu-timeout.internal:8000/*' => function (Request $request) use (&$sentRequests) {
                $sentRequests[] = $request;
                throw new ConnectionException('cURL error 28: Operation timed out');
            },
            'http://asu-ok.internal:8000/*' => function (Request $request) use (&$sentRequests) {
                $sentRequests[] = $request;

                return Http::response(['success' => true, 'data' => []], 200);
            },
        ]);

        $coordinator = new CatalogSearchCoordinator([$asu1, $asu2]);
        $coordinator->executeStream('search_cid_resilience', 'query', 10, fn () => null);

        $this->assertCount(2, $sentRequests);

        $req1Cid = $sentRequests[0]->header('X-Correlation-ID');
        $req2Cid = $sentRequests[1]->header('X-Correlation-ID');

        $this->assertSame([$customCorrelationId], $req1Cid);
        $this->assertSame([$customCorrelationId], $req2Cid);
    }

    // 5. Token rahasia tidak pernah bocor ke event payload SSE ketika terjadi timeout
    public function test_token_does_not_leak_into_sse_events_on_timeout(): void
    {
        $secretToken = 'super-secret-auth-token-999888';

        $adapter = new HttpSourceAdapter(
            sourceCode: 'secure_source',
            sourceName: 'Secure Source',
            baseUrl: 'http://secure-source.internal:8000',
            token: $secretToken,
            timeoutSeconds: 1.0,
            connectTimeoutSeconds: 0.5
        );

        Http::fake([
            'http://secure-source.internal:8000/*' => function () {
                throw new ConnectionException('cURL error 28: Operation timed out after 1000 milliseconds with 0 bytes received');
            },
        ]);

        $events = [];
        $eventEmitter = function (string $event, array $payload) use (&$events) {
            $events[] = ['event' => $event, 'payload' => $payload];
        };

        $coordinator = new CatalogSearchCoordinator([$adapter]);
        $coordinator->executeStream('search_secret_test', 'query', 10, $eventEmitter);

        $this->assertNotEmpty($events);

        foreach ($events as $entry) {
            $jsonPayload = json_encode($entry);
            $this->assertStringNotContainsString($secretToken, $jsonPayload);
        }
    }

    // 6. Registry mengonfigurasi timeout dan connect_timeout masing-masing ASU secara independen
    public function test_registry_configures_independent_timeouts_for_each_asu(): void
    {
        $json = json_encode([
            'larissama' => [
                'name' => 'Larissama',
                'url' => 'http://larissama-api:8000',
                'timeout' => 2.0,
                'connect_timeout' => 1.0,
            ],
            'laundry' => [
                'name' => 'Laundry',
                'url' => 'http://laundry-api:8000',
                'timeout' => 3.5,
                'connect_timeout' => 1.5,
            ],
            'asset' => [
                'name' => 'Asset',
                'url' => 'http://aset-api:8000',
                'timeout' => 0.8,
                'connect_timeout' => 0.4,
            ],
        ]);

        $parser = new CatalogSourceConfigParser;
        $registry = new CatalogSourceRegistry($parser, $json, 'dynamic');
        $adapters = $registry->getAdapters();

        $this->assertCount(3, $adapters);

        /** @var HttpSourceAdapter $larissama */
        $larissama = $adapters['larissama'];
        $this->assertSame(2.0, $larissama->getTimeoutSeconds());
        $this->assertSame(1.0, $larissama->getConnectTimeoutSeconds());

        /** @var HttpSourceAdapter $laundry */
        $laundry = $adapters['laundry'];
        $this->assertSame(3.5, $laundry->getTimeoutSeconds());
        $this->assertSame(1.5, $laundry->getConnectTimeoutSeconds());

        /** @var HttpSourceAdapter $asset */
        $asset = $adapters['asset'];
        $this->assertSame(0.8, $asset->getTimeoutSeconds());
        $this->assertSame(0.4, $asset->getConnectTimeoutSeconds());
    }

    // 7. Coordinator menghormati timeout adapter yang lebih besar dari default per-source timeout
    public function test_coordinator_respects_custom_adapter_timeout_higher_than_coordinator_default(): void
    {
        $adapterCustom = new HttpSourceAdapter(
            sourceCode: 'custom_timeout_asu',
            sourceName: 'Custom Timeout ASU',
            baseUrl: 'http://custom-timeout.internal:8000',
            timeoutSeconds: 3.0,
            connectTimeoutSeconds: 1.0
        );

        Http::fake([
            'http://custom-timeout.internal:8000/*' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'id' => 'item-c1',
                        'name' => 'Custom Item',
                        'price' => ['amount' => 10000, 'currency' => 'IDR'],
                    ],
                ],
            ], 200),
        ]);

        $events = [];
        $eventEmitter = function (string $event, array $payload) use (&$events) {
            $events[] = ['event' => $event, 'payload' => $payload];
        };

        // Coordinator default perSourceTimeout is 2.0, but adapter is configured with 3.0
        $coordinator = new CatalogSearchCoordinator([$adapterCustom], perSourceTimeoutSeconds: 2.0);
        $coordinator->executeStream('search_custom_t', 'item', 10, $eventEmitter);

        $resultEvents = array_values(array_filter($events, fn ($e) => $e['event'] === 'source_result'));
        $this->assertCount(1, $resultEvents);
        $this->assertSame('custom_timeout_asu', $resultEvents[0]['payload']['source']['code']);
    }
}
