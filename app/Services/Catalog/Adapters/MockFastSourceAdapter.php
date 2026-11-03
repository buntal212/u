<?php

namespace App\Services\Catalog\Adapters;

use App\Contracts\Catalog\SourceAdapterInterface;

class MockFastSourceAdapter implements SourceAdapterInterface
{
    public function __construct(
        protected int $delayMicroseconds = 50000 // 50ms
    ) {}

    public function getSourceCode(): string
    {
        return 'mock_larissama';
    }

    public function getSourceName(): string
    {
        return 'Larissama F&B';
    }

    public function search(string $query, int $limit = 20): array
    {
        if ($this->delayMicroseconds > 0) {
            usleep($this->delayMicroseconds);
        }

        $items = [
            [
                'source' => ['code' => $this->getSourceCode()],
                'offer' => [
                    'id' => 'lrs-001',
                    'name' => 'Nasi Goreng Spesial ' . trim($query),
                    'type' => 'product',
                    'summary' => 'Nasi goreng lezat dengan bumbu racikan khas dan telur mata sapi',
                ],
                'provider' => [
                    'id' => 'tenant-lrs-10',
                    'name' => 'Warung Makan Bu Rina',
                ],
                'image' => 'https://images.unsplash.com/photo-1512058564366-18510be2db19?w=600&auto=format&fit=crop&q=80',
                'contact' => [
                    'phone' => '+6281234567890',
                    'whatsapp' => '+6281234567890',
                    'email' => null,
                ],
                'location' => [
                    'address' => 'Jl. Kaliurang KM 5 No. 12, Sleman',
                    'latitude' => -7.7654,
                    'longitude' => 110.3812,
                    'type' => 'outlet',
                ],
                'availability' => 'available',
                'operational_status' => 'open',
            ],
            [
                'source' => ['code' => $this->getSourceCode()],
                'offer' => [
                    'id' => 'lrs-002',
                    'name' => 'Es Teh Manis Segar',
                    'type' => 'product',
                    'summary' => 'Es teh manis segar pelepas dahaga',
                ],
                'provider' => [
                    'id' => 'tenant-lrs-10',
                    'name' => 'Warung Makan Bu Rina',
                ],
                'image' => 'https://images.unsplash.com/photo-1556679343-c7306c1976bc?w=600&auto=format&fit=crop&q=80',
                'contact' => [
                    'phone' => '+6281234567890',
                    'whatsapp' => '+6281234567890',
                    'email' => null,
                ],
                'location' => [
                    'address' => 'Jl. Kaliurang KM 5 No. 12, Sleman',
                    'latitude' => -7.7654,
                    'longitude' => 110.3812,
                    'type' => 'outlet',
                ],
                'availability' => 'available',
                'operational_status' => 'open',
            ],
        ];

        return [
            'status' => 'completed',
            'data' => array_slice($items, 0, $limit),
        ];
    }
}
