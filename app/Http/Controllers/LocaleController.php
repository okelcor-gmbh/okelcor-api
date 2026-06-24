<?php

namespace App\Http\Controllers;

use App\Support\LocaleResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    /**
     * GET /api/v1/i18n/locales
     *
     * Returns the supported locales, the default, and the full country -> locale
     * map so the frontend can auto-detect client-side with a single fetch.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                'supported'      => LocaleResolver::supported(),
                'default'        => LocaleResolver::default(),
                'country_locale' => (object) config('i18n.country_locale', []),
            ],
            'meta' => [
                'rule' => 'A country mapped to a supported language auto-switches to it; '
                    . 'every other country falls back to the default locale.',
            ],
        ]);
    }

    /**
     * GET /api/v1/i18n/resolve
     *
     * Resolves the best locale for the visitor. The frontend should pass the
     * country it detected via ?country=XX (ISO 3166-1 alpha-2); the endpoint
     * also honours an explicit ?locale= override, CDN geo headers, and the
     * Accept-Language header as fallbacks.
     */
    public function resolve(Request $request): JsonResponse
    {
        $resolved = LocaleResolver::resolve($request);

        return response()->json([
            'data' => [
                'locale'     => $resolved['locale'],
                'country'    => $resolved['country'],
                'source'     => $resolved['source'],
                'is_default' => $resolved['locale'] === LocaleResolver::default(),
                'supported'  => LocaleResolver::supported(),
            ],
        ]);
    }
}
