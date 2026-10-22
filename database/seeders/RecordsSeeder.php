<?php

namespace Database\Seeders;

use App\Actions\Authz\CreatePolicy;
use App\Actions\Authz\CreateResourceGroup;
use App\Actions\Authz\CreateRoutePrefixResourceGroup;
use App\Actions\Authz\CreateRouteResource;
use App\Actions\Authz\FindOrCreateSingleConditionSubjectGroup;
use App\Actions\Records\AddRecordField;
use App\Actions\Records\AddRecordRule;
use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordDetailRowInput;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\CreateRecordModule;
use App\Enums\Authz\PolicyEffect;
use App\Enums\Authz\ServiceAction;
use App\Enums\Menu\MenuIcon;
use App\Enums\Records\RecordModuleAction;
use App\Models\Master\Department;
use App\Models\Master\User;
use App\Support\AccessContext;
use Illuminate\Database\Seeder;

/**
 * 業務データの入れ物の初期データ(docs の 設計/業務データの入れ物/認可とメニュー/メニューと初期データ.md 6 節・7 節、設計/メニュー/初期データ.md 4 節):
 * 1. 接頭辞のリソースグループ http-services.records・…data・…admin(・modules・fields・headings・related-columns・rules・categories)と、業務のルートのリソースとポリシー(ゲストユーザと認証済みユーザに
 *    execute の許可。接頭辞には置かない)、管理のルートのリソース(ポリシーなし)
 * 2. セット record-modules と、システム管理者への 5 つのアクション(全件の参照を含む)の許可(全部を許すロール = 認可とメニュー/README.md 3 節)
 * 3. 業務モジュール「プロジェクト」「課題」(業務モジュールを作る業務処理 = リソース・初期ポリシー・「業務」のアイテム)と、明細「マイルストーン」
 * 4. 項目・選択肢・規則、5. レコード(判定の入力 = u0004 伊藤 涼子、基準日 = 流す日の Asia/Tokyo の日付、ロケール ja)。
 *    レコードの日付の項目は流す日からの相対({@see SeedingDates})。項目の後に見本の見出し({@see RecordHeadingsSeeder})、
 *    レコードの後に見本のコメント({@see RecordCommentsSeeder})
 *
 * AccountSeeder の後に 1 回入れる前提。すべて業務処理を通す。中身の正本はこの seeder(設計の値 = 7.2・7.3)。
 */
class RecordsSeeder extends Seeder
{
    use FindsAuthzSubjects;

    /** 接頭辞のリソースグループ: 接頭辞 => [表示名(ja), 表示名(en)] */
    private const PREFIXES = [
        'records' => ['業務データ', 'Records'],
        'records.data' => ['レコード', 'Record data'],
        'records.data.attachments' => ['添付ファイル', 'Record attachments'],
        'records.data.comments' => ['コメント', 'Record comments'],
        'records.data.revision-files' => ['更新履歴のファイル', 'Revision files'],
        'records.admin' => ['業務データの管理', 'Records administration'],
        'records.admin.comments' => ['コメントの管理', 'Comment administration'],
        'records.admin.modules' => ['業務モジュール', 'Record modules'],
        'records.admin.fields' => ['項目', 'Fields'],
        'records.admin.headings' => ['見出し', 'Field headings'],
        'records.admin.related-columns' => ['関連レコードの列', 'Related record columns'],
        'records.admin.rules' => ['規則', 'Rules'],
        'records.admin.categories' => ['モジュール分類', 'Module categories'],
    ];

