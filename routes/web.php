<?php

use App\Http\Controllers\Web\Account\CurrentDepartmentController;
use App\Http\Controllers\Web\Account\LogoutController;
use App\Http\Controllers\Web\Authz\AuthzCellController;
use App\Http\Controllers\Web\Authz\AuthzSubjectCandidateController;
use App\Http\Controllers\Web\Authz\AuthzSubjectController;
use App\Http\Controllers\Web\Authz\OverviewController;
use App\Http\Controllers\Web\Master\OrganizationChartController;
use App\Http\Controllers\Web\Master\SearchController;
use App\Http\Controllers\Web\Meeting\MeetingController;
use App\Http\Controllers\Web\Menu\HomeController;
use App\Http\Controllers\Web\Menu\SitemapController;
use App\Http\Controllers\Web\Notice\NoticeController;
use App\Http\Controllers\Web\Records\FieldController;
use App\Http\Controllers\Web\Records\ModuleCategoryController;
use App\Http\Controllers\Web\Records\ModuleController;
use App\Http\Controllers\Web\Records\RecordAttachmentController;
use App\Http\Controllers\Web\Records\RecordCandidatesController;
use App\Http\Controllers\Web\Records\RecordCommentAdminController;
use App\Http\Controllers\Web\Records\RecordCommentController;
use App\Http\Controllers\Web\Records\RecordController;
use App\Http\Controllers\Web\Records\RecordRevisionFileController;
use App\Http\Controllers\Web\Records\RuleController;
use App\Http\Controllers\Web\Workflow\ApplicationController;
use App\Http\Controllers\Web\Workflow\AssigneeCandidateController;
use App\Http\Controllers\Web\Workflow\CaseActionController;
use App\Http\Controllers\Web\Workflow\CaseController;
use App\Http\Controllers\Web\Workflow\DraftController;
use App\Http\Controllers\Web\Workflow\FlowController;
use App\Http\Controllers\Web\Workflow\ReapplicationController;
use Illuminate\Support\Facades\Route;

// ルートの名前 = <モジュール名の小文字>.<区切り>(.<区切り>…)、241 文字以内、ほかのルートの名前の接頭辞にしない、アプリのルートの中で一意。
// 先頭の区切りは処理が属するモジュール(コントローラは名前空間の <M>、Route::inertia はページ名の <M>)。ホストとスキームの条件を付けない。
// 名前の規則・一意・持ち主のモジュール・条件は検査が確かめる(tests/Feature/Routing/AppRoutesTest.php)。
// ルートの処理にクロージャを使わない(コントローラか Route::inertia・redirect・view。ルートのキャッシュの後は定義のファイルが分からない)。
// ルートを足したら、そのモジュールの seeder にリソースとポリシーを足す(docs の 設計/メニュー/認可との結び付け.md 2.3)。
Route::get('/', HomeController::class)->name('menu.home');
Route::get('/menu/sitemap', SitemapController::class)->name('menu.sitemap');
Route::post('/account/logout', LogoutController::class)->name('account.logout');
Route::post('/account/current-department', CurrentDepartmentController::class)->name('account.current-department');
Route::get('/authz/overview', OverviewController::class)->name('authz.overview');

// 認可設定の編集(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 6 節)。{subject} = 対象者の id(数字だけ)。
Route::put('/authz/editor/cells', [AuthzCellController::class, 'update'])->name('authz.editor.cells.update');
Route::post('/authz/editor/subjects', [AuthzSubjectController::class, 'store'])->name('authz.editor.subjects.store');
Route::delete('/authz/editor/subjects/{subject}', [AuthzSubjectController::class, 'destroy'])
    ->name('authz.editor.subjects.destroy')->where('subject', '[0-9]{1,18}');
Route::get('/authz/editor/subject-candidates', AuthzSubjectCandidateController::class)
    ->name('authz.editor.subject-candidates');

Route::get('/master/organization-chart', OrganizationChartController::class)->name('master.organization-chart');
Route::get('/master/search', SearchController::class)->name('master.search');

// 通知(docs の 設計/ワークフロー/案件の運用/通知.md の 7 節)。{notice} = 通知の id(数字だけ。read-all は {notice} に合わない)。
Route::get('/notices', [NoticeController::class, 'index'])->name('notice.index');
Route::get('/notices/latest', [NoticeController::class, 'latest'])->name('notice.latest');
Route::post('/notices/read-all', [NoticeController::class, 'readAll'])->name('notice.read-all');
Route::post('/notices/{notice}/read', [NoticeController::class, 'read'])
    ->name('notice.read')->where('notice', '[0-9]{1,18}');

