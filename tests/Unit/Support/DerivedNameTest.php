<?php

use App\Support\Identity\DerivedName;

/*
| Signup collects an email and a password. These are the rules that turn the
| first of those into the two names the database insists on having, and they
| are pinned here because the output is user-visible: it is what the /welcome
| page greets the owner with, and — if a payment is made before the correction
| on the payment settings screen — what the subscription receipt says.
*/

test('the local part of the address becomes the name', function () {
    expect(DerivedName::forUser('ada@example.com'))->toBe('Ada')
        ->and(DerivedName::forUser('chidi.okonkwo@example.com'))->toBe('Chidi Okonkwo')
        ->and(DerivedName::forUser('chidi_okonkwo@example.com'))->toBe('Chidi Okonkwo')
        ->and(DerivedName::forUser('chidi-okonkwo@example.com'))->toBe('Chidi Okonkwo')
        ->and(DerivedName::forUser('chidi okonkwo@example.com'))->toBe('Chidi Okonkwo');
});

test('the sub-address is routing information, not part of the name', function () {
    expect(DerivedName::forUser('john+shop@example.com'))->toBe('John')
        ->and(DerivedName::forUser('john+@example.com'))->toBe('John');
});

test('runs of separators collapse, including the ones an address cannot hold', function () {
    // `a..b@` and `a__b@` are both legal addresses, and neither should become
    // a name with a hole in it. `a!b@` is not legal but costs nothing to
    // handle, and handling it is one character class instead of four.
    expect(DerivedName::forUser('a..b@example.com'))->toBe('A B')
        ->and(DerivedName::forUser('a__b@example.com'))->toBe('A B')
        ->and(DerivedName::forUser('a!b@example.com'))->toBe('A B')
        ->and(DerivedName::forUser('.ada.@example.com'))->toBe('Ada');
});

test('digits inside a word are kept, because they are part of the word', function () {
    // `ada99@` is a real shape of a real address, and Ada99 is nearer the
    // truth than Ada — which would be a different person's name. A digit is
    // also a title-case word boundary, so `shop2go` comes out as Shop2Go; the
    // point of the assertion is that the digit survives at all.
    expect(DerivedName::forUser('ada99@example.com'))->toBe('Ada99')
        ->and(DerivedName::forUser('shop2go@example.com'))->toBe('Shop2Go');
});

test('an address with no letters in it falls back rather than inventing a name', function () {
    // A business called "1234" is worse than one called "My Business", which is
    // at least recognisably a placeholder somebody will correct.
    expect(DerivedName::forUser('1234@example.com'))->toBe(DerivedName::USER_FALLBACK)
        ->and(DerivedName::forBusiness('1234@example.com'))->toBe(DerivedName::BUSINESS_FALLBACK)
        ->and(DerivedName::forUser('...@example.com'))->toBe(DerivedName::USER_FALLBACK);
});

test('the person and the business do not get the same fallback', function () {
    expect(DerivedName::USER_FALLBACK)->not->toBe(DerivedName::BUSINESS_FALLBACK);
});

test('a non-latin address still produces a name', function () {
    // The character class is Unicode-aware on purpose. Matching `[A-Za-z]`
    // would send every non-Latin signup to the English fallback, which reads
    // as a bug rather than a placeholder.
    expect(DerivedName::forUser('ọgá@example.com'))->toBe('Ọgá')
        ->and(DerivedName::forUser('мария@example.com'))->toBe('Мария');
});

test('a string with no domain is treated as all local part', function () {
    // Not a legal address, but `Str::before` answers this way and the rule is
    // worth stating rather than depending on by accident.
    expect(DerivedName::forUser('ada'))->toBe('Ada');
});

test('the result never exceeds the column it is written to', function () {
    // Both `users.name` and `businesses.name` are varchar(255). A local part
    // cannot legally be this long, which is exactly why an unbounded result
    // would go unnoticed until it hit a real database.
    $name = DerivedName::forUser(str_repeat('a', 300).'@example.com');

    expect(mb_strlen($name))->toBeLessThanOrEqual(255);
});
