<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSettingController extends Controller
{
    // Human-readable labels for each key. Keys not listed here fall back to
    // humanising the key name on the frontend.
    private const LABELS = [
        'company_name'             => 'Company Name',
        'company_email'            => 'Company Email',
        'company_phone'            => 'Company Phone',
        'company_fax'              => 'Company Fax',
        'company_address'          => 'Company Address',
        'vat_rate'                 => 'VAT Rate (%)',
        'default_currency'         => 'Default Currency',
        'free_shipping_threshold'  => 'Free Shipping Threshold',
        'estimated_dispatch_days'  => 'Estimated Dispatch (days)',
        'contact_email'            => 'Contact Email',
        'quote_email'              => 'Quote Email',
        'from_email'               => 'From Email',
        'quote_response_time'      => 'Quote Response Time',
        'site_tagline'             => 'Site Tagline',
        'google_analytics_id'      => 'Google Analytics ID',
        'maintenance_mode'         => 'Maintenance Mode',
    ];

    public function index(): JsonResponse
    {
        $settings = SiteSetting::orderBy('group')->orderBy('key')->get();

        $data = $settings->map(fn ($s) => [
            'key'   => $s->key,
            'value' => $this->castValue($s->value, $s->type),
            'label' => self::LABELS[$s->key] ?? null,
            'group' => $s->group,
        ])->values();

        return response()->json([
            'data'    => $data,
            'message' => 'success',
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'settings'         => ['required', 'array', 'min:1'],
            'settings.*.key'   => ['required', 'string'],
            'settings.*.value' => ['nullable'],
        ]);

        foreach ($request->settings as $item) {
            $setting = SiteSetting::where('key', $item['key'])->first();

            if (! $setting) {
                continue; // Only update keys that already exist
            }

            $setting->update([
                'value' => $this->encodeValue($item['value'] ?? null, $setting->type),
            ]);
        }

        $settings = SiteSetting::orderBy('group')->orderBy('key')->get();

        $data = $settings->map(fn ($s) => [
            'key'   => $s->key,
            'value' => $this->castValue($s->value, $s->type),
            'label' => self::LABELS[$s->key] ?? null,
            'group' => $s->group,
        ])->values();

        return response()->json([
            'data'    => $data,
            'message' => 'Settings saved.',
        ]);
    }

    private function castValue(?string $value, string $type): mixed
    {
        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json'    => json_decode($value, true),
            default   => $value,
        };
    }

    private function encodeValue(mixed $value, string $type): string
    {
        return match ($type) {
            'boolean' => $value ? 'true' : 'false',
            'json'    => is_string($value) ? $value : json_encode($value),
            default   => (string) ($value ?? ''),
        };
    }
}
