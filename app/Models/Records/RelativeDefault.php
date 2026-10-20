<?php

namespace App\Models\Records;

use App\Enums\Records\RecordFieldType;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * 日付・日時の項目の相対の既定値「登録の時から <期間> 後」(docs の 設計/業務データの入れ物/項目と規則/README.md 3 節)。
 * 長さ duration は ISO 8601 の形で持ち、先頭の 0 を受けない形だけにする(書き方を 1 つにする)。
 */
final readonly class RelativeDefault
{
    private const DURATION_PATTERN = '/^P(0|[1-9][0-9]{0,2})([DMY])$/D';

    private function __construct(
        public string $duration,
        private int $amount,
        private string $unit,
    ) {}

    /**
     * 長さの文字列から作る(形が違えば null)。
     */
    public static function tryFromDuration(mixed $duration): ?self
    {
        if (! is_string($duration) || preg_match(self::DURATION_PATTERN, $duration, $parts) !== 1) {
            return null;
        }

        return new self($duration, (int) $parts[1], $parts[2]);
    }

    /**
     * 保存形のオブジェクト {"relative": "<長さ>"} から作る(キーが relative だけで値が長さの形でなければ null)。
     * キャストと定義の検証が同じ形の規則を使うため、ここ 1 か所に置く。
     *
     * @param  array<array-key, mixed>  $value
     */
    public static function tryFromObject(array $value): ?self
    {
        return array_keys($value) === ['relative'] ? self::tryFromDuration($value['relative']) : null;
    }

    /**
     * 保存形のオブジェクト。
     *
     * @return array{relative: string}
     */
    public function toObject(): array
    {
        return ['relative' => $this->duration];
    }

    /**
     * 時点 $at から長さだけ後の値(項目と規則/README.md 3 節。保存形は データモデル/値の形と直列化.md 3 節)。
     *
     * @throws LogicException 日付・日時でない型のとき
     */
    public function valueAt(CarbonImmutable $at, RecordFieldType $type): string
    {
        $local = $at->setTimezone(AccessContext::TIMEZONE);

        return match ($type) {
            RecordFieldType::Date => $this->added($local->startOfDay())->toDateString(),
            RecordFieldType::Datetime => $this->added($local->startOfMinute())
                ->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z'),
            default => throw new LogicException("相対の既定値は日付・日時の項目だけが持つ: {$type->value}"),
        };
    }

    /**
     * 月・年は日があふれない足し方(Carbon の NoOverflow)。
     */
    private function added(CarbonImmutable $start): CarbonImmutable
    {
        return match ($this->unit) {
            'D' => $start->addDays($this->amount),
            'M' => $start->addMonthsNoOverflow($this->amount),
            default => $start->addYearsNoOverflow($this->amount),
        };
    }
}
