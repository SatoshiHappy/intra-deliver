<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\CompletedRun;
use App\Actions\Ai\Api\FailedRun;
use App\Actions\Ai\Api\PromptKind;
use App\Models\Ai\AiRun;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * テストの種類(コード test.summary)。complete と failed に渡された値と、そのときの実行の状態・トランザクションの深さを覚える
 * (同じトランザクションで見えるか・別のトランザクションか)。complete と failed の中身・書いてよい業務モジュール・failed の例外はテストが差し替える。
 */
final class RecordingPromptKind implements PromptKind
{
    public const CODE = 'test.summary';

    /** 書いてよい業務モジュール(テストが置く) */
    public ?string $writesTo = null;

    /** @var (Closure(CompletedRun): void)|null complete の中で走らせる処理(書き込みの口を呼ぶ・例外を投げる) */
    public ?Closure $onComplete = null;

    /** @var (Closure(FailedRun): void)|null failed の中で走らせる処理(通知を書く・ほかの経路を真似る) */
    public ?Closure $onFailed = null;

    /** failed が投げる例外(テストが置く。onFailed の後に投げる) */
    public ?Throwable $failedThrows = null;

    /** @var list<CompletedRun> */
    public array $completed = [];

    /** @var list<string> complete の中で読んだ実行の状態 */
    public array $statusesSeen = [];

    /** @var list<FailedRun> */
    public array $failed = [];

    /** @var list<array{string, int}> failed の中で読んだ実行の状態と、トランザクションの深さ */
    public array $failedSeen = [];

    public function code(): string
    {
        return self::CODE;
    }

    public function schema(JsonSchema $schema): array
    {
        return ['summary' => $schema->string()->required()];
    }

    public function writesTo(): ?string
    {
        return $this->writesTo;
    }

    public function complete(CompletedRun $run): void
    {
        $this->completed[] = $run;
        $this->statusesSeen[] = AiRun::query()->findOrFail($run->runId)->status->value;

        if ($this->onComplete !== null) {
            ($this->onComplete)($run);
        }
    }

    public function failed(FailedRun $run): void
    {
        $this->failed[] = $run;
        $this->failedSeen[] = [AiRun::query()->findOrFail($run->runId)->status->value, DB::transactionLevel()];

        if ($this->onFailed !== null) {
            ($this->onFailed)($run);
        }

        if ($this->failedThrows !== null) {
            throw $this->failedThrows;
        }
    }
}
