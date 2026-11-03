<?php

namespace App\Services\Catalog\Adapters;

use App\Contracts\Catalog\SourceAdapterInterface;

class MockFlakySourceAdapter implements SourceAdapterInterface
{
    public function __construct(
        protected int $delayMicroseconds = 450000 // 450ms
    ) {}

    public function getSourceCode(): string
    {
        return 'mock_aset';
    }

    public function getSourceName(): string
    {
        return 'Aset & Properti';
    }

    public function search(string $query, int $limit = 20): array
    {
        if ($this->delayMicroseconds > 0) {
            usleep($this->delayMicroseconds);
        }

        $normalizedQuery = strtolower(trim($query));

        // Check if query instructs simulated failure
        if (str_contains($normalizedQuery, 'error') || str_contains($normalizedQuery, 'fail') || str_contains($normalizedQuery, 'timeout')) {
            return [
                'status' => 'failed',
                'error_code' => 'SOURCE_TIMEOUT',
                'message' => 'Aplikasi sumber Aset & Properti tidak merespons dalam batas waktu.',
            ];
        }

        // Check if query instructs simulated empty result
        if (str_contains($normalizedQuery, 'kosong') || str_contains($normalizedQuery, 'empty')) {
            return [
                'status' => 'completed',
                'data' => [],
            ];
        }

        $items = [
            [
                'source' => ['code' => $this->getSourceCode()],
                'offer' => [
                    'id' => 'ast-001',
                    'name' => 'Rumah Cantik 2 Lantai Siap Huni (' . trim($query) . ')',
                    'type' => 'property',
                    'summary' => 'Rumah modern minimalis strategis dekat kampus dan pusat perbelanjaan',
                ],
                'provider' => [
                    'id' => 'tenant-ast-01',
                    'name' => 'Properti Nusantara Mandiri',
                ],
                'image' => 'https://images.unsplash.com/photo-1570129477492-45c003edd2be?w=600&auto=format&fit=crop&q=80',
                'contact' => [
                    'phone' => '+6281122334455',
                    'whatsapp' => '+6281122334455',
                    'email' => null,
                ],
                'location' => [
                    'address' => 'Jl. Palagan Tentara Pelajar KM 9, Sleman',
                    'latitude' => -7.7210,
                    'longitude' => 110.3750,
                    'type' => 'property_location',
                ],
                'availability' => 'available',
                'operational_status' => null,
            ],
        ];

        return [
            'status' => 'completed',
            'data' => array_slice($items, 0, $limit),
        ];
    }
}
