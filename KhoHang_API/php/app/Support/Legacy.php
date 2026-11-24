<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Helpers that reproduce the storage/serialization conventions of the old
 * FastAPI + SQLAlchemy backend so both backends can share one SQLite file.
 *
 * - DATETIME columns hold naive UTC strings "Y-m-d H:i:s.uuuuuu".
 * - Pydantic serializes naive datetimes as "Y-m-dTH:i:s[.uuuuuu]".
 * - datetime.isoformat() on aware UTC values gives "...+00:00".
 * - Pydantic serializes aware UTC datetimes with a trailing "Z".
 */
class Legacy
{
    public const DB_FORMAT = 'Y-m-d H:i:s.u';

    public static function utc(): DateTimeZone
    {
        static $tz;

        return $tz ??= new DateTimeZone('UTC');
    }

    public static function nowDt(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::utc());
    }

    /** Current UTC time in DB storage format. */
    public static function now(): string
    {
        return self::nowDt()->format(self::DB_FORMAT);
    }

    public static function toDb(DateTimeImmutable $dt): string
    {
        return $dt->setTimezone(self::utc())->format(self::DB_FORMAT);
    }

    public static function parse(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat(self::DB_FORMAT, $value, self::utc())
            ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, self::utc());
        if ($dt === false) {
            try {
                $dt = new DateTimeImmutable($value, self::utc());
            } catch (\Exception) {
                return null;
            }
        }

        return $dt->setTimezone(self::utc());
    }

    private static function isoBase(DateTimeImmutable $dt): string
    {
        $base = $dt->format('Y-m-d\TH:i:s');
        $micro = $dt->format('u');

        return $micro === '000000' ? $base : $base.'.'.$micro;
    }

    /** Pydantic serialization of a naive datetime / datetime.isoformat() of naive value. */
    public static function iso(?string $value): ?string
    {
        $dt = self::parse($value);

        return $dt ? self::isoBase($dt) : null;
    }

    /** to_utc_iso(): isoformat() of an aware UTC datetime. */
    public static function isoOffset(?string $value): ?string
    {
        $dt = self::parse($value);

        return $dt ? self::isoBase($dt).'+00:00' : null;
    }

    public static function isoOffsetDt(DateTimeImmutable $dt): string
    {
        return self::isoBase($dt->setTimezone(self::utc())).'+00:00';
    }

    /** Pydantic serialization of an aware UTC datetime (ensure_utc + response model). */
    public static function isoZ(?string $value): ?string
    {
        $dt = self::parse($value);

        return $dt ? self::isoBase($dt).'Z' : null;
    }

    /** Decode a SQLAlchemy JSON column. */
    public static function json(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            return $value;
        }

        return json_decode($value, true);
    }

    /** Encode for a SQLAlchemy JSON column (None is stored as JSON 'null'). */
    public static function toJson(mixed $value): string
    {
        return json_encode($value, JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function uuid4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function float(mixed $v): float
    {
        return (float) ($v ?? 0);
    }

    /** Python's "{:.2f}" */
    public static function f2(float $v): string
    {
        return number_format($v, 2, '.', '');
    }

    /** Python's "{:.1f}" */
    public static function f1(float $v): string
    {
        return number_format($v, 1, '.', '');
    }

    /** Python str() of a float, e.g. 100.0, 0.5 */
    public static function pyFloat(float $v): string
    {
        $s = json_encode($v, JSON_PRESERVE_ZERO_FRACTION);

        return $s;
    }

    /** Python datetime.fromisoformat(s.replace('Z', '+00:00')); null when invalid. */
    public static function fromIso(string $s): ?DateTimeImmutable
    {
        $s = str_replace('Z', '+00:00', trim($s));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}(:\d{2}(:\d{2}(\.\d{1,6})?)?)?([+-]\d{2}:?\d{2})?)?$/', $s)) {
            return null;
        }
        try {
            return new DateTimeImmutable($s, self::utc());
        } catch (\Exception) {
            return null;
        }
    }
}
