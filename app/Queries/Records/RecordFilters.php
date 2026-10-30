<?php

namespace App\Queries\Records;

use App\Enums\Records\RecordFieldType;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use LogicException;

/**
 * 一覧の検索条件(docs の 設計/業務データの入れ物/項目と規則/README.md 7 節、クエリの形は 画面/業務の画面.md 2.1)。
 * 参照の相手の書き方 = id の 10 進の数字(型「ユーザーと組織」は `user:<id>`・`department:<id>`)。複数を選べる項目の条件は相手の並び
 * (クエリの配列。添字の形 `filter[<コード>][<n>]`)で、選んだ相手を全部値に持つレコードに当てる(7 節のデシジョン表は {@see self::applyAll()})。
 */
class RecordFilters
{
    /** 複数を選べる項目の検索条件で選べる相手の数の上限(7 節) */
    private const MAX_SEARCH_REFERENCE_TARGETS = 100;

    /** 参照の相手の id の書き方(正の整数の 10 進。bigint に収まる 18 桁まで) */
    private const ID_PATTERN = '[1-9][0-9]{0,17}';

    /**
     * 真偽の項目の値の SQL の式(キーが無ければ偽 = 設計/業務データの入れ物/データモデル/値の形と直列化.md 3 節。PHP の側の同じ読み方は {@see FieldValueParser::stored()})。
     * 束縛 1 つ = 項目の id
     */
    public const BOOLEAN_SQL = 'COALESCE((records.field_values ->> ?)::boolean, false)';

    public function __construct(private FieldValueParser $parser) {}

    /**
     * 検索条件を問い合わせに掛け、解釈できた検索条件(画面/業務の画面.md 2.1 の query.filters。送られた値のまま)を返す。
     *
     * @param  Builder<Record>  $query
     * @param  iterable<RecordField>  $fields  検索条件にする項目(選択肢を読み込んでおく)
     * @return array<string, string|list<string>|array{from: string|null, to: string|null}>
     */
    public function apply(Builder $query, iterable $fields, mixed $filters): array
    {
        $applied = [];

        foreach ($fields as $field) {
            $value = is_array($filters) ? ($filters[$field->code] ?? null) : null;
            $interpreted = match (true) {
                $field->type->isOrdered() => $this->applyRange($query, $field, $value),
                $field->is_multiple => $this->applyAll($query, $field, $value),
                default => $this->applyMatch($query, $field, $this->text($value)),
            };

            if ($interpreted !== null) {
                $applied[$field->code] = $interpreted;
            }
        }

        return $applied;
    }

    /**
     * 一致・部分一致の条件。解釈できれば掛けて値を返す。
     *
     * @param  Builder<Record>  $query
     */
    private function applyMatch(Builder $query, RecordField $field, ?string $value): ?string
    {
        $condition = $value === null ? null : $this->matchCondition($field, $value);

        if ($condition === null) {
            return null;
        }

        $query->where($condition);

        return $value;
    }

    /**
     * 一致・部分一致の条件を掛ける関数(型に合わなければ null)。
     *
     * @return (Closure(Builder<Record>): mixed)|null
     */
    private function matchCondition(RecordField $field, string $value): ?Closure
    {
        $key = (string) $field->id;

        return match (true) {
            $field->type === RecordFieldType::String => fn (Builder $query) => $query->whereRaw('(records.field_values ->> ?) ILIKE ?', [$key, '%'.addcslashes($value, '\\%_').'%']),
            $field->type === RecordFieldType::Boolean && $value === 'true' => fn (Builder $query) => $query->whereRaw(self::BOOLEAN_SQL.' = true', [$key]),
            $field->type === RecordFieldType::Boolean && $value === 'false' => fn (Builder $query) => $query->whereRaw(self::BOOLEAN_SQL.' = false', [$key]),
            $field->type === RecordFieldType::Choice && $this->parsed($field, $value) !== null => fn (Builder $query) => $query->whereRaw('(records.field_values ->> ?) = ?', [$key, $value]),
            $field->type->isReference() => $this->referenceCondition($field, $value),
            default => null,
        };
    }

    /**
     * 複数を選べる項目の条件「全部を含む」。選んだ相手ごとに EXISTS を重ね、読めない並びは読めた相手だけで絞らずに指定なしにする
     * (利用者が選んだより広い条件で黙って当てないため)。行の結果 = docs の 設計/業務データの入れ物/項目と規則/README.md 7 節のデシジョン表。
     *
     * @param  Builder<Record>  $query
     * @return list<string>|null
     */
    private function applyAll(Builder $query, RecordField $field, mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === [] || count($value) > self::MAX_SEARCH_REFERENCE_TARGETS) {
            return null;
        }

        $texts = [];
        $conditions = [];

