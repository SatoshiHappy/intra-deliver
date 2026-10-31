<?php

namespace App\Http\Controllers\Web\Records;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\UpdateRecord;
use App\Actions\Records\DeleteRecord;
use App\Enums\Records\RecordModuleAction;
use App\Enums\Records\RecordWriteDestination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Records\RecordStoreRequest;
use App\Http\Requests\Records\RecordUpdateRequest;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordShowPanelQuery;
use App\Queries\Records\RecordListQuery;
use App\Queries\Records\RecordPageQuery;
use App\Queries\Records\RecordQuery;
use App\Queries\Records\RecordRelatedProps;
use App\Queries\Records\RecordRowAccessValues;
use App\Queries\Records\RecordScreenAddonCatalog;
use App\Support\AccessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Inertia\Support\Header;

/**
 * 業務の画面(docs の 設計/業務データの入れ物/画面/README.md 1.1・設計/業務データの入れ物/画面/業務の画面.md 2 節): 一覧 Records/Index・登録 Records/Create・詳細 Records/Show・更新 Records/Edit と、
 * 登録・更新・削除。ルートの認可(全員)の後に、業務モジュールのアクションを判定する(判定の順 = 認可とメニュー/README.md 2.2)。画面は {@see RecordPageQuery::open()} で 1 回開き、
 * 一覧・詳細・定義・can をそこから作る。
 * 定義 module と can はクロージャで渡し、一覧の検索・並び替え・ページの部分再読み込み(records・query・addonBadges)では作らない。
 * 後ろのモジュールが足す操作・札・注(業務の画面.md 2.5)も、判定の後に求められたときだけ作るためクロージャで渡す。対応表の形の値は、
 * 空でも JSON のオブジェクトで届くよう (object) にする(画面は自分のキーだけを引く)。
 * 書き込みの成功の戻り方は 画面/README.md 1.1(移り先は {@see self::redirectAfterWrite()})、検証の失敗の戻り方は 項目と規則/規則と業務処理/失敗と定義の変更.md 10 節。
 * 持ち主の鍵は HTTP から来ない(更新はいつも鍵 null を渡し、FormRequest は holder を受けない = 設計/ワークフロー/業務データとの接続/申請と書き込み.md 4 節)。
 * 更新は編集の画面を開いたときの版を業務処理に渡す(項目と規則/規則と業務処理/README.md 9.2 の 2)。
 * 登録の画面のクエリ copy = 複製(レコードの共通機能/複製.md の 1 節)、登録の入力 related = 関連レコードのダイアログの新規(関連レコード.md の 4.2 節)。
 */
class RecordController extends Controller
{
    public function __construct(
        private RecordListQuery $list,
        private RecordQuery $records,
        private RecordPageQuery $pages,
        private CreateRecord $createRecord,
        private UpdateRecord $updateRecord,
        private DeleteRecord $deleteRecord,
        private RecordShowPanelQuery $panels,
        private RecordScreenAddonCatalog $addons,
        private RecordRelatedProps $related,
        private RecordRowAccessValues $rowAccess,
    ) {}

    public function index(Request $request, string $module): Response
    {
        $context = AccessContext::of($request);
        $requester = Requester::fromAccessContext($context);
        $opened = $this->pages->open($requester, $module, $context->date, RecordModuleAction::Read);
        $listed = $this->list->list($requester, $opened, $request->query('filter'), $request->query('sort'), $request->query('page'), $context->date, $context->localeCode);

        return Inertia::render('Records/Index', [
            'module' => fn () => $this->pages->definition($opened),
            'records' => $listed['records'],
            'query' => $listed['query'],
            'can' => fn () => $this->pages->can($requester, $opened, [RecordModuleAction::Create], $context->date),
            'addons' => fn () => self::withObjectProps($this->addons->index($context, $module)),
            // 行と同じ読み取りの結果(行の判定で絞った後)から id を取り、行の判定を二重にしない(設計/業務データの入れ物/画面/業務の画面.md の 2.5)
            'addonBadges' => fn () => (object) $this->addons
                ->badges($context, $module, array_column($listed['records']['data'], 'id')),
        ]);
    }

