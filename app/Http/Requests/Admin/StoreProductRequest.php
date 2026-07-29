<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sku'           => ['required', 'string', 'max:50', Rule::unique('products', 'sku')],
            'brand'         => ['required', 'string', 'max:100'],
            'name'          => ['required', 'string', 'max:200'],
            'size'          => ['required', 'string', 'max:50'],
            'spec'          => ['nullable', 'string', 'max:50'],
            'season'        => ['required', Rule::in(['Summer', 'Winter', 'All Season', 'All-Terrain'])],
            'type'          => ['required', Rule::in(['PCR', 'TBR', 'Used', 'OTR'])],
            'price'         => ['required', 'numeric', 'min:0'],
            'price_b2b'     => ['nullable', 'numeric', 'min:0'],
            'price_b2c'     => ['nullable', 'numeric', 'min:0'],
            'description'   => ['required', 'string'],
            'primary_image' => ['nullable', 'file', 'mimes:jpeg,png,jpg,gif,webp,svg', 'max:5120'],
            'is_active'     => ['nullable', 'boolean'],
            'in_stock'      => ['nullable', 'boolean'],
            'stock'         => ['nullable', 'integer', 'min:0'],
            'sort_order'    => ['nullable', 'integer'],

            // Tyre passport (see AddTyreBatchFieldsToProductsTable). Grade is a
            // free string — ops hasn't fixed a grading scale yet.
            'condition_grade'   => ['nullable', 'string', 'max:10'],
            'tread_depth_mm'    => ['nullable', 'numeric', 'min:0', 'max:99.9'],
            'dot_code'          => ['nullable', 'string', 'max:20'],
            'inspection_date'   => ['nullable', 'date'],
        ];
    }
}
