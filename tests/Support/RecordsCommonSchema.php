<?php

namespace Tests\Support;

use App\Models\Records\RecordAttachment;
use App\Models\Records\RecordComment;
use App\Models\Records\RecordCommentMention;
use App\Models\Records\RecordFieldHeading;
use App\Models\Records\RecordRelatedColumn;
use App\Models\Records\RecordRevision;
use Illuminate\Database\Eloquent\Model;

/**
 * レコードの共通機能が足した業務データの入れ物の 6 表の設計どおりの列と外部キー(docs の
 * 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.1・2.3〜2.7 節)。列を足した record_modules は
 * {@see RecordsSchema} の COLUMNS_ADDED_LATER。書き方は {@see RecordsSchema} と同じ。
 */
final class RecordsCommonSchema
{
    private const KEY = 'bigint not null';

    private const OPTIONAL_KEY = 'bigint';

    private const SORT_KEY = "bigint not null default '0'::bigint";

    private const TIMESTAMP = 'timestamp(0) with time zone';

    /** 表 → モデル(テストのデータを factory で作るため) */
    public const MODELS = [
        'record_revisions' => RecordRevision::class,
        'record_attachments' => RecordAttachment::class,
        'record_comments' => RecordComment::class,
        'record_comment_mentions' => RecordCommentMention::class,
        'record_field_headings' => RecordFieldHeading::class,
        'record_related_columns' => RecordRelatedColumn::class,
    ];

    /**
     * 外部キー(書き方は tests/Feature/Models/ForeignKeysTest.php の FOREIGN_KEYS と同じ)。参照先の側のリレーションは、
     * 参照先が既存のモデル・別のモジュールのモデルのときは null(逆向きのリレーションは使う業務処理が足す)。
     *
     * @var list<array{class-string<Model>, string|null, list<string>, string, list<string>, string, string,
     *     string|null}>
     */
    public const FOREIGN_KEYS = [
        [
            RecordRevision::class, null, ['record_module_id', 'record_id'],
            'records', ['record_module_id', 'id'], 'cascade', 'record', null,
        ],
        [
            RecordRevision::class, 'byUser', ['user_id'],
            'users', ['id'], 'restrict', 'user', null,
        ],
        [
            RecordRevision::class, 'byProxy', ['principal_user_id'],
            'users', ['id'], 'restrict', 'principalUser', null,
        ],
        [
            RecordAttachment::class, null, ['record_module_id', 'record_id'],
            'records', ['record_module_id', 'id'], 'restrict', 'record', null,
        ],
        [
            RecordAttachment::class, null, ['upload_id'],
            'uploads', ['id'], 'restrict', 'upload', null,
        ],
        [
            RecordAttachment::class, 'byUser', ['user_id'],
            'users', ['id'], 'restrict', 'user', null,
        ],
        [
            RecordComment::class, null, ['record_module_id', 'record_id'],
            'records', ['record_module_id', 'id'], 'cascade', 'record', null,
        ],
        [
            RecordComment::class, null, ['user_id'],
            'users', ['id'], 'restrict', 'user', null,
        ],
        [
            RecordComment::class, 'deleted', ['deleted_by_user_id'],
            'users', ['id'], 'restrict', 'deletedByUser', null,
        ],
        [
            RecordCommentMention::class, null, ['record_comment_id'],
            'record_comments', ['id'], 'cascade', 'recordComment', 'recordCommentMentions',
        ],
        [
            RecordCommentMention::class, null, ['user_id'],
            'users', ['id'], 'restrict', 'user', null,
        ],
        [
            RecordFieldHeading::class, null, ['record_module_id'],
            'record_modules', ['id'], 'cascade', 'recordModule', null,
        ],
        [
            RecordRelatedColumn::class, null, ['record_field_id'],
            'record_fields', ['id'], 'cascade', 'recordField', null,
        ],
        [
            RecordRelatedColumn::class, null, ['record_module_id', 'column_record_field_id'],
            'record_fields', ['record_module_id', 'id'], 'cascade', 'columnRecordField', null,
        ],
    ];

    /** @var array<string, array<string, string>> */
    public const COLUMNS = [
        'record_revisions' => [
            'record_module_id' => self::KEY,
            'record_id' => self::KEY,
            'revision' => 'integer not null',
            'action' => 'character varying(20) not null',
            'snapshot' => 'jsonb not null',
            'labels' => 'jsonb not null',
            'user_id' => self::OPTIONAL_KEY,
            'principal_user_id' => self::OPTIONAL_KEY,
            'origin_operation' => 'character varying(100)',
            'origin_node_name' => 'character varying(1000)',
        ],
        'record_attachments' => [
            'record_module_id' => self::KEY,
            'record_id' => self::KEY,
            'upload_id' => self::KEY,
            'name' => 'character varying(255) not null',
            'user_id' => self::OPTIONAL_KEY,
        ],
        'record_comments' => [
            'record_module_id' => self::KEY,
            'record_id' => self::KEY,
            'user_id' => self::KEY,
            'body' => 'character varying(4000)',
            'deleted_at' => self::TIMESTAMP,
            'deleted_by_user_id' => self::OPTIONAL_KEY,
        ],
        'record_comment_mentions' => ['record_comment_id' => self::KEY, 'user_id' => self::KEY],
        'record_field_headings' => [
            'record_module_id' => self::KEY,
            'name' => 'character varying(1000) not null',
            'sort_key' => self::SORT_KEY,
        ],
        'record_related_columns' => [
            'record_field_id' => self::KEY,
            'record_module_id' => self::KEY,
            'column_record_field_id' => self::KEY,
            'sort_key' => self::SORT_KEY,
        ],
    ];
}
