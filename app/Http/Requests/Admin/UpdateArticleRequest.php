<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $articleId = $this->route('article');

        return [
            'slug'                               => ['sometimes', 'string', 'max:300', Rule::unique('articles', 'slug')->ignore($articleId)],
            'image'                              => ['nullable', 'string', 'max:500'],
            'og_image'                           => ['nullable', 'string', 'max:500'],
            'published_at'                       => ['nullable', 'date'],
            'is_published'                       => ['nullable', 'boolean'],
            'sort_order'                         => ['nullable', 'integer'],
            'translations'                       => ['sometimes', 'array'],
            'translations.*.category'            => ['required_with:translations', 'string', 'max:100'],
            'translations.*.title'               => ['required_with:translations', 'string', 'max:500'],
            'translations.*.read_time'           => ['nullable', 'string', 'max:30'],
            'translations.*.summary'             => ['required_with:translations', 'string', 'max:1000'],
            // body is rich HTML string from the editor; sanitized before save
            'translations.*.body'                => ['required_with:translations', 'string'],
            'translations.*.meta_title'          => ['nullable', 'string', 'max:160'],
            'translations.*.meta_description'    => ['nullable', 'string', 'max:300'],
            'translations.*.cover_alt'           => ['nullable', 'string', 'max:200'],
        ];
    }
}