// 会議の作成(テキスト・貼り付け)と書き起こしからの再作成(docs の 設計/業務ツール/会議/処理.md の 3.2・3.7)。{meeting} = 会議のレコードの id。
Route::post('/meetings', [MeetingController::class, 'store'])
    ->name('meeting.store');
Route::post('/meetings/{meeting}/regenerate', [MeetingController::class, 'regenerate'])
    ->name('meeting.regenerate')->where('meeting', '[0-9]{1,18}');

// 業務データの入れ物の業務の画面(docs の 設計/業務データの入れ物/画面/README.md 1.1)。{module} = 業務モジュールのコード、{record} = レコードの id(数字だけ)。
// 引数の形を限るので、/candidates・/create は {record} に合わない。{record} の桁は整数に収まる 18 桁まで(超えるとどのルートにも合わず 404)。
Route::get('/records/data/{module}', [RecordController::class, 'index'])->name('records.data.index')->where('module', '[a-z][a-z0-9_]{0,49}');
Route::get('/records/data/{module}/create', [RecordController::class, 'create'])->name('records.data.create')->where('module', '[a-z][a-z0-9_]{0,49}');
Route::post('/records/data/{module}', [RecordController::class, 'store'])->name('records.data.store')->where('module', '[a-z][a-z0-9_]{0,49}');
Route::get('/records/data/{module}/candidates', RecordCandidatesController::class)->name('records.data.candidates')->where('module', '[a-z][a-z0-9_]{0,49}');
Route::get('/records/data/{module}/{record}', [RecordController::class, 'show'])->name('records.data.show')->where(['module' => '[a-z][a-z0-9_]{0,49}', 'record' => '[0-9]{1,18}']);
Route::get('/records/data/{module}/{record}/edit', [RecordController::class, 'edit'])->name('records.data.edit')->where(['module' => '[a-z][a-z0-9_]{0,49}', 'record' => '[0-9]{1,18}']);
Route::put('/records/data/{module}/{record}', [RecordController::class, 'update'])->name('records.data.update')->where(['module' => '[a-z][a-z0-9_]{0,49}', 'record' => '[0-9]{1,18}']);
Route::delete('/records/data/{module}/{record}', [RecordController::class, 'destroy'])->name('records.data.destroy')->where(['module' => '[a-z][a-z0-9_]{0,49}', 'record' => '[0-9]{1,18}']);

// レコードの添付と外した時点のファイル(docs の 設計/業務データの入れ物/レコードの共通機能/認可と初期データ.md の 2.1 節)。
// {attachment} = record_attachments.id、{upload} = uploads.id、{comment} = record_comments.id
// (どれも {record} と同じ 18 桁までの数字)。
$recordFile = [
    'module' => '[a-z][a-z0-9_]{0,49}',
    'record' => '[0-9]{1,18}',
    'attachment' => '[0-9]{1,18}',
    'upload' => '[0-9]{1,18}',
    'comment' => '[0-9]{1,18}',
];
$recordPath = '/records/data/{module}/{record}';
Route::post("{$recordPath}/attachments", [RecordAttachmentController::class, 'store'])
    ->name('records.data.attachments.store')->where($recordFile);
Route::delete("{$recordPath}/attachments/{attachment}", [RecordAttachmentController::class, 'destroy'])
    ->name('records.data.attachments.destroy')->where($recordFile);
Route::get("{$recordPath}/attachments/{attachment}", [RecordAttachmentController::class, 'show'])
    ->name('records.data.attachments.show')->where($recordFile);
Route::get("{$recordPath}/attachments/{attachment}/preview", [RecordAttachmentController::class, 'preview'])
    ->name('records.data.attachments.preview')->where($recordFile);
// コメント(docs の 設計/業務データの入れ物/レコードの共通機能/コメント.md の 6 節)。candidates は {comment} と重ならない(GET と DELETE)。
Route::post("{$recordPath}/comments", [RecordCommentController::class, 'store'])
    ->name('records.data.comments.store')->where($recordFile);
Route::get("{$recordPath}/comments/candidates", [RecordCommentController::class, 'candidates'])
    ->name('records.data.comments.candidates')->where($recordFile);
Route::delete("{$recordPath}/comments/{comment}", [RecordCommentController::class, 'destroy'])
    ->name('records.data.comments.destroy')->where($recordFile);
Route::delete('/records/admin/comments/{comment}', [RecordCommentAdminController::class, 'destroy'])
    ->name('records.admin.comments.destroy')->where($recordFile);
Route::get("{$recordPath}/revision-files/{upload}", [RecordRevisionFileController::class, 'show'])
    ->name('records.data.revision-files.show')->where($recordFile);
