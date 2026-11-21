<?php

namespace Tests\Architecture\Policy;

use PHPat\Test\Attributes\TestRule;
use PHPat\Test\Builder\Rule;

/**
 * 機械検査 (b) のうち `Ai` の主体の判定を呼び手に任せる口の呼び手の一覧(docs の 設計/業務ツール/機械検査.md の検査 (b))。
 * (1) 会話の依頼の口: RequestConversation に依存できるのは、一覧 {@see self::CONVERSATION_REQUEST_CALLERS} の場所だけ(依頼者が会話の本人で
 * あることと前の実行の選び方は呼び手の入口が確かめるので、入口の確かめを持つ呼び手に絞る = 設計/AI/公開の口.md 3 節の「依頼の時点の確かめ」の (d))。
 * (2) エージェントの定義を書く口: SaveAgentDefinition に依存できるのは、一覧 {@see self::DEFINITION_WRITE_CALLERS} の場所だけ
 * (主体の判定をしない口なので、入口の確かめを持つ設定の業務処理に絞る = 設計/AI/設定とプロンプト/README.md 11.2)。
 * 規則の形と一覧の書き方は {@see RecordWriters} と同じ(文章・文字起こしの依頼の口とは一覧を分ける)。
 */
final class AiApiCallers
{
    /** 会話の依頼の口 */
    public const CONVERSATION_REQUEST_APIS = ['App\Actions\Ai\Api\RequestConversation'];

    /** エージェントの定義を書く口 */
    public const DEFINITION_WRITE_APIS = ['App\Actions\Ai\Api\SaveAgentDefinition'];

    /** 会話の依頼の口の呼び手(文を送る・確認に答える ① の業務処理は、① を作るときに足す) */
    public const CONVERSATION_REQUEST_CALLERS = [];

    /**
     * 定義を書く口の呼び手(定義の設定の画面の業務処理は、その画面を作るときに足す)。
     *
     * @var list<array{type: 'namespace'|'class'|'path', place: string, reason: string}>
     */
    public const DEFINITION_WRITE_CALLERS = [
        ['type' => 'path', 'place' => LayerRules::SEEDERS_PATH, 'reason' => '初期データ(既定の定義を定義の口で入れる)'],
    ];

    #[TestRule]
    public function conversationRequestDependencies(): Rule
    {
        return RecordWriters::dependenciesOnlyFrom(
            self::CONVERSATION_REQUEST_CALLERS,
            self::CONVERSATION_REQUEST_APIS,
            '機械検査 (b): RequestConversation に依存できるのは呼び手の一覧(AiApiCallers::CONVERSATION_REQUEST_CALLERS)の場所だけ',
        );
    }

    #[TestRule]
    public function definitionWriteDependencies(): Rule
    {
        return RecordWriters::dependenciesOnlyFrom(
            self::DEFINITION_WRITE_CALLERS,
            self::DEFINITION_WRITE_APIS,
            '機械検査 (b): SaveAgentDefinition に依存できるのは呼び手の一覧(AiApiCallers::DEFINITION_WRITE_CALLERS)の場所だけ',
        );
    }
}
