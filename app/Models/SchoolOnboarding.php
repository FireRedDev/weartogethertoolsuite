<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchoolOnboarding extends Model
{
    public const STATUSES = [
        'neu' => 'Neu',
        'in_bearbeitung' => 'In Bearbeitung',
        'angelegt' => 'Im Shop angelegt',
        'abgeschlossen' => 'Abgeschlossen',
    ];

    public const DELIVERY_TYPES = [
        'collective' => 'Sammelbestellfenster',
        'ondemand' => 'On-Demand (Printify)',
        'list' => 'Listenbestellung (ohne Webshop)',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'address' => 'array',
            'products' => 'array',
            'print_areas' => 'array',
            'logo_files' => 'array',
            'raw_entry' => 'array',
            'woo_product_ids' => 'array',
            'printify_product_ids' => 'array',
            'provision_log' => 'array',
            'window_start' => 'date',
            'window_end' => 'date',
        ];
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function deliveryTypeLabel(): string
    {
        return self::DELIVERY_TYPES[$this->delivery_type] ?? $this->delivery_type;
    }

    /** Nur die im Konfigurator aktivierten Produkte. */
    public function enabledProducts(): array
    {
        return array_values(array_filter($this->products ?? [], fn ($p) => ! empty($p['enabled'])));
    }
}