Route::get("{$recordPath}/revision-files/{upload}/preview", [RecordRevisionFileController::class, 'preview'])
    ->name('records.data.revision-files.preview')->where($recordFile);

// 業務データの入れ物の管理の画面(docs の 設計/業務データの入れ物/画面/README.md 1.1・設計/業務データの入れ物/画面/管理の画面.md 3 節)。ルートの認可だけで判定し、ポリシーを置かない(システム管理者が
// http-services の先頭の許可を継承する = 認可とメニュー/README.md 4 節)。{module} = 業務モジュールのコード(明細のモジュールも)、{field} = 項目のコード、
// {rule} = 規則の id。/modules/create・/modules/reorder は {module} より先に、/fields/reorder は {field} より先に置く(create・reorder はコードの予約語なので、
// 同じコードの業務モジュール・項目は無い)。/modules/reorder は「業務」の直下の並び替え、/modules/{module}/categories は業務モジュールの分類の更新
// (分類の画面のドラッグ。どちらもモジュール分類の画面へ戻す = モジュール分類/画面と検査.md 4.3)。
Route::get('/records/admin/modules', [ModuleController::class, 'index'])->name('records.admin.modules.index');
Route::get('/records/admin/modules/create', [ModuleController::class, 'create'])->name('records.admin.modules.create');
Route::post('/records/admin/modules', [ModuleController::class, 'store'])->name('records.admin.modules.store');
Route::get('/records/admin/modules/{module}', [ModuleController::class, 'show'])->name('records.admin.modules.show')->where('module', '[a-z][a-z0-9_]{0,49}');
Route::get('/records/admin/modules/{module}/edit', [ModuleController::class, 'edit'])->name('records.admin.modules.edit')->where('module', '[a-z][a-z0-9_]{0,49}');
Route::put('/records/admin/modules/reorder', [ModuleController::class, 'reorder'])->name('records.admin.modules.reorder');
Route::put('/records/admin/modules/{module}', [ModuleController::class, 'update'])->name('records.admin.modules.update')->where('module', '[a-z][a-z0-9_]{0,49}');
Route::put('/records/admin/modules/{module}/categories', [ModuleController::class, 'updateCategories'])->name('records.admin.modules.categories')->where('module', '[a-z][a-z0-9_]{0,49}');
Route::get('/records/admin/modules/{module}/fields/create', [FieldController::class, 'create'])->name('records.admin.fields.create')->where('module', '[a-z][a-z0-9_]{0,49}');
Route::post('/records/admin/modules/{module}/fields', [FieldController::class, 'store'])->name('records.admin.fields.store')->where('module', '[a-z][a-z0-9_]{0,49}');
Route::put('/records/admin/modules/{module}/fields/reorder', [FieldController::class, 'reorder'])->name('records.admin.fields.reorder')->where('module', '[a-z][a-z0-9_]{0,49}');
Route::get('/records/admin/modules/{module}/fields/{field}/edit', [FieldController::class, 'edit'])->name('records.admin.fields.edit')->where(['module' => '[a-z][a-z0-9_]{0,49}', 'field' => '[a-z][a-z0-9_]{0,49}']);
Route::put('/records/admin/modules/{module}/fields/{field}', [FieldController::class, 'update'])->name('records.admin.fields.update')->where(['module' => '[a-z][a-z0-9_]{0,49}', 'field' => '[a-z][a-z0-9_]{0,49}']);
Route::delete('/records/admin/modules/{module}/fields/{field}', [FieldController::class, 'destroy'])->name('records.admin.fields.destroy')->where(['module' => '[a-z][a-z0-9_]{0,49}', 'field' => '[a-z][a-z0-9_]{0,49}']);
Route::post('/records/admin/modules/{module}/rules', [RuleController::class, 'store'])->name('records.admin.rules.store')->where('module', '[a-z][a-z0-9_]{0,49}');
Route::put('/records/admin/modules/{module}/rules/{rule}', [RuleController::class, 'update'])->name('records.admin.rules.update')->where(['module' => '[a-z][a-z0-9_]{0,49}', 'rule' => '[0-9]{1,18}']);
Route::delete('/records/admin/modules/{module}/rules/{rule}', [RuleController::class, 'destroy'])->name('records.admin.rules.destroy')->where(['module' => '[a-z][a-z0-9_]{0,49}', 'rule' => '[0-9]{1,18}']);

