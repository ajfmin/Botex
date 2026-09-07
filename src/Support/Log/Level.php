<?php

namespace Botex\Support\Log;

/**
 * Severity ladder, lowest to highest.
 *
 * An entry is written when its level is at least the configured minimum,
 * and pushed to the telegram log chat when it is at least the notify
 * threshold. Two thresholds so noisy diagnostics can fill the file without
 * ever reaching the admins' group.
 */
enum Level: int
{
    case Debug = 0;
    case Info = 1;
    case Notice = 2;
    case Warning = 3;
    case Error = 4;
    case Critical = 5;

    public function label(): string
    {
        return strtoupper($this->name);
    }

    public function atLeast(self $other): bool
    {
        return $this->value >= $other->value;
    }

    /**
     * Parses a config/env name into a level, tolerating typos and
     * numbers. Unknown input falls back to the default rather than
     * breaking the boot sequence.
     */
    public static function resolve(string|int $name, self $default = self::Info): self
    {
        if (is_numeric($name)) {
            return self::tryFrom((int) $name) ?? $default;
        }

        foreach (self::cases() as $case) {
            if (strcasecmp($case->name, $name) === 0) {
                return $case;
            }
        }

        return $default;
    }
}
