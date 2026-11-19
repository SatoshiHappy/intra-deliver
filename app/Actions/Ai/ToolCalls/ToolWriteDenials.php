<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\AgentToolResult;
use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Records\Api\RecordHolderRefusal;
use App\Actions\Records\Api\RecordStaleRefusal;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\AiToolWriteDenial;
use App\Queries\Ai\Api\RecordMarkers;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * 書く時の断りの、入れ物の答えから表 2 の行への読み替えと、モデルに返す結果(docs の 設計/AI/道具と権限/まとめた実行と送るデータ.md 10.5 の
 * 表 2 と「入れ物の断りと表 2 の行」、失敗の印 = 設計/AI/エージェントの実行/道具の包みと止める.md 9.4)。入れ物の答えは型で見分ける(文言は
 * 画面の語で変わりうるため)。
 */
final class ToolWriteDenials
{
    /** 断りの理由ごとの文(見つからないと入れ物の検証は別に組む) */
    private const TEXTS = [
        'prior_not_written' => 'この件が指す前の件が書けなかったので、この件も書かなかった。',
        'unavailable' => 'この道具か業務モジュールは今は使えないので、書かなかった。',
        'no_permission' => '依頼した利用者は今はこの業務モジュールでこの操作をする権限を持たないので、書かなかった。',
        'held' => 'このレコードはほかの処理の途中なので、書かなかった。',
        'stale' => 'このレコードは確認の後にほかの人が直したので、書かなかった。直すなら、読み直してから改めて直す。',
    ];

    /** 入れ物の検証の誤りの前置き(後に項目ごとの誤りを並べる) */
    public const VALIDATION = '入れ物の検証を通らなかったので、書かなかった。';

    /**
     * 入れ物の答えの表 2 の行。予期しない例外は null(道具の例外)。
     *
     * @param  bool  $creates  作るか(登録の 404 は業務モジュールが無い = P2、更新の 404 は P4)
     * @return array{AiToolWriteDenial, string}|null 理由と結果の文
     */
    public static function of(?Throwable $exception, bool $creates): ?array
    {
        return match (true) {
            $exception instanceof AuthorizationException => self::denial(AiToolWriteDenial::NoPermission),
            $exception instanceof ModelNotFoundException => $creates
                ? self::denial(AiToolWriteDenial::Unavailable)
                : [AiToolWriteDenial::NotFound, ''],
            $exception instanceof RecordHolderRefusal => self::denial(AiToolWriteDenial::Held),
            $exception instanceof RecordStaleRefusal => self::denial(AiToolWriteDenial::Stale),
            $exception instanceof ValidationException => [AiToolWriteDenial::Validation, self::validation($exception)],
            default => null,
        };
    }

    /**
     * 判定の関数の断り(P1〜P3)の理由と文。
     *
     * @return array{AiToolWriteDenial, string}
     */
    public static function denial(AiToolWriteDenial $denial): array
    {
        return [$denial, self::TEXTS[$denial->value] ?? ''];
    }

    /** 断りの結果の項目(見つからないは失敗の印の無い本文、ほかは道具の失敗の包み) */
    public static function item(string $callId, AiToolWriteDenial $denial, string $text): ConversationItem
    {
        if ($denial === AiToolWriteDenial::NotFound) {
            $body = ToolResultText::full(AgentToolResult::notFound());

            return ConversationItem::toolResult($callId, null, [], $body, false);
        }

        $values = ['error' => RecordMarkers::neutralize($text)];

        return ConversationItem::toolResult($callId, AiInstructionTemplate::ToolError, $values, null, true);
    }

    /** 入れ物の検証の誤りの文(キーごと) */
    private static function validation(ValidationException $exception): string
    {
        $lines = [self::VALIDATION];

        foreach ($exception->errors() as $key => $messages) {
            foreach ($messages as $message) {
                $lines[] = "- `{$key}`: {$message}";
            }
        }

        return implode("\n", $lines);
    }
}
