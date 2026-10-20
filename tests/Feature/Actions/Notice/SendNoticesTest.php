<?php

namespace Tests\Feature\Actions\Notice;

use App\Actions\Notice\Api\NoticeMessage;
use App\Actions\Notice\Api\SendNotices;
use App\Models\Master\User;
use App\Models\Notice\Notice;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\TransactionalTestCase;

/**
 * 通知を送る口(docs の 設計/ワークフロー/案件の運用/通知.md の 1 節・2 節・3 節): 宛先の重複を除いて 1 人 1 行、0 人で何も書かない、
 * 空の値を JSON のオブジェクトで書く、種類と値の形の誤りと存在しない宛先は例外、呼び手のトランザクションが巻き戻ると通知も残らない。
 */
final class SendNoticesTest extends TransactionalTestCase
{
    /**
     * @return array<string, array{NoticeMessage}>
     */
    public static function malformedMessages(): array
    {
        return [
            '種類にモジュールが無い' => [new NoticeMessage('process_request', null, null, null, [])],
            '種類に大文字' => [new NoticeMessage('Workflow.process_request', null, null, null, [])],
            '種類の末尾の改行' => [new NoticeMessage("workflow.process_request\n", null, null, null, [])],
            '種類が 100 字を超える' => [new NoticeMessage('workflow.'.str_repeat('a', 92), null, null, null, [])],
            '対象の種類だけ' => [new NoticeMessage('workflow.process_request', 'workflow-case', null, null, [])],
            '対象の id だけ' => [new NoticeMessage('workflow.process_request', null, 1, null, [])],
            '対象の種類の書式' => [new NoticeMessage('workflow.process_request', 'workflow_case', 1, null, [])],
            '値のキーが整数' => [self::withParams(['a', 'b'])],
            '値が配列' => [self::withParams(['names' => ['a']])],
            '値が小数' => [self::withParams(['rate' => 1.5])],
        ];
    }

    public function test_writes_one_row_per_recipient_without_duplicates(): void
    {
        [$first, $second, $actor] = $this->userIds(3);
        $params = ['case_name' => '出張申請', 'case_number' => 12, 'urgent' => false, 'comment' => null];

        $message = new NoticeMessage('workflow.process_request', 'workflow-case', 7, $actor, $params);

        app(SendNotices::class)->execute($message, [$first, $second, $first]);

        $rows = Notice::query()->orderBy('id')->get();
        $this->assertSame([$first, $second], $rows->pluck('user_id')->all());
        ksort($params);

        foreach ($rows as $notice) {
            $written = $notice->params;
            ksort($written);
            $this->assertSame($params, $written);
            $this->assertSame(
                ['type' => 'workflow.process_request', 'target_type' => 'workflow-case', 'target_id' => 7],
                $notice->only(['type', 'target_type', 'target_id']),
            );
            $this->assertSame([$actor, null], [$notice->actor_user_id, $notice->read_at]);
        }
    }

    public function test_writes_nothing_for_no_recipients(): void
    {
        app(SendNotices::class)->execute(new NoticeMessage('records.comment', 'record', 1, null, []), []);

        $this->assertSame(0, Notice::query()->count());
    }

    public function test_writes_empty_params_as_a_json_object(): void
    {
        [$recipient] = $this->userIds(1);

        app(SendNotices::class)->execute(new NoticeMessage('records.comment', 'record', 3, null, []), [$recipient]);

        $this->assertSame('{}', DB::table('notices')->value('params'));
        $this->assertSame([], Notice::query()->sole()->params);
    }

    #[DataProvider('malformedMessages')]
    public function test_rejects_a_malformed_message_without_writing(NoticeMessage $message): void
    {
        [$recipient] = $this->userIds(1);

        try {
            app(SendNotices::class)->execute($message, [$recipient]);
            $this->fail('例外にならなかった');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, Notice::query()->count());
        }
    }

    public function test_rejects_a_missing_recipient(): void
    {
        [$recipient] = $this->userIds(1);
        $missing = (int) User::query()->max('id') + 1;

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('notices_user_id_foreign');

        app(SendNotices::class)->execute(self::comment(), [$recipient, $missing]);
    }

    public function test_leaves_no_notice_when_the_caller_rolls_back(): void
    {
        [$recipient] = $this->userIds(1);

        try {
            DB::transaction(function () use ($recipient): void {
                app(SendNotices::class)->execute(self::comment(), [$recipient]);

                throw new RuntimeException('呼び手の操作が失敗した');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, Notice::query()->count());
    }

    /**
     * 値の無い種類の通知(対象なし)。
     */
    private static function comment(): NoticeMessage
    {
        return new NoticeMessage('records.comment', null, null, null, []);
    }

    /**
     * 口の型(文字列のキーと文字列・整数・真偽・null の値)の外の値を渡す、呼び手の誤りの通知。
     */
    private static function withParams(mixed $params): NoticeMessage
    {
        return new NoticeMessage('workflow.process_request', null, null, null, $params);
    }

    /**
     * @return list<int>
     */
    private function userIds(int $count): array
    {
        return array_values(array_map('intval', User::query()->orderBy('id')->limit($count)->pluck('id')->all()));
    }
}
