<?php

namespace UpdateCheck;

use DateTimeImmutable;
use DateTimeZone;

/**
 * 日付の読み方。すべて UTC の暦日で読み、UTC の今日と比べる(PHP の既定のタイムゾーンに依らない)。
 */
final class Dates
{
    /**
     * YYYY-MM-DD だけを受ける("tomorrow" や "2026" のような文字列を日付に読まない)。
     *
     * @throws UnconfirmedException
     */
    public static function parse(string $text, string $what): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new UnconfirmedException("{$what} が日付(YYYY-MM-DD)として読めない");
        }

        return $date;
    }

    /**
     * 与えられた時刻の UTC の今日(0 時)。
     */
    public static function today(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);
    }

    /**
     * $from から $to までの日数(過ぎていれば負)。
     */
    public static function daysBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return (int) $from->diff($to)->format('%r%a');
    }
}
