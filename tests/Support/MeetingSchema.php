<?php

namespace Tests\Support;

use App\Models\Meeting\MeetingRun;
use App\Models\Meeting\Transcript;
use App\Models\Meeting\TranscriptSegment;
use Illuminate\Database\Eloquent\Model;

/**
 * 会議の 3 表(書き起こし・発言の行・会議と AI の実行の対応)の設計どおりの列と外部キー(docs の 設計/業務ツール/会議/README.md の 2 節、
 * 削除時の振る舞い = 設計/業務ツール/共通データ.md の「専用の表」)。書き方は {@see NoticeSchema} と同じ(id・created_at・updated_at は SchemaTest が足す)。
 */
final class MeetingSchema
{
    private const KEY = 'bigint not null';

    /** 表 → モデル */
    public const MODELS = [
        'transcripts' => Transcript::class,
        'transcript_segments' => TranscriptSegment::class,
        'meeting_runs' => MeetingRun::class,
    ];

    /**
     * 表 → 列の定義(列の順)。
     *
     * @var array<string, array<string, string>>
     */
    public const COLUMNS = [
        'transcripts' => [
            'record_id' => self::KEY,
            'source' => 'character varying(16) not null',
            'file_name' => 'character varying(255)',
            'language' => 'character varying(8) not null',
            'ai_run_id' => 'bigint',
            'chunk_count' => 'integer not null default 1',
        ],
        'transcript_segments' => [
            'transcript_id' => self::KEY,
            'seq' => 'integer not null',
            'speaker_label' => 'character varying(100)',
            'started_at_sec' => 'numeric(10,3)',
            'ended_at_sec' => 'numeric(10,3)',
            'text' => 'text not null',
        ],
        'meeting_runs' => [
            'record_id' => self::KEY,
            'ai_run_id' => 'bigint',
            'stage' => 'character varying(16) not null',
            'origin' => 'character varying(16) not null',
            'writes_title' => 'boolean not null',
            'declined_code' => 'character varying(16)',
            'requester_user_id' => 'bigint',
        ],
    ];

    /**
     * 外部キー(書き方は tests/Feature/Models/ForeignKeysTest.php の FOREIGN_KEYS と同じ)。会議のレコード・AI の実行・利用者の側は
     * 後ろの Meeting に依存できないので、参照先の側のリレーションは null。
     *
     * @var list<array{class-string<Model>, string|null, list<string>, string, list<string>, string, string,
     *     string|null}>
     */
    public const FOREIGN_KEYS = [
        [Transcript::class, null, ['record_id'], 'records', ['id'], 'cascade', 'record', null],
        [Transcript::class, 'audio', ['ai_run_id'], 'ai_runs', ['id'], 'restrict', 'aiRun', null],
        [TranscriptSegment::class, null, ['transcript_id'], 'transcripts', ['id'], 'cascade', 'transcript', 'segments'],
        [MeetingRun::class, null, ['record_id'], 'records', ['id'], 'cascade', 'record', null],
        [MeetingRun::class, null, ['ai_run_id'], 'ai_runs', ['id'], 'restrict', 'aiRun', null],
        [MeetingRun::class, 'declined', ['requester_user_id'], 'users', ['id'], 'restrict', 'requester', null],
    ];
}
