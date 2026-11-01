<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Engineering\NormalizeExampleValue;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\NormalizeExampleRequest;
use App\Http\Resources\Api\V1\NormalizeExampleResource;

class NormalizeExampleController extends Controller
{
    public function __invoke(
        NormalizeExampleRequest $request,
        NormalizeExampleValue $normalizeExampleValue,
    ): NormalizeExampleResource {
        $original = $request->validated()['value'];

        return new NormalizeExampleResource([
            'original' => $original,
            'normalized' => $normalizeExampleValue->execute($original),
        ]);
    }
}