    /** 業務のルート: 名前 => [表示名(ja), 表示名(en)] */
    private const ROUTES = [
        'records.data.index' => ['レコードの一覧', 'Record list'],
        'records.data.create' => ['レコードの登録画面', 'New record form'],
        'records.data.store' => ['レコードの登録', 'Create record'],
        'records.data.show' => ['レコードの詳細', 'Record detail'],
        'records.data.edit' => ['レコードの編集画面', 'Edit record form'],
        'records.data.update' => ['レコードの更新', 'Update record'],
        'records.data.destroy' => ['レコードの削除', 'Delete record'],
        'records.data.candidates' => ['レコードの候補', 'Record candidates'],
        'records.data.attachments.store' => ['添付ファイルの追加', 'Add attachments'],
        'records.data.attachments.destroy' => ['添付ファイルの削除', 'Remove attachment'],
        'records.data.attachments.show' => ['添付ファイルのダウンロード', 'Download attachment'],
        'records.data.attachments.preview' => ['添付ファイルのプレビュー', 'Preview attachment'],
        'records.data.comments.store' => ['コメントの投稿', 'Post comment'],
        'records.data.comments.destroy' => ['コメントの削除', 'Delete comment'],
        'records.data.comments.candidates' => ['コメントの宛先の候補', 'Comment mention candidates'],
        'records.data.revision-files.show' => ['更新履歴のファイルのダウンロード', 'Download revision file'],
        'records.data.revision-files.preview' => ['更新履歴のファイルのプレビュー', 'Preview revision file'],
    ];

    /** 管理のルート: 名前 => [表示名(ja), 表示名(en)](ポリシーを置かない。システム管理者が http-services の先頭の許可を継承する = 認可とメニュー/README.md 4 節) */
    private const ADMIN_ROUTES = [
        'records.admin.comments.destroy' => ['コメントの削除(管理)', 'Delete comment as administrator'],
        'records.admin.modules.index' => ['業務モジュールの一覧', 'Record module list'],
        'records.admin.modules.create' => ['業務モジュールの作成画面', 'New record module form'],
        'records.admin.modules.store' => ['業務モジュールの作成', 'Create record module'],
        'records.admin.modules.show' => ['業務モジュールの定義', 'Record module definition'],
        'records.admin.modules.edit' => ['業務モジュールの編集画面', 'Edit record module form'],
        'records.admin.modules.update' => ['業務モジュールの更新', 'Update record module'],
        'records.admin.modules.reorder' => ['業務モジュールの並び替え(業務の直下)', 'Reorder uncategorized record modules'],
        'records.admin.modules.categories' => ['業務モジュールのモジュール分類の更新', 'Update record module categories'],
        'records.admin.fields.create' => ['項目の追加画面', 'New field form'],
        'records.admin.fields.store' => ['項目の追加', 'Create field'],
        'records.admin.fields.edit' => ['項目の編集画面', 'Edit field form'],
        'records.admin.fields.update' => ['項目の更新', 'Update field'],
        'records.admin.fields.destroy' => ['項目の削除', 'Delete field'],
        'records.admin.fields.reorder' => ['項目の並び替え', 'Reorder fields'],
        'records.admin.headings.store' => ['見出しの追加', 'Create field heading'],
        'records.admin.headings.update' => ['見出しの更新', 'Update field heading'],
        'records.admin.headings.destroy' => ['見出しの削除', 'Delete field heading'],
        'records.admin.related-columns.update' => ['関連レコードの列の保存', 'Save related record columns'],
        'records.admin.rules.store' => ['規則の追加', 'Create rule'],
        'records.admin.rules.update' => ['規則の更新', 'Update rule'],
        'records.admin.rules.destroy' => ['規則の削除', 'Delete rule'],
        'records.admin.categories.index' => ['モジュール分類の一覧', 'Module category list'],
        'records.admin.categories.store' => ['モジュール分類の作成', 'Create module category'],
        'records.admin.categories.update' => ['モジュール分類の更新', 'Update module category'],
        'records.admin.categories.destroy' => ['モジュール分類の削除', 'Delete module category'],
        'records.admin.categories.reorder' => ['モジュール分類の並び替え', 'Reorder module categories'],
        'records.admin.categories.reorder-modules' => ['モジュール分類の中の並び替え', 'Reorder modules in category'],
        'records.admin.categories.modules' => ['モジュール分類の中の業務モジュールの更新', 'Update modules in category'],
    ];

