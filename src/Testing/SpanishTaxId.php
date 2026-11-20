<?php

declare(strict_types=1);

namespace Vlec\Verifactu\Testing;

use Illuminate\Support\Str;

/**
 * The format and check character of a Spanish tax ID: a DNI, an NIE, a
 * K, L or M NIF, or a legal entity's NIF, as Orden EHA/451/2008 sets them.
 */
class SpanishTaxId
{
    protected const string PersonalLetters = 'TRWAGMYFPDXBNJZSQVHLCKE';

    protected const string EntityLetters = 'JABCDEFGHI';

    public static function isInvalid(string $taxId): bool
    {
        $taxId = Str::upper($taxId);

        if (Str::isMatch('/^\d{8}[A-Z]$/', $taxId)) {
            return $taxId[8] !== self::personalLetter(Str::substr($taxId, 0, 8));
        }

        if (Str::isMatch('/^[XYZ]\d{7}[A-Z]$/', $taxId)) {
            $number = Str::swap(['X' => '0', 'Y' => '1', 'Z' => '2'], $taxId[0]).Str::substr($taxId, 1, 7);

            return $taxId[8] !== self::personalLetter($number);
        }

        if (Str::isMatch('/^[KLM]\d{7}[A-Z]$/', $taxId)) {
            return $taxId[8] !== self::personalLetter(Str::substr($taxId, 1, 7));
        }

        if (Str::isMatch('/^[ABCDEFGHJNPQRSUVW]\d{7}[0-9A-J]$/', $taxId)) {
            return self::entityControlIsWrong($taxId);
        }

        return true;
    }

    protected static function personalLetter(string $number): string
    {
        return self::PersonalLetters[(int) $number % 23];
    }

    /**
     * The control is a letter for keys P, Q, S and W, a digit for keys A, B,
     * E and H, and either for the other keys.
     */
    protected static function entityControlIsWrong(string $taxId): bool
    {
        $digit = self::entityControlDigit(Str::substr($taxId, 1, 7));
        $letter = self::EntityLetters[$digit];
        $control = $taxId[8];

        if (Str::contains('PQSW', $taxId[0])) {
            return $control !== $letter;
        }

        if (Str::contains('ABEH', $taxId[0])) {
            return $control !== (string) $digit;
        }

        return $control !== $letter && $control !== (string) $digit;
    }

    /**
     * The odd positions count twice, each digit of the double added up.
     */
    protected static function entityControlDigit(string $digits): int
    {
        $sum = Str::of($digits)->split(1)->reduce(function (int $sum, string $digit, int $position): int {
            if ($position % 2 === 1) {
                return $sum + (int) $digit;
            }

            $double = (int) $digit * 2;

            return $sum + intdiv($double, 10) + $double % 10;
        }, 0);

        return (10 - $sum % 10) % 10;
    }
}
