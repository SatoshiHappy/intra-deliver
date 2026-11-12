<?php

namespace Tests\Support;

use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordModuleAction;
use App\Models\Master\Department;
use App\Models\Master\DepartmentSet;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordFieldHeading;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordReference;
use App\Models\Records\RecordRowAccessViewer;
use App\Queries\Authz\Api\Requester;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;

/**
 * 写す時の確かめのテストの手助け(docs の 設計/業務データの入れ物/項目と規則/README.md の 5 節の「開く時の確かめ」の表、
 * 設計/業務データの入れ物/レコードの共通機能/複製.md の 2 節)。写す元の業務モジュール m_src の項目を、確かめの表の値の種類ごとに並べる
 * (見出しの前 = note・choice、見出し H の後 = user・dept・party・ref・secret・self、明細 lines は sort_key が最小でも欄の最後)。
 * 参照先 = m_ref(read あり。今の利用者が作った見えるレコード ok と、ほかの人が作った参照制限の hidden)・m_secret(read なし)。
 * 今日 = 2026-10-11。人と組織の有効の境界は {@see self::people()}。
 *
 * @phpstan-type CopyCheckFixture array{me: User, module: RecordModule, lines: RecordField, refOk: Record,
 *     refHidden: Record, secret: Record}
 */
trait BuildsCopyChecks
{
    use BuildsCommonMaster;
    use BuildsRecordModules;

    /**
     * @return CopyCheckFixture
     */
    protected function copyCheckFixture(): array
    {
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $me = $this->user();
        $all = [RecordModuleAction::Read, RecordModuleAction::Create, RecordModuleAction::Update];
        $ref = $this->recordModule('m_ref', $all);
        $this->field($ref, 'label', RecordFieldType::String, ['is_title' => true]);
        $secretModule = $this->recordModule('m_secret', [RecordModuleAction::Create]);
        $module = $this->recordModule('m_src', $all);
        $lines = $this->detailsField($module, 'lines', ['sort_key' => 5]);
        $this->field($module, 'note', RecordFieldType::String, ['sort_key' => 10]);
        $this->choiceField($module, 'choice', ['a', 'b'], ['sort_key' => 20]);
        RecordFieldHeading::factory()->create(['record_module_id' => $module->id, 'name' => 'H', 'sort_key' => 25]);
        $this->field($module, 'user', RecordFieldType::User, ['sort_key' => 30]);
        $this->field($module, 'dept', RecordFieldType::Department, ['sort_key' => 40]);
        $this->field($module, 'party', RecordFieldType::UserOrDepartment, ['sort_key' => 50, 'is_multiple' => true]);
        $this->referenceField($module, 'ref', $ref, ['sort_key' => 60]);
        $this->referenceField($module, 'secret', $secretModule, ['sort_key' => 70]);
        $this->referenceField($module, 'self', $module, ['sort_key' => 80]);
        $child = $this->childOf($lines);
        $this->field($child, 'who', RecordFieldType::User, ['sort_key' => 1]);
        $this->field($child, 'item', RecordFieldType::String, ['sort_key' => 2]);
        $this->referenceField($child, 'parent_ref', $module, ['sort_key' => 3]);
        $refOk = Record::factory()->create(['record_module_id' => $ref->id, 'created_by_user_id' => $me->id]);
        $refHidden = Record::factory()->create([
            'record_module_id' => $ref->id, 'created_by_user_id' => $this->user()->id,
        ]);
        RowAccessRestriction::restrict([$ref->id]);
        $secret = Record::factory()->create(['record_module_id' => $secretModule->id]);

        return [
            'me' => $me, 'module' => $module, 'lines' => $lines,
            'refOk' => $refOk, 'refHidden' => $refHidden, 'secret' => $secret,
        ];
    }

    /**
     * 今日の有効の境界の人(終わりが今日 = 無効・明日 = 有効、始まりが今日 = 有効・明日 = 無効、無効の旗 = 無効)。
     *
     * @return array{users: array<string, User>, departments: array<string, Department>}
     */
    protected function people(): array
    {
        $terms = [
            'endsToday' => [['2020-01-01', '2026-10-11', false]],
            'endsTomorrow' => [['2020-01-01', '2026-10-12', false]],
            'startsToday' => [['2026-10-11', null, false]],
            'startsTomorrow' => [['2026-10-12', null, false]],
            'disabled' => [['2020-01-01', null, true]],
        ];
        $set = DepartmentSet::factory()->create();

        return [
            'users' => array_map(fn (array $term) => $this->user($term), $terms),
            'departments' => array_map(fn (array $term) => $this->department($set, $term), $terms),
        ];
    }

    /**
     * 判定を求める者(今の利用者)。
     *
     * @param  CopyCheckFixture  $fixture
     */
    protected function requesterOf(array $fixture): Requester
    {
        return new Requester($fixture['me']->id, null);
    }

    /**
     * レコードの参照の値の行(項目のコードで。相手 = ユーザー・組織・レコード)。
     */
    protected function referTo(Record $record, string $code, User|Department|Record $target, int $sortKey = 0): void
    {
        $field = RecordField::query()->where('record_module_id', $record->record_module_id)
            ->where('code', $code)->sole();
        RecordReference::factory()->create([
            'record_module_id' => $record->record_module_id,
            'record_id' => $record->id,
            'record_field_id' => $field->id,
            'is_multiple' => $field->is_multiple,
            'sort_key' => $sortKey,
            'user_id' => $target instanceof User ? $target->id : null,
            'department_id' => $target instanceof Department ? $target->id : null,
            'referenced_record_module_id' => $target instanceof Record ? $target->record_module_id : null,
            'referenced_record_id' => $target instanceof Record ? $target->id : null,
        ]);
    }

    /**
     * 参照制限の参照者の行(チェックを付ける)。
     *
     * @param  list<User|Department>  $viewers
     */
    protected function restrictTo(Record $record, array $viewers): void
    {
        $record->update(['row_access_restricted' => true]);

        foreach ($viewers as $index => $viewer) {
            RecordRowAccessViewer::factory()->create([
                'record_id' => $record->id,
                'user_id' => $viewer instanceof User ? $viewer->id : null,
                'department_id' => $viewer instanceof Department ? $viewer->id : null,
                'sort_key' => $index,
            ]);
        }
    }

    /**
     * 画面の値から相手の種類と id だけを取る(表示名は見ない。Inertia の props の確かめが渡す Collection も読む)。
     *
     * @return list<array{type: mixed, id: mixed}>|array{id: mixed}|null
     */
    protected static function targetsOf(mixed $value): ?array
    {
        $value = $value instanceof Arrayable ? $value->toArray() : $value;

        if (! is_array($value)) {
            return null;
        }

        return array_is_list($value)
            ? array_map(fn (array $item) => ['type' => $item['type'] ?? null, 'id' => $item['id']], $value)
            : ['id' => $value['id']];
    }
}
