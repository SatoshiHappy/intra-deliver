<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\ConversationKind;

/**
 * Ai のテストの会話の型の種類を登録する手助け(① が無い間、会話の型の種類はテストの中だけに置く)。test.conversation を予算の区分 default・
 * 入力の上限 1,000 文字・回数の上限 10・実行の時間の上限 300 秒・用途ごとの呼び出しの時間の上限 100 秒と出力の上限(会話 1,000・題 100・
 * ファイルを作る 1,000)で設定に足す(公開デモの設定 config/ai_runs.php の kinds には会話の型の行を置かない = ① の分)。
 */
trait UsesTestConversationKinds
{
    protected RecordingConversationKind $conversationKind;

    protected function registerTestConversationKind(): void
    {
        $this->conversationKind = new RecordingConversationKind;
        $this->app->instance(RecordingConversationKind::class, $this->conversationKind);
        $this->app->tag([RecordingConversationKind::class], ConversationKind::TAG);
        $kinds = (array) config('ai_runs.kinds');
        $kinds[RecordingConversationKind::CODE] = self::conversationKindSettings();
        config(['ai_runs.kinds' => $kinds]);
    }

    /**
     * 会話の型の種類の設定。
     *
     * @return array<string, mixed>
     */
    protected static function conversationKindSettings(): array
    {
        return [
            'type' => 'conversation',
            'budget' => 'default',
            'max_input_chars' => 1000,
            'max_turns' => 10,
            'run_timeout_seconds' => 300,
            'purposes' => [
                'conversation' => ['timeout_seconds' => 100, 'max_output_tokens' => 1000],
                'title' => ['timeout_seconds' => 100, 'max_output_tokens' => 100],
                'create_file' => ['timeout_seconds' => 100, 'max_output_tokens' => 1000],
            ],
        ];
    }
}
