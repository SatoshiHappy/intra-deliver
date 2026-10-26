<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\PromptKind;
use App\Actions\Ai\Api\PromptRequest;
use App\Actions\Ai\Api\RequestPrompt;
use App\Actions\Ai\ExecutePrompt;
use App\Models\Ai\AiRun;
use App\Models\Master\User;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Throwable;

/**
 * Ai のテストの種類を登録する手助け(種類の実装はテストの中だけに置く)。test.summary・test.throwing を Haiku 5.5(公開デモの単価)・
 * 予算の区分 default・呼び出しの時間の上限 100 秒・出力の上限 100・入力の上限 1,000 文字・思考の量 low で設定に足し、上限は既定で無しにする(上限のテストが値を置く)。
 * Anthropic の鍵は偽の値にする({@see self::FAKE_PROVIDER_KEY})。
 * 実装の登録が無い種類 {@see self::UNREGISTERED_KIND} も設定に足す(本物の種類のコードは、登録が変わると意味が変わるので使わない)。
 */
trait UsesTestPromptKinds
{
    /** 接続先の偽の鍵(接続先は Http::fake か PromptAgent::fake で作り、本物を呼ばない) */
    protected const FAKE_PROVIDER_KEY = 'fake-key-for-tests';

    /** 設定にあり、実装の登録が無いテストだけの種類 */
    protected const UNREGISTERED_KIND = 'test.unregistered';

    protected RecordingPromptKind $recordingKind;

    protected function registerTestPromptKinds(): void
    {
        $this->recordingKind = new RecordingPromptKind;
        $this->app->instance(RecordingPromptKind::class, $this->recordingKind);
        $this->app->tag([RecordingPromptKind::class, ThrowingPromptKind::class], PromptKind::TAG);

        $settings = [
            'type' => 'prompt',
            'provider' => 'anthropic',
            'model' => 'claude-haiku-5-5',
            'budget' => 'default',
            'timeout_seconds' => 100,
            'max_output_tokens' => 100,
            'max_input_chars' => 1000,
            'provider_options' => ['output_config' => ['effort' => 'low']],
        ];
        $unlimited = ['daily_usd' => null, 'monthly_usd' => null, 'total_usd' => null];
        config([
            'ai_runs.kinds' => [
                ...(array) config('ai_runs.kinds'),
                RecordingPromptKind::CODE => $settings,
                ThrowingPromptKind::CODE => $settings,
                self::UNREGISTERED_KIND => $settings,
            ],
            'ai_runs.budget' => ['default' => $unlimited, 'transcription' => $unlimited],
            'ai.providers.anthropic.key' => self::FAKE_PROVIDER_KEY,
        ]);
    }

    /**
     * 種類の設定の 1 つの値を差し替える(種類のコードは . を含むので、config() の . の区切りを通さず配列ごと置く)。
     */
    protected function setKindSetting(string $code, string $key, mixed $value): void
    {
        $kinds = (array) config('ai_runs.kinds');
        $kinds[$code] = [...(array) $kinds[$code], $key => $value];
        config(['ai_runs.kinds' => $kinds]);
    }

    /**
     * 依頼(システムの文 10 文字 + 利用者の文 90 文字 = 100 文字。見積もり = (100 × 2 + 2,000)× 0.10 + 100 × 0.50 = 270 / 100 万 = 0.000270 ドル)。
     *
     * @param  list<int>  $sourceRecordIds
     */
    protected function promptRequest(string $kindCode = RecordingPromptKind::CODE, ?int $recordId = null, array $sourceRecordIds = [], ?string $userText = null): PromptRequest
    {
        return new PromptRequest($kindCode, $recordId, $sourceRecordIds, str_repeat('S', 10), $userText ?? str_repeat('U', 90));
    }

    /**
     * 依頼して待ちの実行を作る(ジョブは出さない)。
     */
    protected function queuedRun(
        string $kindCode = RecordingPromptKind::CODE,
        ?int $recordId = null,
        ?AccessContext $context = null,
    ): int {
        Queue::fake();
        $request = $this->promptRequest($kindCode, $recordId);

        return app(RequestPrompt::class)->execute($context ?? $this->requesterContext(), $request);
    }

    /**
     * 失敗する実行を動かし、投げ直された例外を確かめて実行を読み直す。
     *
     * @param  class-string<Throwable>|null  $expected  投げ直される例外(null = LogicException でない例外)
     */
    protected function executeFailing(int $runId, ?string $expected = null): AiRun
    {
        $thrown = null;

        try {
            app(ExecutePrompt::class)->execute($runId);
        } catch (Throwable $exception) {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown, '失敗が投げ直されなかった');
        $this->assertInstanceOf($expected ?? Throwable::class, $thrown);

        if ($expected === null) {
            $this->assertNotInstanceOf(LogicException::class, $thrown);
        }

        return AiRun::query()->findOrFail($runId);
    }

    protected function requesterContext(
        ?int $userId = null,
        ?int $selectedDepartmentId = null,
        ?string $visitorId = null,
    ): AccessContext {
        $userId ??= $this->requesterUserId();
        $today = CarbonImmutable::today(AccessContext::TIMEZONE);

        return new AccessContext($userId, null, $selectedDepartmentId, $today, AccessContext::LOCALE_CODE, $visitorId);
    }

    protected function guestContext(): AccessContext
    {
        return new AccessContext(null, null, null, CarbonImmutable::today(AccessContext::TIMEZONE), AccessContext::LOCALE_CODE);
    }

    protected function requesterUserId(): int
    {
        return (int) User::query()->orderBy('id')->value('id');
    }
}