    /** 業務モジュール(作る順): コード => [名前, 説明, アイコン] */
    private const MODULES = [
        'project' => ['プロジェクト', '社内のプロジェクトの担当・期間・状態', MenuIcon::FolderKanban],
        'project_issue' => ['課題', 'プロジェクトで起きた課題と対応', MenuIcon::ListTodo],
    ];

    /** 一覧に出す(束ねる印は一覧だけ。検索条件は run で全項目に付ける) */
    private const LISTED = ['is_listed' => true];

    /** 業務モジュールのコード => 項目の入力(並びの順) */
    private const FIELDS = [
        'project' => [
            ['code' => 'name', 'name' => 'プロジェクト名', 'type' => 'string', 'is_required' => true, 'is_title' => true, ...self::LISTED],
            ['code' => 'department', 'name' => '担当部署', 'type' => 'department', 'is_required' => true, ...self::LISTED],
            ['code' => 'manager', 'name' => '責任者', 'type' => 'user', 'is_required' => true, ...self::LISTED],
            ['code' => 'status', 'name' => '状態', 'type' => 'choice', 'is_required' => true, ...self::LISTED, 'default_value' => 'planning', 'options' => [
                ['code' => 'planning', 'name' => '計画中'], ['code' => 'active', 'name' => '進行中'], ['code' => 'done', 'name' => '完了'],
            ]],
            ['code' => 'start_date', 'name' => '開始日', 'type' => 'date', 'is_required' => true, ...self::LISTED],
            ['code' => 'end_date', 'name' => '終了日', 'type' => 'date', 'is_listed' => true],
            ['code' => 'budget', 'name' => '予算(万円)', 'type' => 'integer'],
            ['code' => 'summary', 'name' => '概要', 'type' => 'string', 'is_multiline' => true],
            ['code' => 'milestones', 'name' => 'マイルストーン', 'type' => 'details', 'child_module' => ['code' => 'project_milestone', 'name' => 'マイルストーン']],
        ],
        'project_milestone' => [
            ['code' => 'name', 'name' => '名称', 'type' => 'string', 'is_required' => true, 'is_title' => true],
            ['code' => 'due_date', 'name' => '期日', 'type' => 'date', 'is_required' => true],
            ['code' => 'effort', 'name' => '工数(人日)', 'type' => 'decimal'],
            ['code' => 'is_done', 'name' => '完了', 'type' => 'boolean', 'default_value' => false],
        ],
        'project_issue' => [
            ['code' => 'title', 'name' => '課題名', 'type' => 'string', 'is_required' => true, 'is_title' => true, ...self::LISTED],
            ['code' => 'project', 'name' => 'プロジェクト', 'type' => 'record', 'referenced_module' => 'project', 'is_required' => true, ...self::LISTED],
            ['code' => 'assignee', 'name' => '担当者', 'type' => 'user', ...self::LISTED],
            ['code' => 'priority', 'name' => '優先度', 'type' => 'choice', 'is_required' => true, ...self::LISTED, 'default_value' => 'medium', 'options' => [
                ['code' => 'high', 'name' => '高'], ['code' => 'medium', 'name' => '中'], ['code' => 'low', 'name' => '低'],
            ]],
            ['code' => 'status', 'name' => '状態', 'type' => 'choice', 'is_required' => true, ...self::LISTED, 'default_value' => 'open', 'options' => [
                ['code' => 'open', 'name' => '未着手'], ['code' => 'in_progress', 'name' => '対応中'], ['code' => 'done', 'name' => '完了'],
            ]],
            ['code' => 'due_date', 'name' => '期限', 'type' => 'date', ...self::LISTED],
            ['code' => 'reported_at', 'name' => '報告日時', 'type' => 'datetime'],
            ['code' => 'resolution', 'name' => '解決内容', 'type' => 'string', 'is_multiline' => true],
        ],
    ];

