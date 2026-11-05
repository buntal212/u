<?php

namespace App\Contracts\Catalog;

interface SourceAdapterInterface
{
    public function getSourceCode(): string;

    public function getSourceName(): string;

    /**
     * Search the source and return catalog items adhering to Canonical Catalog Contract v1.
     *
     * @return array{
     *     status: 'completed'|'failed',
     *     data?: array<int, array<string, mixed>>,
     *     error_code?: string,
     *     message?: string
     * }
     */
    public function search(string $query, int $limit = 20): array;
}
