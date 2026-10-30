<?php

namespace Tests\Feature\Queries\Records;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordInput;
use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordModuleAction;
use App\Models\Master\Department;
use App\Models\Master\DepartmentSet;
use App\Models\Master\Locale;
use App\Models\Master\User;
use App\Models\Master\UserTerm;
use App\Models\Master\UserTermTranslation;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordConditions;
use App\Queries\Records\Api\RecordDisplayQuery;
use App\Queries\Records\Api\RecordFormQuery;
use App\Queries\Records\RecordDefinitionQuery;
use App\Queries\Records\RecordListQuery;
use App\Queries\Records\RecordPageQuery;
use App\Queries\Records\RecordQuery;
use App\Queries\Records\RecordRevisionDiff;
use App\Queries\Records\RecordRevisionLabels;
use App\Queries\Records\RecordSnapshots;
use Carbon\CarbonImmutable;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsPartyInputs;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 型「ユーザーと組織」の読み取り(docs の 設計/業務データの入れ物/項目と規則/README.md 5 節の読むときの規則、画面/README.md 1.2 の RecordPartyValue、
 * 写しとつながる申請.md 9 節の一時保存の入力、レコードの共通機能/データモデル/表.md 2.1.1・2.1.2 の版の写し、更新履歴.md 5.2 の差分、複製.md 5 節):
 * 相手ごとに種類・id・名称を返し、一時保存を開き直しても版の写しでも種類と id が残り、版の差分は種類と id で比べる。
 */
