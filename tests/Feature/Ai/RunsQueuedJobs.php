<?php

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * キューの表(jobs)のジョブを読み、テストの中で明示して流す手助け(テストのキューも既定の DB 接続の database = docs の 設計/AI/実行と費用.md 4 節の
 * 「ジョブの入れ方」。ジョブは依頼のトランザクションの中では走らない)。
 */
trait RunsQueuedJobs
{
    /**
     * 表 jobs にあるジョブのうち、$jobClass の実行の id(入れた順)。
     *
     * @param  class-string  $jobClass
     * @return list<int>
     */
    protected function queuedRunIds(string $jobClass): array
    {
        $runIds = [];

        foreach (DB::table('jobs')->orderBy('id')->pluck('payload') as $payload) {
            $data = json_decode((string) $payload, true, flags: JSON_THROW_ON_ERROR)['data'];

            if ($data['commandName'] === $jobClass) {
                $runIds[] = (int) unserialize($data['command'], ['allowed_classes' => [$jobClass]])->runId;
            }
        }

        return $runIds;
    }

    /**
     * 既定のキューから次のジョブを 1 つ取り出して流す(worker の 1 回分。ジョブの例外はそのまま投げる)。
     */
    protected function runNextQueuedJob(): void
    {
        $job = Queue::pop();
        $this->assertNotNull($job, 'キューにジョブが無い');
        $job->fire();
    }
}
