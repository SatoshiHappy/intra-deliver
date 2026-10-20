<?php

namespace Tests\Support;

use App\Models\Upload\Upload;
use Illuminate\Database\Eloquent\Model;

/**
 * アップロードの台帳 uploads の設計どおりの列・外部キー(docs の 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.2 節)。
 * 書き方は {@see NoticeSchema} と同じ。
 */
final class UploadSchema
{
    /** 表 → モデル(テストのデータを factory で作るため) */
    public const MODELS = [
        'uploads' => Upload::class,
    ];

    /**
     * 表 → 列の定義(列の順)。
     *
     * @var array<string, array<string, string>>
     */
    public const COLUMNS = [
        'uploads' => [
            'path' => 'character varying(100) not null',
            'size' => 'bigint not null',
            'mime' => 'character varying(100) not null',
            'user_id' => 'bigint',
            'purged_at' => 'timestamp(0) with time zone',
            'purge_due_on' => 'date',
        ],
    ];

    /**
     * 外部キー(書き方は tests/Feature/Models/ForeignKeysTest.php の FOREIGN_KEYS と同じ)。共通マスタの側は台帳に依存できないので、
     * 参照先の側のリレーションは null。
     *
     * @var list<array{class-string<Model>, string|null, list<string>, string, list<string>, string, string,
     *     string|null}>
     */
    public const FOREIGN_KEYS = [
        [Upload::class, 'byUser', ['user_id'], 'users', ['id'], 'restrict', 'user', null],
    ];
}
