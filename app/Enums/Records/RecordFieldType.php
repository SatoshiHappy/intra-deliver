<?php

namespace App\Enums\Records;

/**
 * 項目の型(docs の 設計/業務データの入れ物/項目と規則/README.md 1 節の 12 種。表 record_fields の type)。値は DB の検査制約と同じ一覧。
 * 文字列の 1 行と複数行は 1 つの型で、属性 is_multiline で分ける。
 */
enum RecordFieldType: string
{
    /** 文字列(1 行・複数行) */
    case String = 'string';

    /** 整数 */
    case Integer = 'integer';

    /** 小数(値は文字列で持つ = 設計/業務データの入れ物/データモデル/値の形と直列化.md 3 節) */
    case Decimal = 'decimal';

    /** 日付 */
    case Date = 'date';

    /** 日時(値は UTC の文字列で持つ) */
    case Datetime = 'datetime';

    /** 真偽(いつも値を持つので必須を持たない) */
    case Boolean = 'boolean';

    /** 選択(単一。値は選択肢のコード) */
    case Choice = 'choice';

    /** ユーザー参照(値は record_references) */
    case User = 'user';

    /** 組織参照(値は record_references) */
    case Department = 'department';

    /** ユーザーと組織(値は record_references。相手ごとに種類 user・department と id を持つ = ADR 0028) */
    case UserOrDepartment = 'user_or_department';

    /** 他モジュール参照(値は record_references) */
    case Record = 'record';

    /** 明細(値は子の業務モジュールのレコード) */
    case Details = 'details';

    /**
     * 値を record_references に置く型(ユーザー・組織・ユーザーと組織・他モジュール参照)。
     */
    public function isReference(): bool
    {
        return in_array($this, [self::User, self::Department, self::UserOrDepartment, self::Record], true);
    }

    /**
     * 値の相手が種類(ユーザーか組織か)を持つ型(ユーザーと組織。相手 = {type: 'user'|'department', id} = 項目と規則/README.md 2 節)。
     * ほかの参照の型の相手は id だけで、種類は項目の型で決まる。
     */
    public function hasPartyKind(): bool
    {
        return $this === self::UserOrDepartment;
    }

    /**
     * 値を field_values に置く型(参照と明細を除く。既定値を持てる型と同じ = 項目と規則/README.md 1 節・3 節)。
     */
    public function isPlain(): bool
    {
        return ! $this->isReference() && $this !== self::Details;
    }

    /**
     * 大小を比べられる型(相関チェックの対象・相手、一覧の範囲の検索 = 項目と規則/README.md 7 節・項目と規則/規則と業務処理/README.md 8.1)。
     */
    public function isOrdered(): bool
    {
        return in_array($this, [self::Integer, self::Decimal, self::Date, self::Datetime], true);
    }
}
