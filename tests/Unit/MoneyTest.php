<?php

use App\Support\Money;

it('adds two amounts', function () {
    $sum = Money::fils(1000)->add(Money::fils(250));

    expect($sum->toFils())->toBe(1250);
});

it('subtracts two amounts and allows the result to go negative', function () {
    $difference = Money::fils(100)->subtract(Money::fils(300));

    expect($difference->toFils())->toBe(-200)
        ->and($difference->isNegative())->toBeTrue();
});

it('multiplies by an integer factor', function () {
    expect(Money::fils(500)->multiply(3)->toFils())->toBe(1500);
});

it('computes a percentage, rounding half-up on fils', function () {
    expect(Money::fils(12000)->percentage(25)->toFils())->toBe(3000)
        ->and(Money::fils(12001)->percentage(25)->toFils())->toBe(3000)
        ->and(Money::fils(99)->percentage(25)->toFils())->toBe(25);
});

it('does not leak float rounding error through decimal string parsing', function () {
    $sum = Money::fromDecimalString('0.1')->add(Money::fromDecimalString('0.2'));

    expect($sum->equals(Money::fromDecimalString('0.3')))->toBeTrue();
});

it('parses whole and fractional decimal strings into fils', function () {
    expect(Money::fromDecimalString('120.00')->toFils())->toBe(12000)
        ->and(Money::fromDecimalString('120')->toFils())->toBe(12000)
        ->and(Money::fromDecimalString('120.5')->toFils())->toBe(12050);
});

it('rejects a decimal string with more than two decimal places', function () {
    Money::fromDecimalString('1.005');
})->throws(InvalidArgumentException::class);

it('rejects a non-numeric decimal string', function () {
    Money::fromDecimalString('abc');
})->throws(InvalidArgumentException::class);

it('rejects a negative decimal string', function () {
    Money::fromDecimalString('-5.00');
})->throws(InvalidArgumentException::class);

it('is immutable — arithmetic returns a new instance', function () {
    $original = Money::fils(1000);
    $result = $original->add(Money::fils(500));

    expect($original->toFils())->toBe(1000)
        ->and($result->toFils())->toBe(1500)
        ->and($result)->not->toBe($original);
});

it('compares and checks equality by fils', function () {
    expect(Money::fils(100)->equals(Money::fils(100)))->toBeTrue()
        ->and(Money::fils(100)->compare(Money::fils(200)))->toBe(-1)
        ->and(Money::fils(200)->compare(Money::fils(100)))->toBe(1)
        ->and(Money::fils(100)->compare(Money::fils(100)))->toBe(0);
});

it('reports zero correctly', function () {
    expect(Money::zero()->isZero())->toBeTrue()
        ->and(Money::fils(1)->isZero())->toBeFalse();
});

it('formats with the currency code and zero-padded fils', function () {
    expect(Money::fils(5)->format())->toBe('AED 0.05')
        ->and(Money::fils(100050)->format())->toBe('AED 1000.50')
        ->and(Money::fils(12000)->format('USD'))->toBe('USD 120.00');
});

it('formats a negative amount with a leading sign', function () {
    expect(Money::fils(-500)->format())->toBe('-AED 5.00');
});

it('serializes to the raw fils integer', function () {
    expect(Money::fils(1234)->jsonSerialize())->toBe(1234)
        ->and(json_encode(Money::fils(1234)))->toBe('1234');
});
