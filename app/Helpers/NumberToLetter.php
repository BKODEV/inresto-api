<?php

declare(strict_types=1);

namespace App\Helpers;

class NumberToLetter
{
    /** @var string[] */
    private static array $units = [
        '', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf',
        'dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize',
        'dix-sept', 'dix-huit', 'dix-neuf',
    ];

    /** @var string[] */
    private static array $tens = [
        '', '', 'vingt', 'trente', 'quarante', 'cinquante',
        'soixante', 'soixante', 'quatre-vingt', 'quatre-vingt',
    ];

    public static function convert(float $number): string
    {
        if ($number == 0) {
            return 'zéro';
        }

        $isNegative = $number < 0;
        $number     = abs($number);

        $integer = (int) $number;
        $decimal = (int) round(($number - $integer) * 100);

        $result = $isNegative ? 'moins ' : '';
        $result .= self::convertInteger($integer);

        if ($decimal > 0) {
            $result .= ' et ' . self::convertInteger($decimal);
            $result .= $decimal === 1 ? ' centime' : ' centimes';
        }

        return $result;
    }

    public static function toFcfaFormula(float $amount): string
    {
        return 'Arrêtée cette présente facture à la somme de ' . self::convert($amount) . ' francs CFA.';
    }

    private static function convertInteger(int $number): string
    {
        if ($number === 0) {
            return '';
        }

        if ($number < 20) {
            return self::$units[$number];
        }

        if ($number < 100) {
            $tens  = intdiv($number, 10);
            $units = $number % 10;

            if ($tens === 7 || $tens === 9) {
                $tens--;
                $units += 10;
            }

            $result = self::$tens[$tens];

            if ($units > 0) {
                $needsEt = ($tens >= 2 && $tens <= 6 && $units === 1) || ($tens === 8 && $units === 1);
                $result .= $needsEt ? '-et-un' : '-' . self::$units[$units];
            } elseif ($tens === 8) {
                $result .= 's';
            }

            return $result;
        }

        if ($number < 1000) {
            $hundreds  = intdiv($number, 100);
            $remainder = $number % 100;

            $result = $hundreds === 1 ? 'cent' : self::$units[$hundreds] . ' cent';

            if ($remainder === 0 && $hundreds > 1) {
                $result .= 's';
            } elseif ($remainder > 0) {
                $result .= ' ' . self::convertInteger($remainder);
            }

            return $result;
        }

        if ($number < 1_000_000) {
            $thousands = intdiv($number, 1000);
            $remainder = $number % 1000;

            $result = $thousands === 1 ? 'mille' : self::convertInteger($thousands) . ' mille';

            if ($remainder > 0) {
                $result .= ' ' . self::convertInteger($remainder);
            }

            return $result;
        }

        if ($number < 1_000_000_000) {
            $millions  = intdiv($number, 1_000_000);
            $remainder = $number % 1_000_000;

            $result = self::convertInteger($millions) . ($millions === 1 ? ' million' : ' millions');

            if ($remainder > 0) {
                $result .= ' ' . self::convertInteger($remainder);
            }

            return $result;
        }

        $billions  = intdiv($number, 1_000_000_000);
        $remainder = $number % 1_000_000_000;

        $result = self::convertInteger($billions) . ($billions === 1 ? ' milliard' : ' milliards');

        if ($remainder > 0) {
            $result .= ' ' . self::convertInteger($remainder);
        }

        return $result;
    }
}
