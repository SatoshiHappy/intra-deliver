<?php

namespace Tests\Architecture\Policy;

use Tests\Architecture\Engine\PhpStan\SideEffectsRule;

/**
 * 副作用の種類・判定する呼び出し・台帳②に載せられる場所の上限(docs の 設計/コード構成/README.md 4 節)。
 *
 * 場所の正本は台帳②(config/guardrails_allow.php の capabilities)。判定本体は Engine の
 * {@see SideEffectsRule}(phpstan.neon が CALLS と台帳②を渡す)。
 * 台帳②のキーが KINDS と一致し、場所が上限に収まることは tests/Architecture/CodeLayoutTest.php が照合する。
 *
 * 判定する呼び出し = 型(クラス・interface・trait。派生・実装・利用を含む)ごとのメソッド名(* はコンストラクタを除くすべてのメソッド。
 * `new` は __construct で、生成そのものが副作用になる型にだけ明示する = SplFileObject)と、
 * 関数名(バッククォートの演算子は shell_exec と同じ)。インスタンスのメソッド呼び出しは受け手の型、静的呼び出しはクラスで判定する。
 * 原則 = 呼び出しの名前と、静的に決まる型で判定する。引数の中身(SQL・URL・コマンド名・開くモード)と、型が静的に決まらない呼び出しは見ない。
 * このため、名前が読み取りの呼び出し(select など)に書き込みの SQL を渡すこと・Artisan::call・URL を渡した file_get_contents・
 * 高階のプロキシ(each->delete())は止まらず、名前が書き込み・保存の呼び出し(fopen など)は開くモードに依らず数える。
 * イベントの発行(event()・Event::dispatch()・broadcast())は 7 種のどれでもない(キューに入る listener があればキューへの投入になるが、静的には決まらない)。
 * セッション・キャッシュ・ログも数えない(コード構成 4 節)。
 */
final class SideEffects
{
    /** 副作用の種類(コード構成 4 節の 7 種。台帳②のキー) */
    public const KINDS = ['dbWrite', 'queue', 'notification', 'fileStorage', 'ai', 'http', 'process'];

    /**
     * 台帳②に載せられる場所の上限(種類 → 名前空間。その名前空間と配下)。DB への書き込みは業務処理だけ。
     * ほかの副作用は業務処理と、キューのジョブ・通知・コマンドの置き場(置き場を足すときにコード構成を改訂してここに足す。
     * ジョブ・コマンドはモジュール Ai で足した。通知の置き場はまだ無い)。
     *
     * @var array<string, list<string>>
     */
    public const UPPER_BOUNDS = [
        'dbWrite' => [LayerRules::ACTIONS],
        'queue' => [LayerRules::ACTIONS, LayerRules::JOBS, LayerRules::CONSOLE],
        'notification' => [LayerRules::ACTIONS, LayerRules::JOBS, LayerRules::CONSOLE],
        'fileStorage' => [LayerRules::ACTIONS, LayerRules::JOBS, LayerRules::CONSOLE],
        'ai' => [LayerRules::ACTIONS, LayerRules::JOBS, LayerRules::CONSOLE],
        'http' => [LayerRules::ACTIONS, LayerRules::JOBS, LayerRules::CONSOLE],
        'process' => [LayerRules::ACTIONS, LayerRules::JOBS, LayerRules::CONSOLE],
    ];

    /** DB への書き込みとトランザクション(接続・DB のファサードに共通のメソッド) */
    private const CONNECTION_WRITES = ['insert', 'update', 'delete', 'statement', 'unprepared', 'affectingStatement', 'transaction', 'beginTransaction', 'commit', 'rollBack'];

    /** クエリビルダの書き込み(Eloquent のモデル・ビルダからも転送される) */
    private const QUERY_WRITES = [
        'insert', 'insertOrIgnore', 'insertOrIgnoreReturning', 'insertGetId', 'insertUsing', 'insertOrIgnoreUsing',
        'update', 'updateFrom', 'updateOrInsert', 'upsert', 'increment', 'incrementEach', 'decrement', 'decrementEach', 'delete', 'truncate',
    ];

    /** Eloquent のビルダの書き込み(モデルの静的呼び出しからも転送される) */
    private const ELOQUENT_WRITES = [
        'create', 'createQuietly', 'createOrFirst', 'forceCreate', 'forceCreateQuietly', 'firstOrCreate', 'updateOrCreate', 'incrementOrCreate',
        'forceDelete', 'restore', 'touch',
    ];

