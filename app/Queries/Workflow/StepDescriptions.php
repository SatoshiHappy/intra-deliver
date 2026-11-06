<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowDecisionRule;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowTargetKind;
use LogicException;

/**
 * ノードの設定から作る文の 1 か所(docs の 設計/ワークフロー/ルートの深さ/画面/図と意匠とE2E.md 9.3 節): 要約の 1 文(区切りの並びの summaryParts)・その可変部・
 * 図の短い文(subtitle)・縦配置の完了の条件・配置の仮の札。要約と短い文は同じ {@see StepTarget} の名前から作る。値だけを扱い、DB を読まない。
 * 分岐終了・同期の要約と、横配置・縦配置の「{n} 人」は 9.3 節の表に無い行で、表の文の形に揃えた。
 * 要約の処理期限と確認する人の文は 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md 7.6 節(カードの並びの順に続ける)。
 *
 * @phpstan-type SummaryPart string|array{departmentId: int, text: string}
 * @phpstan-type SubtitlePart string|array{text: string, emphasis: true}
 */
final class StepDescriptions
{
    private const BASES = ['applicant' => '申請者', 'previous' => '前の承認者'];

    private const SCOPES = ['own' => '所属組織', 'parent' => '上位組織', 'ancestors' => '上位組織全て'];

    /**
     * 要約の 1 文を、具体の組織(対象者で指定の組織の行)の名前だけを {departmentId, text} にした並びで返す。画面が組織の名前に
     * 「…」(組織図で見る)を付けるため(設計/ワークフロー/申請の周辺/メニューと導線.md の 2.2)。つなぐと要約の 1 文になる。
     *
     * @param  string  $name  ノードの名前(分岐開始の文に使う)
     * @param  StepTarget|null  $target  処理対象者の指定(分岐・同期は null)
     * @param  bool  $preventsAttachmentAdd  真のときだけ「添付ファイルを追加できません。」を続ける(設計/ワークフロー/申請の周辺/画面.md の 4 節)
     * @param  int|null  $deadlineDays  処理期限の日数(期限を持てる種類だけ文を続ける = 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md 7.6)
     * @param  list<string>  $confirmerNames  確認する人の名前(確認する人を持てる種類だけ文を続ける = 同じ節)
     * @return list<SummaryPart>
     */
    public static function summaryParts(
        WorkflowNodeKind $kind,
        string $name,
        ?StepTarget $target,
        ?WorkflowDecisionRule $rule = null,
        ?int $value = null,
        bool $preventsAttachmentAdd = false,
        ?int $deadlineDays = null,
        array $confirmerNames = [],
    ): array {
        $phrase = fn () => self::targetParts($target ?? throw new LogicException("ステップ {$name} に処理対象者の指定が無い"));

        $sentence = match ($kind) {
            WorkflowNodeKind::Apply => [...$phrase(), 'が申請できます。'],
            WorkflowNodeKind::Approve => [...$phrase(), 'が承認します。'],
            WorkflowNodeKind::Serial => [...$phrase(), 'が、順に承認します。'],
            WorkflowNodeKind::Parallel => [...$phrase(), 'のうち、'.self::decisionPhrase($rule, $value).'が承認すると次へ進みます。'],
            WorkflowNodeKind::BranchStart => ["分岐「{$name}」の条件に該当する先へ、すべて進みます。"],
            WorkflowNodeKind::BranchEnd => ['分岐の枝がここで合流します。'],
            WorkflowNodeKind::SyncStart => ['後ろのステップへ並行して進みます。'],
            WorkflowNodeKind::SyncEnd => ['並行したステップがすべて終わると次へ進みます。'],
        };

        return self::merged([
            ...$sentence,
            ...($kind->isIn(WorkflowNodeKind::withDeadline()) ? [self::deadlineSentence($deadlineDays)] : []),
            ...($kind->isIn(WorkflowNodeKind::withConfirmers()) ? [self::confirmersSentence($confirmerNames)] : []),
            ...($preventsAttachmentAdd ? ['添付ファイルを追加できません。'] : []),
        ]);
    }

    /**
     * 要約の処理期限の文(期限なしも文にする)。
     */
    private static function deadlineSentence(?int $days): string
    {
        return $days === null ? '期限はありません。' : "到達した日から {$days} 日以内に処理します。";
    }

    /**
     * 要約の確認する人の文(名前を「、」でつなぐ。いなければ、いないことを文にする)。
     *
     * @param  list<string>  $names
     */
    private static function confirmersSentence(array $names): string
    {
        return $names === [] ? '確認する人はいません。' : implode('、', $names).'が確認します。';
    }

    /**
     * 要約の「が承認します。」「が申請できます。」の前の部分(可変部。申請操作の面の再展開の「?」も使う)。
     */
    public static function targetPhrase(StepTarget $target): string
    {
        return match ($target->kind) {
            WorkflowTargetKind::Fixed => self::names($target),
            WorkflowTargetKind::Relative => self::plainText(self::relativeParts($target)),
            WorkflowTargetKind::Designated => "{$target->designatorName}が選択した ".self::count($target),
        };
    }

