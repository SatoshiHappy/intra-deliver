<?php

namespace App\Queries\Workflow;

use App\Models\Workflow\WorkflowCase;
use App\Support\InputText;
use Illuminate\Database\Eloquent\Builder;

/**
 * 案件の検索の語 q の 1 か所(docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 2.1 節)。
 */
final class CaseKeywords
{
    /** 語の最大の文字数 */
    public const MAX_LENGTH = 100;

    /**
     * クエリの q の語(文字列でない・UTF-8 として読めない・{@see self::MAX_LENGTH} 文字を超える値は空
     * (解釈できない値は指定なしと同じ = docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 2 節)。
     */
    public static function of(mixed $q): string
    {
        $keyword = InputText::normalize(is_string($q) ? $q : null);

        return is_string($keyword) && mb_check_encoding($keyword, 'UTF-8') && mb_strlen($keyword) <= self::MAX_LENGTH
            ? $keyword
            : '';
    }

    /**
     * 語で絞る(空の語は絞らない)。
     *
     * @param  Builder<WorkflowCase>  $query
     * @param  bool  $isDraft  一時保存のタブか(案件名だけに掛け、案件名が無い行に出すフロー名には掛けない)
     */
    public static function apply(Builder $query, string $keyword, bool $isDraft): void
    {
        if ($keyword === '') {
            return;
        }

        $pattern = '%'.addcslashes($keyword, '\\%_').'%';

        if ($isDraft) {
            $query->whereRaw("(workflow_cases.draft_input ->> 'name') ILIKE ?", [$pattern]);

            return;
        }

        // 表示の形と同じく 6 桁に満たない番号だけを 0 で埋める(lpad は長い値を切るので、桁数を下限 6 にして渡す)
        $digits = 'workflow_cases.case_number::text';
        $number = "'WF-' || lpad({$digits}, greatest(6, length({$digits})), '0')";
        $query->where(fn (Builder $match) => $match
            ->whereRaw("{$number} ILIKE ?", [$pattern])
            ->orWhere('workflow_cases.name', 'ILIKE', $pattern));
    }
}
