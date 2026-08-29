<?php

namespace App\Models;

use App\Services\SchoolShop\OnboardingStatus;
use Illuminate\Database\Eloquent\Model;

class SchoolOnboarding extends Model
{
    /**
     * Nur die Bezeichnungen — Bedeutung und erlaubte Wechsel stehen in
     * App\Services\SchoolShop\OnboardingStatus.
     */
    public const STATUSES = [
        OnboardingStatus::NEU => 'Neu',
        OnboardingStatus::IN_BEARBEITUNG => 'In Bearbeitung',
        OnboardingStatus::ANGELEGT => 'Im Shop angelegt',
        OnboardingStatus::ABGESCHLOSSEN => 'Abgeschlossen',
    ];

    public const DELIVERY_TYPES = [
        'collective' => 'Sammelbestellfenster',
        'ondemand' => 'On-Demand (Printify)',
        'list' => 'Listenbestellung (ohne Webshop)',
    ];

    // On-Demand-Produkte werden laufend einzeln an die Kund:innen verschickt —
    // es gibt kein Bestellfenster. Statt die Felder leer zu lassen (Pods
    // erwartet Datumswerte), wird ein durchgehend "offenes" Fenster gesetzt.
    public const ONDEMAND_WINDOW_START = '2000-01-01';

    public const ONDEMAND_WINDOW_END = '2099-01-01';

    /** Die beiden Druckstellen mit ihrer Bezeichnung im Formular/in der UI. */
    public const PRINT_SLOTS = ['front' => 'Frontprint', 'back' => 'Backprint'];

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
            'mockups_enabled' => 'boolean',
            'mockup_images' => 'array',
            'print_front' => 'boolean',
            'print_back' => 'boolean',
            'sheet_products' => 'array',
            'sheet_back_focus_x' => 'float',
            'sheet_back_focus_y' => 'float',
            'sheet_back_zoom' => 'float',
            'sheet_front_focus_x' => 'float',
            'sheet_front_focus_y' => 'float',
            'sheet_front_zoom' => 'float',
            'sheet_detail_focus_x' => 'float',
            'sheet_detail_focus_y' => 'float',
            'sheet_detail_zoom' => 'float',
            'auto_extend' => 'boolean',
            'auto_extended_at' => 'datetime',
            'auto_extend_from' => 'date',
            'documents_exported_at' => 'datetime',
            'window_start' => 'date',
            'window_end' => 'date',
        ];
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function statusDescription(): string
    {
        return OnboardingStatus::description((string) $this->status);
    }

    /** Läuft das Bestellfenster gerade (Sammelbestellung, angelegt, Enddatum in der Zukunft)? */
    public function windowIsRunning(): bool
    {
        return $this->status === OnboardingStatus::ANGELEGT
            && $this->delivery_type === 'collective'
            && $this->window_end !== null
            && $this->window_end->endOfDay()->isFuture();
    }

    /** Fenster abgelaufen, im Shop aber noch offen — dort wird weiter bestellt. */
    public function windowExpiredButOpen(): bool
    {
        return $this->status === OnboardingStatus::ANGELEGT
            && $this->delivery_type === 'collective'
            && $this->window_end !== null
            && $this->window_end->endOfDay()->isPast();
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

    /** Wurde für diese Schule bereits ein Shop angelegt (Kategorie oder CPT)? */
    public function isProvisioned(): bool
    {
        return $this->woo_category_id !== null || $this->pods_post_id !== null;
    }

    /**
     * Wird dieser Druck gedruckt? Solange im Konfigurator nichts explizit
     * gesetzt wurde (NULL), zählt der Formularwunsch aus print_areas; hat das
     * Formular gar nichts geliefert, gibt es zumindest einen Frontprint.
     */
    public function prints(string $slot): bool
    {
        $explicit = $slot === 'back' ? $this->print_back : $this->print_front;
        if ($explicit !== null) {
            return $explicit;
        }

        $areas = $this->print_areas ?? [];
        if ($areas === []) {
            return $slot === 'front';
        }

        return in_array(self::PRINT_SLOTS[$slot] ?? '', $areas, true);
    }

    /** @return list<string> Die tatsächlich zu druckenden Slots ('front'/'back'). */
    public function activePrintSlots(): array
    {
        return array_values(array_filter(array_keys(self::PRINT_SLOTS), fn ($slot) => $this->prints($slot)));
    }

    /**
     * Extern erreichbare Logo-Adresse für diesen Druck — Printify und Dynamic
     * Mockups laden die Datei selbst herunter. Vorrang hat ein im Tool
     * hochgeladenes Logo; sonst gilt der Formular-Upload der Kund:innen
     * (dieselbe Datei ist Standard für beide Drucke).
     */
    public function logoUrl(string $slot): ?string
    {
        $own = $slot === 'back' ? $this->logo_back_url : $this->logo_front_url;
        if ($own) {
            return $own;
        }

        $files = array_values($this->logo_files ?? []);

        return $slot === 'back'
            ? ($files[1] ?? $files[0] ?? null)
            : ($files[0] ?? null);
    }

    /** Wurde für diesen Druck im Tool eine eigene Datei hochgeladen? */
    public function hasUploadedLogo(string $slot): bool
    {
        return (bool) ($slot === 'back' ? $this->logo_back_path : $this->logo_front_path);
    }

    public function logoPath(string $slot): ?string
    {
        return $slot === 'back' ? $this->logo_back_path : $this->logo_front_path;
    }

    public function logoPositionKey(string $slot): string
    {
        $stored = $slot === 'back' ? $this->logo_back_position : $this->logo_front_position;
        $positions = config('schoolshop.logo_positions');

        return isset($positions[$stored]) ? $stored : config("schoolshop.logo_defaults.{$slot}.position");
    }

    public function logoSizeKey(string $slot): string
    {
        $stored = $slot === 'back' ? $this->logo_back_size : $this->logo_front_size;
        $sizes = config('schoolshop.logo_sizes');

        return isset($sizes[$stored]) ? $stored : config("schoolshop.logo_defaults.{$slot}.size");
    }

    /**
     * Platzierung eines Drucks als relative Werte für Printify/Dynamic Mockups.
     *
     * @return array{x: float, y: float, width: float}
     */
    public function logoPlacement(string $slot): array
    {
        $position = config('schoolshop.logo_positions.'.$this->logoPositionKey($slot));
        $size = config('schoolshop.logo_sizes.'.$this->logoSizeKey($slot));

        return ['x' => $position['x'], 'y' => $position['y'], 'width' => $size['width']];
    }

    /** Menschlich lesbare Beschreibung eines Drucks (Protokoll/Vorschau). */
    public function logoPlacementLabel(string $slot): string
    {
        return config('schoolshop.logo_positions.'.$this->logoPositionKey($slot).'.label')
            .', '.config('schoolshop.logo_sizes.'.$this->logoSizeKey($slot).'.label');
    }
}
