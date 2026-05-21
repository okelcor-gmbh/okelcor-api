<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Article extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'slug',
        'image',
        'og_image',
        'published_at',
        'is_published',
        'sort_order',
    ];

    protected $casts = [
        'published_at' => 'date',
        'is_published' => 'boolean',
    ];

    public function translations()
    {
        return $this->hasMany(ArticleTranslation::class);
    }

    public function translation(string $locale = 'en')
    {
        return $this->hasOne(ArticleTranslation::class)->where('locale', $locale);
    }
}
