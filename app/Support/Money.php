<?php

namespace App\Support;

use App\Support\Casts\MoneyCast;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use InvalidArgumentException;
use JsonSerializable;

final readonly class Money implements Castable, JsonSerializable
{
    private function __construct(private int $fils) {}

    public static function fils(int $fils): self
    {
        return new self($fils);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Parse a decimal amount ("120.00", "120", "120.5") into fils. Rejects
     * anything with more than two decimal places, negatives, or non-numeric
     * input — negative amounts only ever arise from subtract().
     */
    public static function fromDecimalString(string $amount): self
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches) !== 1) {
            throw new InvalidArgumentException("Invalid money amount: \"{$amount}\".");
        }

        $whole = (int) $matches[1];
        $fraction = (int) str_pad($matches[2] ?? '', 2, '0');

        return new self($whole * 100 + $fraction);
    }

    public function toFils(): int
    {
        return $this->fils;
    }

    public function add(self $other): self
    {
        return new self($this->fils + $other->fils);
    }

    public function subtract(self $other): self
    {
        return new self($this->fils - $other->fils);
    }

    public function multiply(int $factor): self
    {
        return new self($this->fils * $factor);
    }

    /**
     * Percentage of this amount, rounded half-up on fils.
     */
    public function percentage(int $percent): self
    {
        return new self(intdiv($this->fils * $percent + 50, 100));
    }

    public function isZero(): bool
    {
        return $this->fils === 0;
    }

    public function isNegative(): bool
    {
        return $this->fils < 0;
    }

    public function equals(self $other): bool
    {
        return $this->fils === $other->fils;
    }

    public function compare(self $other): int
    {
        return $this->fils <=> $other->fils;
    }

    public function format(string $currencyCode = 'AED'): string
    {
        $sign = $this->fils < 0 ? '-' : '';
        $absolute = abs($this->fils);

        return sprintf('%s%s %d.%02d', $sign, $currencyCode, intdiv($absolute, 100), $absolute % 100);
    }

    public function jsonSerialize(): int
    {
        return $this->fils;
    }

    /**
     * @param  array<int, mixed>  $arguments
     * @return class-string<CastsAttributes<self, self|int>>
     */
    public static function castUsing(array $arguments): string
    {
        return MoneyCast::class;
    }
}
