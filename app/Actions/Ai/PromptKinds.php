<?php

namespace App\Actions\Ai;

use App\Actions\Ai\Api\PromptKind;
use App\Actions\Ai\Instructions\DefaultInstructionBuilder;
use App\Actions\Ai\Instructions\InstructionBuilder;
use App\Actions\Ai\ToolCalls\AgentTools;
use App\Queries\Ai\ConversationSettings;
use App\Queries\Ai\KindSettings;
use App\Support\DefaultDatabaseQueue;
use Illuminate\Container\Attributes\Tag;
use LogicException;

/**
 * AI の実行の種類の全体 = タグ {@see PromptKind::TAG} で束ねた実装を引く 1 か所(内部。docs の 設計/AI/公開の口.md 3.1)。
 * Ai の業務処理は種類の実装を直接呼ばずにここを通す。登録と設定の食い違い({@see self::problems()}。文字起こしと会話の型の種類と道具の登録を含む)は
 * `ai:check-settings` とテストが落とす。
 */
class PromptKinds
{
    /**
     * @param  iterable<PromptKind>  $kinds
     */
    public function __construct(
        #[Tag(PromptKind::TAG)] private iterable $kinds,
        private TranscriptionKinds $transcriptionKinds,
        private ConversationKinds $conversationKinds,
        private KindSettings $kindSettings,
        private KindLimitChecks $limits,
        private ConversationSettings $conversation,
        private DefaultInstructionBuilder $instructions,
        private InstructionBuilder $boundInstructions,
        private AgentTools $agentTools,
    ) {}

    /**
     * コードの種類の実装。
     *
     * @throws LogicException 登録の無いコードのとき(作りの誤り)
     */
    public function of(string $code): PromptKind
    {
        return $this->find($code) ?? throw new LogicException(self::notRegistered($code));
    }

    /** コードの種類の実装。登録が無ければ null */
    public function find(string $code): ?PromptKind
    {
        foreach ($this->kinds as $kind) {
            if ($kind->code() === $code) {
                return $kind;
            }
        }

        return null;
    }

    /** 登録の無い種類の文 */
    public static function notRegistered(string $code): string
    {
        return "AI の実行の種類 {$code} の実装が登録されていない(タグ ".PromptKind::TAG.')';
    }

    /**
     * 登録と設定の食い違い(起動の時に確かめるもの = 設計/AI/データと失敗と検査.md 8 節。文章・文字起こし・会話の 3 つの型をまたいで見る)。無ければ空。
     * 設定の値の形の誤りも投げずに文にし、ほかの確かめを続ける(起動を止めるのは ai:check-settings の終了コード)。
     *
     * @return list<string>
     */
    public function problems(): array
    {
        // 既定の結びでは口と代わりの版が同じ物なので、同じ確かめを 2 回走らせない
        $bound = $this->boundInstructions === $this->instructions ? [] : $this->boundInstructions->problems();

        return [
            ...DefaultDatabaseQueue::storeProblems(),
            ...DefaultDatabaseQueue::afterCommitProblems(),
            ...$this->conversation->problems(),
            ...array_values(array_unique([...$this->instructions->problems(), ...$bound])),
            ...$this->registrationProblems(),
            ...$this->agentTools->problems(),
            ...$this->limits->problems($this->kindSettings->all()),
        ];
    }

    /**
     * 登録した実装と設定の食い違い(同じコードの実装が 2 つ・設定に無いコード・設定の型が実装の型と違う)。
     * コードは 3 つの型をまたいで一意。
     *
     * @return list<string>
     */
    private function registrationProblems(): array
    {
        $registered = [];

        foreach ($this->kinds as $kind) {
            $registered[] = [$kind->code(), KindSettings::PROMPT];
        }

        foreach ($this->transcriptionKinds->codes() as $code) {
            $registered[] = [$code, KindSettings::TRANSCRIPTION];
        }

        foreach ($this->conversationKinds->codes() as $code) {
            $registered[] = [$code, KindSettings::CONVERSATION];
        }

        $problems = [];
        $seen = [];

        foreach ($registered as [$code, $type]) {
            $duplicate = isset($seen[$code]) ? ["種類 {$code} の実装が 2 つある"] : [];
            $problems = [...$problems, ...$duplicate, ...$this->settingProblem($code, $type)];
            $seen[$code] = true;
        }

        return $problems;
    }

    /**
     * 登録した実装のコードが設定に無いか、設定の型が実装の型と違うときの文。
     *
     * @return list<string>
     */
    private function settingProblem(string $code, string $type): array
    {
        return match (true) {
            ! in_array($code, $this->kindSettings->codes(), true) => ["種類 {$code} が設定(config/ai_runs.php の kinds)に無い"],
            ! in_array($code, $this->kindSettings->codes($type), true) => [
                "種類 {$code} の設定の type が実装の型 {$type} と違う(config/ai_runs.php の kinds)",
            ],
            default => [],
        };
    }
}
