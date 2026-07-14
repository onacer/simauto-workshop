<?php

namespace App\Service;

class FrenchNumberFormatter
{
    public function money(float $amount): string
    {
        $dirhams = (int) floor($amount);
        $centimes = (int) round(($amount - $dirhams) * 100);
        if ($centimes === 100) {
            $dirhams++;
            $centimes = 0;
        }

        $words = $this->number($dirhams) . ' dirhams';
        if ($centimes > 0) {
            $words .= ' et ' . $this->number($centimes) . ' centimes';
        }

        return $words . ' TTC';
    }

    private function number(int $number): string
    {
        if ($number === 0) {
            return 'zero';
        }

        $parts = [];
        $millions = intdiv($number, 1000000);
        if ($millions > 0) {
            $parts[] = ($millions === 1 ? 'un' : $this->belowThousand($millions)) . ' million' . ($millions > 1 ? 's' : '');
            $number %= 1000000;
        }

        $thousands = intdiv($number, 1000);
        if ($thousands > 0) {
            $parts[] = $thousands === 1 ? 'mille' : $this->belowThousand($thousands) . ' mille';
            $number %= 1000;
        }

        if ($number > 0) {
            $parts[] = $this->belowThousand($number);
        }

        return implode(' ', $parts);
    }

    private function belowThousand(int $number): string
    {
        $hundreds = intdiv($number, 100);
        $rest = $number % 100;
        $parts = [];

        if ($hundreds > 0) {
            if ($hundreds === 1) {
                $parts[] = 'cent';
            } else {
                $parts[] = $this->belowHundred($hundreds) . ' cent' . ($rest === 0 ? 's' : '');
            }
        }

        if ($rest > 0) {
            $parts[] = $this->belowHundred($rest);
        }

        return implode(' ', $parts);
    }

    private function belowHundred(int $number): string
    {
        $units = [
            0 => '', 1 => 'un', 2 => 'deux', 3 => 'trois', 4 => 'quatre', 5 => 'cinq',
            6 => 'six', 7 => 'sept', 8 => 'huit', 9 => 'neuf', 10 => 'dix',
            11 => 'onze', 12 => 'douze', 13 => 'treize', 14 => 'quatorze', 15 => 'quinze', 16 => 'seize',
        ];
        $tens = [
            20 => 'vingt', 30 => 'trente', 40 => 'quarante', 50 => 'cinquante', 60 => 'soixante',
        ];

        if ($number <= 16) {
            return $units[$number];
        }
        if ($number < 20) {
            return 'dix-' . $units[$number - 10];
        }
        if ($number < 70) {
            $ten = intdiv($number, 10) * 10;
            $unit = $number % 10;
            if ($unit === 0) {
                return $tens[$ten];
            }
            return $tens[$ten] . ($unit === 1 ? ' et ' : '-') . $units[$unit];
        }
        if ($number < 80) {
            return 'soixante-' . $this->belowHundred($number - 60);
        }
        if ($number === 80) {
            return 'quatre-vingts';
        }
        return 'quatre-vingt-' . $this->belowHundred($number - 80);
    }
}
