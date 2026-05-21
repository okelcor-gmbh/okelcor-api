<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArticleTranslation extends Model
{
    protected $fillable = [
        'article_id',
        'locale',
        'category',
        'title',
        'read_time',
        'summary',
        'body',
        'body_format',
        'meta_title',
        'meta_description',
        'cover_alt',
    ];

    // No cast on 'body' — stored as plain string (HTML or legacy JSON).
    // Use bodyHtml() or the body_html attribute to get resolved HTML.

    public function article()
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * Return the body as sanitized HTML regardless of storage format.
     * Legacy JSON-array bodies are converted to <p> tags on the fly.
     */
    public function getBodyHtmlAttribute(): string
    {
        if (empty($this->body)) {
            return '';
        }

        if ($this->body_format === 'html') {
            return $this->body;
        }

        // Legacy format: body is a JSON array of paragraph strings
        $decoded = json_decode($this->body, true);
        if (! is_array($decoded)) {
            // Stored as a plain string (very old records) — wrap in <p>
            return '<p>' . htmlspecialchars($this->body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }

        $parts = [];
        foreach ($decoded as $line) {
            $text = trim((string) $line);
            if ($text !== '') {
                $parts[] = '<p>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
            }
        }
        return implode("\n", $parts);
    }
}