    /**
     * 複製(クエリ copy)は元の値を返すので create と read の両方を求め、その後に copy の形と元のレコードの有無・行の判定を見る
     * (複製.md の 1 節・4 節。read が無ければ copy の値によらず 403)。
     */
    public function create(Request $request, string $module): Response
    {
        $context = AccessContext::of($request);
        $requester = Requester::fromAccessContext($context);
        $copy = $request->query('copy');
        $copyActions = $copy === null ? [] : [RecordModuleAction::Read];
        $opened = $this->pages->open($requester, $module, $context->date, RecordModuleAction::Create, ...$copyActions);
        $copyId = self::copyId($copy);

        if ($copyId !== null) {
            $this->pages->ensureRowAccess($requester, $opened, $copyId, $context->date);
        }

        return Inertia::render('Records/Create', [
            'module' => fn () => $this->pages->definition($opened),
            'headings' => fn () => $this->panels->headings($opened->id),
            'can' => fn () => $this->pages->can($requester, $opened, [RecordModuleAction::Read], $context->date),
            'rowAccess' => fn () => $this->rowAccess->initial($opened),
            'copySource' => fn () => $copyId === null
                ? null
                : $this->records->copySource($requester, $opened, $copyId, $context->date, $context->localeCode),
        ]);
    }

    /**
     * 関連レコードのダイアログの新規(related あり)は、成功の応答を元の画面へ戻す(back() の 303。トーストなし = 関連レコード.md の 4.2 節、
     * 戻り先と安全の前提 = 詳細の画面/README.md の 3 節)。
     */
    public function store(RecordStoreRequest $request, string $module): RedirectResponse
    {
        $input = $request->recordInputWithRowAccess();
        $recordId = $this->createRecord->execute(AccessContext::of($request), $module, $input);

        if ($request->isRelated()) {
            return redirect()->back(303);
        }

        return $this->redirectAfterWrite($request, $module, $recordId, '登録しました');
    }

    /**
     * サイドパネルの props(詳細の画面/README.md の 2.2 節): どれも遅延の値で、部分の再読み込みでは求められたものだけを作る。panel と sections は
     * 1 回の {@see RecordShowPanelQuery::panel()} を分ける。panelContent は rows を id で突き合わせて後ろに足す(部分の再読み込みのときだけ)。
     */
    public function show(Request $request, string $module, string $record): Response
    {
        $context = AccessContext::of($request);
        $requester = Requester::fromAccessContext($context);
        $opened = $this->pages->open($requester, $module, $context->date, RecordModuleAction::Read);
        $recordId = (int) $record;
        $this->pages->ensureRowAccess($requester, $opened, $recordId, $context->date);
        $loaded = null;
        $entries = null;
        // can.apply と applyEntries は 1 つの応答で入口の口を 1 回だけ呼ぶ
        $applyEntries = function () use (&$entries, $requester, $recordId, $context): array {
            return $entries ??= $this->pages->applyEntries($requester, $recordId, $context->date);
        };
        // 1 つの応答で panel() を 1 回だけ呼び、panel と sections に分ける(null の結果も覚える)
        $panel = function () use (&$loaded, $context, $module, $recordId): ?array {
            $loaded ??= ['panel' => $this->panels->panel($context, $module, $recordId)];

            return $loaded['panel'];
        };

        return Inertia::render('Records/Show', [
            'module' => fn () => $this->pages->definition($opened),
            'headings' => fn () => $this->panels->headings($opened->id),
            'record' => fn () => $this->records
                ->detail($requester, $opened, $recordId, $context->date, $context->localeCode),
            'can' => fn () => $this->pages->detailCan($requester, $opened, $context->date, $applyEntries()),
            'applyEntries' => fn () => $applyEntries(),
            'hold' => fn () => $this->pages->hold($recordId),
            'panel' => fn () => $panel()['panel'] ?? null,
            'sections' => fn () => $panel()['sections'] ?? [],
            'panelContent' => Inertia::merge(fn () => $this->panelContent($request, $context, $module, $recordId))
                ->append('rows', 'id'),
            // 関連レコード(関連レコード.md の 7 節)。表は tab が related のとき、フォームは「新規」を開いた部分の再読み込みでだけ
            'relatedRecords' => fn () => $this->related->records($context, $recordId),
            'relatedRecordRows' => fn () => $this->related->rows($context, $recordId, $request->query('tab'),
                $request->query('related'), self::isPartial($request) ? $request->query('relatedPage') : null),
            'relatedRecordForm' => Inertia::optional(fn () => $this->related
                ->form($context, $recordId, $request->query('related'))),
            // 閲覧できない見る人に呼ばないため、ensureRowAccess の後のクロージャにする(業務の画面.md 2.5)
            'addons' => function () use ($context, $module, $recordId): array {
                $addons = self::withObjectProps($this->addons->show($context, $module, $recordId));

                return [...$addons, 'fieldNotes' => (object) $addons['fieldNotes']];
            },
        ]);
    }