    /** モデルのインスタンスの書き込み */
    private const MODEL_WRITES = [
        'save', 'saveQuietly', 'saveOrFail', 'saveOrIgnore', 'updateQuietly', 'updateOrFail', 'deleteQuietly', 'deleteOrFail',
        'forceDeleteQuietly', 'destroy', 'restoreQuietly', 'push', 'pushQuietly', 'touchQuietly', 'incrementQuietly', 'decrementQuietly',
    ];

    /** リレーションの書き込み(関連の作成・保存・中間表の更新) */
    private const RELATION_WRITES = [
        'attach', 'attachOrFail', 'detach', 'detachOrFail', 'sync', 'syncOrFail', 'syncWithoutDetaching', 'syncWithoutDetachingOrFail',
        'syncWithPivotValues', 'syncWithPivotValuesOrFail', 'toggle', 'toggleOrFail', 'updateExistingPivot', 'updateExistingPivotOrFail',
        'save', 'saveQuietly', 'saveMany', 'saveManyQuietly', 'createMany', 'createManyQuietly', 'forceCreateMany', 'forceCreateManyQuietly',
        'touchIfTouching',
    ];

    /**
     * Eloquent(モデル・ビルダ・リレーション)の書き込み(型 → メソッド名)。dbWrite の一部で、モジュールの表の持ち主の規則(機械検査 (d) =
     * {@see ModuleTables})も同じ一覧を使う(書き込みのメソッドを 2 か所に書かない)。
     */
    public const ELOQUENT_WRITE_CALLS = [
        'Illuminate\Database\Eloquent\Model' => [...self::MODEL_WRITES, ...self::ELOQUENT_WRITES, ...self::QUERY_WRITES],
        'Illuminate\Database\Eloquent\Builder' => [...self::ELOQUENT_WRITES, ...self::QUERY_WRITES],
        'Illuminate\Database\Eloquent\Relations\Relation' => [...self::RELATION_WRITES, ...self::ELOQUENT_WRITES, ...self::QUERY_WRITES],
    ];

    /** スキーマの変更(表の定義の変更) */
    private const SCHEMA_WRITES = [
        'create', 'table', 'drop', 'dropIfExists', 'rename', 'dropColumns', 'dropAllTables', 'dropAllViews', 'dropAllTypes',
        'createDatabase', 'dropDatabaseIfExists', 'enableForeignKeyConstraints', 'disableForeignKeyConstraints', 'withoutForeignKeyConstraints',
    ];

    /** キューへの投入(キューのファサード・契約・マネージャに共通のメソッド) */
    private const QUEUE_PUSHES = ['push', 'pushOn', 'pushRaw', 'later', 'laterOn', 'bulk'];

    /** ファイルの保存(Laravel のストレージと、ローカルのファイルシステム) */
    private const STORAGE_WRITES = ['put', 'putFile', 'putFileAs', 'prepend', 'append', 'writeStream', 'delete', 'copy', 'move', 'makeDirectory', 'deleteDirectory', 'setVisibility'];

    private const FILESYSTEM_WRITES = [
        'put', 'replace', 'replaceInFile', 'append', 'prepend', 'delete', 'move', 'copy', 'link', 'relativeLink', 'makeDirectory',
        'ensureDirectoryExists', 'moveDirectory', 'copyDirectory', 'deleteDirectory', 'deleteDirectories', 'cleanDirectory', 'chmod',
    ];

