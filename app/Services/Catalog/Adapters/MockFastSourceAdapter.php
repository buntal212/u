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
                'id' => 'lrs-001',
                'code' => 'MNU-001',
                'name' => 'Nasi Goreng Spesial '.trim($query),
                'category' => 'Makanan Utama',
                'summary' => 'Nasi goreng lezat dengan bumbu racikan khas dan telur mata sapi',
                'price' => [
                    'amount' => 25000,
                    'currency' => 'IDR',
                ],
                'image' => [
                    'url' => 'https://images.unsplash.com/photo-1512058564366-18510be2db19?w=600&auto=format&fit=crop&q=80',
                ],
                'availability' => 'available',
                'provider' => [
                    'id' => 'tenant-lrs-10',
                    'code' => 'WRG-001',
                    'name' => 'Warung Makan Bu Rina',
                    'image' => null,
                    'contact' => [
                        'phone' => '+6281234567890',
                        'whatsapp' => '+6281234567890',
                    ],
                    'location' => [
                        'address' => 'Jl. Kaliurang KM 5 No. 12, Sleman',
                        'latitude' => -7.7654,
                        'longitude' => 110.3812,
                    ],
                ],
                'source' => ['code' => $this->getSourceCode()],
            ],
            [
                'id' => 'lrs-002',
                'code' => 'MNU-002',
                'name' => 'Es Teh Manis Segar',
                'category' => 'Minuman',
                'summary' => 'Es teh manis segar pelepas dahaga',
                'price' => [
                    'amount' => 5000,
                    'currency' => 'IDR',
                ],
                'image' => [
                    'url' => 'https://images.unsplash.com/photo-1556679343-c7306c1976bc?w=600&auto=format&fit=crop&q=80',
                ],
                'availability' => 'available',
                'provider' => [
                    'id' => 'tenant-lrs-10',
                    'code' => 'WRG-001',
                    'name' => 'Warung Makan Bu Rina',
                    'image' => null,
                    'contact' => [
                        'phone' => '+6281234567890',
                        'whatsapp' => '+6281234567890',
                    ],
                    'location' => [
                        'address' => 'Jl. Kaliurang KM 5 No. 12, Sleman',
                        'latitude' => -7.7654,
                        'longitude' => 110.3812,
                    ],
                ],
                'source' => ['code' => $this->getSourceCode()],
            ],
        ];

        return [
            'status' => 'completed',
            'data' => array_slice($items, 0, $limit),
        ];
    }
}
