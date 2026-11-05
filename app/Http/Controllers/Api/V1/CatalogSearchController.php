<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CatalogSearchRequest;
use App\Services\Catalog\CatalogSearchCoordinator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CatalogSearchController extends Controller
{
    public function __construct(
        protected CatalogSearchCoordinator $coordinator
    ) {}

    public function __invoke(CatalogSearchRequest $request): StreamedResponse
    {
        $validated = $request->validated();
        $searchId = $validated['search_id'];
        $query = $validated['query'];
        $limit = (int) ($validated['limit'] ?? 20);

        return response()->stream(function () use ($searchId, $query, $limit): void {
            // Disable output compression/buffering if active
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            $eventEmitter = function (string $eventName, array $payload): void {
                echo "event: {$eventName}\n";
                echo 'data: '.json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n\n";

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            $this->coordinator->executeStream($searchId, $query, $limit, $eventEmitter);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