final class UserOrDepartmentReadTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsPartyInputs;
    use BuildsRecordModules;

    private RecordModule $module;

    protected function setUp(): void
    {
        parent::setUp();
        $this->module = $this->recordModule('meetings');
        $this->field($this->module, 'host', RecordFieldType::UserOrDepartment, ['is_listed' => true]);
        $multiple = ['is_multiple' => true, 'is_listed' => true];
        $this->field($this->module, 'parties', RecordFieldType::UserOrDepartment, $multiple);
    }

    public function test_every_reader_returns_the_kind_the_id_and_the_name_of_each_target(): void
    {
        $user = $this->namedUser('山田 太郎');
        $section = Department::query()->where('code', 'general-affairs')->sole();
        $recordId = $this->create([
            'host' => self::partyInputOf($section),
            'parties' => [self::partyInputOf($user), self::partyInputOf($section)],
        ]);
        $userValue = ['type' => 'user', 'id' => $user->id, 'code' => $user->code, 'label' => '山田 太郎'];
        $sectionValue = ['type' => 'department', 'id' => $section->id, 'code' => $section->code, 'label' => '総務部'];
        $userValue['affiliation'] = null;
        $sectionValue['affiliation'] = null;
        $expected = ['host' => $sectionValue, 'parties' => [$userValue, $sectionValue]];

        foreach ($this->readers($recordId) as $label => $values) {
            $this->assertSame($expected, $values, $label);
        }
    }

    public function test_draft_values_keep_the_kind_and_drop_targets_of_another_shape_or_repeated(): void
    {
        [$user, $department] = $this->userAndDepartmentWithTheSameId(DepartmentSet::factory()->create());
        $draft = $this->draftValues([
            'host' => self::partyInputOf($department),
            'parties' => [
                self::partyInputOf($department),
                ['type' => 'role', 'id' => 1],
                $user->id,
                self::partyInputOf($user),
                self::partyInputOf($department),
            ],
        ]);

        $this->assertSame(['department', $department->id], [$draft['host']['type'], $draft['host']['id']]);
        $this->assertSame(
            [['department', $department->id], ['user', $user->id]],
            array_map(fn (array $value) => [$value['type'], $value['id']], $draft['parties']),
        );
        $this->assertNull($this->draftValues(['host' => $user->id, 'parties' => self::partyInputOf($user)])['host']);
    }

    public function test_a_copy_carries_the_kind_and_the_id_and_registers_the_same_targets(): void
    {
        [$user, $department] = $this->userAndDepartmentWithTheSameId(DepartmentSet::factory()->create());
        $recordId = $this->create(['parties' => [self::partyInputOf($user), self::partyInputOf($department)]]);

        $records = app(RecordQuery::class);
        $source = $records->copySource($this->requester(), $this->opened(), $recordId, $this->today(), 'ja');
        $copied = $source['values']['parties'];
        $sent = array_map(fn (array $value) => ['type' => $value['type'], 'id' => $value['id']], $copied);
        $copyId = $this->create(['parties' => $sent]);

        $this->assertSame([self::partyInputOf($user), self::partyInputOf($department)], $sent);
        $copy = Record::query()->with('recordReferences')->findOrFail($copyId);
        $this->assertSame($sent, $copy->referenceValue($this->parties()));
    }

    public function test_a_snapshot_holds_the_kind_and_the_id_and_the_labels_carry_multiple(): void
    {
        [$user, $department] = $this->userAndDepartmentWithTheSameId(DepartmentSet::factory()->create());
        $recordId = $this->create([
            'host' => self::partyInputOf($user),
            'parties' => [self::partyInputOf($department), self::partyInputOf($user)],
        ]);
        $definition = app(RecordDefinitionQuery::class)->load($this->module);

        $snapshot = app(RecordSnapshots::class)->snapshot(Record::query()->findOrFail($recordId), $definition);
        $labels = app(RecordRevisionLabels::class)->of($definition, $snapshot)['fields'];

        $this->assertSame([
            $this->host()->id => self::partyInputOf($user),
            $this->parties()->id => [self::partyInputOf($department), self::partyInputOf($user)],
        ], $snapshot['references']);
        $multiple = [$labels[$this->host()->id]['multiple'], $labels[$this->parties()->id]['multiple']];
        $this->assertSame([false, true], $multiple);
        $this->assertSame('user_or_department', $labels[$this->parties()->id]['type']);
    }

    public function test_the_diff_shows_a_change_of_only_the_kind_of_the_same_id_and_not_a_reorder(): void
    {
        [$user, $department] = $this->userAndDepartmentWithTheSameId(DepartmentSet::factory()->create());
        $snapshot = fn (array ...$parties) => ['references' => [$this->parties()->id => $parties]];
        $shown = fn (User|Department $party) => [
            'type' => $party instanceof User ? 'user' : 'department',
            'id' => $party->id,
            'code' => $party->code,
            'label' => null,
            'affiliation' => null,
        ];

        $kindChanged = $this->diff($snapshot(self::partyInputOf($user)), $snapshot(self::partyInputOf($department)));
        $reordered = $this->diff(
            $snapshot(self::partyInputOf($user), self::partyInputOf($department)),
            $snapshot(self::partyInputOf($department), self::partyInputOf($user)),
        );
        $hostChanged = $this->diff(
            ['references' => [$this->host()->id => self::partyInputOf($user)]],
            ['references' => [$this->host()->id => self::partyInputOf($department)]],
        );

        $this->assertSame(
            [['code' => 'parties', 'name' => 'parties', 'before' => [$shown($user)], 'after' => [$shown($department)]]],
            $kindChanged['fields'],
        );
        $this->assertSame([], $reordered['fields']);
        $this->assertSame(
            [['code' => 'host', 'name' => 'host', 'before' => $shown($user), 'after' => $shown($department)]],
            $hostChanged['fields'],
        );
    }

    public function test_a_condition_reads_no_targets_as_empty(): void
    {
        $empty = $this->create([]);
        $held = $this->create(['parties' => [self::partyInputOf($this->user())]]);
        $holds = fn (int $recordId, string $operator) => app(RecordConditions::class)
            ->matches($recordId, ['all' => [['field' => 'parties', 'op' => $operator]]]);

        $this->assertSame([true, false], [$holds($empty, 'empty'), $holds($empty, 'not_empty')]);
        $this->assertSame([false, true], [$holds($held, 'empty'), $holds($held, 'not_empty')]);
    }

    /**
     * 業務の詳細・編集の初期値・一覧・公開の口の値(読み取りの名前 → 項目のコード → 値)。
     *
     * @return array<string, array<string, mixed>>
     */
    private function readers(int $recordId): array
    {
        $requester = $this->requester();
        $date = $this->today();
        $opened = $this->opened();
        $listed = app(RecordListQuery::class)->list($requester, $opened, [], null, null, $date, 'ja');
        $records = app(RecordQuery::class);
        $forms = app(RecordFormQuery::class);
        $display = app(RecordDisplayQuery::class);

        return [
            '詳細' => $records->show($requester, $opened, $recordId, $date, 'ja')['values'],
            '編集' => $records->editable($requester, $opened, $recordId, $date, 'ja')['values'],
            '一覧' => $listed['records']['data'][0]['values'],
            'editValues' => $forms->editValues($requester, 'meetings', $recordId, $date, 'ja')['values'],
            'RecordDisplayQuery::show' => $display->show($requester, $recordId, $date, 'ja')['record']['values'],
        ];
    }

    /**
     * 一時保存の入力を画面の初期値の形に直した値(公開の口 RecordFormQuery::draftValues)。
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function draftValues(array $values): array
    {
        $forms = app(RecordFormQuery::class);

        return $forms->draftValues($this->requester(), 'meetings', $values, [], $this->today(), 'ja')['values'];
    }

    /**
     * 今の定義の写しを持つ版の写し 2 つ(保存形の JSON に通す)の差分。
     *
     * @param  array<array-key, mixed>  $before
     * @param  array<array-key, mixed>  $after
     * @return array<string, mixed>
     */
    private function diff(array $before, array $after): array
    {
        $roundTrip = fn (array $value) => json_decode(
            json_encode($value, JSON_THROW_ON_ERROR),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $empty = ['values' => [], 'references' => [], 'details' => [], 'attachments' => []];
        $definition = app(RecordDefinitionQuery::class)->load($this->module);
        $labels = $roundTrip(app(RecordRevisionLabels::class)->of($definition, $empty));
        $copy = fn (array $snapshot) => ['snapshot' => $roundTrip($snapshot), 'labels' => $labels];

        $differ = app(RecordRevisionDiff::class);

        return $differ->diff($copy($before), $copy($after), $this->requester(), $this->today(), 'ja');
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function create(array $values): int
    {
        return app(CreateRecord::class)->execute($this->contextFor(), 'meetings', new RecordInput($values));
    }

    private function host(): RecordField
    {
        return $this->module->recordFields()->where('code', 'host')->sole();
    }

    private function parties(): RecordField
    {
        return $this->module->recordFields()->where('code', 'parties')->sole();
    }

    private function opened(): RecordModule
    {
        $pages = app(RecordPageQuery::class);

        return $pages->open($this->requester(), 'meetings', $this->today(), RecordModuleAction::Read);
    }

    private function namedUser(string $name): User
    {
        $user = $this->user();
        UserTermTranslation::factory()->create([
            'user_term_id' => UserTerm::query()->where('user_id', $user->id)->sole()->id,
            'locale_id' => Locale::query()->where('code', 'ja')->sole()->id,
            'name' => $name,
        ]);

        return $user;
    }

    private function requester(): Requester
    {
        return new Requester(null, null);
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::TODAY);
    }
}
