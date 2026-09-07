<?php

namespace Botex\Wallet;

/**
 * An amount in minor units.
 *
 * Money is only ever an integer here. Formatting uses integer division
 * and modulo rather than converting to a float, so no rounding error can
 * enter through the display path.
 */
class Money
{
    public function __construct(
        public readonly int $minor,
        public readonly string $currency = 'IRT',
        public readonly int $scale = 0
    ) {
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function absolute(): self
    {
        return new self(abs($this->minor), $this->currency, $this->scale);
    }

    /** e.g. 150000 at scale 2 becomes "1500.00". */
    public function amount(): string
    {
        if ($this->scale <= 0) {
            return (string) $this->minor;
        }

        $divisor = 10 ** $this->scale;
        $sign = $this->minor < 0 ? '-' : '';
        $absolute = abs($this->minor);

        return $sign
            . intdiv($absolute, $divisor)
            . '.'
            . str_pad((string) ($absolute % $divisor), $this->scale, '0', STR_PAD_LEFT);
    }

    public function format(): string
    {
        return $this->amount() . ' ' . $this->currency;
    }

    public function __toString(): string
    {
        return $this->format();
    }
}