    /** 業務モジュールのコード => 規則の入力 */
    private const RULES = [
        'project' => [['kind' => 'compare', 'target' => 'end_date', 'operator' => 'ge', 'compared' => 'start_date', 'message' => '終了日は開始日以後の日にしてください']],
        'project_issue' => [['kind' => 'required_when', 'target' => 'resolution', 'condition' => ['all' => [['field' => 'status', 'op' => 'eq', 'value' => 'done']]], 'message' => '状態が完了のときは解決内容を入力してください']],
    ];

    /** プロジェクト: [プロジェクト名, 担当部署のコード, 責任者のコード, 状態, 開始日, 終了日, 予算, 概要, マイルストーン [名称, 期日, 工数, 完了]] */
    private const PROJECTS = [
        ['社内ポータル刷新', 'development', 'u0011', 'active', '2026-07-01', '2026-12-25', 1200, '社内ポータルを新しい基盤へ移し、申請の入口を一つにまとめる。', [
            ['要件定義', '2026-07-31', '10', true], ['設計', '2026-09-30', '15.5', true], ['実装', '2026-11-30', '40', false], ['受け入れ', '2026-12-18', '5', false],
        ]],
        ['新規顧客開拓キャンペーン', 'sales-1', 'u0003', 'planning', '2026-11-01', '2027-03-31', 300, null, [
            ['対象リスト作成', '2026-11-15', '3', false], ['初回訪問', '2026-12-20', '12', false],
        ]],
        ['経費精算の電子化', 'accounting', 'u0010', 'done', '2026-04-01', '2026-09-30', 500, null, [
            ['運用開始', '2026-09-01', '8', true],
        ]],
    ];

    /** 課題: [課題名, プロジェクトの添字, 担当者のコード, 優先度, 状態, 期限, 報告日時, 解決内容] */
    private const ISSUES = [
        ['検索の応答が遅い', 0, 'u0012', 'high', 'in_progress', '2026-10-20', '2026-10-05T10:15:00+09:00', null],
        ['権限の設計が決まっていない', 0, 'u0011', 'medium', 'open', '2026-10-31', null, null],
        ['旧ポータルのデータ移行手順', 0, null, 'low', 'open', null, null, null],
        ['訪問先リストの重複', 1, 'u0001', 'medium', 'open', '2026-11-10', null, null],
        ['領収書の読み取りの誤り', 2, 'u0010', 'high', 'done', '2026-08-31', '2026-08-20T14:00:00+09:00', '読み取りの設定を見直し、手入力の確認を加えた。'],
    ];

    /** 初期データのレコードを作る人(システム管理者) */
    private const AUTHOR = 'u0004';

    public function __construct(
        private CreateRoutePrefixResourceGroup $createPrefix,
        private CreateRouteResource $createRouteResource,
        private CreateResourceGroup $createResourceGroup,
        private CreatePolicy $createPolicy,
        private FindOrCreateSingleConditionSubjectGroup $findOrCreate,
        private CreateRecordModule $createModule,
        private AddRecordField $addField,
        private AddRecordRule $addRule,
        private CreateRecord $createRecord,
    ) {}

