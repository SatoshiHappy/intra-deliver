<?php

namespace App\Queries\Records;

use App\Enums\Records\RecordFieldType;
use App\Models\Master\Department;
use App\Models\Master\DepartmentTerm;
use App\Models\Master\User;
use App\Models\Master\UserTerm;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordFieldOption;
use App\Models\Records\RecordReference;
use App\Queries\Authz\Api\Requester;
use App\Queries\Master\Api\MasterNames;
use App\Queries\Master\TermQuery;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use UnexpectedValueException;

/**
 * レコードの値を、画面に返す形(設計/業務データの入れ物/画面/README.md 1.2 の RecordFieldValue)で読む(docs の 設計/業務データの入れ物/項目と規則/README.md 5 節・
 * 設計/業務データの入れ物/データモデル/値の形と直列化.md 3 節)。
 *
 * - 通常の項目 = field_values の項目の id のキー(無ければ null。真偽は偽)。定義に無いキーは読まない。選択は {code, name}(選択肢に無いコードは name が null)。
 * - 参照 = {id, code, label, affiliation}。ユーザー・組織は d・ロケールの名称(無ければ null)とコード。他モジュール参照は参照先の表示の項目の値で、
 *   判定を求める者が参照先の業務モジュールの参照を許されていないか、参照先のレコードが行の判定を通らなければ null
 *   (code はいつも null。画面は #<id> を出す = 認可とメニュー/README.md 2.4 の「参照の表示名」)。複数選べる参照は相手ごとの値の配列
 *   (sort_key の順。相手が無ければ null)。affiliation はユーザーの主所属の組織の名前(画面/README.md 1.2。ほかの相手は null)。
 *
 * 参照の相手は {@see self::target()} の形(種類 = 項目の型の値 user・department・record と id)で扱い、更新履歴の版の写し(RecordSnapshots)と
 * 一時保存の入力(RecordFormQuery)の相手も同じ口で値にする。
 *
 * @phpstan-type ReferenceValue array{id: int, code: string|null, label: string|null, affiliation: string|null}
 * @phpstan-type ReferenceTarget array{type: string, id: int}
 * @phpstan-type RawValues array{values: array<string, mixed>, references: array<string, list<ReferenceTarget>>}
 */
class RecordValueReader
{
    public function __construct(
        private TermQuery $terms,
        private FieldValueParser $parser,
        private MasterNames $names,
        private ReferencedRecordLabels $recordLabels,
    ) {}

    /**
     * @param  Collection<int, Record>  $records  同じ業務モジュールのレコード
     * @param  Collection<int, RecordField>  $fields  返す項目(明細を除く。選択肢を読み込んでおく)
     * @param  Requester|null  $requester  参照先の表示名を判定する者(null = 判定できる主体が無い = 他モジュール参照の表示名を引かない)
     * @return array<int, array<string, mixed>> レコードの id → 項目のコード → 値
     */
    public function values(Collection $records, Collection $fields, ?Requester $requester, DateTimeInterface $date, string $localeCode): array
    {
        $references = RecordReference::query()->whereIn('record_id', $records->modelKeys())->whereIn('record_field_id', $fields->modelKeys())->orderBy('sort_key')->get();
        $labels = $this->targetValues(array_values($references->map(fn (RecordReference $reference) => $this->target($reference))->all()), $requester, $date, $localeCode);
        $values = [];

        foreach ($records as $record) {
            $values[$record->id] = $this->recordValues($record, $fields, $references->where('record_id', $record->id)->groupBy('record_field_id'), $labels);
        }

        return $values;
    }