// モジュール分類の画面(docs の 設計/業務データの入れ物/モジュール分類/画面と検査.md 4.3)。ルートの認可だけで判定する(上の管理の画面と同じ)。
// {category} = 分類 ID(小文字の ULID の 26 文字)。/categories/reorder は {category} の形に合わないが、並びを揃えて {category} より先に置く。
Route::get('/records/admin/categories', [ModuleCategoryController::class, 'index'])->name('records.admin.categories.index');
Route::post('/records/admin/categories', [ModuleCategoryController::class, 'store'])->name('records.admin.categories.store');
Route::put('/records/admin/categories/reorder', [ModuleCategoryController::class, 'reorder'])->name('records.admin.categories.reorder');
Route::put('/records/admin/categories/{category}', [ModuleCategoryController::class, 'update'])->name('records.admin.categories.update')->where('category', '[0-9a-z]{26}');
Route::delete('/records/admin/categories/{category}', [ModuleCategoryController::class, 'destroy'])->name('records.admin.categories.destroy')->where('category', '[0-9a-z]{26}');
Route::put('/records/admin/categories/{category}/reorder', [ModuleCategoryController::class, 'reorderModules'])->name('records.admin.categories.reorder-modules')->where('category', '[0-9a-z]{26}');
Route::put('/records/admin/categories/{category}/modules', [ModuleCategoryController::class, 'updateModules'])->name('records.admin.categories.modules')->where('category', '[0-9a-z]{26}');

// ワークフローの画面(docs の 設計/ワークフロー/画面.md 1.1)。{flow} = フローのコード、{case} = 案件の id(数字だけ。一時保存も同じ形で指す)。
// 入口の判定の順(案件 404 → フロー 404 → 申請できる人・update 403 → 封筒の 422)は FormRequest の authorize と読み取りが持つ(案件の操作/操作.md 3.1 の 1)。
Route::get('/workflow/flows', [FlowController::class, 'index'])->name('workflow.flows.index');
Route::get('/workflow/flows/{flow}/apply', [ApplicationController::class, 'create'])->name('workflow.applications.create')->where('flow', '[a-z][a-z0-9_]{0,49}');
Route::post('/workflow/flows/{flow}/apply', [ApplicationController::class, 'store'])->name('workflow.applications.store')->where('flow', '[a-z][a-z0-9_]{0,49}');
Route::post('/workflow/flows/{flow}/drafts', [DraftController::class, 'store'])->name('workflow.drafts.store')->where('flow', '[a-z][a-z0-9_]{0,49}');
Route::get('/workflow/drafts/{case}/edit', [DraftController::class, 'edit'])->name('workflow.drafts.edit')->where('case', '[0-9]{1,18}');
Route::put('/workflow/drafts/{case}', [DraftController::class, 'update'])->name('workflow.drafts.update')->where('case', '[0-9]{1,18}');
Route::post('/workflow/drafts/{case}/apply', [DraftController::class, 'apply'])->name('workflow.drafts.apply')->where('case', '[0-9]{1,18}');
Route::delete('/workflow/drafts/{case}', [DraftController::class, 'destroy'])->name('workflow.drafts.destroy')->where('case', '[0-9]{1,18}');
Route::get('/workflow/cases', [CaseController::class, 'index'])->name('workflow.cases.index');
Route::get('/workflow/cases/{case}', [CaseController::class, 'show'])->name('workflow.cases.show')->where('case', '[0-9]{1,18}');
Route::post('/workflow/cases/{case}/approve', [CaseActionController::class, 'approve'])->name('workflow.cases.approve')->where('case', '[0-9]{1,18}');
Route::post('/workflow/cases/{case}/approve-early', [CaseActionController::class, 'approveEarly'])->name('workflow.cases.approve-early')->where('case', '[0-9]{1,18}');
Route::post('/workflow/cases/{case}/reject', [CaseActionController::class, 'reject'])->name('workflow.cases.reject')->where('case', '[0-9]{1,18}');
Route::post('/workflow/cases/{case}/send-back', [CaseActionController::class, 'sendBack'])->name('workflow.cases.send-back')->where('case', '[0-9]{1,18}');
Route::post('/workflow/cases/{case}/pull-back', [CaseActionController::class, 'pullBack'])->name('workflow.cases.pull-back')->where('case', '[0-9]{1,18}');
Route::get('/workflow/cases/{case}/assignee-candidates', AssigneeCandidateController::class)->name('workflow.cases.assignee-candidates')->where('case', '[0-9]{1,18}');
Route::post('/workflow/cases/{case}/withdraw', [CaseActionController::class, 'withdraw'])->name('workflow.cases.withdraw')->where('case', '[0-9]{1,18}');
Route::get('/workflow/cases/{case}/reapply', [ReapplicationController::class, 'create'])->name('workflow.reapplications.create')->where('case', '[0-9]{1,18}');
Route::post('/workflow/cases/{case}/reapply', [ReapplicationController::class, 'store'])->name('workflow.reapplications.store')->where('case', '[0-9]{1,18}');
