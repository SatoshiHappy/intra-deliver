<?php

namespace Tests\Feature\Ai;

use App\Enums\Ai\AiConversationAnswer;
use App\Enums\Ai\AiRunStatus;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunStep;
use App\Models\Ai\AiToolCall;
use App\Models\Master\User;
use Illuminate\Support\Facades\DB;

/**
 * 失敗で終わる会話の型の実行の後始末のテストが使う、実行の行の作り方と子の行の状態の読み方。
 */
trait BuildsFailingConversations
{
    /**
     * 確認待ちで終わった前の実行と、それに続く処理中の実行(答えを渡せば答えの実行、null なら文の実行)。
     *
     * @return array{AiRun, AiRun}
     */
    private function answeredPair(?AiConversationAnswer $answer = AiConversationAnswer::Approve): array
    {
        $finished = ['started_at' => now(), 'finished_at' => now()];
        $previous = $this->conversationRun(['status' => AiRunStatus::AwaitingConfirmation, ...$finished]);
        $following = $this->conversationRun([
            'status' => AiRunStatus::Running,
            'started_at' => now(),
            'previous_run_id' => $previous->id,
            'answer' => $answer,
        ]);

        return [$previous, $following];
    }

    /**
     * 会話の型の実行(種類は test.conversation、依頼者は初期データの最初の利用者)。
     *
     * @param  array<string, mixed>  $attributes
     */
    private function conversationRun(array $attributes): AiRun
    {
        $userId = (int) User::query()->orderBy('id')->value('id');

        return AiRun::factory()->conversation()->create([
            'kind' => RecordingConversationKind::CODE,
            'requester_user_id' => $userId,
            ...$attributes,
        ]);
    }

    /**
     * 子の行の今の状態。
     *
     * @param  array<string, AiRunStep|AiToolCall>  $rows
     * @return array<string, string>
     */
    private function statuses(array $rows): array
    {
        return array_map(
            fn (AiRunStep|AiToolCall $row): string => (string) DB::table($row->getTable())
                ->where('id', $row->getKey())->value('status'),
            $rows,
        );
    }
}