    /**
     * 詳細の画面の部分の再読み込みか(before・relatedPage は部分の再読み込みのときだけ読む = 詳細の画面/README.md の 2.2 節・3 節)。
     */
    private static function isPartial(Request $request): bool
    {
        return $request->header(Header::PARTIAL_COMPONENT) === 'Records/Show';
    }

    /**
     * 複製の元のレコードの id(クエリ copy が無ければ null。整数の形でなければ 404。ルートの {record} と同じ形)。判定の後に呼ぶ。
     */
    private static function copyId(mixed $copy): ?int
    {
        if ($copy === null) {
            return null;
        }

        if (! is_string($copy) || preg_match('/^[0-9]{1,18}\z/', $copy) !== 1) {
            abort(404);
        }

        return (int) $copy;
    }

    /**
     * 後ろのモジュールの操作の props を、空でも JSON のオブジェクトで届くよう (object) にする(画面は自分のキーだけを引く)。
     *
     * @param  array{actions: list<array{props: array<string, mixed>}>}  $addons  一覧か詳細の操作・札・注
     * @return array<string, mixed>
     */
    private static function withObjectProps(array $addons): array
    {
        $addons['actions'] = array_map(
            fn (array $action) => [...$action, 'props' => (object) $action['props']],
            $addons['actions'],
        );

        return $addons;
    }

    /**
     * 編集の画面は今の値を返すので update と read の両方を求める(認可とメニュー/README.md 2.2)。どちらの 403 もレコードの有無と行の判定より先に返す。
     */
    public function edit(Request $request, string $module, string $record): Response
    {
        $context = AccessContext::of($request);
        $requester = Requester::fromAccessContext($context);
        $opened = $this->pages->open($requester, $module, $context->date, RecordModuleAction::Update, RecordModuleAction::Read);
        $this->pages->ensureRowAccess($requester, $opened, (int) $record, $context->date);
        $editable = $this->records->editable($requester, $opened, (int) $record, $context->date, $context->localeCode);

        return Inertia::render('Records/Edit', [
            'module' => fn () => $this->pages->definition($opened),
            'headings' => fn () => $this->panels->headings($opened->id),
            'record' => $editable,
            'hold' => fn () => $this->pages->hold($editable['id']),
        ]);
    }

    public function update(RecordUpdateRequest $request, string $module, string $record): RedirectResponse
    {
        $input = $request->recordInputWithRowAccess();
        $this->updateRecord->execute(AccessContext::of($request), $module, (int) $record, $input, $request->recordVersion(), holder: null);

        return $this->redirectAfterWrite($request, $module, (int) $record, '更新しました');
    }

    public function destroy(Request $request, string $module, string $record): RedirectResponse
    {
        $this->deleteRecord->execute(AccessContext::of($request), $module, (int) $record);

        return $this->redirectAfterWrite($request, $module, null, '削除しました');
    }

