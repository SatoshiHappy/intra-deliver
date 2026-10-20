<?php

namespace App\Queries\Records;

use App\Enums\Records\RecordFieldType;
use App\Models\Records\RecordField;
use App\Models\Records\RecordFieldOption;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;

/**
 * 通常の項目(参照と明細を除く)の値を、型ごとに読んで正規の形にする(docs の 設計/業務データの入れ物/項目と規則/README.md 2 節、値の JSON の形は
 * 設計/業務データの入れ物/データモデル/値の形と直列化.md 3 節)。業務処理(値の検証・既定値・条件の value)と一覧の検索条件の読み方が分かれないように 1 か所に置く。
 * 呼び手が先に、文字列の前後の空白を除いて空文字を null にし(2 節の前文)、選択の項目の選択肢(recordFieldOptions)を読み込んでおく。
 */
class FieldValueParser
{
    /** 整数の桁と、小数の整数部の桁の上限(設計/業務データの入れ物/データモデル/値の形と直列化.md 3 節) */
    public const INTEGER_DIGITS = 15;

    /** 小数の小数部の桁の上限 */
    public const FRACTION_DIGITS = 4;

    /** 1 行の文字列の最大の文字数(属性が NULL のとき) */
    public const SINGLE_LINE_MAX = 1000;

    /** 複数行の文字列の最大の文字数(属性が NULL のとき) */
    public const MULTILINE_MAX = 4000;

    /** 複数行の文字列の最大の文字数の属性に指定できる上限(項目と規則/README.md 1 節の最後) */
    public const MULTILINE_MAX_LIMIT = 20000;

    private const DATETIME_PATTERN = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?(Z|[+-](\d{2}):(\d{2}))$/D';

    /**
     * @return string|int|bool|null 正規の形の値(空なら null。真偽はいつも真偽)
     *
     * @throws InvalidFieldValue 型・長さ・選択肢に合わないとき
     */
    public function parse(RecordField $field, mixed $value): string|int|bool|null
    {
        if ($field->type === RecordFieldType::Boolean) {
            return $value === null || is_bool($value) ? (bool) $value : throw new InvalidFieldValue("{$field->name}は真偽(true・false)で指定してください");
        }

        if ($value === null) {
            return null;
        }

        return match ($field->type) {
            RecordFieldType::String => $this->stringOf($field, $value),
            RecordFieldType::Integer => $this->integerOf($field, $value),
            RecordFieldType::Decimal => $this->decimalOf($field, $value),
            RecordFieldType::Date => $this->dateOf($field, $value),
            RecordFieldType::Datetime => $this->datetimeOf($field, $value),
            RecordFieldType::Choice => $this->choiceOf($field, $value),
            default => throw new LogicException("参照・明細の値は通常の値として読まない: {$field->code}"),
        };
    }

    /**
     * 保存された通常の項目の値(field_values の項目の id のキー)を読む、ただ 1 つの口(設計/業務データの入れ物/データモデル/値の形と直列化.md 3 節): 真偽はキーが無ければ偽
     * (真偽は未設定を持たない)、ほかはキーが無ければ null。業務処理(今の値・書くかの比べ)と読み取り(画面の値)が使う。
     * SQL の側の同じ読み方は {@see RecordFilters::BOOLEAN_SQL}。
     *
     * @param  array<array-key, mixed>  $fieldValues
     */
    public function stored(RecordField $field, array $fieldValues): mixed
    {
        $value = $fieldValues[$field->id] ?? null;

        return $field->type === RecordFieldType::Boolean ? (bool) $value : $value;
    }

    /**
     * 文字列の項目の最大の文字数(属性が NULL なら型の上限)。
     */
    public function maxLengthOf(RecordField $field): int
    {
        return $field->max_length ?? ($field->is_multiline ? self::MULTILINE_MAX : self::SINGLE_LINE_MAX);
    }

