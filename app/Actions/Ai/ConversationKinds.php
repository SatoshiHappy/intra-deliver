<?php

namespace App\Actions\Ai;

use App\Actions\Ai\Api\ConversationKind;
use Illuminate\Container\Attributes\Tag;
use LogicException;

/**
 * AI の会話の型の種類の全体 = タグ {@see ConversationKind::TAG} で束ねた実装を引く 1 か所(内部。docs の 設計/AI/公開の口.md 3.4、
 * 設計/AI/エージェントの実行/口と契約.md 9.13)。Ai の業務処理は種類の実装を直接呼ばずにここを通す。登録と設定の食い違いは文章の種類と一緒に
 * {@see PromptKinds::problems()} が見る。
 */
class ConversationKinds
{
    /**
     * @param  iterable<ConversationKind>  $kinds
     */
    public function __construct(#[Tag(ConversationKind::TAG)] private iterable $kinds) {}

    /**
     * コードの種類の実装。
     *
     * @throws LogicException 登録の無いコードのとき(作りの誤り)
     */
    public function of(string $code): ConversationKind
    {
        return $this->find($code) ?? throw new LogicException(self::notRegistered($code));
    }

    /** コードの種類の実装。登録が無ければ null */
    public function find(string $code): ?ConversationKind
    {
        foreach ($this->kinds as $kind) {
            if ($kind->code() === $code) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * 登録された実装のコード(登録の順。同じコードが 2 つあれば 2 回入る)。
     *
     * @return list<string>
     */
    public function codes(): array
    {
        $codes = [];

        foreach ($this->kinds as $kind) {
            $codes[] = $kind->code();
        }

        return $codes;
    }

    /** 登録の無い種類の文 */
    public static function notRegistered(string $code): string
    {
        return "AI の会話の型の種類 {$code} の実装が登録されていない(タグ ".ConversationKind::TAG.')';
    }
}
