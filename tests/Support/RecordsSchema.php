<?php

namespace Tests\Support;

use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordFieldOption;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordReference;
use App\Models\Records\RecordRule;
use Illuminate\Database\Eloquent\Model;

/**
 * 業務データの入れ物の 6 表の設計どおりの列(docs の 設計/業務データの入れ物/データモデル/表.md 2 節。型は 1 節で採った共通の規則 4 節)。
 * 書き方は {@see CommonMasterSchema} と同じ(列 → PostgreSQL の format_type と NOT NULL・既定値。id・created_at・updated_at は SchemaTest が足す)。
 * 生成列は「generated always as (式) stored」と書く(式は pg_get_expr の形)。
 */
final class RecordsSchema
{
    private const CODE = 'character varying(100) not null';

    private const SORT_KEY = "bigint not null default '0'::bigint";

    private const FLAG = 'boolean not null default false';

    private const KEY = 'bigint not null';

    private const OPTIONAL_KEY = 'bigint';

    private const NAME = 'character varying(1000) not null';

    private const VALUE_LIST = 'character varying(20) not null';

    /** 表 → モデル(テストのデータを factory で作るため) */
    public const MODELS = [
        'record_modules' => RecordModule::class,
        'record_fields' => RecordField::class,
        'record_field_options' => RecordFieldOption::class,
        'record_rules' => RecordRule::class,
        'records' => Record::class,
        'record_references' => RecordReference::class,
    ];

    /**
     * 外部キー(書き方は tests/Feature/Models/ForeignKeysTest.php の FOREIGN_KEYS と同じ: 参照する側のモデル、factory の状態、列、参照先の表と列、
     * 削除の動作、参照する側のリレーション、参照先の側のリレーション)。参照先のモジュールから参照する側のモジュールへ依存できないとき・
     * 同じ向きのリレーションが別の行にあるときは参照先の側のリレーションを null にする。
     *
     * @var list<array{class-string<Model>, string|null, list<string>, string, list<string>, string, string, string|null}>
     */
    public const FOREIGN_KEYS = [
        [RecordModule::class, 'detail', ['parent_record_module_id'], 'record_modules', ['id'], 'cascade', 'parentRecordModule', 'childRecordModules'],
        [RecordField::class, null, ['record_module_id'], 'record_modules', ['id'], 'cascade', 'recordModule', 'recordFields'],
        [RecordField::class, 'reference', ['referenced_record_module_id'], 'record_modules', ['id'], 'restrict', 'referencedRecordModule', null],
        [RecordField::class, 'details', ['child_record_module_id', 'record_module_id'], 'record_modules', ['id', 'parent_record_module_id'], 'restrict', 'childRecordModule', null],
        [RecordFieldOption::class, null, ['record_field_id'], 'record_fields', ['id'], 'cascade', 'recordField', 'recordFieldOptions'],
        [RecordRule::class, null, ['record_module_id'], 'record_modules', ['id'], 'cascade', 'recordModule', 'recordRules'],
        [RecordRule::class, null, ['record_module_id', 'target_record_field_id'], 'record_fields', ['record_module_id', 'id'], 'restrict', 'targetRecordField', null],
        [RecordRule::class, 'compare', ['record_module_id', 'compared_record_field_id'], 'record_fields', ['record_module_id', 'id'], 'restrict', 'comparedRecordField', null],
        [Record::class, null, ['record_module_id'], 'record_modules', ['id'], 'restrict', 'recordModule', 'records'],
        [Record::class, 'detail', ['parent_record_module_id', 'parent_record_id'], 'records', ['record_module_id', 'id'], 'cascade', 'parentRecord', 'detailRecords'],
        [Record::class, 'detail', ['record_module_id', 'parent_record_module_id'], 'record_modules', ['id', 'parent_record_module_id'], 'restrict', 'recordModule', null],
        [Record::class, null, ['record_module_id', 'is_detail'], 'record_modules', ['id', 'is_detail'], 'restrict', 'recordModule', null],
        [Record::class, 'byUsers', ['created_by_user_id'], 'users', ['id'], 'restrict', 'createdByUser', null],
        [Record::class, 'byUsers', ['updated_by_user_id'], 'users', ['id'], 'restrict', 'updatedByUser', null],
        [RecordReference::class, null, ['record_module_id', 'record_id'], 'records', ['record_module_id', 'id'], 'cascade', 'record', 'recordReferences'],
        [RecordReference::class, null, ['record_module_id', 'record_field_id'], 'record_fields', ['record_module_id', 'id'], 'restrict', 'recordField', null],
        [RecordReference::class, 'forRecord', ['referenced_record_module_id', 'referenced_record_id'], 'records', ['record_module_id', 'id'], 'restrict', 'referencedRecord', null],
        [RecordReference::class, 'forRecord', ['record_field_id', 'referenced_record_module_id'], 'record_fields', ['id', 'referenced_record_module_id'], 'restrict', 'recordField', null],
        [
            RecordReference::class, null, ['record_field_id', 'is_multiple'],
            'record_fields', ['id', 'is_multiple'], 'restrict', 'recordField', null,
        ],
        [RecordReference::class, null, ['user_id'], 'users', ['id'], 'restrict', 'user', null],
        [RecordReference::class, 'forDepartment', ['department_id'], 'departments', ['id'], 'restrict', 'department', null],
    ];