    private function stringOf(RecordField $field, mixed $value): string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidFieldValue("{$field->name}は文字列で入力してください");
        }

        if (str_contains($value, "\0")) {
            throw new InvalidFieldValue("{$field->name}に使用できない文字(U+0000)が含まれています");
        }

        if (! $field->is_multiline && preg_match('/[\r\n]/', $value) === 1) {
            throw new InvalidFieldValue("{$field->name}は改行を含めずに入力してください");
        }

        $max = $this->maxLengthOf($field);

        // 今の定義(最大の文字数)だけに合わない誤り(値の形には合う = isWrongShape false。InvalidFieldValue の線引き)
        return mb_strlen($value) <= $max ? $value : throw new InvalidFieldValue("{$field->name}は {$max} 文字以内で入力してください", isWrongShape: false);
    }

    private function integerOf(RecordField $field, mixed $value): int
    {
        $digits = match (true) {
            is_int($value) => (string) abs($value),
            is_string($value) && preg_match('/^-?[0-9]+$/D', $value) === 1 => ltrim(ltrim($value, '-'), '0'),
            default => throw new InvalidFieldValue("{$field->name}は整数で入力してください"),
        };

        if (strlen($digits) > self::INTEGER_DIGITS) {
            throw new InvalidFieldValue("{$field->name}は ".self::INTEGER_DIGITS.' 桁までの整数で入力してください');
        }

        return (int) $value;
    }

    private function decimalOf(RecordField $field, mixed $value): string
    {
        $text = is_int($value) ? (string) $value : $value;

        if (! is_string($text) || preg_match('/^(-?)([0-9]+)(?:\.([0-9]+))?$/D', $text, $parts) !== 1) {
            throw new InvalidFieldValue("{$field->name}は数で入力してください");
        }

        $integer = ltrim($parts[2], '0');
        $fraction = rtrim($parts[3] ?? '', '0');

        if (strlen($integer) > self::INTEGER_DIGITS || strlen($fraction) > self::FRACTION_DIGITS) {
            throw new InvalidFieldValue("{$field->name}は整数部 ".self::INTEGER_DIGITS.' 桁・小数部 '.self::FRACTION_DIGITS.' 桁までの数で入力してください');
        }

        $absolute = ($integer === '' ? '0' : $integer).($fraction === '' ? '' : '.'.$fraction);

        return $parts[1] === '-' && $absolute !== '0' ? '-'.$absolute : $absolute;
    }

    private function dateOf(RecordField $field, mixed $value): string
    {
        $isDate = is_string($value)
            && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);

        return $isDate ? $value : throw new InvalidFieldValue("{$field->name}は日付(YYYY-MM-DD)で入力してください");
    }

    private function datetimeOf(RecordField $field, mixed $value): string
    {
        $invalid = new InvalidFieldValue("{$field->name}は時差つきの日時(例 2026-10-11T09:30+09:00)で入力してください");

        if (! is_string($value) || preg_match(self::DATETIME_PATTERN, $value, $parts) !== 1 || ! $this->existsAsDatetime($parts)) {
            throw $invalid;
        }

        $offset = $parts[7] === 'Z' ? '+00:00' : $parts[7];
        $local = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s P', "{$parts[1]}-{$parts[2]}-{$parts[3]} {$parts[4]}:{$parts[5]}:".($parts[6] !== '' ? $parts[6] : '00')." {$offset}");

        if ($local === false) {
            throw $invalid;
        }

        $utc = $local->setTimezone(new DateTimeZone('UTC'));
        $year = (int) $utc->format('Y');

        // 下限: PostgreSQL に年 0 が無く、それより前は timestamptz に変換できない。上限: 保存形の年は 4 桁で、5 桁になると条件の大小(文字列で比べる)が
        // 時刻の順にならない(項目と規則/README.md 2 節、データモデル/値の形と直列化.md 3 節)
        return $year >= 1 && $year <= 9999 ? $utc->format('Y-m-d\TH:i:s\Z') : throw new InvalidFieldValue("{$field->name}は 0001 年から 9999 年までの日時で入力してください");
    }

    /**
     * 日時の文字列の部分(正規表現の取り出し)が、実在する日・時刻・時差か。
     *
     * @param  array<int, string>  $parts
     */
    private function existsAsDatetime(array $parts): bool
    {
        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            && (int) $parts[4] <= 23 && (int) $parts[5] <= 59 && (int) ($parts[6] !== '' ? $parts[6] : 0) <= 59
            && (int) ($parts[8] ?? 0) <= 23 && (int) ($parts[9] ?? 0) <= 59;
    }

    private function choiceOf(RecordField $field, mixed $value): string
    {
        if (! is_string($value)) {
            throw new InvalidFieldValue("{$field->name}の値が正しくありません");
        }

        $isOption = $field->recordFieldOptions->contains(fn (RecordFieldOption $option) => $option->code === $value);

        // 今の定義(選択肢)だけに合わない誤り(isWrongShape false。InvalidFieldValue の線引き)。文字列でない値は値の形の誤り(上)
        return $isOption ? $value : throw new InvalidFieldValue("{$field->name}の値が正しくありません", isWrongShape: false);
    }
}
