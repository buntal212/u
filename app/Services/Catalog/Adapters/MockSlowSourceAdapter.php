<?php

namespace App\Services\Catalog\Adapters;

use App\Contracts\Catalog\SourceAdapterInterface;

class MockSlowSourceAdapter implements SourceAdapterInterface
{
    public function __construct(
        protected int $delayMicroseconds = 250000 // 250ms
    ) {}

    public function getSourceCode(): string
    {
        return 'mock_laundry';
    }

    public function getSourceName(): string
    {
        return 'Laundry Kilat';
    }

    public function search(string $query, int $limit = 20): array
    {
        if ($this->delayMicroseconds > 0) {
            usleep($this->delayMicroseconds);
        }

        $items = [
            [
                'id' => 'lnd-001',
                'code' => 'LND-001',
                'name' => 'Paket Cuci Setrika Kiloan ('.trim($query).')',
                'category' => 'Jasa Laundry',
                'summary' => 'Cuci bersih, wangi, rapi, selesai dalam 24 jam',
                'price' => [
                    'amount' => 15000,
                    'currency' => 'IDR',
                ],
                'image' => [
                    'url' => 'https://images.unsplash.com/photo-1545173168-9f1947eebb7f?w=600&auto=format&fit=crop&q=80',
                ],
                'availability' => 'available',
                'provider' => [
                    'id' => 'tenant-lnd-05',
                    'code' => 'LND-PRV-01',
                    'name' => 'Laundry Bersih Berkah',
                    'image' => null,
                    'contact' => [
                        'phone' => '+6289876543210',
                        'whatsapp' => '+6289876543210',
                    ],
                    'location' => [
                        'address' => 'Jl. Gejayan No. 45, Sleman',
                        'latitude' => -7.7712,
                        'longitude' => 110.3890,
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
