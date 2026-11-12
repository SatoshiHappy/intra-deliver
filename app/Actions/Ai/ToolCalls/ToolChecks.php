<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\AgentRecordTool;
use App\Actions\Ai\Api\AgentTool;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\AiToolNature;

/**
 * 道具の登録の起動の時の確かめ(docs の 設計/AI/データと失敗と検査.md 8 節の会話の型で足すもの・設計/AI/道具と権限/README.md 10.1)。投げずに文にする。
 * 名前の頭・汎用か製品かは {@see AgentTools} の頭の対応で決める。
 */
final readonly class ToolChecks
{
    /** 名前と別名の形(どの接続先も受ける形 = 10.1 の名前) */
    private const NAME_PATTERN = '/^[a-z0-9_]{1,64}$/D';

    /** 大きい結果の定型(残りを読む道 = 設計/AI/設定とプロンプト/使う文と組み立て.md 11.5) */
    private const LARGE_RESULT_TEMPLATES = [
        AiInstructionTemplate::LargeResultSearch,
        AiInstructionTemplate::LargeResultRead,
        AiInstructionTemplate::LargeResultAggregate,
        AiInstructionTemplate::LargeResultTruncated,
    ];

    public function __construct(private AgentTools $tools) {}

    /**
     * 登録の食い違い(登録の順)。無ければ空。
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];

        foreach ($this->tools->registered() as $tool) {
            array_push(
                $problems,
                ...$this->namingProblems($tool),
                ...$this->shapeProblems($tool),
                ...self::argumentProblems($tool),
            );
        }

        return [...$problems, ...$this->duplicates()];
    }

    /**
     * 名前と別名の形と頭・画面の名前の食い違い。
     *
     * @return list<string>
     */
    private function namingProblems(AgentTool $tool): array
    {
        $label = self::label($tool);
        $prefix = $this->tools->prefixOf($tool);

        if ($prefix === null) {
            return ["{$label}のクラスが道具の名前空間に無い"];
        }

        $problems = [];

        foreach ([$tool->name(), ...$tool->aliases()] as $name) {
            $problems[] = match (true) {
                preg_match(self::NAME_PATTERN, $name) !== 1 => "{$label}の名前か別名 {$name} が英小文字・数字・_ の 64 文字までの形でない",
                ! str_starts_with($name, $prefix) => "{$label}の名前か別名 {$name} が登録したモジュールの頭 {$prefix} で始まらない",
                default => null,
            };
        }

        if (trim($tool->displayName()) === '') {
            $problems[] = "{$label}の画面に出す名前が空";
        }

        return array_values(array_filter($problems, is_string(...)));
    }

    /**
     * 性質・業務モジュール・結果の長さの食い違い。
     *
     * @return list<string>
     */
    private function shapeProblems(AgentTool $tool): array
    {
        $label = self::label($tool);
        $nature = AiToolNature::of($tool->isReadOnly(), $tool->requiredAction());
        $readsOrWrites = $nature !== null && $nature !== AiToolNature::CreateFile;
        $targets = $tool->targetModules();

        $problems = [
            $nature === null || $nature === AiToolNature::CreateFile ? $this->natureProblem($tool) : null,
            $readsOrWrites && ! $tool instanceof AgentRecordTool
                ? "{$label}は業務モジュールを読み書きするが、呼び出しが指す業務モジュールを答えない(AgentRecordTool でない)" : null,
            ! $this->tools->isGeneric($tool) && ($targets === null || $targets === [])
                ? "{$label}は製品の道具だが、対象の業務モジュールを持たない" : null,
            ...self::resultProblems($tool),
        ];

        return array_values(array_filter($problems, is_string(...)));
    }

    /**
     * 結果の長さの上限と大きい結果の定型の食い違い。上限の下限は上限を超えたときに返す先頭の長さ(先頭は上限によらず同じ長さなので、
     * 下回る上限では縮めた本文が上限を超える。Claude Code も先頭の長さを上限によらず同じにする = 10.7)。
     *
     * @return list<string|null>
     */
    private static function resultProblems(AgentTool $tool): array
    {
        $label = self::label($tool);
        $limit = $tool->maxResultLength();
        $template = $tool->largeResultTemplate();

        return [
            $limit !== null && $limit < ToolResultText::PREVIEW_LENGTH
                ? "{$label}の結果の長さの上限 {$limit} が先頭の長さ ".ToolResultText::PREVIEW_LENGTH.' 未満' : null,
            ! in_array($template, self::LARGE_RESULT_TEMPLATES, true)
                ? "{$label}の大きい結果の定型 {$template->value} が大きい結果の定型でない" : null,
        ];
    }

    /**
     * 包みが照らせない引数の形(照らせない形の値を、形の誤りでなく道具の例外で知ることにならないように、起動の時に断る)。
     *
     * @return list<string>
     */
    private static function argumentProblems(AgentTool $tool): array
    {
        return array_map(
            fn (array $found): string => self::label($tool)."の引数の形 {$found[0]} が照らせない形({$found[1]})",
            ToolArgumentSchema::unreadable(ToolArgumentSchema::of($tool)),
        );
    }

    /** 性質の起きない組(ファイルを作る道具は、汎用の道具にまだ無いので起きない組に数える) */
    private function natureProblem(AgentTool $tool): string
    {
        $readOnly = $tool->isReadOnly() ? '真' : '偽';
        $action = $tool->requiredAction()->value ?? '無し';

        return self::label($tool)."の読むだけか({$readOnly})と要る権限({$action})の組は起きない";
    }

    /**
     * 名前と別名の重なり(道具の中と道具どうし。2 回目からを 1 つずつ)。
     *
     * @return list<string>
     */
    private function duplicates(): array
    {
        $seen = [];
        $problems = [];

        foreach ($this->tools->registered() as $tool) {
            foreach ([$tool->name(), ...$tool->aliases()] as $name) {
                if (isset($seen[$name])) {
                    $problems[] = "道具の名前か別名 {$name} が 2 つ以上ある({$seen[$name]}・".$tool::class.')';
                }

                $seen[$name] ??= $tool::class;
            }
        }

        return $problems;
    }

    private static function label(AgentTool $tool): string
    {
        return '道具 '.$tool->name().'('.$tool::class.')';
    }
}