    /**
     * laravel/ai の外部の接続先に届く入口(v1.2.3 の実物で決めた。どれもすべてのメソッドを数える): ファサードとマネージャ、接続先と Gateway の契約と
     * 生成の繰り返し、静的な入口と生成の依頼、接続先のファイル・ベクトルの置き場、ファイルの保存・取得・削除の trait、エージェントの契約。
     */
    private const AI_METHODS = [
        'Laravel\Ai\Ai' => ['*'],
        'Laravel\Ai\AiManager' => ['*'],
        'Laravel\Ai\Contracts\Providers\Provider' => ['*'],
        'Laravel\Ai\Contracts\Providers\TextProvider' => ['*'],
        'Laravel\Ai\Contracts\Providers\AudioProvider' => ['*'],
        'Laravel\Ai\Contracts\Providers\ClassificationProvider' => ['*'],
        'Laravel\Ai\Contracts\Providers\EmbeddingProvider' => ['*'],
        'Laravel\Ai\Contracts\Providers\FileProvider' => ['*'],
        'Laravel\Ai\Contracts\Providers\ImageProvider' => ['*'],
        'Laravel\Ai\Contracts\Providers\RerankingProvider' => ['*'],
        'Laravel\Ai\Contracts\Providers\StoreProvider' => ['*'],
        'Laravel\Ai\Contracts\Providers\TranscriptionProvider' => ['*'],
        'Laravel\Ai\Contracts\Gateway\Gateway' => ['*'],
        'Laravel\Ai\Contracts\Gateway\StepTextGateway' => ['*'],
        'Laravel\Ai\Contracts\Gateway\AudioGateway' => ['*'],
        'Laravel\Ai\Contracts\Gateway\ClassificationGateway' => ['*'],
        'Laravel\Ai\Contracts\Gateway\EmbeddingGateway' => ['*'],
        'Laravel\Ai\Contracts\Gateway\FileGateway' => ['*'],
        'Laravel\Ai\Contracts\Gateway\ImageGateway' => ['*'],
        'Laravel\Ai\Contracts\Gateway\RerankingGateway' => ['*'],
        'Laravel\Ai\Contracts\Gateway\StoreGateway' => ['*'],
        'Laravel\Ai\Contracts\Gateway\TranscriptionGateway' => ['*'],
        'Laravel\Ai\Gateway\TextGenerationLoop' => ['*'],
        'Laravel\Ai\Embeddings' => ['*'],
        'Laravel\Ai\Image' => ['*'],
        'Laravel\Ai\Audio' => ['*'],
        'Laravel\Ai\Transcription' => ['*'],
        'Laravel\Ai\Reranking' => ['*'],
        'Laravel\Ai\Classification' => ['*'],
        'Laravel\Ai\PendingResponses\PendingImageGeneration' => ['*'],
        'Laravel\Ai\PendingResponses\PendingAudioGeneration' => ['*'],
        'Laravel\Ai\PendingResponses\PendingEmbeddingsGeneration' => ['*'],
        'Laravel\Ai\PendingResponses\PendingTranscriptionGeneration' => ['*'],
        'Laravel\Ai\PendingResponses\PendingReranking' => ['*'],
        'Laravel\Ai\PendingResponses\PendingClassification' => ['*'],
        'Laravel\Ai\Files' => ['*'],
        'Laravel\Ai\Stores' => ['*'],
        'Laravel\Ai\Store' => ['*'],
        'Laravel\Ai\Files\Concerns\CanBeUploadedToProvider' => ['*'],
        'Laravel\Ai\Files\Concerns\CanBeRetrievedOrDeletedFromProvider' => ['*'],
        'Laravel\Ai\Contracts\Agent' => ['*'],
    ];

