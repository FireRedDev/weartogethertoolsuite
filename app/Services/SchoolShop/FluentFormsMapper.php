<?php

namespace App\Services\SchoolShop;

use App\Models\SchoolOnboarding;

/**
 * Mappt einen FluentForms-Webhook-Payload (Formular "Webshopstartfragebogen",
 * Form-ID 4) auf einen Onboarding-Antrag. Feld-Keys entsprechen dem
 * Formular-Export vom 12.07.2026.
 */
class FluentFormsMapper
{
    public function map(array $payload): SchoolOnboarding
    {
        $deliveryType = match ($this->str($payload, 'input_radio_7')) {
            'On-Demand online' => 'ondemand',
            'Listenbestellung (ohne Webshop)' => 'list',
            default => 'collective',
        };

        // Produkte je Lieferart: multi_select_4 = Sammelbestellung, multi_select_1 = On-Demand
        $formProducts = $this->list($payload, $deliveryType === 'ondemand' ? 'multi_select_1' : 'multi_select_4');
        $formColors = $this->list($payload, $deliveryType === 'ondemand' ? 'multi_select_2' : 'multi_select_3');

        $windowStart = $this->parseDate($this->str($payload, 'datetime'));
        $windowDays = (int) config('schoolshop.default_window_days');

        $schoolName = trim($this->str($payload, 'input_text_6')) ?: 'Unbenannte Schule';

        return new SchoolOnboarding([
            'status' => 'neu',
            'source' => 'webhook',
            'school_name' => $schoolName,
            'org_type' => $this->str($payload, 'input_radio'),
            'contact_name' => $this->str($payload, 'input_text'),
            'contact_email' => $this->str($payload, 'email'),
            'contact_phone' => $this->str($payload, 'phone'),
            'contact_preference' => $this->str($payload, 'input_radio_8'),
            'contact_role' => $this->str($payload, 'input_radio_1'),
            'address' => $this->address($payload),
            'student_count' => $this->int($payload, 'numeric-field_1'),
            'expected_orders' => $this->int($payload, 'numeric-field'),
            'delivery_type' => $deliveryType,
            'products' => ProductConfigurator::defaultsFromFormProducts($formProducts, $formColors),
            'print_areas' => $this->list($payload, 'multi_select'),
            'class_list' => $this->str($payload, 'description_3'),
            'window_start' => $windowStart,
            'window_end' => $windowStart?->copy()->addDays($windowDays),
            'logo_files' => $this->files($payload, 'file-upload_1'),
            'logo_notes' => $this->str($payload, 'description_5'),
            'design_notes' => $this->str($payload, 'description'),
            'notes' => $this->str($payload, 'description_2'),
            'raw_entry' => $payload,
        ]);
    }

    private function str(array $payload, string $key): string
    {
        $value = $payload[$key] ?? '';
        if (is_array($value)) {
            $value = implode(', ', array_filter($value, 'is_scalar'));
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function int(array $payload, string $key): ?int
    {
        $value = $this->str($payload, $key);

        return is_numeric($value) ? (int) $value : null;
    }

    /** @return list<string> */
    private function list(array $payload, string $key): array
    {
        $value = $payload[$key] ?? [];
        if (is_string($value)) {
            // FluentForms liefert Mehrfachauswahl je nach Konfiguration als
            // Array oder als kommaseparierten String
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : array_map('trim', explode(',', $value));
        }
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : '', $value)));
    }

    /** @return list<string> */
    private function files(array $payload, string $key): array
    {
        return $this->list($payload, $key);
    }

    private function address(array $payload): array
    {
        $address = $payload['address_1'] ?? null;
        if (is_array($address)) {
            return [
                'line1' => trim((string) ($address['address_line_1'] ?? '')),
                'line2' => trim((string) ($address['address_line_2'] ?? '')),
                'zip' => trim((string) ($address['zip'] ?? '')),
                'city' => trim((string) ($address['city'] ?? '')),
                'state' => trim((string) ($address['state'] ?? '')),
                'country' => trim((string) ($address['country'] ?? '')),
            ];
        }

        return ['line1' => $this->str($payload, 'address_1')];
    }

    private function parseDate(string $value): ?\Illuminate\Support\Carbon
    {
        if ($value === '') {
            return null;
        }
        foreach (['d.m.Y', 'Y-m-d', 'd/m/Y'] as $format) {
            try {
                return \Illuminate\Support\Carbon::createFromFormat($format, $value)->startOfDay();
            } catch (\Throwable) {
                continue;
            }
        }
        try {
            return \Illuminate\Support\Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
