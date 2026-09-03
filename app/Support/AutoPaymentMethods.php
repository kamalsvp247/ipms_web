<?php

namespace App\Support;

/**
 * Wallets the auto-payment driver can drive, and their display labels.
 *
 * Mirrors IvacBookingCities: one list the validation rule and the UI both read, so a method can
 * never be selectable in the form but rejected by the API.
 */
class AutoPaymentMethods
{
    /**
     * @var array<string, array{label: string, supported: bool}>
     */
    private const METHODS = [
        'bkash' => ['label' => 'bKash', 'supported' => false],
        'nagad' => ['label' => 'Nagad', 'supported' => false],
        'rocket' => ['label' => 'Rocket', 'supported' => true],
    ];

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::METHODS);
    }

    /**
     * Methods the UI offers and the account validation rule accepts. Rocket only for now — bKash
     * has never had its checkout HAR captured, and Nagad is parked deliberately, so accepting
     * either would queue a payment that fails. Unsupported methods stay in METHODS so a stored
     * value still resolves a label(); they can no longer be saved.
     *
     * @return list<string>
     */
    public static function supported(): array
    {
        return array_keys(array_filter(self::METHODS, fn (array $m): bool => $m['supported']));
    }

    public static function isSupported(?string $method): bool
    {
        return $method !== null && (self::METHODS[$method]['supported'] ?? false);
    }

    public static function label(?string $method): ?string
    {
        return $method !== null ? (self::METHODS[$method]['label'] ?? null) : null;
    }

    /**
     * Shape the UI consumes for its method picker.
     *
     * @return list<array{value: string, label: string, supported: bool}>
     */
    public static function options(): array
    {
        return array_values(array_map(
            fn (string $key): array => [
                'value' => $key,
                'label' => self::METHODS[$key]['label'],
                'supported' => self::METHODS[$key]['supported'],
            ],
            self::keys(),
        ));
    }
}
