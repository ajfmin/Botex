<?php

namespace Extensions\Clock\Service;

use Botex\Extension\SettingsFactory;

/**
 * An extension's own service. Resolved by the same Feeder as core
 * services, so it can take core dependencies in its constructor.
 *
 * Reads its timezone and formats from settings, which an admin can
 * change without editing the extension.
 */
class ClockService
{
    private const SLUG = 'Clock';

    public function __construct(
        private SettingsFactory $settings
    ) {
    }

    public function now(?string $timezone = null): string
    {
        return $this->format(
            (string) $this->setting('time_format', 'H:i:s'),
            $timezone
        );
    }

    public function today(?string $timezone = null): string
    {
        return $this->format(
            (string) $this->setting('date_format', 'Y-m-d'),
            $timezone
        );
    }

    /**
     * The message body, in one place so the command, the refresh
     * callback and the Run action cannot drift apart.
     */
    public function text(?string $timezone = null): string
    {
        $zone = $this->zone($timezone);

        return '<b>' . $this->now($timezone) . '</b>' . PHP_EOL
            . $this->today($timezone) . PHP_EOL
            . '<i>' . $zone->getName() . '</i>';
    }

    /**
     * Timezones offered as buttons.
     *
     * @return array<int, string>
     */
    public function zones(): array
    {
        $zones = $this->setting('zones', ['UTC']);

        if (!is_array($zones)) {
            return ['UTC'];
        }

        $valid = array_values(array_filter(
            array_map('strval', $zones),
            fn(string $zone) => $this->isValid($zone)
        ));

        return $valid === [] ? ['UTC'] : $valid;
    }

    public function defaultZone(): string
    {
        return $this->zone(null)->getName();
    }

    private function format(string $format, ?string $timezone): string
    {
        return (new \DateTimeImmutable('now', $this->zone($timezone)))->format($format);
    }

    /**
     * Falls back to the configured zone, then to UTC.
     *
     * A stored action can name a zone that was later dropped from
     * settings, so an unknown zone degrades instead of throwing.
     */
    private function zone(?string $timezone): \DateTimeZone
    {
        foreach ([$timezone, (string) $this->setting('timezone', 'UTC'), 'UTC'] as $candidate) {
            if ($candidate !== null && $candidate !== '' && $this->isValid($candidate)) {
                return new \DateTimeZone($candidate);
            }
        }

        return new \DateTimeZone('UTC');
    }

    private function isValid(string $timezone): bool
    {
        try {
            new \DateTimeZone($timezone);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function setting(string $key, mixed $default): mixed
    {
        return $this->settings->for(self::SLUG)->get($key, $default);
    }
}
