<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\Api\ConversationKind;
use App\Actions\Ai\ConversationKinds;
use App\Enums\Ai\AiKindType;
use App\Enums\Ai\AiRunStatus;
use App\Models\Ai\AiRun;
use App\Queries\Ai\ConversationSettings;
use App\Queries\Ai\KindSettings;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * 会話のジョブの 1(処理中にする。docs の 設計/AI/エージェントの実行/README.md 9.1 のジョブの順、写す値 = 設計/AI/実行の記録.md 2.1 の
 * 「用途ごとの接続先とモデル・出力の上限」)。種類の実装が引けなければ処理中にせず、呼び手が失敗(完了の処理)にする。もう終わった実行
 * (待ちの間に止めた実行など)は変えない(設計/AI/エージェントの実行/状態の移り方.md の S24)。
 */
final readonly class StartConversationRun
{
    public function __construct(
        private ConversationKinds $kinds,
        private KindSettings $kindSettings,
        private ConversationSettings $settings,
    ) {}

    /**
     * @return array{AiRun, ConversationKind|null}|null もう終わった実行なら null
     *
     * @throws LogicException 実行が会話の型でない・処理中・種類の設定が無いとき(作りの誤り)
     */
    public function execute(int $runId): ?array
    {
        return DB::transaction(function () use ($runId): ?array {
            $run = AiRun::query()->lockForUpdate()->findOrFail($runId);

            if ($run->kind_type === AiKindType::Conversation && $run->status->isFinished()) {
                return null;
            }

            if ($run->kind_type !== AiKindType::Conversation || $run->status !== AiRunStatus::Queued) {
                throw new LogicException("AI の実行 {$runId} は待ちの会話の型の実行でない");
            }

            $kind = $this->kinds->find($run->kind);

            if ($kind !== null) {
                $run->fill([
                    'status' => AiRunStatus::Running,
                    'started_at' => now(),
                    'purposes' => $this->purposes($run->kind),
                    'compacted' => false,
                ])->save();
            }

            return [$run, $kind];
        });
    }

    /**
     * @return array<string, array{provider: string, model: string, max_output_tokens: int}>
     */
    private function purposes(string $kindCode): array
    {
        $limits = ($this->kindSettings->conversation($kindCode)
            ?? throw new LogicException("会話の種類 {$kindCode} の設定が無い"))['purposes'];
        $purposes = [];

        foreach (ConversationSettings::PURPOSES as $purpose) {
            $values = $this->settings->purpose($purpose);
            $purposes[$purpose] = [
                'provider' => $values['provider'],
                'model' => $values['model'],
                'max_output_tokens' => $limits[$purpose]['max_output_tokens'],
            ];
        }

        return $purposes;
    }
}
