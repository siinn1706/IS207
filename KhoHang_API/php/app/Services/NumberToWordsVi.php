<?php

namespace App\Services;

/** Port of number_to_words_vi.py */
class NumberToWordsVi
{
    private const ONES = ['', 'một', 'hai', 'ba', 'bốn', 'năm', 'sáu', 'bảy', 'tám', 'chín'];

    private const TEENS = ['mười', 'mười một', 'mười hai', 'mười ba', 'mười bốn', 'mười lăm',
        'mười sáu', 'mười bảy', 'mười tám', 'mười chín'];

    private const TENS = ['', 'mười', 'hai mươi', 'ba mươi', 'bốn mươi', 'năm mươi',
        'sáu mươi', 'bảy mươi', 'tám mươi', 'chín mươi'];

    private const SCALES = ['', 'nghìn', 'triệu', 'tỷ', 'nghìn tỷ', 'triệu tỷ'];

    private static function group(int $num): string
    {
        if ($num === 0) {
            return '';
        }
        $hundred = intdiv($num, 100);
        $rem = $num % 100;
        $ten = intdiv($rem, 10);
        $one = $rem % 10;
        $r = '';
        if ($hundred > 0) {
            $r .= self::ONES[$hundred].' trăm';
            if ($rem > 0) {
                $r .= ' ';
            }
        }
        if ($rem >= 10 && $rem < 20) {
            $r .= $rem === 15 ? 'mười lăm' : self::TEENS[$rem - 10];
        } elseif ($rem >= 20) {
            $r .= self::TENS[$ten];
            if ($one > 0) {
                $r .= ' ';
                if ($one === 1 && $ten > 1) {
                    $r .= 'mốt';
                } elseif ($one === 5 && $ten > 0) {
                    $r .= 'lăm';
                } else {
                    $r .= self::ONES[$one];
                }
            }
        } elseif ($rem > 0) {
            $r .= $hundred > 0 ? 'lẻ '.self::ONES[$one] : self::ONES[$one];
        }

        return $r;
    }

    public static function number(int $num): string
    {
        if ($num === 0) {
            return 'không';
        }
        if ($num < 0) {
            return 'âm '.self::number(-$num);
        }
        $groups = [];
        while ($num > 0) {
            $groups[] = $num % 1000;
            $num = intdiv($num, 1000);
        }
        $parts = [];
        for ($i = count($groups) - 1; $i >= 0; $i--) {
            if ($groups[$i] > 0) {
                $scale = self::SCALES[$i] ?? '';
                $parts[] = self::group($groups[$i]).($scale !== '' ? ' '.$scale : '');
            }
        }

        return preg_replace('/\s+/u', ' ', trim(implode(' ', $parts)));
    }

    public static function amount(float $amount, string $currency = 'đồng', bool $capitalize = true): string
    {
        $r = self::number((int) $amount);
        if ($r === 'không') {
            $r = 'Không '.$currency;
        } else {
            $r .= ' '.$currency;
            if ($amount >= 1000 && fmod($amount, 1000) == 0) {
                $r .= ' chẵn';
            }
        }
        if ($capitalize && $r !== '') {
            $r = mb_strtoupper(mb_substr($r, 0, 1)).mb_substr($r, 1);
        }

        return $r;
    }
}
