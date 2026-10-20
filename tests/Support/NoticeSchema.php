<?php

namespace Tests\Support;

use App\Models\Notice\Notice;
use Illuminate\Database\Eloquent\Model;

/**
 * 通知の表 notices の設計どおりの列・外部キー(docs の 設計/ワークフロー/案件の運用/通知.md の 2 節)。
 * 列の書き方は {@see WorkflowSchema} と同じ(列 → PostgreSQL の format_type と NOT NULL・既定値。
 * id・created_at・updated_at は SchemaTest が足す)。
 */
final class NoticeSchema
{
    /** 表 → モデル(テストのデータを factory で作るため) */
    public const MODELS = [
        'notices' => Notice::class,
    ];

    /**
     * 表 → 列の定義(列の順)。
     *
     * @var array<string, array<string, string>>
     */
    public const COLUMNS = [
        'notices' => [
            'user_id' => 'bigint not null',
            'type' => 'character varying(100) not null',
            'target_type' => 'character varying(100)',
            'target_id' => 'bigint',
            'actor_user_id' => 'bigint',
            'params' => 'jsonb not null',
            'read_at' => 'timestamp(0) with time zone',
        ],
    ];

    /**
     * 外部キー(書き方は tests/Feature/Models/ForeignKeysTest.php の FOREIGN_KEYS と同じ)。参照先の共通マスタは後ろの通知に依存できないので、
     * 参照先の側のリレーションは null。
     *
     * @var list<array{class-string<Model>, string|null, list<string>, string, list<string>, string, string,
     *     string|null}>
     */
    public const FOREIGN_KEYS = [
        [Notice::class, null, ['user_id'], 'users', ['id'], 'restrict', 'user', null],
        [Notice::class, 'withActor', ['actor_user_id'], 'users', ['id'], 'restrict', 'actor', null],
    ];
}