    public function run(): void
    {
        $this->createRoutes();
        $set = $this->createResourceGroup->execute(RecordModuleAction::SET_CODE, null, ['ja' => ['display_name' => '業務モジュール'], 'en' => ['display_name' => 'Record modules']]);

        foreach (RecordModuleAction::cases() as $action) {
            $this->createPolicy->execute($set->id, $this->systemAdministratorSubjectGroupId($this->findOrCreate), RecordModuleAction::RESOURCE_TYPE, $action->value, PolicyEffect::Permit);
        }

        foreach (self::MODULES as $code => [$name, $description, $icon]) {
            $this->createModule->execute(['code' => $code, 'name' => $name, 'description' => $description, 'icon' => $icon->value]);
        }

        foreach (self::FIELDS as $moduleCode => $fields) {
            foreach ($fields as $sortKey => $field) {
                // 検索条件にするのは型「明細」以外の全項目(項目を足す画面の初期値と同じ = 画面/管理の画面.md 3.4)
                $this->addField->execute($moduleCode, ['is_searchable' => $field['type'] !== 'details', ...$field, 'sort_key' => $sortKey]);
            }
        }

        foreach (self::RULES as $moduleCode => $rules) {
            foreach ($rules as $rule) {
                $this->addRule->execute($moduleCode, $rule);
            }
        }

        $this->call(RecordHeadingsSeeder::class);
        $this->createRecords();
        $this->call(RecordCommentsSeeder::class);
    }

    /**
     * 接頭辞のリソースグループと、業務のルートのリソース・ポリシー、管理のルートのリソース。
     */
    private function createRoutes(): void
    {
        foreach (self::PREFIXES as $prefix => [$ja, $en]) {
            $this->createPrefix->execute($prefix, ['ja' => ['display_name' => $ja], 'en' => ['display_name' => $en]]);
        }

        $everyone = [$this->guestSubjectGroupId($this->findOrCreate), $this->authenticatedSubjectGroupId($this->findOrCreate)];

        foreach (self::ROUTES as $name => [$ja, $en]) {
            $resource = $this->createRouteResource->execute($name, ['ja' => ['display_name' => $ja], 'en' => ['display_name' => $en]]);

            foreach ($everyone as $subjectGroupId) {
                $this->createPolicy->execute($resource->authz_resource_group_id, $subjectGroupId, ServiceAction::RESOURCE_TYPE, ServiceAction::Execute->value, PolicyEffect::Permit);
            }
        }

        foreach (self::ADMIN_ROUTES as $name => [$ja, $en]) {
            $this->createRouteResource->execute($name, ['ja' => ['display_name' => $ja], 'en' => ['display_name' => $en]]);
        }
    }

    /**
     * プロジェクト(マイルストーンを含む)と課題。
     */
    private function createRecords(): void
    {
        $context = new AccessContext(
            User::query()->where('code', self::AUTHOR)->sole()->id,
            null,
            null,
            SeedingDates::seedingDay(),
            AccessContext::LOCALE_CODE,
        );
        $projectIds = [];

        foreach (self::PROJECTS as [$name, $department, $manager, $status, $start, $end, $budget, $summary, $milestones]) {
            $projectIds[] = $this->createRecord->execute($context, 'project', new RecordInput([
                'name' => $name, 'department' => $this->departmentId($department), 'manager' => $this->userId($manager), 'status' => $status,
                'start_date' => SeedingDates::day($start), 'end_date' => SeedingDates::day($end),
                'budget' => $budget, 'summary' => $summary,
            ], ['milestones' => array_map(fn (array $row) => new RecordDetailRowInput(values: [
                'name' => $row[0], 'due_date' => SeedingDates::day($row[1]), 'effort' => $row[2], 'is_done' => $row[3],
            ]), $milestones)]));
        }

        foreach (self::ISSUES as [$title, $project, $assignee, $priority, $status, $due, $reportedAt, $resolution]) {
            $this->createRecord->execute($context, 'project_issue', new RecordInput([
                'title' => $title, 'project' => $projectIds[$project], 'assignee' => $assignee === null ? null : $this->userId($assignee), 'priority' => $priority,
                'status' => $status, 'due_date' => SeedingDates::day($due), 'reported_at' => SeedingDates::dateTime($reportedAt),
                'resolution' => $resolution,
            ]));
        }
    }

    private function departmentId(string $code): int
    {
        return Department::query()->where('code', $code)->sole()->id;
    }

    private function userId(string $code): int
    {
        return User::query()->where('code', $code)->sole()->id;
    }
}
