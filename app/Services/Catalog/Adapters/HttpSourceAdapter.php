<?php

namespace App\Services\Catalog\Adapters;

use App\Contracts\Catalog\SourceAdapterInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

class HttpSourceAdapter implements SourceAdapterInterface
{
    public function __construct(
        protected string $sourceCode,
        protected string $sourceName,
        protected string $baseUrl,
        protected ?string $token = null,
        protected float $timeoutSeconds = 2.0,
        protected float $connectTimeoutSeconds = 1.0,
        protected string $searchPath = '/api/v1/s2s/catalog/search',
        protected ?LoggerInterface $logger = null
    ) {}

    public function getSourceCode(): string
    {
        return $this->sourceCode;
    }

    public function getSourceName(): string
    {
        return $this->sourceName;
    }

    /**
     * Search the external source via HTTP S2S API.
     *
     * @return array{
     *     status: 'completed'|'failed',
     *     data?: array<int, array<string, mixed>>,
     *     error_code?: string,
     *     message?: string
     * }
     */
    public function search(string $query, int $limit = 20): array
    {
        $url = rtrim($this->baseUrl, '/').'/'.ltrim($this->searchPath, '/');
        $correlationId = $this->resolveCorrelationId();

        try {
            $client = Http::timeout($this->timeoutSeconds)
                ->connectTimeout($this->connectTimeoutSeconds)
                ->acceptJson();

            if (! empty($this->token)) {
                $client = $client->withToken($this->token);
            }

            if (! empty($correlationId)) {
                $client = $client->withHeaders([
                    'X-Correlation-ID' => $correlationId,
                ]);
            }

            $response = $client->get($url, [
                'q' => $query,
                'limit' => $limit,
            ]);

            // 1. Authentication / Authorization Failure (401 / 403)
            if ($response->status() === 401 || $response->status() === 403) {
                $this->logWarning('Source authentication or authorization failed', [
                    'source' => $this->sourceCode,
                    'status' => $response->status(),
                ]);

                return [
                    'status' => 'failed',
                    'error_code' => 'SOURCE_AUTH_ERROR',
                    'message' => 'Autentikasi ke aplikasi sumber gagal.',
                ];
            }

            // 2. Request validation failure at source (422)
            if ($response->status() === 422) {
                $this->logWarning('Source rejected search parameters', [
                    'source' => $this->sourceCode,
                    'status' => 422,
                ]);

                return [
                    'status' => 'failed',
                    'error_code' => 'SOURCE_INVALID_REQUEST',
                    'message' => 'Parameter pencarian ditolak oleh aplikasi sumber.',
                ];
            }

            // 3. Upstream Server Error (5xx or other non-success status codes)
            if (! $response->successful()) {
                $this->logWarning('Source server returned error status', [
                    'source' => $this->sourceCode,
                    'status' => $response->status(),
                ]);

                return [
                    'status' => 'failed',
                    'error_code' => 'SOURCE_UNAVAILABLE',
                    'message' => 'Aplikasi sumber sedang mengalami gangguan.',
                ];
            }

            // 4. Validate JSON payload
            $json = $response->json();
            if ($json === null || ! is_array($json)) {
                $this->logWarning('Source response is not valid JSON', [
                    'source' => $this->sourceCode,
                ]);

                return [
                    'status' => 'failed',
                    'error_code' => 'SOURCE_MALFORMED_RESPONSE',
                    'message' => 'Format data dari aplikasi sumber tidak valid.',
                ];
            }

            // Extract data array (supports envelope `{ success: true, data: [...] }` or root list `[...]`)
            if (array_key_exists('data', $json)) {
                if (! is_array($json['data'])) {
                    $this->logWarning('Source response data property is not an array', [
                        'source' => $this->sourceCode,
                    ]);

                    return [
                        'status' => 'failed',
                        'error_code' => 'SOURCE_MALFORMED_RESPONSE',
                        'message' => 'Format data dari aplikasi sumber tidak valid.',
                    ];
                }

                $items = $json['data'];
            } elseif (array_is_list($json)) {
                $items = $json;
            } else {
                $this->logWarning('Source response lacks data array property', [
                    'source' => $this->sourceCode,
                ]);

                return [
                    'status' => 'failed',
                    'error_code' => 'SOURCE_MALFORMED_RESPONSE',
                    'message' => 'Format data dari aplikasi sumber tidak valid.',
                ];
            }

            return [
                'status' => 'completed',
                'data' => $items,
            ];
        } catch (ConnectionException $e) {
            $isTimeout = str_contains(strtolower($e->getMessage()), 'timeout')
                || str_contains(strtolower($e->getMessage()), 'timed out');

            $this->logWarning($isTimeout ? 'Source request timed out' : 'Source connection failed', [
                'source' => $this->sourceCode,
            ]);

            return [
                'status' => 'failed',
                'error_code' => $isTimeout ? 'SOURCE_TIMEOUT' : 'SOURCE_CONNECTION_ERROR',
                'message' => $isTimeout
                    ? 'Koneksi ke aplikasi sumber melebihi batas waktu.'
                    : 'Tidak dapat terhubung ke aplikasi sumber.',
            ];
        } catch (\Throwable $e) {
            $this->logWarning('Unexpected exception during source HTTP search', [
                'source' => $this->sourceCode,
                'exception' => $e::class,
            ]);

            return [
                'status' => 'failed',
                'error_code' => 'SOURCE_ERROR',
                'message' => 'Terjadi kesalahan saat menghubungi aplikasi sumber.',
            ];
        }
    }

    /**
     * Resolve correlation ID safely from request context or generate a new UUID.
     */
    protected function resolveCorrelationId(): string
    {
        if (class_exists(Facade::class) && Facade::getFacadeApplication() !== null) {
            try {
                $header = request()->header('X-Correlation-ID');
                if (is_string($header) && trim($header) !== '') {
                    return trim($header);
                }
            } catch (\Throwable) {
                // Ignore and fallback to UUID
            }
        }

        return (string) Str::uuid();
    }

    /**
     * Safely log a warning without logging sensitive credentials or throwing when container is not booted.
     *
     * @param  array<string, mixed>  $context
     */
    protected function logWarning(string $message, array $context = []): void
    {
        // Enforce safe context: never log tokens, passwords, or auth headers
        unset($context['token'], $context['password'], $context['authorization'], $context['secret']);

        if ($this->logger !== null) {
            $this->logger->warning($message, $context);

            return;
        }

        if (class_exists(Log::class) && Facade::getFacadeApplication() !== null) {
            Log::warning($message, $context);
        }
    }
}
