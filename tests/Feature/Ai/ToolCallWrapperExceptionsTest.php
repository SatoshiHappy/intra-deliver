<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Instructions\SharedInstructionTemplates;
use App\Actions\Ai\ToolCalls\ToolCallException;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\AiToolCallDecision;
use App\Enums\Ai\AiToolCallStatus;
use App\Queries\Records\Api\RecordFormQuery;
use App\Queries\Records\Api\RecordModuleDirectory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Exceptions;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\TransactionalTestCase;
use Throwable;

/**
 * 道具を呼ぶ包みの中の例外と道具の例外(docs の 設計/AI/エージェントの実行/道具の包みと止める.md 9.4・設計/AI/実行の記録.md 2.4 の T2・T4): 道具の検証の
 * 例外・呼び出しが指す業務モジュールの null と例外・判定の関数の口の例外・一覧で見た後に業務モジュールが消えた競合は包みの中の例外、道具の例外は
 * 道具の誤り。どれも報告し(既定では報告しない見つからないの例外も、元の例外を前の例外に持つ例外で報告する)、結果は道具の名前だけを入れた
 * 決まった失敗の文で、例外の文を入れない。会話は続ける(次の呼び出しも通る)。
 */
final class ToolCallWrapperExceptionsTest extends TransactionalTestCase
{
    use RunsToolCalls;

    private const SECRET = '例外の文 select * from secrets';

    protected function setUp(): void
    {
        parent::setUp();
        $this->arrangeWrapper();
        Exceptions::fake();
    }

    /**
     * 場合 → 報告される例外。
     *
     * @return array<string, array{string, class-string<Throwable>}>
     */
    public static function wrapperExceptions(): array
    {
        return [
            '道具の検証の例外' => ['validator', RuntimeException::class],
            '呼び出しが指す業務モジュールが null' => ['module_null', LogicException::class],
            '呼び出しが指す業務モジュールの例外' => ['module_throws', RuntimeException::class],
            '判定の関数の口の例外' => ['gate_throws', RuntimeException::class],
            '一覧で見た後に消えた業務モジュール' => ['vanished', ModelNotFoundException::class],
        ];
    }

    /**
     * @param  class-string<Throwable>  $reported
     */
    #[DataProvider('wrapperExceptions')]
    public function test_an_exception_inside_the_wrapper_is_reported_and_becomes_a_wrapper_error(
        string $case,
        string $reported,
    ): void {
        $tool = $this->tool('ai_read');
        $throw = fn () => throw new RuntimeException(self::SECRET);
        $module = 'm_view';
        match ($case) {
            'validator' => $tool->validator = $throw,
            'module_null' => $tool->module = fn (): ?string => null,
            'module_throws' => $tool->module = $throw,
            'gate_throws' => $this->throwingGate(),
            'vanished' => $this->app->instance(RecordModuleDirectory::class, app(RacingModuleDirectory::class)),
            default => throw new LogicException("知らない場合: {$case}"),
        };
        $module = $case === 'vanished' ? 'm_vanished' : $module;

        $this->wrap([self::toolCall('c1', 'ai_read', ['module' => $module]), self::toolCall('c2', 'ai_missing')]);

        $row = $this->row('c1');
        $expected = [AiToolCallDecision::WrapperException, AiToolCallStatus::ToolError];
        $this->assertSame($expected, [$row->decision, $row->status]);
        Exceptions::assertReported(fn (ToolCallException $e): bool => $e->getPrevious() instanceof $reported);
        $this->assertFailedWithoutTheMessage();
        $this->assertSame(AiToolCallDecision::UnknownTool, $this->row('c2')->decision);
        $this->assertSame(0, $tool->executions);
    }

    public function test_an_exception_of_the_tool_is_reported_and_becomes_a_tool_error(): void
    {
        $this->tool('ai_read')->executor = fn () => throw new RuntimeException(self::SECRET);

        $this->wrap([self::toolCall('c1', 'ai_read', ['module' => 'm_view'])]);

        $row = $this->row('c1');
        $this->assertSame([AiToolCallDecision::Allow, AiToolCallStatus::ToolError], [$row->decision, $row->status]);
        $this->assertNotNull($row->finished_at);
        $this->assertNull($row->text?->result_text);
        Exceptions::assertReported(fn (ToolCallException $e) => $e->getPrevious()?->getMessage() === self::SECRET);
        $this->assertFailedWithoutTheMessage();
    }

    /** 業務モジュールの権限の判定の口が例外を投げるようにする */
    private function throwingGate(): void
    {
        $forms = app(CountingRecordFormQuery::class);
        $forms->afterAllows = fn () => throw new RuntimeException(self::SECRET);
        $this->app->instance(RecordFormQuery::class, $forms);
    }

    /** 会話の保存の結果は道具の失敗の包みに道具の名前だけの決まった失敗の文(例外の文を含まない) */
    private function assertFailedWithoutTheMessage(): void
    {
        $shared = new SharedInstructionTemplates;
        $text = $shared->text(AiInstructionTemplate::ToolException, ['tool_name' => 'ai_read']);
        $stored = $this->storedContents();

        $expected = ['call_id' => 'c1', 'template' => 'tool_error', 'values' => ['error' => $text]];
        $this->assertSame([...$expected, 'body' => null, 'is_error' => true], $stored[0]);
        $this->assertStringNotContainsString(self::SECRET, (string) json_encode($stored, JSON_UNESCAPED_UNICODE));
    }
}
