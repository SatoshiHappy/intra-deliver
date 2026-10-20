<?php

namespace App\Http\Controllers\Web\Records;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\UpdateRecord;
use App\Actions\Records\DeleteRecord;
use App\Enums\Records\RecordModuleAction;
use App\Enums\Records\RecordWriteDestination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Records\RecordInputRequest;
use App\Http\Requests\Records\RecordUpdateRequest;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\RecordListQuery;
use App\Queries\Records\RecordPageQuery;
use App\Queries\Records\RecordQuery;
use App\Support\AccessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 業務の画面(docs の 設計/業務データの入れ物/画面/README.md 1.1・設計/業務データの入れ物/画面/業務の画面.md 2 節): 一覧 Records/Index・登録 Records/Create・詳細 Records/Show・更新 Records/Edit と、
 * 登録・更新・削除。ルートの認可(全員)の後に、業務モジュールのアクションを判定する(判定の順 = 認可とメニュー/README.md 2.2)。画面は {@see RecordPageQuery::open()} で 1 回開き、
 * 一覧・詳細・定義・can をそこから作る。
 * 定義 module と can はクロージャで渡し、一覧の検索・並び替え・ページの部分再読み込み(records・query)では作らない。
 * 書き込みの成功の戻り方は 画面/README.md 1.1(移り先は {@see self::redirectAfterWrite()})、検証の失敗の戻り方は 項目と規則/規則と業務処理/失敗と定義の変更.md 10 節。
 * 持ち主の鍵は HTTP から来ない(更新はいつも鍵 null を渡し、FormRequest は holder を受けない = 設計/ワークフロー/業務データとの接続/README.md 4 節)。
 * 更新は編集の画面を開いたときの版を業務処理に渡す(項目と規則/規則と業務処理/README.md 9.2 の 2)。
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
        ]);
    }

    public function create(Request $request, string $module): Response
    {
        $context = AccessContext::of($request);
        $requester = Requester::fromAccessContext($context);
        $opened = $this->pages->open($requester, $module, $context->date, RecordModuleAction::Create);

        return Inertia::render('Records/Create', [
            'module' => fn () => $this->pages->definition($opened),
            'can' => fn () => $this->pages->can($requester, $opened, [RecordModuleAction::Read], $context->date),
        ]);
    }

    public function store(RecordInputRequest $request, string $module): RedirectResponse
    {
        $recordId = $this->createRecord->execute(AccessContext::of($request), $module, $request->recordInput());

        return $this->redirectAfterWrite($request, $module, $recordId, '登録しました');
    }

    public function show(Request $request, string $module, string $record): Response
    {
        $context = AccessContext::of($request);
        $requester = Requester::fromAccessContext($context);
        $opened = $this->pages->open($requester, $module, $context->date, RecordModuleAction::Read);
        $this->pages->ensureRowAccess($requester, $opened, (int) $record, $context->date);
        $shown = $this->records->show($requester, $opened, (int) $record, $context->date, $context->localeCode);
        // 持ち主は 1 描画で 1 回だけ読み、hold と can の両方に使う
        $hold = $this->pages->hold($shown['id']);

        return Inertia::render('Records/Show', [
            'module' => fn () => $this->pages->definition($opened),
            'record' => $shown,
            'can' => fn () => $this->pages->can($requester, $opened, [RecordModuleAction::Update, RecordModuleAction::Delete], $context->date, held: $hold !== null),
            'hold' => $hold,
        ]);
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
            'record' => $editable,
            'hold' => fn () => $this->pages->hold($editable['id']),
        ]);
    }

    public function update(RecordUpdateRequest $request, string $module, string $record): RedirectResponse
    {
        $this->updateRecord->execute(AccessContext::of($request), $module, (int) $record, $request->recordInput(), $request->recordVersion(), holder: null);

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
}
