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
 */
class RecordFilters
{
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
     * @return array<string, string|array{from: string|null, to: string|null}>
     */
    public function apply(Builder $query, iterable $fields, mixed $filters): array
    {
        $applied = [];

        foreach ($fields as $field) {
            $value = is_array($filters) ? ($filters[$field->code] ?? null) : null;
            $interpreted = $field->type->isOrdered() ? $this->applyRange($query, $field, $value) : $this->applyMatch($query, $field, $this->text($value));

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
            $field->type->isReference() && preg_match('/^[1-9][0-9]{0,17}$/D', $value) === 1 => fn (Builder $query) => $query->whereExists(fn ($references) => $references
                ->from('record_references')
                ->whereColumn('record_references.record_id', 'records.id')
                ->where('record_references.record_field_id', $field->id)
                ->where($this->referenceColumn($field->type), (int) $value)),
            default => null,
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
     * 参照の型の、record_references の相手の列。
     */
    private function referenceColumn(RecordFieldType $type): string
    {
        return match ($type) {
            RecordFieldType::User => 'record_references.user_id',
            RecordFieldType::Department => 'record_references.department_id',
            default => 'record_references.referenced_record_id',
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
     * クエリの値の文字列(前後の空白を除き、空と文字列でないものは null)。
     */
    private function text(mixed $value): ?string
    {
        $text = is_string($value) ? Str::trim($value) : null;

        return $text === '' ? null : $text;
    }
}
