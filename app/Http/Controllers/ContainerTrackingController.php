<?php

namespace App\Http\Controllers;

use App\Services\DhlTrackingService;
use App\Services\ShipsGoService;
use Illuminate\Http\JsonResponse;

class ContainerTrackingController extends Controller
{
    public function __invoke(string $container): JsonResponse
    {
        $isDhl = (bool) preg_match('/^\d{10,12}$|^[0-9]{3}[-]?[0-9]{8}$|^JD|^00|^1Z|^GM/', $container);

        if ($isDhl) {
            $result      = app(DhlTrackingService::class)->track($container);
            $carrierName = 'DHL';
            $carrierType = 'dhl';
        } else {
            $result      = app(ShipsGoService::class)->trackContainer($container);
            $carrierName = 'Sea Freight';
            $carrierType = 'sea_freight';
        }

        if (isset($result['error'])) {
            $status = $result['error'] === 'not_found' ? 404 : 503;
            return response()->json(['data' => null, 'message' => $result['error']], $status);
        }

        return response()->json([
            'data' => array_merge($result, [
                'identifier'  => $container,
                'carrier'     => $carrierName,
                'carrier_type' => $carrierType,
            ]),
        ]);
    }
}