    /**
     * @param  Collection<int, RecordField>  $fields
     * @param  \Illuminate\Support\Collection<array-key, Collection<int, RecordReference>>  $references  このレコードの参照の行(項目の id → sort_key の順の行)
     * @param  array<string, ReferenceValue>  $labels
     * @return array<string, mixed>
     */
    private function recordValues(Record $record, Collection $fields, \Illuminate\Support\Collection $references, array $labels): array
    {
        $fieldValues = $record->field_values ?? [];
        $raw = $this->rawValues(
            $fields,
            fn (RecordField $field) => $this->parser->stored($field, $fieldValues),
            fn (RecordField $field) => array_values(($references->get($field->id) ?? new Collection)->map(fn (RecordReference $row) => $this->target($row))->all()),
        );

        return $this->shown($fields, $raw, $labels);
    }

    /**
     * 項目ごとの読んだ値を組む(通常の項目 = $plainValue、参照の項目 = $referenceTarget の結果 = 相手の並び。相手が無ければ空)。レコードの値・
     * 一時保存の入力(RecordFormQuery)が同じ形に組む 1 か所。参照の相手をまとめて引くための帳簿は呼び手が $referenceTarget の中で持つ。
     *
     * @param  Collection<int, RecordField>  $fields
     * @param  callable(RecordField): mixed  $plainValue
     * @param  callable(RecordField): list<ReferenceTarget>  $referenceTarget
     * @return RawValues
     */
    public function rawValues(Collection $fields, callable $plainValue, callable $referenceTarget): array
    {
        $raw = ['values' => [], 'references' => []];

        foreach ($fields as $field) {
            if ($field->type->isReference()) {
                $raw['references'][$field->code] = $referenceTarget($field);
            } else {
                $raw['values'][$field->code] = $plainValue($field);
            }
        }

        return $raw;
    }

    /**
     * 項目ごとの読んだ値(通常の項目 = 保存された値の読み方で読んだ値、参照 = 相手の並び)を画面の値の形にする(複数選べる参照は相手ごとの値の配列、ほかは 1 つ。相手が無ければ null)(項目のコード → 値)。
     * レコードの値・一時保存の入力(RecordFormQuery)が同じ形にする 1 か所。相手の値が無い(今は無い相手)ときは
     * id を残して code・label を null にする(例外にしない)。
     *
     * @param  Collection<int, RecordField>  $fields
     * @param  RawValues  $raw
     * @param  array<string, ReferenceValue>  $labels  {@see self::targetValues()} の結果
     * @return array<string, mixed>
     */
    public function shown(Collection $fields, array $raw, array $labels): array
    {
        $shown = [];

        foreach ($fields as $field) {
            $targets = $raw['references'][$field->code] ?? [];
            $shown[$field->code] = match (true) {
                ! $field->type->isReference() => $this->plainValue($field, $raw['values'][$field->code] ?? null),
                $targets === [] => null,
                $field->is_multiple => array_map(fn (array $target) => $this->shownTarget($target, $labels), $targets),
                default => $this->shownTarget($targets[0], $labels),
            };
        }

        return $shown;
    }

    /**
     * 相手 1 つの画面の値(今は無い相手は id を残して code・label を null)。
     *
     * @param  ReferenceTarget  $target
     * @param  array<string, ReferenceValue>  $labels
     * @return ReferenceValue
     */
    private function shownTarget(array $target, array $labels): array
    {
        return $labels[$this->targetKey($target)]
            ?? ['id' => $target['id'], 'code' => null, 'label' => null, 'affiliation' => null];
    }

    /**
     * ユーザーの参照の値の形(作成者・更新者にも使う。所属は主所属の組織の名前)。
     *
     * @param  list<int>  $userIds
     * @return array<int, ReferenceValue> ユーザーの id → 値
     */
    public function users(array $userIds, DateTimeInterface $date, string $localeCode): array
    {
        if ($userIds === []) {
            return [];
        }

        $names = $this->terms->userTermsOn($userIds, $date, $localeCode)->map(fn (UserTerm $term) => $term->userTermTranslations->first()?->name)->all();
        $affiliations = $this->names->mainDepartmentNames($userIds, $date, $localeCode);

        return User::query()->whereKey($userIds)->get()
            ->mapWithKeys(fn (User $user) => [$user->id => [
                'id' => $user->id,
                'code' => $user->code,
                'label' => $names[$user->id] ?? null,
                'affiliation' => $affiliations[$user->id] ?? null,
            ]])
            ->all();
    }

