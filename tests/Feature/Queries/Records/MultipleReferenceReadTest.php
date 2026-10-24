<?php

namespace Tests\Feature\Queries\Records;

use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordModuleAction;
use App\Models\Master\Locale;
use App\Models\Master\User;
use App\Models\Master\UserTerm;
use App\Models\Master\UserTermTranslation;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordReference;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordConditions;
use App\Queries\Records\Api\RecordDisplayQuery;
use App\Queries\Records\Api\RecordFormQuery;
use App\Queries\Records\RecordListQuery;
use App\Queries\Records\RecordPageQuery;
use App\Queries\Records\RecordQuery;
use Carbon\CarbonImmutable;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 複数選べる参照の読み取り(docs の 設計/業務データの入れ物/項目と規則/README.md 5 節の読むときの規則・7 節の検索の「含む」、画面/README.md 1.2 の
 * RecordFieldValue、設計/ワークフロー/業務データとの接続/申請と書き込み.md 2 節の一時保存の入力): 一覧・詳細・編集の初期値・公開の口が、相手の配列を
 * sort_key の順で返し、無ければ null。
 */
final class MultipleReferenceReadTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRecordModules;

    private RecordField $members;

    protected function setUp(): void
    {
        parent::setUp();
        $module = $this->recordModule('projects');
        $listed = ['is_multiple' => true, 'is_listed' => true, 'is_searchable' => true];
        $this->members = $this->field($module, 'members', RecordFieldType::User, $listed);
        $this->field($module, 'reviewers', RecordFieldType::Department, ['is_multiple' => true, 'is_listed' => true]);
    }

    public function test_every_reader_returns_the_targets_in_the_order_of_sort_key(): void
    {
        $active = $this->namedUser('山田 太郎', [['2020-01-01', null]]);
        $retired = $this->namedUser('鈴木 花子', [['2020-01-01', '2026-04-01']]);
        $record = Record::factory()->create(['record_module_id' => $this->members->record_module_id]);
        $this->refer($record, $retired, 1);
        $this->refer($record, $active, 0);
        $expected = [
            'members' => [
                ['id' => $active->id, 'code' => $active->code, 'label' => '山田 太郎', 'affiliation' => null],
                ['id' => $retired->id, 'code' => $retired->code, 'label' => null, 'affiliation' => null],
            ],
            'reviewers' => null,
        ];

        foreach ($this->readers($record->id) as $label => $values) {
            $this->assertSame($expected, $values, $label);
        }
    }

    public function test_draft_values_read_only_integer_elements_of_an_array(): void
    {
        $user = $this->user();
        $shown = fn (mixed $members) => $this->draftValues(['members' => $members])['members'];

        $expected = [['id' => $user->id, 'code' => $user->code, 'label' => null, 'affiliation' => null]];

        $this->assertSame($expected, $shown(['x', $user->id, 1.5]));
        $this->assertNull($shown($user->id));
        $this->assertNull($shown([]));
        $this->assertNull($shown(['x']));
    }

    public function test_draft_values_drop_a_repeated_target_keeping_the_first_position(): void
    {
        [$first, $second] = [$this->user(), $this->user()];

        $shown = $this->draftValues(['members' => [$second->id, $first->id, $second->id]])['members'];

        $this->assertSame([$second->id, $first->id], array_column($shown, 'id'));
    }

    public function test_a_user_target_has_the_name_of_the_main_department_as_its_affiliation(): void
    {
        $manager = User::query()->where('code', 'u0009')->sole();
        $record = Record::factory()->create(['record_module_id' => $this->members->record_module_id]);
        $this->refer($record, $manager, 0);
        $this->refer($record, $this->user(), 1);
        $drafted = $this->draftValues(['members' => [$manager->id]])['members'];

        foreach ($this->readers($record->id) as $label => $values) {
            $this->assertSame(['総務部', null], array_column($values['members'], 'affiliation'), $label);
        }
        $this->assertSame(['総務部'], array_column($drafted, 'affiliation'));
    }

    public function test_the_search_finds_records_that_hold_the_target_among_their_values(): void
    {
        [$a, $b, $c] = [$this->user(), $this->user(), $this->user()];
        $first = Record::factory()->create(['record_module_id' => $this->members->record_module_id]);
        $second = Record::factory()->create(['record_module_id' => $this->members->record_module_id]);
        $this->refer($first, $a, 0);
        $this->refer($first, $b, 1);
        $this->refer($second, $c, 0);

        $this->assertSame([$first->id], $this->searched((string) $b->id));
        $this->assertSame([$second->id], $this->searched((string) $c->id));
        $this->assertSame([], $this->searched((string) $this->user()->id));
    }

    public function test_a_condition_reads_no_targets_as_empty(): void
    {
        $empty = Record::factory()->create(['record_module_id' => $this->members->record_module_id]);
        $held = Record::factory()->create(['record_module_id' => $this->members->record_module_id]);
        $this->refer($held, $this->user(), 0);
        $holds = fn (Record $record, string $operator) => app(RecordConditions::class)
            ->matches($record->id, ['all' => [['field' => 'members', 'op' => $operator]]]);

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
        $opened = app(RecordPageQuery::class)->open($requester, 'projects', $date, RecordModuleAction::Read);
        $listed = app(RecordListQuery::class)->list($requester, $opened, [], null, null, $date, 'ja');
        $records = app(RecordQuery::class);
        $forms = app(RecordFormQuery::class);
        $display = app(RecordDisplayQuery::class);

        return [
            '詳細' => $records->show($requester, $opened, $recordId, $date, 'ja')['values'],
            '編集' => $records->editable($requester, $opened, $recordId, $date, 'ja')['values'],
            '一覧' => $listed['records']['data'][0]['values'],
            'editValues' => $forms->editValues($requester, 'projects', $recordId, $date, 'ja')['values'],
            'RecordDisplayQuery::show' => $display->show($requester, $recordId, $date, 'ja')['record']['values'],
        ];
    }

    /**
     * members の検索条件で当たったレコードの id。
     *
     * @return list<int>
     */
    private function searched(string $userId): array
    {
        $requester = $this->requester();
        $opened = app(RecordPageQuery::class)->open($requester, 'projects', $this->today(), RecordModuleAction::Read);
        $filters = ['members' => $userId];
        $listed = app(RecordListQuery::class)->list($requester, $opened, $filters, null, null, $this->today(), 'ja');

        return array_column($listed['records']['data'], 'id');
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
        $draft = $forms->draftValues($this->requester(), 'projects', $values, [], $this->today(), 'ja');

        return $draft['values'];
    }

    private function refer(Record $record, User $user, int $sortKey): void
    {
        RecordReference::factory()->create([
            'record_module_id' => $record->record_module_id,
            'record_id' => $record->id,
            'record_field_id' => $this->members->id,
            'user_id' => $user->id,
            'sort_key' => $sortKey,
            'is_multiple' => true,
        ]);
    }

    /**
     * 名前(ja)を持つユーザー。
     *
     * @param  list<array{0: string, 1: string|null}>  $terms
     */
    private function namedUser(string $name, array $terms): User
    {
        $user = $this->user($terms);
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
