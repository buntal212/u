<?php

namespace Tests\Unit;

use App\Services\Catalog\Adapters\HttpSourceAdapter;
use App\Services\Catalog\CatalogSearchCoordinator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class HttpSourceAdapterTest extends TestCase
{
    protected string $baseUrl = 'http://source-api.internal:8000';

    protected string $token = 'secret-test-token-123';

    protected HttpSourceAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adapter = new HttpSourceAdapter(
            sourceCode: 'test_source',
            sourceName: 'Test Source Application',
            baseUrl: $this->baseUrl,
            token: $this->token,
            timeoutSeconds: 2.0,
            connectTimeoutSeconds: 1.0,
            searchPath: '/api/v1/s2s/catalog/search'
        );
    }

    public function test_getters_return_configured_metadata(): void
    {
        $this->assertSame('test_source', $this->adapter->getSourceCode());
        $this->assertSame('Test Source Application', $this->adapter->getSourceName());
    }

    // 1. HTTP 200 dengan payload valid
    public function test_handles_http_200_with_valid_payload(): void
    {
        $mockItems = [
            [
                'id' => 'item-101',
                'name' => 'Kopi Robusta',
                'category' => 'Minuman',
                'price' => ['amount' => 18000, 'currency' => 'IDR'],
            ],
        ];

        Http::fake([
            $this->baseUrl.'/*' => Http::response([
                'success' => true,
                'data' => $mockItems,
                'meta' => ['total' => 1, 'limit' => 20],
            ], 200),
        ]);

        $result = $this->adapter->search('kopi', 10);

        $this->assertSame('completed', $result['status']);
        $this->assertCount(1, $result['data']);
        $this->assertSame('item-101', $result['data'][0]['id']);
        $this->assertSame('Kopi Robusta', $result['data'][0]['name']);
    }

    // 2. HTTP 200 dengan payload kosong yang valid
    public function test_handles_http_200_with_valid_empty_payload(): void
    {
        Http::fake([
            $this->baseUrl.'/*' => Http::response([
                'success' => true,
                'data' => [],
                'meta' => ['total' => 0, 'limit' => 20],
            ], 200),
        ]);

        $result = $this->adapter->search('tidakada', 10);

        $this->assertSame('completed', $result['status']);
        $this->assertSame([], $result['data']);
    }

    // 3. HTTP 401
    public function test_handles_http_401_authentication_failure(): void
    {
        Http::fake([
            $this->baseUrl.'/*' => Http::response([
                'message' => 'Unauthenticated server-to-server request.',
            ], 401),
        ]);

        $result = $this->adapter->search('kopi', 10);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('SOURCE_AUTH_ERROR', $result['error_code']);
        $this->assertSame('Autentikasi ke aplikasi sumber gagal.', $result['message']);
    }

    // 4. HTTP 403
    public function test_handles_http_403_authorization_failure(): void
    {
        Http::fake([
            $this->baseUrl.'/*' => Http::response([
                'message' => 'Forbidden resource.',
            ], 403),
        ]);

        $result = $this->adapter->search('kopi', 10);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('SOURCE_AUTH_ERROR', $result['error_code']);
        $this->assertSame('Autentikasi ke aplikasi sumber gagal.', $result['message']);
    }

    // 5. HTTP 422
    public function test_handles_http_422_validation_failure(): void
    {
        Http::fake([
            $this->baseUrl.'/*' => Http::response([
                'message' => 'The limit field must not be greater than 50.',
                'errors' => ['limit' => ['The limit field must not be greater than 50.']],
            ], 422),
        ]);

        $result = $this->adapter->search('kopi', 100);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('SOURCE_INVALID_REQUEST', $result['error_code']);
        $this->assertSame('Parameter pencarian ditolak oleh aplikasi sumber.', $result['message']);
    }

    // 6. HTTP 500
    public function test_handles_http_500_upstream_server_error(): void
    {
        Http::fake([
            $this->baseUrl.'/*' => Http::response('Internal Server Error', 500),
        ]);

        $result = $this->adapter->search('kopi', 10);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('SOURCE_UNAVAILABLE', $result['error_code']);
        $this->assertSame('Aplikasi sumber sedang mengalami gangguan.', $result['message']);
    }

    // 7. Request timeout
    public function test_handles_request_timeout(): void
    {
        Http::fake([
            $this->baseUrl.'/*' => function () {
                throw new ConnectionException('cURL error 28: Operation timed out after 2000 milliseconds with 0 bytes received');
            },
        ]);

        $result = $this->adapter->search('kopi', 10);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('SOURCE_TIMEOUT', $result['error_code']);
        $this->assertSame('Koneksi ke aplikasi sumber melebihi batas waktu.', $result['message']);
    }

    // 8. Connection/network failure
    public function test_handles_connection_network_failure(): void
    {
        Http::fake([
            $this->baseUrl.'/*' => function () {
                throw new ConnectionException('cURL error 7: Failed to connect to source-api.internal port 8000: Connection refused');
            },
        ]);

        $result = $this->adapter->search('kopi', 10);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('SOURCE_CONNECTION_ERROR', $result['error_code']);
        $this->assertSame('Tidak dapat terhubung ke aplikasi sumber.', $result['message']);
    }

    // 9. JSON rusak
    public function test_handles_malformed_non_json_response(): void
    {
        Http::fake([
            $this->baseUrl.'/*' => Http::response('<html><body>502 Bad Gateway</body></html>', 200, [
                'Content-Type' => 'text/html',
            ]),
        ]);

        $result = $this->adapter->search('kopi', 10);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('SOURCE_MALFORMED_RESPONSE', $result['error_code']);
        $this->assertSame('Format data dari aplikasi sumber tidak valid.', $result['message']);
    }

    // 10. Payload JSON dengan struktur tidak sesuai
    public function test_handles_json_with_unexpected_structure(): void
    {
        // Case 10a: 'data' is not an array (e.g. string)
        Http::fake([
            $this->baseUrl.'/*' => Http::response([
                'success' => true,
                'data' => 'invalid-should-be-array',
            ], 200),
        ]);

        $result = $this->adapter->search('kopi', 10);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('SOURCE_MALFORMED_RESPONSE', $result['error_code']);

        // Case 10b: associative array without 'data' property
        Http::fake([
            $this->baseUrl.'/*' => Http::response([
                'status' => 'ok',
                'other_field' => 123,
            ], 200),
        ]);

        $result2 = $this->adapter->search('kopi', 10);

        $this->assertSame('failed', $result2['status']);
        $this->assertSame('SOURCE_MALFORMED_RESPONSE', $result2['error_code']);
    }

    // 11. Memastikan request mengirim query dan limit yang benar
    public function test_sends_correct_query_parameters_and_headers(): void
    {
        Http::fake([
            $this->baseUrl.'/*' => Http::response(['success' => true, 'data' => []], 200),
        ]);

        $this->adapter->search('nasi goreng', 25);

        Http::assertSent(function (Request $request) {
            $hasCorrectUrl = str_starts_with($request->url(), $this->baseUrl.'/api/v1/s2s/catalog/search');
            $hasQuery = $request['q'] === 'nasi goreng';
            $hasLimit = (int) $request['limit'] === 25;
            $hasAuth = $request->hasHeader('Authorization', 'Bearer '.$this->token);
            $hasAccept = $request->hasHeader('Accept', 'application/json');
            $hasCorrelationId = $request->hasHeader('X-Correlation-ID');

            return $hasCorrectUrl && $hasQuery && $hasLimit && $hasAuth && $hasAccept && $hasCorrelationId;
        });
    }

    // 12. Memastikan token/secret tidak bocor ke log atau pesan error
    public function test_ensures_token_does_not_leak_into_logs_or_error_messages(): void
    {
        $loggedContexts = [];
        $mockLogger = $this->createMock(LoggerInterface::class);
        $mockLogger->expects($this->any())
            ->method('warning')
            ->willReturnCallback(function (string $message, array $context) use (&$loggedContexts) {
                $loggedContexts[] = ['message' => $message, 'context' => $context];
            });

        $adapterWithLogger = new HttpSourceAdapter(
            sourceCode: 'test_secure',
            sourceName: 'Secure Source',
            baseUrl: $this->baseUrl,
            token: 'super-sensitive-token-secret-999',
            timeoutSeconds: 2.0,
            connectTimeoutSeconds: 1.0,
            searchPath: '/api/v1/s2s/catalog/search',
            logger: $mockLogger
        );

        Http::fake([
            $this->baseUrl.'/*' => Http::response('Unauthorized', 401),
        ]);

        $result = $adapterWithLogger->search('kopi', 10);

        // Check error message returned to caller
        $this->assertStringNotContainsString('super-sensitive-token', $result['message']);
        $this->assertStringNotContainsString('secret-999', $result['message']);

        // Check logged contents
        $this->assertNotEmpty($loggedContexts);
        foreach ($loggedContexts as $entry) {
            $contextString = json_encode($entry);
            $this->assertStringNotContainsString('super-sensitive-token', $contextString);
            $this->assertStringNotContainsString('secret-999', $contextString);
            $this->assertArrayNotHasKey('token', $entry['context']);
            $this->assertArrayNotHasKey('password', $entry['context']);
            $this->assertArrayNotHasKey('authorization', $entry['context']);
        }
    }

    // 13. Integrasi mulus dengan CatalogSearchCoordinator
    public function test_integrates_seamlessly_with_catalog_search_coordinator(): void
    {
        Http::fake([
            $this->baseUrl.'/*' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'id' => 'food-001',
                        'code' => 'MNU-101',
                        'name' => 'Sate Ayam Madura',
                        'category' => 'Makanan Utama',
                        'price' => ['amount' => 30000, 'currency' => 'IDR'],
                        'image' => ['url' => 'https://example.com/sate.jpg'],
                        'availability' => 'available',
                        'provider' => [
                            'id' => 'prov-1',
                            'name' => 'Warung Sate Barokah',
                            'location' => ['address' => 'Jl. Magelang KM 4'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $events = [];
        $eventEmitter = function (string $event, array $payload) use (&$events) {
            $events[] = ['event' => $event, 'payload' => $payload];
        };

        $coordinator = new CatalogSearchCoordinator([$this->adapter]);
        $coordinator->executeStream('search_http_1', 'sate', 10, $eventEmitter);

        $this->assertNotEmpty($events);
        $this->assertSame('search_init', $events[0]['event']);
        $this->assertSame(['test_source'], $events[0]['payload']['targets']);

        $sourceResultEvents = array_values(array_filter($events, fn ($e) => $e['event'] === 'source_result'));
        $this->assertCount(1, $sourceResultEvents);

        $payload = $sourceResultEvents[0]['payload'];
        $this->assertSame('test_source', $payload['source']['code']);
        $this->assertSame('Test Source Application', $payload['source']['name']);
        $this->assertSame(1, $payload['meta']['total']);

        // Verify Canonical Catalog v1 normalization on item
        $item = $payload['data'][0];
        $this->assertSame('food-001', $item['id']);
        $this->assertSame('Sate Ayam Madura', $item['name']);
        $this->assertSame(30000, $item['price']['amount']);
        $this->assertSame('IDR', $item['price']['currency']);
        $this->assertSame('Warung Sate Barokah', $item['provider']['name']);
        $this->assertSame('Jl. Magelang KM 4', $item['provider']['location']['address']);
        $this->assertSame('test_source', $item['source']['code']);

        $searchDoneEvents = array_values(array_filter($events, fn ($e) => $e['event'] === 'search_done'));
        $this->assertCount(1, $searchDoneEvents);
        $this->assertSame(1, $searchDoneEvents[0]['payload']['total_items']);
        $this->assertSame(['test_source' => 'completed'], $searchDoneEvents[0]['payload']['summary']);
    }

    public function test_handles_http_400_bad_request_as_invalid_request(): void
    {
        Http::fake([
            $this->baseUrl.'/*' => Http::response([
                'message' => 'Malformed query string syntax.',
            ], 400),
        ]);

        $result = $this->adapter->search('kopi', 10);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('SOURCE_INVALID_REQUEST', $result['error_code']);
        $this->assertSame('Permintaan ke aplikasi sumber tidak valid.', $result['message']);
    }

    public function test_handles_http_404_not_found_as_invalid_request(): void
    {
        Http::fake([
            $this->baseUrl.'/*' => Http::response([
                'message' => 'Endpoint route not found.',
            ], 404),
        ]);

        $result = $this->adapter->search('kopi', 10);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('SOURCE_INVALID_REQUEST', $result['error_code']);
        $this->assertSame('Permintaan ke aplikasi sumber tidak valid.', $result['message']);
    }

    public function test_handles_http_429_rate_limiting_as_source_unavailable(): void
    {
        Http::fake([
            $this->baseUrl.'/*' => Http::response([
                'message' => 'Too many requests.',
            ], 429),
        ]);

        $result = $this->adapter->search('kopi', 10);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('SOURCE_UNAVAILABLE', $result['error_code']);
        $this->assertSame('Aplikasi sumber membatasi kuota permintaan.', $result['message']);
    }

    public function test_handles_http_5xx_upstream_server_errors_consistently(): void
    {
        $statusCodes = [500, 502, 503, 504];

        foreach ($statusCodes as $status) {
            Http::fake([
                $this->baseUrl.'/*' => Http::response('Server Error '.$status, $status),
            ]);

            $result = $this->adapter->search('kopi', 10);

            $this->assertSame('failed', $result['status'], "Failed asserting status failed for HTTP {$status}");
            $this->assertSame('SOURCE_UNAVAILABLE', $result['error_code'], "Failed asserting error_code for HTTP {$status}");
            $this->assertSame('Aplikasi sumber sedang mengalami gangguan.', $result['message'], "Failed asserting message for HTTP {$status}");
        }
    }

    public function test_explicit_correlation_id_setters_and_getters(): void
    {
        $this->assertNull($this->adapter->getCorrelationId());

        $this->adapter->setCorrelationId('custom-cid-1');
        $this->assertSame('custom-cid-1', $this->adapter->getCorrelationId());

        $cloned = $this->adapter->withCorrelationId('custom-cid-2');
        $this->assertSame('custom-cid-1', $this->adapter->getCorrelationId());
        $this->assertSame('custom-cid-2', $cloned->getCorrelationId());
    }

    public function test_propagates_incoming_x_correlation_id_header_consistently(): void
    {
        $incomingCid = 'test-header-cid-999';

        $httpRequest = \Illuminate\Http\Request::create('/catalog/search', 'GET');
        $httpRequest->headers->set('X-Correlation-ID', $incomingCid);
        $this->app->instance('request', $httpRequest);

        Http::fake([
            $this->baseUrl.'/*' => Http::response(['success' => true, 'data' => []], 200),
        ]);

        $this->adapter->search('kopi', 10);

        Http::assertSent(function (Request $request) use ($incomingCid) {
            return $request->header('X-Correlation-ID') === [$incomingCid];
        });
    }

    public function test_generates_consistent_correlation_id_across_multiple_adapters_when_header_absent(): void
    {
        $httpRequest = \Illuminate\Http\Request::create('/catalog/search', 'GET');
        $this->app->instance('request', $httpRequest);

        $adapterA = new HttpSourceAdapter(
            sourceCode: 'source_a',
            sourceName: 'Source A',
            baseUrl: 'http://source-a.internal:8000',
        );

        $adapterB = new HttpSourceAdapter(
            sourceCode: 'source_b',
            sourceName: 'Source B',
            baseUrl: 'http://source-b.internal:8000',
        );

        Http::fake([
            'http://source-a.internal:8000/*' => Http::response(['success' => true, 'data' => []], 200),
            'http://source-b.internal:8000/*' => Http::response(['success' => true, 'data' => []], 200),
        ]);

        $events = [];
        $eventEmitter = function (string $event, array $payload) use (&$events) {
            $events[] = ['event' => $event, 'payload' => $payload];
        };

        $coordinator = new CatalogSearchCoordinator([$adapterA, $adapterB]);
        $coordinator->executeStream('search_cid_test', 'test', 10, $eventEmitter);

        $sentRequests = Http::recorded();
        $this->assertCount(2, $sentRequests);

        $cidA = $sentRequests[0][0]->header('X-Correlation-ID');
        $cidB = $sentRequests[1][0]->header('X-Correlation-ID');

        $this->assertNotEmpty($cidA);
        $this->assertNotEmpty($cidB);
        $this->assertSame($cidA, $cidB);
    }

    public function test_safe_correlation_id_generation_outside_http_request(): void
    {
        $this->app->offsetUnset('request');

        $adapter = new HttpSourceAdapter(
            sourceCode: 'isolated_source',
            sourceName: 'Isolated Source',
            baseUrl: $this->baseUrl,
        );

        Http::fake([
            $this->baseUrl.'/*' => Http::response(['success' => true, 'data' => []], 200),
        ]);

        $result = $adapter->search('kopi', 10);
        $this->assertSame('completed', $result['status']);

        Http::assertSent(function (Request $request) {
            $cid = $request->header('X-Correlation-ID');

            return ! empty($cid) && is_array($cid) && ! empty($cid[0]);
        });
    }
}