    /**
     * 書き込みの後のリダイレクトと成功のトースト。移り先は {@see RecordPageQuery::destinationAfterWrite()}(認可とメニュー/README.md 2.2)が決め、
     * ここは移り先のルートへの写像と通知だけ。トーストは移り先が決まってから出す(どの移り先でも同じ)。ルートの引数は業務モジュールのコード。
     */
    private function redirectAfterWrite(Request $request, string $moduleCode, ?int $recordId, string $message): RedirectResponse
    {
        $context = AccessContext::of($request);
        $destination = $this->pages->destinationAfterWrite(Requester::fromAccessContext($context), $moduleCode, $recordId, $context->date);
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return match ($destination) {
            RecordWriteDestination::Show => redirect()->route('records.data.show', ['module' => $moduleCode, 'record' => $recordId], 303),
            RecordWriteDestination::Index => redirect()->route('records.data.index', ['module' => $moduleCode], 303),
            RecordWriteDestination::Home => redirect()->route('menu.home', [], 303),
        };
    }

    /**
     * 開いた欄の中身(クエリ panel の値は口がキーの一覧で照らす)。before は部分の再読み込みのときだけ読む(全体の応答は新しいものから)。
     * 開く URL はここで足す(ルートの名前は入口が持つ = 詳細の画面/README.md の 7 節)。
     *
     * @return array<string, mixed>|null
     */
    private function panelContent(Request $request, AccessContext $context, string $module, int $recordId): ?array
    {
        $key = $request->query('panel');
        $before = $request->query('before');
        $beforeId = self::isPartial($request) && is_string($before)
            && preg_match('/^[1-9][0-9]{0,17}\z/', $before) === 1 ? (int) $before : null;
        $content = is_string($key) ? $this->panels->content($context, $module, $recordId, $key, $beforeId) : null;
        $route = ['module' => $module, 'record' => $recordId];

        return match ($content['kind'] ?? null) {
            'attachments' => [...$content, 'rows' => array_map(
                fn (array $row) => $this->withAttachmentLinks($row, $route),
                $content['rows'],
            )],
            'revisions' => [...$content, 'rows' => array_map(
                fn (array $row) => $this->withRevisionFileLinks($row, $route),
                $content['rows'],
            )],
            default => $content,
        };
    }

    /**
     * 添付の行のダウンロード・プレビューの URL(期限で実体を消した行は開けない = 添付ファイル/レコードの添付.md の 9 節)。
     *
     * @param  array{id: int, previewable: bool, purgeDueOn: string|null}  $row
     * @param  array{module: string, record: int}  $route
     * @return array<string, mixed>
     */
    private function withAttachmentLinks(array $row, array $route): array
    {
        $parameters = [...$route, 'attachment' => $row['id']];

        return [
            ...$row,
            'downloadUrl' => $row['purgeDueOn'] === null ? route('records.data.attachments.show', $parameters) : null,
            'previewUrl' => $row['previewable'] ? route('records.data.attachments.preview', $parameters) : null,
        ];
    }

    /**
     * 更新履歴の添付の削除の行から開く、外した時点のファイルの URL(開けない行は uploadId が null = 更新履歴.md の 5.2 節)。
     *
     * @param  array{changes: array{attachments: list<array{uploadId: int|null, previewable: bool}>}|null}  $row
     * @param  array{module: string, record: int}  $route
     * @return array<string, mixed>
     */
    private function withRevisionFileLinks(array $row, array $route): array
    {
        if ($row['changes'] === null) {
            return $row;
        }

        $row['changes']['attachments'] = array_map(function (array $change) use ($route) {
            $parameters = [...$route, 'upload' => $change['uploadId']];
            $opens = $change['uploadId'] !== null;

            return [
                ...$change,
                'downloadUrl' => $opens ? route('records.data.revision-files.show', $parameters) : null,
                'previewUrl' => $opens && $change['previewable']
                    ? route('records.data.revision-files.preview', $parameters)
                    : null,
            ];
        }, $row['changes']['attachments']);

        return $row;
    }
}
