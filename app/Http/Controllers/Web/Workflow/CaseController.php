<?php

namespace App\Http\Controllers\Web\Workflow;

use App\Http\Controllers\Controller;
use App\Queries\Workflow\CaseListQuery;
use App\Queries\Workflow\CaseShowProps;
use App\Queries\Workflow\CaseShowSelection;
use App\Support\AccessContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Inertia\Support\Header;

/**
 * 案件一覧 Workflow/CaseIndex(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 2 節。クエリ tab・scope・page・q は読み取りが解釈し、
 * 解釈できない値は既定にする)と、案件の詳細 Workflow/CaseShow(設計/ワークフロー/画面/申請と案件.md 5 節。閲覧できない・一時保存の案件は 404。
 * props の層と伏せ方は {@see CaseShowProps})。
 */
class CaseController extends Controller
{
    private const SHOW_COMPONENT = 'Workflow/CaseShow';

    public function __construct(
        private CaseListQuery $list,
        private CaseShowProps $show,
    ) {}

    public function index(Request $request): Response
    {
        [$tab, $scope, $page, $q] = array_map(fn (string $key) => $request->query($key), ['tab', 'scope', 'page', 'q']);
        $list = $this->list->list(AccessContext::of($request), $tab, $scope, $page, $q);

        return Inertia::render('Workflow/CaseIndex', $list);
    }

    /**
     * 求められた props だけを組んで返す(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md の 2.2 節、
     * 設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の 6 節)。
     */
    public function show(Request $request, string $case): Response
    {
        $props = $this->show->of(AccessContext::of($request), (int) $case, self::showSelectionOf($request));
        $props['recordAccess'] = Inertia::always($props['recordAccess']);

        if (array_key_exists('panelContent', $props)) {
            $panelContent = $props['panelContent'];
            $props['panelContent'] = Inertia::merge(fn () => $panelContent)->append('rows', 'id');
        }

        if (array_key_exists('relatedRecordForm', $props)) {
            $form = $props['relatedRecordForm'];
            $props['relatedRecordForm'] = Inertia::optional(fn () => $form);
        }

        return Inertia::render(self::SHOW_COMPONENT, $props);
    }

    /**
     * 申請の詳細の要求(部分の再読み込みの見出しは inertia-laravel の PropsResolver と同じ読み方)。
     */
    private static function showSelectionOf(Request $request): CaseShowSelection
    {
        $listOf = function (string $header) use ($request): ?array {
            $names = array_values(array_filter(explode(',', (string) $request->header($header, ''))));

            return $names === [] ? null : $names;
        };

        return new CaseShowSelection(
            partial: $request->header(Header::PARTIAL_COMPONENT) === self::SHOW_COMPONENT,
            only: $listOf(Header::PARTIAL_ONLY),
            except: $listOf(Header::PARTIAL_EXCEPT),
            panel: $request->query('panel'),
            before: $request->query('before'),
            tab: $request->query('tab'),
            related: $request->query('related'),
            relatedPage: $request->query('relatedPage'),
        );
    }
}
