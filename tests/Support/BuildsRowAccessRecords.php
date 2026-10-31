<?php

namespace Tests\Support;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordDetailRowInput;
use App\Actions\Records\Api\RecordInput;
use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordModuleAction;
use App\Models\Master\Department;
use App\Models\Master\DepartmentSet;
use App\Models\Master\User;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRowAccessViewer;

/**
 * 行の権限のテストのデータ(docs の 設計/業務データの入れ物/認可とメニュー/メニューと初期データ.md 8 節の「行の権限」): 参照制限の既定を「制限する」にした
 * 業務モジュール meetings と、関係者の印を付けたユーザー・組織(1 つと複数)の項目・印を付けた明細の項目、印の無いユーザーの項目、関係者と参照者と
 * 関係者でも参照者でもない人。基準日は {@see BuildsRecordModules} の 2026-10-11。
 *
 * 人(キー): creator = 作成者、attendee = 印の付いたユーザーの項目、member = 印の付いたユーザー(複数)の項目、mainMember = 印の付いた組織の項目の組織に主所属、
 * concurrentMember = 印の付いた組織(複数)の項目の組織に兼務(主所属は other)、assignee = 明細の行の印の付いた項目、viewer = 参照者のユーザー、
 * viewerMember = 参照者の組織 viewing に主所属、viewerConcurrent = viewing に兼務(主所属は other)、childOnly = section の下の組織 child
 * にだけ所属、viewingChildOnly = viewing の下の組織 viewingChild にだけ所属、watcher = 印の無いユーザーの項目、stranger = どれにも入らない。
 *
 * @phpstan-type RowAccessFixture array{
 *     module: RecordModule,
 *     recordId: int,
 *     otherId: int,
 *     people: array<string, User>,
 *     departments: array<string, Department>,
 * }
 */
trait BuildsRowAccessRecords
{
    use BuildsCommonMaster;
    use BuildsRecordModules;

    /** 関係者(一覧・詳細・編集・候補・更新・削除ができ、通知の宛先に入る) */
    protected const RELATED = ['creator', 'attendee', 'member', 'mainMember', 'concurrentMember', 'assignee'];

    /** 参照者(関係者と同じく見られ・書けるが、通知の宛先に入らない) */
    protected const VIEWERS = ['viewer', 'viewerMember', 'viewerConcurrent'];

    /** 関係者でも参照者でもない人(ゲストは別に確かめる) */
    protected const UNRELATED = ['childOnly', 'viewingChildOnly', 'watcher', 'stranger'];

    /**
     * meetings と、関係者と参照者(viewer・組織 viewing)のいる参照制限のあるレコード(recordId)と、stranger が作った関係者も参照者もいない
     * 参照制限のあるレコード(otherId)。
     *
     * @param  list<RecordModuleAction>|null  $permitted  ゲストと認証済みの全員に許すアクション(既定は初期ポリシーと同じ 4 つ)
     * @param  array{0: string, 1: string|null, 2?: bool}  $memberTerm  mainMember の section の所属の期間
     * @return RowAccessFixture
     */
    protected function rowAccessFixture(?array $permitted = null, array $memberTerm = self::ALWAYS[0]): array
    {
        $module = $this->relatedModule('meetings', $permitted);
        $set = DepartmentSet::factory()->create();
        $departments = [];

        foreach (['section', 'child', 'other', 'sections', 'viewing', 'viewingChild'] as $key) {
            $departments[$key] = $this->department($set);
        }

        $this->include($departments['section'], $departments['child']);
        $this->include($departments['viewing'], $departments['viewingChild']);
        $people = [];

        foreach ([...self::RELATED, ...self::VIEWERS, ...self::UNRELATED] as $key) {
            $people[$key] = $this->user();
        }

        $this->join($people['viewerMember'], $departments['viewing'], isMain: true);
        $this->join($people['viewerConcurrent'], $departments['other'], isMain: true);
        $this->join($people['viewerConcurrent'], $departments['viewing']);
        $this->join($people['viewingChildOnly'], $departments['viewingChild'], isMain: true);

        $this->join($people['mainMember'], $departments['section'], $memberTerm, isMain: true);
        $this->join($people['concurrentMember'], $departments['other'], isMain: true);
        $this->join($people['concurrentMember'], $departments['sections']);
        $this->join($people['childOnly'], $departments['child'], isMain: true);

        $recordId = $this->createMeeting($people['creator'], '定例', [
            'attendee' => $people['attendee']->id,
            'attendees' => [$people['member']->id],
            'section' => $departments['section']->id,
            'sections' => [$departments['sections']->id],
            'watcher' => $people['watcher']->id,
        ], [['assignee' => $people['assignee']->id]], viewers: [
            ['type' => 'user', 'id' => $people['viewer']->id],
            ['type' => 'department', 'id' => $departments['viewing']->id],
        ]);
        $otherId = $this->createMeeting($people['stranger'], '別の会議', [], []);

        return [
            'module' => $module->refresh(),
            'recordId' => $recordId,
            'otherId' => $otherId,
            'people' => $people,
            'departments' => $departments,
        ];
    }