        foreach ($value as $element) {
            $text = $this->text($element);
            $condition = $text === null ? null : $this->referenceCondition($field, $text);

            if ($text === null || $condition === null || in_array($text, $texts, true)) {
                return null;
            }

            $texts[] = $text;
            $conditions[] = $condition;
        }

        foreach ($conditions as $condition) {
            $query->where($condition);
        }

        return $texts;
    }

    /**
     * 参照の相手 1 つを値に持つレコードに当てる関数(相手として読めなければ null)。相手は種類と id で比べ、組織を選んでも所属する
     * ユーザーの値には当たらない(値の組織そのものとだけ比べる = 7 節)。
     *
     * @return (Closure(Builder<Record>): mixed)|null
     */
    private function referenceCondition(RecordField $field, string $value): ?Closure
    {
        $target = $this->referenceTarget($field, $value);

        return $target === null ? null : fn (Builder $query) => $query->whereExists(fn ($references) => $references
            ->from('record_references')
            ->whereColumn('record_references.record_id', 'records.id')
            ->where('record_references.record_field_id', $field->id)
            ->where($target[0], $target[1]));
    }

    /**
     * 参照の相手の書き方を、record_references の相手の列と id にする(読めなければ null)。型ごとの分岐は既定の枝に落とさず全部の型を挙げる
     * (新しい型を足したら静的解析が漏れを知らせる)。
     *
     * @return array{string, int}|null
     *
     * @throws LogicException 参照でない型のとき(呼び手が参照の項目だけを渡す)
     */
    private function referenceTarget(RecordField $field, string $value): ?array
    {
        $id = preg_match('/^'.self::ID_PATTERN.'$/D', $value) === 1 ? (int) $value : null;
        $party = preg_match('/^(user|department):('.self::ID_PATTERN.')$/D', $value, $parts) === 1 ? $parts : null;

        return match ($field->type) {
            RecordFieldType::User => $id === null ? null : ['record_references.user_id', $id],
            RecordFieldType::Department => $id === null ? null : ['record_references.department_id', $id],
            RecordFieldType::Record => $id === null ? null : ['record_references.referenced_record_id', $id],
            RecordFieldType::UserOrDepartment => $party === null ? null : ["record_references.{$party[1]}_id", (int) $party[2]],
            RecordFieldType::String,
            RecordFieldType::Integer,
            RecordFieldType::Decimal,
            RecordFieldType::Date,
            RecordFieldType::Datetime,
            RecordFieldType::Boolean,
            RecordFieldType::Choice,
            RecordFieldType::Details => throw new LogicException("参照でない型の相手は読まない: {$field->code}"),
        };
    }

    /**
     * 範囲の条件。片側ずつ型に合えば掛け、どちらかを掛けたら送られた値を返す。
     *
     * @param  Builder<Record>  $query
     * @return array{from: string|null, to: string|null}|null
     */
    private function applyRange(Builder $query, RecordField $field, mixed $value): ?array
    {
        $range = ['from' => null, 'to' => null];

        foreach (['from' => '>=', 'to' => '<='] as $side => $operator) {
            $text = $this->text(is_array($value) ? ($value[$side] ?? null) : null);
            $parsed = $this->parsed($field, $text);

            if ($parsed !== null) {
                $sqlType = self::sqlType($field->type);
                $query->whereRaw("(records.field_values ->> ?)::{$sqlType} {$operator} CAST(? AS {$sqlType})", [(string) $field->id, (string) $parsed]);
                $range[$side] = $text;
            }
        }

        return $range === ['from' => null, 'to' => null] ? null : $range;
    }

    /**
     * 大小を比べる型の値を比べる SQL の型(設計/業務データの入れ物/データモデル/値の形と直列化.md 3 節)。
     *
     * @return 'bigint'|'numeric'|'date'|'timestamptz'
     */
    public static function sqlType(RecordFieldType $type): string
    {
        return match ($type) {
            RecordFieldType::Integer => 'bigint',
            RecordFieldType::Decimal => 'numeric',
            RecordFieldType::Date => 'date',
            RecordFieldType::Datetime => 'timestamptz',
            default => throw new LogicException("大小を比べない型: {$type->value}"),
        };
    }

    /**
     * 型に合えば正規の形の値、合わなければ null。
     */
    private function parsed(RecordField $field, ?string $text): string|int|bool|null
    {
        try {
            return $this->parser->parse($field, $text);
        } catch (InvalidFieldValue) {
            return null;
        }
    }

    /**
     * クエリの値の文字列(前後の空白を除き、空と文字列でないものは null)。UTF-8 として正しくない値も、型に合わない値と同じに null にする
     * (DB に渡すと誤りになるため)。
     */
    private function text(mixed $value): ?string
    {
        $text = is_string($value) && mb_check_encoding($value, 'UTF-8') ? Str::trim($value) : null;

        return $text === '' ? null : $text;
    }
}
