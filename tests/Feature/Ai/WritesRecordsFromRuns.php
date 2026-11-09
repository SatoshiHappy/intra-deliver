<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\WriteRecordFromRun;
use App\Actions\Ai\CompletingRun;
use App\Actions\Records\Api\RecordInput;
use App\Enums\Ai\AiRunStatus;
use App\Enums\Records\RecordModuleAction;
use App\Models\Ai\AiRun;
use App\Models\Records\Record;
use Tests\Support\BuildsRecordModules;

/**
 * AI の書き込みの口のテストの手助け(書く先の業務モジュール ai_notes とレコード・処理中の実行・完了の受け手の中からの書き込み)。
 * 使うクラスは {@see BuildsRecordModules} と {@see UsesTestPromptKinds} も使う。
 */
trait WritesRecordsFromRuns
{
    protected const MODULE = 'ai_notes';

    /**
     * 業務モジュール ai_notes(項目 summary・memo)と、summary が「元の要約」のレコード。
     *
     * @param  list<RecordModuleAction>|null  $permitted  ゲストと認証済みの全員に許すアクション(既定はすべて)
     */
    protected function noteRecord(?array $permitted = null): Record
    {
        $module = $this->recordModule(self::MODULE, $permitted);
        $summary = $this->field($module, 'summary');
        $this->field($module, 'memo');

        return Record::factory()->create([
            'record_module_id' => $module->id,
            'field_values' => [$summary->id => '元の要約'],
        ]);
    }

    /**
     * レコードを対象に依頼した文章の実行を、処理中にする($attributes で列を置き換える = ほかの型の行にもできる)。
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function runningRun(int $recordId, array $attributes = []): int
    {
        $runId = $this->queuedRun(recordId: $recordId);
        AiRun::query()->whereKey($runId)->update([
            'status' => AiRunStatus::Running,
            'started_at' => now(),
            ...$attributes,
        ]);

        return $runId;
    }

    /** 実行の完了の受け手の中から、書き込みの口で summary を書く(口の結果を返す) */
    protected function writeDuringComplete(int $runId, string $operation, string $summary = 'x'): bool
    {
        $written = false;
        $input = new RecordInput(['summary' => $summary]);
        app(CompletingRun::class)->during($runId, function () use ($runId, $operation, $input, &$written): void {
            $written = app(WriteRecordFromRun::class)->execute($runId, $input, $operation);
        });

        return $written;
    }
}