    /**
     * 参照制限の既定を「制限する」にした業務モジュール(表示の項目 title、関係者の印の付いた attendee・attendees・section・sections、印の無い watcher、
     * 明細 rows の印の付いた assignee)。
     *
     * @param  list<RecordModuleAction>|null  $permitted
     */
    protected function relatedModule(string $code, ?array $permitted = null): RecordModule
    {
        $module = $this->recordModule($code, $permitted);
        $module->update(['row_access_restricted_by_default' => true]);
        $flagged = ['grants_row_access' => true];
        $this->field($module, 'title', RecordFieldType::String, ['is_title' => true]);
        $this->field($module, 'attendee', RecordFieldType::User, $flagged);
        $this->field($module, 'attendees', RecordFieldType::User, ['is_multiple' => true, ...$flagged]);
        $this->field($module, 'section', RecordFieldType::Department, $flagged);
        $this->field($module, 'sections', RecordFieldType::Department, ['is_multiple' => true, ...$flagged]);
        $this->field($module, 'watcher', RecordFieldType::User);
        $this->field($this->childOf($this->detailsField($module, 'rows')), 'assignee', RecordFieldType::User, $flagged);

        return $module;
    }

    /**
     * 業務処理でレコードを作る(作成者 = $creator)。
     *
     * @param  array<string, mixed>  $values
     * @param  list<array<string, mixed>>  $rows  明細 rows の行の値
     * @param  bool|null  $restricted  チェック(null = 業務モジュールの既定)
     * @param  list<array{type: string, id: int}>|null  $viewers  参照者(null = 送らない)
     */
    protected function createMeeting(
        User $creator,
        string $title,
        array $values,
        array $rows,
        ?bool $restricted = null,
        ?array $viewers = null,
    ): int {
        $input = new RecordInput(
            ['title' => $title, ...$values],
            ['rows' => array_map(fn (array $row) => new RecordDetailRowInput(values: $row), $rows)],
            rowAccessRestricted: $restricted,
            rowAccessViewers: $viewers,
        );

        return app(CreateRecord::class)->execute($this->contextFor($creator->id), 'meetings', $input);
    }

    /**
     * stranger が作った参照制限の無いレコード(R1。同じ業務モジュールで、関係者も参照者もいない)。
     *
     * @param  RowAccessFixture  $fixture
     */
    protected function openMeeting(array $fixture): int
    {
        return $this->createMeeting($fixture['people']['stranger'], '公開の会議', [], [], restricted: false);
    }

    /**
     * 業務処理を通さずに参照者の行を足す(d に有効でない人・組織を参照者に置くため。並びは今の行の後ろ)。
     *
     * @param  list<User>  $users
     */
    protected function addViewerRows(int $recordId, array $users): void
    {
        $sortKey = (int) RecordRowAccessViewer::query()->where('record_id', $recordId)->max('sort_key');

        foreach ($users as $user) {
            RecordRowAccessViewer::query()->create([
                'record_id' => $recordId, 'user_id' => $user->id, 'sort_key' => ++$sortKey,
            ]);
        }
    }
}