    /**
     * 通常の項目の保存された値({@see FieldValueParser::stored()} で読んだもの)を画面の値の形にする(選択は {code, name})。
     */
    public function plainValue(RecordField $field, mixed $stored): mixed
    {
        return match ($field->type) {
            RecordFieldType::Choice => $stored === null ? null : [
                'code' => $stored,
                'name' => $field->recordFieldOptions->first(fn (RecordFieldOption $option) => $option->code === $stored)?->name,
            ],
            default => $stored,
        };
    }

    /**
     * 参照の行の相手(1 行は 1 つの相手だけを指す = 設計/業務データの入れ物/データモデル/表.md 2.6)。
     *
     * @return ReferenceTarget
     *
     * @throws UnexpectedValueException 相手を持たない行のとき(起きない)
     */
    public function target(RecordReference $reference): array
    {
        return match (true) {
            $reference->user_id !== null => ['type' => RecordFieldType::User->value, 'id' => $reference->user_id],
            $reference->department_id !== null => ['type' => RecordFieldType::Department->value, 'id' => $reference->department_id],
            $reference->referenced_record_id !== null => ['type' => RecordFieldType::Record->value, 'id' => $reference->referenced_record_id],
            default => throw new UnexpectedValueException("参照の行が相手を持たない(DB の検査制約が止める): {$reference->id}"),
        };
    }

    /**
     * 相手の鍵(「user:<id>」など。{@see self::targetValues()} の結果の鍵)。
     *
     * @param  ReferenceTarget  $target
     */
    public function targetKey(array $target): string
    {
        return "{$target['type']}:{$target['id']}";
    }

    /**
     * 相手ごとの値(鍵 = {@see self::targetKey()} → 値)。今は無い相手は鍵を持たない。
     *
     * @param  list<ReferenceTarget>  $targets
     * @param  Requester|null  $requester  null = 他モジュール参照の表示名を引かない({@see self::values()})
     * @return array<string, ReferenceValue>
     */
    public function targetValues(array $targets, ?Requester $requester, DateTimeInterface $date, string $localeCode): array
    {
        $idsOf = fn (RecordFieldType $type): array => array_values(array_unique(array_column(array_filter($targets, fn (array $target) => $target['type'] === $type->value), 'id')));
        $userIds = $idsOf(RecordFieldType::User);
        $departmentIds = $idsOf(RecordFieldType::Department);
        $recordIds = $idsOf(RecordFieldType::Record);
        $labels = [];

        foreach ($this->users($userIds, $date, $localeCode) as $id => $value) {
            $labels["user:{$id}"] = $value;
        }

        foreach ($this->departments($departmentIds, $date, $localeCode) as $id => $value) {
            $labels["department:{$id}"] = $value;
        }

        foreach ($this->recordLabels->of($recordIds, $requester, $date) as $id => $value) {
            $labels["record:{$id}"] = $value;
        }

        return $labels;
    }

    /**
     * 表示の項目の値(表示の項目が無い・値が文字列でなければ null)。
     */
    public function title(Record $record, ?RecordField $titleField): ?string
    {
        return $this->recordLabels->title($record, $titleField);
    }

    /**
     * @param  list<int>  $departmentIds
     * @return array<int, ReferenceValue>
     */
    private function departments(array $departmentIds, DateTimeInterface $date, string $localeCode): array
    {
        $names = $this->terms->departmentTermsOn($departmentIds, $date, $localeCode)->map(fn (DepartmentTerm $term) => $term->departmentTermTranslations->first()?->name)->all();

        return Department::query()->whereKey($departmentIds)->get()
            ->mapWithKeys(fn (Department $department) => [$department->id => [
                'id' => $department->id,
                'code' => $department->code,
                'label' => $names[$department->id] ?? null,
                'affiliation' => null,
            ]])
            ->all();
    }
}
