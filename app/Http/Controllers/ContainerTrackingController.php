<?php

namespace App\Http\Controllers;

use App\Services\DhlTrackingService;
use App\Services\ShipsGoService;
use Illuminate\Http\JsonResponse;

class ContainerTrackingController extends Controller
{
    public function __invoke(string $container): JsonResponse
    {
        $isDhl = (bool) preg_match('/^\d{10,12}$|^[0-9]{3}[-]?[0-9]{8}$|^JD|^1Z|^GM/', $container);

        if ($isDhl) {
            $data    = app(DhlTrackingService::class)->track($container);
            $carrier = 'DHL';
        } else {
            $data    = app(ShipsGoService::class)->trackContainer($container);
            $carrier = 'Sea Freight';
        }

        if (isset($data['error'])) {
            return response()->json([
                'data'    => null,
                'carrier' => $carrier,
                'message' => $data['error'],
            ], 503);
        }

        return response()->json([
            'data'    => $data,
            'carrier' => $carrier,
            'message' => 'success',
        ]);
    }
}
