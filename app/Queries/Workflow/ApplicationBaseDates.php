<?php

namespace App\Queries\Workflow;

use App\Support\InputText;
use Carbon\CarbonImmutable;

/**
 * 申請基準日の読み方と「今日の前後 1 年」の 1 か所(docs の 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 10.1 の (1)・(2))。読み方は業務処理の 422
 * (ApplicationBaseDate)と申請の画面の初期値(ApplicationFormQuery)が、範囲は業務処理の 422 と画面の min/max({@see ApplicationNext::baseDateRange()})が同じ規則を使う
 * (画面の初期値は範囲の外の候補も捨てない = 範囲は送った後の 422 で示す。通しの回の T04・T06)。
 * 空と読めない値を別の文言(必須・日付でない)にする区別は業務処理が持つ。DB を読まない。
 */
final class ApplicationBaseDates
{
    /** 日付の形(画面の日付の入力の値・文言の {日付}) */
    public const FORMAT = 'Y-m-d';

    /**
     * 前後の空白を除いた YYYY-MM-DD の暦の日付(読めなければ null。空・空白だけ・文字列でないも null)。
     */
    public static function parse(mixed $input): ?CarbonImmutable
    {
        $text = InputText::normalize($input);

        if (! is_string($text) || preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $text, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return CarbonImmutable::createStrict((int) $parts[1], (int) $parts[2], (int) $parts[3]);
    }

    /**
     * 選べる最も早い日(今日の 1 年前の日。今日が 2 月 29 日なら 2 月 28 日)。
     */
    public static function earliest(CarbonImmutable $today): CarbonImmutable
    {
        return $today->subYearNoOverflow();
    }

    /**
     * 選べる最も遅い日(今日の 1 年後の日。今日が 2 月 29 日なら 2 月 28 日)。
     */
    public static function latest(CarbonImmutable $today): CarbonImmutable
    {
        return $today->addYearNoOverflow();
    }

    /**
     * 今日の前後 1 年の中か(両端を含む)。
     */
    public static function isWithinYear(CarbonImmutable $date, CarbonImmutable $today): bool
    {
        $day = $date->format(self::FORMAT);

        return $day >= self::earliest($today)->format(self::FORMAT) && $day <= self::latest($today)->format(self::FORMAT);
    }
}
