<?php

namespace App\Support;

class IvacBookingCities
{
    /**
     * IVAC centre cities mapped to the booking-config payload
     * ({mission, ivacCenter}) IVAC's own frontend sends.
     *
     * @var array<string, array{mission: string, ivacCenter: string}>
     */
    private const CITIES = [
        'Dhaka'      => ['mission' => 'Dhaka',      'ivacCenter' => 'IVAC, Dhaka (JFP)'],
        'Khulna'     => ['mission' => 'Khulna',     'ivacCenter' => 'IVAC, KHULNA'],
        'Chittagong' => ['mission' => 'Chittagong', 'ivacCenter' => 'IVAC, CHITTAGONG'],
        'Rajshahi'   => ['mission' => 'Rajshahi',   'ivacCenter' => 'IVAC, RAJSHAHI'],
        'Sylhet'     => ['mission' => 'Sylhet',     'ivacCenter' => 'IVAC, SYLHET'],
    ];

    /**
     * @return array<string, array{mission: string, ivacCenter: string}>
     */
    public static function all(): array
    {
        return self::CITIES;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::CITIES);
    }

    /**
     * The full centre list in the shape the bot consumes (/api/config bookingCityOptions). The bot
     * rotates through these when IVAC rejects an account's configured centre with
     * 400 "Invalid High Commission.", so the list ships as data rather than being compiled into the JAR.
     *
     * @return list<array{city: string, mission: string, ivacCenter: string}>
     */
    public static function options(): array
    {
        return array_values(array_map(
            fn (string $city, array $payload): array => ['city' => $city] + $payload,
            array_keys(self::CITIES),
            self::CITIES
        ));
    }

    /**
     * @return array{mission: string, ivacCenter: string}|null
     */
    public static function resolve(?string $city): ?array
    {
        if ($city === null) {
            return null;
        }

        return self::CITIES[$city] ?? null;
    }
}
