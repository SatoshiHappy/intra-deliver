<?php

namespace App\Http\Controllers\Web\Workflow;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * 申請の詳細で始めた処理・申請操作・確認・再申請の後に、操作を始めたときのタブを開く(docs の
 * 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md の 3 節の表)。元の画面は要求の Referer で読む(無ければ既定のタブ)。
 */
trait RedirectsToCaseShow
{
    /** タブのキーの形(タブの値は画面が持つので、形だけを見る。今の画面に無いキーは画面が既定にする) */
    private const CASE_TAB_PATTERN = '/^[a-z]{1,30}$/D';

    private function redirectToCaseShow(Request $request, int $caseId): RedirectResponse
    {
        $tab = self::caseTabOf((string) $request->headers->get('referer'), $caseId);
        $parameters = $tab === null ? ['case' => $caseId] : ['case' => $caseId, 'tab' => $tab];

        return redirect()->route('workflow.cases.show', $parameters);
    }

    /**
     * 元の画面が同じ申請の詳細かその再申請の画面のときの、クエリ tab(ほかの画面のクエリ tab は申請の詳細のタブでない)。
     */
    private static function caseTabOf(string $previous, int $caseId): ?string
    {
        $screens = [
            parse_url(route('workflow.cases.show', ['case' => $caseId]), PHP_URL_PATH),
            parse_url(route('workflow.reapplications.create', ['case' => $caseId]), PHP_URL_PATH),
        ];

        if (! in_array(parse_url($previous, PHP_URL_PATH), $screens, true)) {
            return null;
        }

        parse_str((string) parse_url($previous, PHP_URL_QUERY), $query);
        $tab = $query['tab'] ?? null;

        return is_string($tab) && preg_match(self::CASE_TAB_PATTERN, $tab) === 1 ? $tab : null;
    }
}
