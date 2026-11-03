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
                'source' => ['code' => $this->getSourceCode()],
                'offer' => [
                    'id' => 'lnd-001',
                    'name' => 'Paket Cuci Setrika Kiloan (' . trim($query) . ')',
                    'type' => 'service',
                    'summary' => 'Cuci bersih, wangi, rapi, selesai dalam 24 jam',
                ],
                'provider' => [
                    'id' => 'tenant-lnd-05',
                    'name' => 'Laundry Bersih Berkah',
                ],
                'image' => 'https://images.unsplash.com/photo-1545173168-9f1947eebb7f?w=600&auto=format&fit=crop&q=80',
                'contact' => [
                    'phone' => '+6289876543210',
                    'whatsapp' => '+6289876543210',
                    'email' => null,
                ],
                'location' => [
                    'address' => 'Jl. Gejayan No. 45, Sleman',
                    'latitude' => -7.7712,
                    'longitude' => 110.3890,
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
