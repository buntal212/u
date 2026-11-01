<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NormalizeExampleResource extends JsonResource
{
    /**
     * @return array{original: string, normalized: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'original' => $this->resource['original'],
            'normalized' => $this->resource['normalized'],
        ];
    }
}
