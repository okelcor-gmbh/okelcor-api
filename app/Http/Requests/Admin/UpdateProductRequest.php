<?php

namespace App\Http\Requests\Admin;

use App\Support\TyreSpecs;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $productId = $this->route('product');

        return [
            'sku'         => ['sometimes', 'string', 'max:50', Rule::unique('products', 'sku')->ignore($productId)],
            'brand'       => ['sometimes', 'string', 'max:100'],
            'name'        => ['sometimes', 'string', 'max:200'],
            'size'        => ['sometimes', 'string', 'max:50'],
            'spec'        => ['nullable', 'string', 'max:50'],
            'season'      => ['sometimes', Rule::in(['Summer', 'Winter', 'All Season', 'All-Terrain'])],
            'type'        => ['sometimes', Rule::in(['PCR', 'TBR', 'Used', 'OTR'])],
            'audience'      => ['sometimes', 'in:both,b2b,b2c'],
            'price'       => ['sometimes', 'numeric', 'min:0'],
            'price_b2b'   => ['nullable', 'numeric', 'min:0'],
            'price_b2c'   => ['nullable', 'numeric', 'min:0'],
            'description' => ['sometimes', 'string'],
            'primary_image' => ['nullable', 'file', 'mimes:jpeg,png,jpg,gif,webp,svg', 'max:5120'],
            'is_active'     => ['nullable', 'boolean'],
            'in_stock'      => ['nullable', 'boolean'],
            'stock'         => ['nullable', 'integer', 'min:0'],
            'sort_order'    => ['nullable', 'integer'],
            'ebay_listed'   => ['nullable', 'boolean'],
            'ebay_item_id'  => ['nullable', 'string', 'max:100'],

            // Tyre passport (see AddTyreBatchFieldsToProductsTable). Grade is a
            // free string — ops hasn't fixed a grading scale yet.
            'condition_grade'   => ['nullable', 'string', 'max:10'],
            'tread_depth_mm'    => ['nullable', 'numeric', 'min:0', 'max:99.9'],
            'dot_code'          => ['nullable', 'string', 'max:20'],
            'inspection_date'   => ['nullable', 'date'],

            // The tyre-size / index columns the spec sheet reads (Session 92).
            // Until now only the CSV import could write these; the admin sheet
            // edits them directly. Strings, not numbers — they are printed, not
            // computed, and "10.5" rims and "91/89" load indexes are real.
            'width'        => ['sometimes', 'nullable', 'string', 'max:10'],
            'height'       => ['sometimes', 'nullable', 'string', 'max:10'],
            'rim'          => ['sometimes', 'nullable', 'string', 'max:10'],
            'load_index'   => ['sometimes', 'nullable', 'string', 'max:10'],
            'speed_rating' => ['sometimes', 'nullable', 'string', 'max:5'],
            'ean'          => ['sometimes', 'nullable', 'string', 'max:20'],

            // Product optimization (Session 92). `sometimes` on slug: absent
            // means "leave the URL alone" — a product rename must never move a
            // live URL as a side effect. description_html is sanitized in the
            // controller, same treatment as article bodies.
            'slug'             => ['sometimes', 'nullable', 'string', 'max:255'],
            'description_html' => ['sometimes', 'nullable', 'string', 'max:200000'],
            'shipping_info'    => ['sometimes', 'nullable', 'string', 'max:2000'],
            'returns_info'     => ['sometimes', 'nullable', 'string', 'max:2000'],
        ] + TyreSpecs::validationRules();
    }
}
