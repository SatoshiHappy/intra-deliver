<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Api\ConversationStore;
use App\Enums\Ai\ConversationItemType;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * テストの中だけの会話の保存の口(① の本物の代わり。docs の 設計/AI/エージェントの実行/口と契約.md 9.13 の「会話の保存の口」)。
 * 会話・会話と実行の対応・項目を、テストの中で作る 3 つの表に持つ({@see self::createTables()}。テストのトランザクションの中で作り、終わりに巻き戻る)。
 * 写しは json の列に持つ(jsonb は鍵を並べ替える = docs の 設計/AI/エージェントの実行/口と契約.md 9.13 の守る結果)。
 * 足すは会話の行の鍵の下で会話の有無を見て、1 つのトランザクションで全部か無しかで書く。{@see self::$failAfterWrites} 件を書いた後に例外を投げられる。
 */
final class FakeConversationStore implements ConversationStore
{
    private const CONVERSATIONS = 'test_conversations';

    private const RUNS = 'test_conversation_runs';

    private const ITEMS = 'test_conversation_items';

    /** 次の足すで、この件数を書いた後に例外を投げる(テストが置く。null = 投げない) */
    public ?int $failAfterWrites = null;

    public static function createTables(): void
    {
        Schema::create(self::CONVERSATIONS, function (Blueprint $table) {
            $table->id();
        });
        Schema::create(self::RUNS, function (Blueprint $table) {
            $table->foreignId('ai_run_id')->primary();
            $table->foreignId('conversation_id')->constrained(self::CONVERSATIONS)->cascadeOnDelete();
        });
        Schema::create(self::ITEMS, function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained(self::CONVERSATIONS)->cascadeOnDelete();
            $table->foreignId('ai_run_id');
            $table->json('copy');
        });
    }

    /** 会話を作り、実行を対応に書く(① の依頼の業務処理の代わり)。会話の id を返す */
    public function startConversation(int $runId): int
    {
        $conversationId = DB::table(self::CONVERSATIONS)->insertGetId([]);
        $this->continueConversation($conversationId, $runId);

        return $conversationId;
    }

    /** 会話に実行の対応を足す */
    public function continueConversation(int $conversationId, int $runId): void
    {
        DB::table(self::RUNS)->insert(['ai_run_id' => $runId, 'conversation_id' => $conversationId]);
    }

    /** 会話を消す(対応と項目も消える) */
    public function deleteConversation(int $conversationId): void
    {
        DB::table(self::CONVERSATIONS)->where('id', $conversationId)->delete();
    }

    public function read(int $runId): ?array
    {
        $conversationId = $this->conversationOf($runId, false);

        if ($conversationId === null) {
            return null;
        }

        $copies = DB::table(self::ITEMS)->where('conversation_id', $conversationId)->orderBy('id')->pluck('copy');
        $items = array_values(array_map(
            fn (mixed $copy): ConversationItem => ConversationItem::fromArray(
                (array) json_decode((string) $copy, true),
            ),
            $copies->all(),
        ));
        $boundary = 0;

        foreach ($items as $index => $item) {
            $boundary = $item->type === ConversationItemType::CompactBoundary ? $index : $boundary;
        }

        return array_slice($items, $boundary);
    }

    public function append(int $runId, array $items): bool
    {
        return DB::transaction(function () use ($runId, $items): bool {
            $conversationId = $this->conversationOf($runId, true);

            if ($conversationId === null) {
                return false;
            }

            foreach ($items as $written => $item) {
                if ($this->failAfterWrites === $written) {
                    $this->failAfterWrites = null;

                    throw new RuntimeException('足す途中の項目で失敗した(テストの偽物)');
                }

                DB::table(self::ITEMS)->insert([
                    'conversation_id' => $conversationId,
                    'ai_run_id' => $runId,
                    'copy' => json_encode($item->toArray(), JSON_THROW_ON_ERROR),
                ]);
            }

            return true;
        });
    }

    /** 実行の会話(対応が無い・会話が消えた = null)。$lock なら会話の行の鍵を取る */
    private function conversationOf(int $runId, bool $lock): ?int
    {
        $conversationId = DB::table(self::RUNS)->where('ai_run_id', $runId)->value('conversation_id');

        if ($conversationId === null) {
            return null;
        }

        $query = DB::table(self::CONVERSATIONS)->where('id', $conversationId);
        $found = ($lock ? $query->lockForUpdate() : $query)->value('id');

        return $found === null ? null : (int) $found;
    }
}
