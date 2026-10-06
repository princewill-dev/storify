<?php

use App\Support\Money\Naira;

/*
| These tests exist to stop the conversion methods being merged.
|
| The API grew seventeen private money converters whose contracts differ on
| real input. If someone later "simplifies" them into one method, the
| disagreement cases below start failing — which is the point.
*/

test('the lenient parse reads digits and ignores what it does not understand', function () {
    expect(Naira::koboFromLenient('123.45'))->toBe(12345)
        ->and(Naira::koboFromLenient('123'))->toBe(12300)
        ->and(Naira::koboFromLenient('123.4'))->toBe(12340)
        ->and(Naira::koboFromLenient('0'))->toBe(0)
        ->and(Naira::koboFromLenient(null))->toBe(0)
        // A trailing point still reads as whole naira rather than failing.
        ->and(Naira::koboFromLenient('12.'))->toBe(1200)
        // Past two decimal places it truncates rather than rounding.
        ->and(Naira::koboFromLenient('12.999'))->toBe(1299)
        ->and(Naira::koboFromLenient('-12.34'))->toBe(-1234);
});

test('the strict parse rejects anything that is not a plain decimal', function () {
    expect(Naira::koboFromStrict('123.45'))->toBe(12345)
        ->and(Naira::koboFromStrict('123'))->toBe(12300)
        ->and(Naira::koboFromStrict('-12.34'))->toBe(-1234)
        ->and(Naira::koboFromStrict(20))->toBe(2000)
        ->and(Naira::koboFromStrict(20.5))->toBe(2050)
        // Everything below is a real disagreement with koboFromLenient.
        ->and(Naira::koboFromStrict('12.'))->toBe(0)
        ->and(Naira::koboFromStrict(''))->toBe(0)
        ->and(Naira::koboFromStrict(null))->toBe(0)
        ->and(Naira::koboFromStrict('abc'))->toBe(0)
        ->and(Naira::koboFromStrict('1e3'))->toBe(0);
});

test('the decimal-or-float parse is exact when it can be and rounds otherwise', function () {
    expect(Naira::koboFromDecimalOrFloat('123.45'))->toBe(12345)
        ->and(Naira::koboFromDecimalOrFloat('123'))->toBe(12300)
        // Not a plain decimal, so it falls through to float rounding — which
        // is why this differs from the strict parse on the same input.
        ->and(Naira::koboFromDecimalOrFloat('12.'))->toBe(1200)
        ->and(Naira::koboFromDecimalOrFloat('1e3'))->toBe(100000)
        ->and(Naira::koboFromDecimalOrFloat(20))->toBe(2000);
});

test('the rounded parse multiplies as a float', function () {
    expect(Naira::koboFromRounded('123.45'))->toBe(12345)
        ->and(Naira::koboFromRounded(20))->toBe(2000)
        ->and(Naira::koboFromRounded(null))->toBe(0)
        // Rounds rather than truncating, unlike the exact-string parses.
        ->and(Naira::koboFromRounded('12.999'))->toBe(1300);
});

test('the parse contracts genuinely disagree, so they must not be merged', function () {
    // Documented here rather than left implicit: these inputs are exactly why
    // one toKobo() cannot serve all call sites.

    // Strict rejects a trailing point; the other two read it as whole naira.
    expect(Naira::koboFromLenient('12.'))->toBe(1200)
        ->and(Naira::koboFromStrict('12.'))->toBe(0)
        ->and(Naira::koboFromDecimalOrFloat('12.'))->toBe(1200);

    // Strict rejects scientific notation. The lenient parse happens to agree
    // with the hybrid here, because PHP treats '1e3' as a numeric string.
    expect(Naira::koboFromStrict('1e3'))->toBe(0)
        ->and(Naira::koboFromLenient('1e3'))->toBe(100000)
        ->and(Naira::koboFromDecimalOrFloat('1e3'))->toBe(100000);

    // A negative with more than two decimals separates lenient from hybrid:
    // lenient truncates the exact string, hybrid falls through to float
    // rounding because its guard only accepts unsigned input.
    expect(Naira::koboFromLenient('-12.999'))->toBe(-1299)
        ->and(Naira::koboFromDecimalOrFloat('-12.999'))->toBe(-1300);
});

test('kobo renders as an exact decimal string', function () {
    expect(Naira::decimalFromKobo(12345))->toBe('123.45')
        ->and(Naira::decimalFromKobo(12300))->toBe('123.00')
        ->and(Naira::decimalFromKobo(5))->toBe('0.05')
        ->and(Naira::decimalFromKobo(0))->toBe('0.00')
        ->and(Naira::decimalFromKobo(100))->toBe('1.00');
});

test('negative kobo renders signed, not as a mangled string', function () {
    // The unsigned variants each controller carried produced "-1.-50" here.
    expect(Naira::decimalFromKobo(-150))->toBe('-1.50')
        ->and(Naira::decimalFromKobo(-5))->toBe('-0.05')
        ->and(Naira::decimalFromKobo(-12345))->toBe('-123.45');
});

test('kobo renders as a float for call sites that persist or assert one', function () {
    expect(Naira::floatFromKobo(12345))->toBe(123.45)
        ->and(Naira::floatFromKobo(0))->toBe(0.0)
        ->and(Naira::floatFromKobo(-150))->toBe(-1.5);
});

test('a percentage of a kobo amount stays in integers', function () {
    expect(Naira::percentOfKobo(100000, 1000))->toBe(10000)   // 10% of ₦1,000
        ->and(Naira::percentOfKobo(100000, 10000))->toBe(100000)
        ->and(Naira::percentOfKobo(0, 500))->toBe(0)
        // Truncates rather than rounding.
        ->and(Naira::percentOfKobo(999, 333))->toBe(33);
});

test('round-tripping kobo through a decimal string is lossless', function () {
    foreach ([0, 1, 5, 99, 100, 12345, 999999, 100000000] as $kobo) {
        expect(Naira::koboFromStrict(Naira::decimalFromKobo($kobo)))->toBe($kobo);
    }
});
