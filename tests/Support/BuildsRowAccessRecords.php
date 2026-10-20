<?php

namespace Tests\Support;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordDetailRowInput;
use App\Actions\Records\Api\RecordInput;
use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordModuleAction;
use App\Enums\Records\RowAccessScope;
use App\Models\Master\Department;
use App\Models\Master\DepartmentSet;
use App\Models\Master\User;
use App\Models\Records\RecordModule;

/**
 * 行の権限のテストのデータ(docs の 設計/業務データの入れ物/認可とメニュー/メニューと初期データ.md 8 節の「行の権限」): 見える範囲を「関係する人だけ」にした
 * 業務モジュール meetings と、旗を付けたユーザー・組織(1 つと複数)の項目・旗を付けた明細の項目、旗の無いユーザーの項目、関係する人と関係しない人。
 * 基準日は {@see BuildsRecordModules} の 2026-10-11。
 *
 * 人(キー): creator = 作成者、attendee = 旗の付いたユーザーの項目、member = 旗の付いたユーザー(複数)の項目、mainMember = 旗の付いた組織の項目の組織に主所属、
 * concurrentMember = 旗の付いた組織(複数)の項目の組織に兼務(主所属は other)、assignee = 明細の行の旗の付いた項目、childOnly = section の下の組織 child
 * にだけ所属、watcher = 旗の無いユーザーの項目、stranger = どれにも入らない。
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

    /** 関係する人(一覧・詳細・編集・候補・更新・削除ができる) */
    protected const RELATED = ['creator', 'attendee', 'member', 'mainMember', 'concurrentMember', 'assignee'];

    /** 関係しない人(ゲストは別に確かめる) */
    protected const UNRELATED = ['childOnly', 'watcher', 'stranger'];

    /**
     * meetings と、関係する人のいるレコード(recordId)と、stranger が作った関係する人のいないレコード(otherId)。
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

        foreach (['section', 'child', 'other', 'sections'] as $key) {
            $departments[$key] = $this->department($set);
        }

        $this->include($departments['section'], $departments['child']);
        $people = [];

        foreach ([...self::RELATED, ...self::UNRELATED] as $key) {
            $people[$key] = $this->user();
        }

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
        ], [['assignee' => $people['assignee']->id]]);
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
     * 見える範囲を「関係する人だけ」にした業務モジュール(表示の項目 title、旗の付いた attendee・attendees・section・sections、旗の無い watcher、
     * 明細 rows の旗の付いた assignee)。
     *
     * @param  list<RecordModuleAction>|null  $permitted
     */
    protected function relatedModule(string $code, ?array $permitted = null): RecordModule
    {
        $module = $this->recordModule($code, $permitted);
        $module->update(['row_access_scope' => RowAccessScope::Related]);
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
     */
    protected function createMeeting(User $creator, string $title, array $values, array $rows): int
    {
        $input = new RecordInput(
            ['title' => $title, ...$values],
            ['rows' => array_map(fn (array $row) => new RecordDetailRowInput(values: $row), $rows)],
        );

        return app(CreateRecord::class)->execute($this->contextFor($creator->id), 'meetings', $input);
    }
}
