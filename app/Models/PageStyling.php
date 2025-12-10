<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class PageStyling extends Model
{
    protected $fillable = [
        'page_name',
        'page_label',
        'background_color',
        'custom_css',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get styling for a specific page
     */
    public static function getPageStyling(string $pageName): ?self
    {
        return Cache::remember("page_styling_{$pageName}", 600, function () use ($pageName) {
            return self::where('page_name', $pageName)
                ->where('is_active', true)
                ->first();
        });
    }

    /**
     * Clear cache when model is saved or deleted
     */
    protected static function boot()
    {
        parent::boot();

        static::saved(function ($model) {
            Cache::forget("page_styling_{$model->page_name}");
        });

        static::deleted(function ($model) {
            Cache::forget("page_styling_{$model->page_name}");
        });
    }
}
