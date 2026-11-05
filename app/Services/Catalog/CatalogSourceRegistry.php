<?php

namespace App\Services\Catalog;

use App\Contracts\Catalog\SourceAdapterInterface;
use App\Services\Catalog\Adapters\HttpSourceAdapter;
use App\Services\Catalog\Adapters\MockFastSourceAdapter;
use App\Services\Catalog\Adapters\MockFlakySourceAdapter;
use App\Services\Catalog\Adapters\MockSlowSourceAdapter;
use App\Services\Catalog\Exceptions\InvalidCatalogSourceException;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

class CatalogSourceRegistry
{
    /**
     * @var array<string, SourceAdapterInterface>|null
     */
    protected ?array $resolvedAdapters = null;

    public function __construct(
        protected CatalogSourceConfigParser $configParser,
        protected ?string $rawSourcesJson = null,
        protected string $mode = 'auto',
        protected float $defaultTimeoutSeconds = 2.0,
        protected float $defaultConnectTimeoutSeconds = 1.0,
        protected ?LoggerInterface $logger = null
    ) {}

    /**
     * Get all registered source adapters based on configuration mode.
     *
     * @return array<string, SourceAdapterInterface>
     *
     * @throws InvalidCatalogSourceException
     */
    public function getAdapters(): array
    {
        if ($this->resolvedAdapters !== null) {
            return $this->resolvedAdapters;
        }

        $mode = strtolower(trim($this->mode));

        if ($mode === 'mock') {
            $this->resolvedAdapters = $this->getMockAdapters();

            return $this->resolvedAdapters;
        }

        if ($mode === 'dynamic') {
            try {
                $sources = $this->configParser->parse($this->rawSourcesJson);
            } catch (InvalidCatalogSourceException $e) {
                $this->logError('Failed to parse dynamic catalog source configuration: '.$e->getMessage());

                throw $e;
            }

            $adapters = [];
            foreach ($sources as $key => $config) {
                $adapters[$key] = $this->buildHttpAdapter($config);
            }

            $this->resolvedAdapters = $adapters;

            return $this->resolvedAdapters;
        }

        // Default 'auto' mode: Use dynamic if provided, otherwise fallback to mock
        if ($this->rawSourcesJson === null || trim($this->rawSourcesJson) === '') {
            $this->resolvedAdapters = $this->getMockAdapters();

            return $this->resolvedAdapters;
        }

        try {
            $sources = $this->configParser->parse($this->rawSourcesJson);
        } catch (InvalidCatalogSourceException $e) {
            $this->logError('Failed to parse catalog source configuration in auto mode: '.$e->getMessage());

            throw $e;
        }

        if (empty($sources)) {
            $this->resolvedAdapters = $this->getMockAdapters();

            return $this->resolvedAdapters;
        }

        $adapters = [];
        foreach ($sources as $key => $config) {
            $adapters[$key] = $this->buildHttpAdapter($config);
        }

        $this->resolvedAdapters = $adapters;

        return $this->resolvedAdapters;
    }

    /**
     * Build an HttpSourceAdapter instance from validated source configuration.
     *
     * @param  array{
     *     key: string,
     *     name: string,
     *     url: string,
     *     token: ?string,
     *     timeout: float,
     *     connect_timeout: float,
     *     search_path: string
     * }  $config
     */
    public function buildHttpAdapter(array $config): HttpSourceAdapter
    {
        return new HttpSourceAdapter(
            sourceCode: $config['key'],
            sourceName: $config['name'],
            baseUrl: $config['url'],
            token: $config['token'] ?? null,
            timeoutSeconds: (float) ($config['timeout'] ?? $this->defaultTimeoutSeconds),
            connectTimeoutSeconds: (float) ($config['connect_timeout'] ?? $this->defaultConnectTimeoutSeconds),
            searchPath: (string) ($config['search_path'] ?? '/api/v1/s2s/catalog/search'),
            logger: $this->logger
        );
    }

    /**
     * Provide standard mock adapters for testing and local development.
     *
     * @return array<string, SourceAdapterInterface>
     */
    public function getMockAdapters(): array
    {
        $fast = new MockFastSourceAdapter;
        $slow = new MockSlowSourceAdapter;
        $flaky = new MockFlakySourceAdapter;

        return [
            $fast->getSourceCode() => $fast,
            $slow->getSourceCode() => $slow,
            $flaky->getSourceCode() => $flaky,
        ];
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function setMode(string $mode): self
    {
        $this->mode = $mode;
        $this->resolvedAdapters = null;

        return $this;
    }

    public function setRawSourcesJson(?string $json): self
    {
        $this->rawSourcesJson = $json;
        $this->resolvedAdapters = null;

        return $this;
    }

    protected function logError(string $message, array $context = []): void
    {
        unset($context['token'], $context['password'], $context['authorization'], $context['secret']);

        if ($this->logger !== null) {
            $this->logger->error($message, $context);

            return;
        }

        if (class_exists(Log::class) && Facade::getFacadeApplication() !== null) {
            Log::error($message, $context);
        }
    }
}
