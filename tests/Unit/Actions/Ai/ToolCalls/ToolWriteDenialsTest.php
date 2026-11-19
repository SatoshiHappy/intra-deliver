<?php

namespace Tests\Unit\Actions\Ai\ToolCalls;

use App\Actions\Ai\ToolCalls\ToolWriteDenials;
use App\Actions\Records\Api\RecordHolderRefusal;
use App\Actions\Records\Api\RecordStaleRefusal;
use App\Enums\Ai\AiToolWriteDenial;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * 入れ物の断りと表 2 の行(docs の 設計/AI/道具と権限/まとめた実行と送るデータ.md 10.5): 入れ物の答えの型ごとに表 2 の行へ読み替え、
 * 持ち主と開いた後の更新は 422 の中でも型で見分け、予期しない例外は道具の例外(null)。
 */
final class ToolWriteDenialsTest extends TestCase
{
    /**
     * 例外は作る関数で渡す(422 の型はアプリの起動の後に作る)。
     *
     * @return array<string, array{Closure(): Throwable, bool, AiToolWriteDenial|null}>
     */
    public static function answers(): array
    {
        return [
            '登録の 403' => [fn () => new AuthorizationException, true, AiToolWriteDenial::NoPermission],
            '更新の 403' => [fn () => new AuthorizationException, false, AiToolWriteDenial::NoPermission],
            '登録の 404' => [fn () => new ModelNotFoundException, true, AiToolWriteDenial::Unavailable],
            '更新の 404' => [fn () => new ModelNotFoundException, false, AiToolWriteDenial::NotFound],
            '持ち主の 422' => [fn () => RecordHolderRefusal::of('申請の途中'), false, AiToolWriteDenial::Held],
            '開いた後の更新の 422' => [fn () => RecordStaleRefusal::of('先に直された'), false, AiToolWriteDenial::Stale],
            'ほかの 422' => [
                fn () => ValidationException::withMessages(['title' => ['必須']]),
                true,
                AiToolWriteDenial::Validation,
            ],
            'ほかの例外' => [fn () => new RuntimeException('壊れた'), true, null],
        ];
    }

    /**
     * @param  Closure(): Throwable  $answer
     */
    #[DataProvider('answers')]
    public function test_each_answer_maps_to_its_row(
        Closure $answer,
        bool $creates,
        ?AiToolWriteDenial $expected,
    ): void {
        $this->assertSame($expected, ToolWriteDenials::of($answer(), $creates)[0] ?? null);
    }

    /** ほかの 422 の文はキーごとの誤りを並べ、見つからないは失敗の印の無い本文 */
    public function test_the_validation_text_lists_each_key_and_not_found_has_no_failure_mark(): void
    {
        $exception = ValidationException::withMessages(['title' => ['必須'], 'amount' => ['数']]);
        $text = ToolWriteDenials::of($exception, true)[1] ?? '';

        $this->assertSame(ToolWriteDenials::VALIDATION."\n- `title`: 必須\n- `amount`: 数", $text);
        $this->assertFalse(ToolWriteDenials::item('c1', AiToolWriteDenial::NotFound, '')->content['is_error']);
        $this->assertTrue(ToolWriteDenials::item('c1', AiToolWriteDenial::Stale, 'x')->content['is_error']);
    }
}