    /**
     * 可変部の並び(対象者で指定は行ごと。組織の行だけ {departmentId, text}、ほかの行と区切りの「、」は文字列)。
     *
     * @return list<SummaryPart>
     */
    private static function targetParts(StepTarget $target): array
    {
        if ($target->kind !== WorkflowTargetKind::Fixed) {
            return [self::targetPhrase($target)];
        }

        $parts = [];

        foreach ($target->rows as $index => $row) {
            $parts[] = $index === 0 ? '' : '、';
            $isDepartment = $row['type'] === 'department';
            $parts[] = $isDepartment ? ['departmentId' => $row['id'], 'text' => $row['name']] : $row['name'];
        }

        return $parts;
    }

    /**
     * 続く文字列をひとつにまとめる(並びの形を短く保つ)。
     *
     * @param  list<SummaryPart>  $parts
     * @return list<SummaryPart>
     */
    private static function merged(array $parts): array
    {
        $merged = [];

        foreach ($parts as $part) {
            $last = count($merged) - 1;

            if (is_string($part) && $last >= 0 && is_string($merged[$last])) {
                $merged[$last] .= $part;
            } elseif ($part !== '') {
                $merged[] = $part;
            }
        }

        return $merged;
    }

    /**
     * 定義の図の短い文(区切りの並び。期限の日数があれば後ろに「　期限 {n} 日」。文が無ければ null = 図と意匠とE2E.md 9.3 節、
     * 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md 7.6 節)。基準にする人は要約の主語と同じ文で、起点と役職名(役職が無ければ
     * 「所属の人」)だけを強調の区切りにする。処理の時に指定は、承認の箱も横配置・縦配置の枠の右も同じ文。
     *
     * @return list<SubtitlePart>|null
     */
    public static function subtitle(?StepTarget $target, ?int $deadlineDays = null): ?array
    {
        if ($target === null) {
            return self::withDeadline([], $deadlineDays);
        }

        $parts = match ($target->kind) {
            WorkflowTargetKind::Fixed => [self::names($target)],
            WorkflowTargetKind::Relative => self::relativeParts($target),
            WorkflowTargetKind::Designated => [self::designatorPhrase((string) $target->designatorName)],
        };

        return self::withDeadline($parts, $deadlineDays);
    }

    /**
     * 案件の図の短い文: 処理の時に指定するノードの文(指定するノードの名前が null なら無し = 図と意匠とE2E.md 8 節)と、写した期限の日数。
     *
     * @return list<SubtitlePart>|null
     */
    public static function caseSubtitle(?string $designatorName, ?int $deadlineDays): ?array
    {
        $parts = $designatorName === null ? [] : [self::designatorPhrase($designatorName)];

        return self::withDeadline($parts, $deadlineDays);
    }

    /**
     * 確認の札(確認する人が 1 人以上のとき。2 人以上は 1 人目と残りの人数。並び = 渡した並び)。いなければ null。
     *
     * @param  list<string>  $names
     */
    public static function confirmBadge(array $names): ?string
    {
        return match (count($names)) {
            0 => null,
            1 => "確認：{$names[0]}",
            default => "確認：{$names[0]} ほか ".(count($names) - 1).' 人',
        };
    }

    /**
     * 処理の時に指定するノードの文(する人を「が」、選ぶ物を「を」で示す = 9.3 節。人を選ぶ操作は「選択」= 設計/画面の意匠/文言.md 11.2)。
     */
    private static function designatorPhrase(string $designatorName): string
    {
        return "{$designatorName}が承認者を選択";
    }

    /**
     * 基準にする人の短い文の区切り(つないだ文が要約の主語 {@see self::targetPhrase()})。
     *
     * @return list<SubtitlePart>
     */
    private static function relativeParts(StepTarget $target): array
    {
        $base = $target->base ?? throw new LogicException('基準にする人で指定に起点が無い');
        $scope = $target->scope ?? throw new LogicException('基準にする人で指定に組織の範囲が無い');

        return [
            ['text' => self::BASES[$base->value], 'emphasis' => true],
            'の'.self::SCOPES[$scope->value].'の',
            ['text' => $target->companyPostName ?? '所属の人', 'emphasis' => true],
        ];
    }

    /**
     * 短い文の後ろに期限を続ける(最後の区切りが文字列ならつなぐ。文が無ければ期限だけ。期限も無ければ null)。
     *
     * @param  list<SubtitlePart>  $parts
     * @return list<SubtitlePart>|null
     */
    private static function withDeadline(array $parts, ?int $deadlineDays): ?array
    {
        if ($deadlineDays === null) {
            return $parts === [] ? null : $parts;
        }

        $deadline = ($parts === [] ? '' : '　')."期限 {$deadlineDays} 日";
        $last = array_key_last($parts);

        if ($last !== null && is_string($parts[$last])) {
            $parts[$last] .= $deadline;

            return $parts;
        }

        return [...$parts, $deadline];
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
        $label = $first === $max ? "承認 {$max}" : "承認 {$first}〜{$max}";

        return [...$labels, ['label' => $label, 'optional' => true]];
    }

    private static function names(StepTarget $target): string
    {
        return implode('、', array_map(fn (array $row) => $row['name'], $target->rows));
    }

    /**
     * 区切りをつないだ文(強調を外す)。
     *
     * @param  list<SubtitlePart>  $parts
     */
    private static function plainText(array $parts): string
    {
        return implode('', array_map(fn (string|array $part) => is_string($part) ? $part : $part['text'], $parts));
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
