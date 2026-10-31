<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowDecisionRule;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowTargetKind;
use LogicException;

/**
 * ノードの設定から作る文の 1 か所(docs の 設計/ワークフロー/ルートの深さ/画面/図と意匠とE2E.md 9.3 節): 要約の 1 文(summary)・その可変部・
 * 図の短い文(subtitle)・縦配置の完了の条件・配置の仮の札。要約と短い文は同じ {@see StepTarget} の名前から作る。値だけを扱い、DB を読まない。
 * 分岐終了・同期の要約と、横配置・縦配置の「{n} 人」は 9.3 節の表に無い行で、表の文の形に揃えた。
 */
final class StepDescriptions
{
    private const BASES = ['applicant' => '申請者', 'previous' => '前の承認者'];

    private const SCOPES = ['own' => '所属組織', 'parent' => '上位組織', 'ancestors' => '上位組織全て'];

    /**
     * 要約の 1 文。
     *
     * @param  string  $name  ノードの名前(分岐開始の文に使う)
     * @param  StepTarget|null  $target  処理対象者の指定(分岐・同期は null)
     */
    public static function summary(
        WorkflowNodeKind $kind,
        string $name,
        ?StepTarget $target,
        ?WorkflowDecisionRule $rule = null,
        ?int $value = null,
    ): string {
        $phrase = fn () => self::targetPhrase($target ?? throw new LogicException("ステップ {$name} に処理対象者の指定が無い"));

        return match ($kind) {
            WorkflowNodeKind::Apply => $phrase().'が申請できます。',
            WorkflowNodeKind::Approve => $phrase().'が承認します。',
            WorkflowNodeKind::Serial => $phrase().'が、順に承認します。',
            WorkflowNodeKind::Parallel => $phrase().'のうち、'.self::decisionPhrase($rule, $value).'が承認すると次へ進みます。',
            WorkflowNodeKind::BranchStart => "分岐「{$name}」の条件に合う先へ、すべて進みます。",
            WorkflowNodeKind::BranchEnd => '分岐の枝がここで合流します。',
            WorkflowNodeKind::SyncStart => '後ろのステップへ並行して進みます。',
            WorkflowNodeKind::SyncEnd => '並行したステップがすべて終わると次へ進みます。',
        };
    }

    /**
     * 要約の「が承認します。」「が申請できます。」の前の部分(可変部。申請操作の面の再展開の「?」も使う)。
     */
    public static function targetPhrase(StepTarget $target): string
    {
        return match ($target->kind) {
            WorkflowTargetKind::Fixed => self::names($target),
            WorkflowTargetKind::Relative => self::relativeScope($target).'の'.($target->companyPostName ?? '所属の人'),
            WorkflowTargetKind::Designated => "{$target->designatorName}が選択した ".self::count($target),
        };
    }

    /**
     * 図の短い文(分岐・同期は null)。
     */
    public static function subtitle(WorkflowNodeKind $kind, ?StepTarget $target): ?string
    {
        if ($target === null) {
            return null;
        }

        return match ($target->kind) {
            WorkflowTargetKind::Fixed => self::names($target),
            WorkflowTargetKind::Relative => self::relativeScope($target)
                .($target->companyPostName === null ? '' : " + {$target->companyPostName}"),
            WorkflowTargetKind::Designated => self::designatorSubtitle($kind, $target->designatorName),
        };
    }

    /**
     * 処理の時に指定するノードの図の短い文(承認・横配置 =「{指定するステップ}が指定」、縦配置 =「処理担当者は{指定するステップ}が指定」)。
     * 指定するノードの名前が null なら null(案件の図は処理の時に指定するノードの文だけを出す = 図と意匠とE2E.md 8 節)。
     */
    public static function designatorSubtitle(WorkflowNodeKind $kind, ?string $designatorName): ?string
    {
        if ($designatorName === null) {
            return null;
        }

        return $kind === WorkflowNodeKind::Parallel ? "処理担当者は{$designatorName}が指定" : "{$designatorName}が指定";
    }

    /**
     * 縦配置の定義の完了の条件(案件の図で進み具合が無いときと、定義の図が同じ文を使う)。
     */
    public static function consensus(?WorkflowDecisionRule $rule, ?int $value): string
    {
        return match ($rule) {
            WorkflowDecisionRule::Count => "完了の条件 承認 {$value} 人",
            WorkflowDecisionRule::Ratio => "完了の条件 承認 {$value}% 以上",
            default => '完了の条件 全員が承認',
        };
    }

    /**
     * 定義の図の縦配置の完了の条件(人数の範囲を続ける)。
     */
    public static function definitionConsensus(?WorkflowDecisionRule $rule, ?int $value, int $min, int $max): string
    {
        return self::consensus($rule, $value)."　人数 {$min}〜{$max} 人";
    }

    /**
     * 定義の図の配置の仮の札: 最小までは 1 つずつ(処理の時に指定)、残りを 1 つにまとめて任意。
     *
     * @return list<array{label: string, optional: bool}>
     */
    public static function placeholders(int $min, int $max): array
    {
        $labels = array_map(
            fn (int $number) => ['label' => "承認 {$number}", 'optional' => false],
            $min < 1 ? [] : range(1, $min),
        );

        if ($max <= $min) {
            return $labels;
        }

        $first = $min + 1;
        $label = $first === $max ? "承認 {$max}" : "承認 {$first} から {$max}";

        return [...$labels, ['label' => $label, 'optional' => true]];
    }

    private static function names(StepTarget $target): string
    {
        return implode('、', array_map(fn (array $row) => $row['name'], $target->rows));
    }

    private static function relativeScope(StepTarget $target): string
    {
        $base = $target->base ?? throw new LogicException('基準にする人で指定に起点が無い');
        $scope = $target->scope ?? throw new LogicException('基準にする人で指定に組織の範囲が無い');

        return self::BASES[$base->value].'の'.self::SCOPES[$scope->value];
    }

    private static function count(StepTarget $target): string
    {
        return $target->min === $target->max ? "{$target->min} 人" : "{$target->min} 人から {$target->max} 人";
    }

    private static function decisionPhrase(?WorkflowDecisionRule $rule, ?int $value): string
    {
        return match ($rule) {
            WorkflowDecisionRule::Count => "{$value} 人",
            WorkflowDecisionRule::Ratio => "{$value}% 以上",
            default => '全員',
        };
    }
}