    /** @var array<string, array<string, string>> */
    public const COLUMNS = [
        'record_modules' => [
            'code' => self::CODE,
            'name' => self::NAME,
            'description' => 'character varying(4000)',
            'parent_record_module_id' => self::OPTIONAL_KEY,
            'is_detail' => 'boolean not null generated always as (parent_record_module_id IS NOT NULL) stored',
            'sort_key' => self::SORT_KEY,
        ],
        'record_fields' => [
            'record_module_id' => self::KEY,
            'code' => self::CODE,
            'name' => self::NAME,
            'type' => self::VALUE_LIST,
            'is_required' => self::FLAG,
            'is_multiline' => self::FLAG,
            'max_length' => 'integer',
            'default_value' => 'jsonb',
            'referenced_record_module_id' => self::OPTIONAL_KEY,
            'child_record_module_id' => self::OPTIONAL_KEY,
            'is_listed' => self::FLAG,
            'is_searchable' => self::FLAG,
            'is_title' => self::FLAG,
            'sort_key' => self::SORT_KEY,
        ],
        'record_field_options' => ['record_field_id' => self::KEY, 'code' => self::CODE, 'name' => self::NAME, 'sort_key' => self::SORT_KEY],
        'record_rules' => [
            'record_module_id' => self::KEY,
            'kind' => self::VALUE_LIST,
            'target_record_field_id' => self::KEY,
            'condition' => 'jsonb',
            'operator' => 'character varying(20)',
            'compared_record_field_id' => self::OPTIONAL_KEY,
            'message' => 'character varying(1000)',
        ],
        'records' => [
            'record_module_id' => self::KEY,
            'parent_record_module_id' => self::OPTIONAL_KEY,
            'parent_record_id' => self::OPTIONAL_KEY,
            'is_detail' => 'boolean not null generated always as (parent_record_id IS NOT NULL) stored',
            'sort_key' => self::SORT_KEY,
            'field_values' => "jsonb not null default '{}'::jsonb",
            'created_by_user_id' => self::OPTIONAL_KEY,
            'updated_by_user_id' => self::OPTIONAL_KEY,
        ],
        'record_references' => [
            'record_module_id' => self::KEY,
            'record_id' => self::KEY,
            'record_field_id' => self::KEY,
            'referenced_record_module_id' => self::OPTIONAL_KEY,
            'referenced_record_id' => self::OPTIONAL_KEY,
            'user_id' => self::OPTIONAL_KEY,
            'department_id' => self::OPTIONAL_KEY,
        ],
    ];

    /**
     * 表を作った後のマイグレーションで足した列(表の末尾に付く。書き方は COLUMNS と同じ。データモデル/表.md 2.1、制約の分担と検査.md 5 節。
     * is_attachment_enabled = 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.8 節、
     * is_builtin = 設計/業務ツール/共通データ.md の「入れ物の拡張」)。
     *
     * @var array<string, array<string, string>>
     */
    public const COLUMNS_ADDED_LATER = [
        'record_modules' => [
            'icon' => 'character varying(100)',
            'row_access_scope' => "character varying(20) not null default 'all'::character varying",
            'is_attachment_enabled' => 'boolean not null default true',
            'is_builtin' => self::FLAG,
        ],
        'record_fields' => [
            'is_multiple' => self::FLAG,
            'grants_row_access' => self::FLAG,
            'is_builtin' => self::FLAG,
        ],
        'record_field_options' => [
            'is_builtin' => self::FLAG,
        ],
        'record_references' => [
            'sort_key' => self::SORT_KEY,
            'is_multiple' => self::FLAG,
        ],
    ];
}