    /**
     * 種類ごとの判定する呼び出し(methods = 型 → メソッド名、functions = 関数名)。
     * ai = laravel/ai の外部の接続先に届く入口すべて(docs の 設計/AI/データと失敗と検査.md 8 節)。関数 Laravel\Ai\agent() はエージェントを作るだけなので数えず、
     * 戻り値(Agent)の prompt などで数える。Stringable のマクロ(toEmbeddings・toAudio)は型が静的に決まらないので止まらない(4 節の止まらない経路)。
     * AI を HTTP で直接呼ぶ実装は http に数える(コード構成 4 節)。
     */
    public const CALLS = [
        'dbWrite' => [
            'methods' => [
                ...self::ELOQUENT_WRITE_CALLS,
                'Illuminate\Database\Query\Builder' => self::QUERY_WRITES,
                'Illuminate\Database\ConnectionInterface' => self::CONNECTION_WRITES,
                'Illuminate\Database\DatabaseManager' => self::CONNECTION_WRITES,
                'Illuminate\Support\Facades\DB' => self::CONNECTION_WRITES,
                'Illuminate\Database\Schema\Builder' => self::SCHEMA_WRITES,
                'Illuminate\Support\Facades\Schema' => self::SCHEMA_WRITES,
                'PDO' => ['exec', 'beginTransaction', 'commit', 'rollBack'],
            ],
            'functions' => [],
        ],
        'queue' => [
            'methods' => [
                'Illuminate\Foundation\Bus\Dispatchable' => ['dispatch', 'dispatchIf', 'dispatchUnless', 'dispatchSync', 'dispatchAfterResponse', 'withChain'],
                'Illuminate\Support\Facades\Bus' => ['dispatch', 'dispatchSync', 'dispatchNow', 'dispatchAfterResponse', 'chain', 'batch'],
                'Illuminate\Contracts\Bus\Dispatcher' => ['dispatch', 'dispatchSync', 'dispatchNow'],
                'Illuminate\Foundation\Bus\DispatchesJobs' => ['dispatch', 'dispatchSync'],
                'Illuminate\Support\Facades\Queue' => self::QUEUE_PUSHES,
                'Illuminate\Contracts\Queue\Queue' => self::QUEUE_PUSHES,
                'Illuminate\Queue\QueueManager' => self::QUEUE_PUSHES,
            ],
            'functions' => ['dispatch', 'dispatch_sync'],
        ],
        'notification' => [
            'methods' => [
                'Illuminate\Support\Facades\Notification' => ['*'],
                'Illuminate\Contracts\Notifications\Dispatcher' => ['send', 'sendNow'],
                'Illuminate\Notifications\RoutesNotifications' => ['notify', 'notifyNow'],
                'Illuminate\Notifications\AnonymousNotifiable' => ['notify', 'notifyNow'],
                'Illuminate\Support\Facades\Mail' => ['*'],
                'Illuminate\Contracts\Mail\Mailer' => ['send', 'sendNow', 'raw', 'html', 'plain'],
                'Illuminate\Contracts\Mail\MailQueue' => ['queue', 'later'],
                'Illuminate\Mail\PendingMail' => ['send', 'sendNow', 'queue', 'later'],
                'Illuminate\Contracts\Mail\Mailable' => ['send', 'queue', 'later'],
                'Illuminate\Mail\MailManager' => ['send', 'sendNow', 'raw', 'html', 'plain', 'queue', 'later'],
                'Symfony\Component\Mailer\MailerInterface' => ['send'],
                'Symfony\Component\Mailer\Transport\TransportInterface' => ['send'],
            ],
            'functions' => ['mail'],
        ],
        'fileStorage' => [
            'methods' => [
                'Illuminate\Support\Facades\Storage' => self::STORAGE_WRITES,
                'Illuminate\Contracts\Filesystem\Filesystem' => self::STORAGE_WRITES,
                'Illuminate\Filesystem\FilesystemManager' => self::STORAGE_WRITES,
                'Illuminate\Http\UploadedFile' => ['store', 'storeAs', 'storePublicly', 'storePubliclyAs', 'move'],
                'Illuminate\Support\Facades\File' => self::FILESYSTEM_WRITES,
                'Illuminate\Filesystem\Filesystem' => self::FILESYSTEM_WRITES,
                'SplFileObject' => ['__construct', 'fwrite', 'fputcsv', 'ftruncate'],
                'SplFileInfo' => ['openFile'],
            ],
            'functions' => [
                'file_put_contents', 'fopen', 'fwrite', 'fputs', 'fputcsv', 'ftruncate', 'mkdir', 'rmdir', 'unlink', 'rename', 'copy',
                'touch', 'chmod', 'chown', 'chgrp', 'lchown', 'lchgrp', 'symlink', 'link', 'tempnam', 'tmpfile', 'move_uploaded_file',
            ],
        ],
        'ai' => [
            'methods' => self::AI_METHODS,
            'functions' => [],
        ],
        'http' => [
            'methods' => [
                'Illuminate\Support\Facades\Http' => ['*'],
                'Illuminate\Http\Client\Factory' => ['*'],
                'Illuminate\Http\Client\PendingRequest' => ['*'],
                'GuzzleHttp\ClientInterface' => ['*'],
                'Psr\Http\Client\ClientInterface' => ['sendRequest'],
            ],
            'functions' => ['curl_exec', 'curl_multi_exec', 'fsockopen', 'pfsockopen', 'stream_socket_client', 'get_headers'],
        ],
        'process' => [
            'methods' => [
                'Illuminate\Support\Facades\Process' => ['*'],
                'Illuminate\Process\Factory' => ['*'],
                'Illuminate\Process\PendingProcess' => ['*'],
                'Symfony\Component\Process\Process' => ['run', 'start', 'mustRun', 'restart'],
            ],
            'functions' => ['exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec', 'pcntl_fork'],
        ],
    ];
}
