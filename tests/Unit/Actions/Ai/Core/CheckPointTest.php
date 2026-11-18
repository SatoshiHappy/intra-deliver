<?php

namespace Tests\Unit\Actions\Ai\Core;

use App\Actions\Ai\Core\CheckPoint;
use App\Actions\Ai\Core\CheckReply;
use App\Actions\Ai\Core\SendKind;
use App\Actions\Ai\Core\StopKind;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 確かめの位置ごとの起きない組の止める制約(docs の 設計/AI/会話の中核.md 13.5): 返せる止める区分・続けるの欄・送信の種別の
 * デシジョン表を、すべての組で通す。
 */
final class CheckPointTest extends EmptyContainerTestCase
{
    /** 表の行(位置 → 返せる区分)。送る前は題とほかの送信で分ける */
    private const TABLE = [
        'before_send' => ['user', 'time_limit', 'cost_limit'],
        'before_send:title' => ['cost_limit'],
        'before_tool' => ['user', 'time_limit'],
        'before_retry_wait' => ['user', 'time_limit'],
        'while_reading' => ['user'],
        'after_reading' => ['user'],
        'after_tools' => ['user'],
        'during_retry_wait' => ['user'],
    ];

    /**
     * @return iterable<string, array{CheckPoint, SendKind, StopKind, bool}>
     */
    public static function combinations(): iterable
    {
        foreach (CheckPoint::cases() as $point) {
            foreach (SendKind::cases() as $kind) {
                $isTitle = $point === CheckPoint::BeforeSend && $kind === SendKind::Title;
                $row = $isTitle ? 'before_send:title' : $point->value;

                foreach (StopKind::cases() as $stop) {
                    $allowed = in_array($stop->value, self::TABLE[$row], true);

                    yield "{$point->value}・{$kind->value}・{$stop->value}" => [$point, $kind, $stop, $allowed];
                }
            }
        }
    }

    #[DataProvider('combinations')]
    public function test_a_stop_kind_is_allowed_only_where_the_table_allows_it(
        CheckPoint $point,
        SendKind $kind,
        StopKind $stop,
        bool $allowed,
    ): void {
        $this->assertSame($allowed, $point->allows($stop, $kind));
    }

    /** 表がすべての位置を覆う(位置を足して表を直し忘れると落ちる) */
    public function test_the_table_covers_every_point(): void
    {
        $rows = array_map(fn (string $row): string => explode(':', $row)[0], array_keys(self::TABLE));

        $points = array_column(CheckPoint::cases(), 'value');

        $this->assertEqualsCanonicalizing($points, array_values(array_unique($rows)));
    }

    /** 続けるの欄の表(位置 → 受ける欄の組: s = ステップの番号、w = 待ちの上限) */
    private const PROCEED_TABLE = [
        'before_send' => ['s'],
        'before_retry_wait' => ['', 'w'],
        'before_tool' => [''],
        'while_reading' => [''],
        'after_reading' => [''],
        'after_tools' => [''],
        'during_retry_wait' => [''],
    ];

    /**
     * @return iterable<string, array{CheckPoint, string, bool}>
     */
    public static function proceeds(): iterable
    {
        foreach (CheckPoint::cases() as $point) {
            // 番号と待ちの上限を両方持つ続けるは起きない(作り手が無い。コンストラクタは private)
            foreach (['', 's', 'w'] as $fields) {
                $accepted = in_array($fields, self::PROCEED_TABLE[$point->value], true);

                yield "{$point->value}・欄 {$fields}" => [$point, $fields, $accepted];
            }
        }
    }

    #[DataProvider('proceeds')]
    public function test_a_proceed_reply_fits_a_point_only_with_the_fields_of_the_table(
        CheckPoint $point,
        string $fields,
        bool $accepted,
    ): void {
        $reply = match ($fields) {
            '' => CheckReply::proceed(),
            's' => CheckReply::proceedToSend(3),
            default => CheckReply::proceedToWait(1000),
        };

        $this->assertSame($accepted, $point->accepts($reply, SendKind::Turn));
    }

    public function test_a_stop_reply_fits_a_point_by_the_stop_table(): void
    {
        $this->assertTrue(CheckPoint::BeforeTool->accepts(CheckReply::stop(StopKind::TimeLimit), SendKind::Turn));
        $this->assertFalse(CheckPoint::BeforeTool->accepts(CheckReply::stop(StopKind::CostLimit), SendKind::Turn));
    }

    /** 送信の種別の表(位置 → 受ける種別。送る前はどれも) */
    private const KIND_TABLE = [
        'before_tool' => ['turn', 'continuation'],
        'after_tools' => ['turn', 'continuation'],
        'while_reading' => ['turn', 'continuation', 'compaction', 'compaction_fallback'],
        'after_reading' => ['turn', 'continuation', 'compaction', 'compaction_fallback'],
        'before_retry_wait' => ['turn', 'continuation', 'compaction', 'compaction_fallback'],
        'during_retry_wait' => ['turn', 'continuation', 'compaction', 'compaction_fallback'],
    ];

    /**
     * @return iterable<string, array{CheckPoint, SendKind, bool}>
     */
    public static function kinds(): iterable
    {
        foreach (CheckPoint::cases() as $point) {
            foreach (SendKind::cases() as $kind) {
                $kinds = self::KIND_TABLE[$point->value] ?? null;
                $received = $kinds === null || in_array($kind->value, $kinds, true);

                yield "{$point->value}・{$kind->value}" => [$point, $kind, $received];
            }
        }
    }

    #[DataProvider('kinds')]
    public function test_a_point_receives_only_the_send_kinds_of_the_table(
        CheckPoint $point,
        SendKind $kind,
        bool $received,
    ): void {
        $this->assertSame($received, $point->receives($kind));
    }
}
