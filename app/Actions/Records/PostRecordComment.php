<?php

namespace App\Actions\Records;

use App\Actions\Notice\Api\NoticeMessage;
use App\Actions\Notice\Api\SendNotices;
use App\Enums\Records\RecordNoticeType;
use App\Models\Records\Record;
use App\Models\Records\RecordComment;
use App\Models\Records\RecordCommentMention;
use App\Models\Records\RecordModule;
use App\Queries\Master\Api\EffectiveUsers;
use App\Queries\Records\RecordCommentGate;
use App\Queries\Records\RecordMentionViewers;
use App\Queries\Records\RecordModuleGate;
use App\Queries\Records\RecordNoticeTargets;
use App\Support\AccessContext;
use App\Support\InputText;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * レコードにコメントを書く(内部。入口と初期データの seeder が呼ぶ。手順・判定の順・鍵の順の正本 = docs の
 * 設計/業務データの入れ物/レコードの共通機能/コメント.md の 1.2 節)。版は作らない。通知は同じトランザクションで送る(3 節)。
 */
class PostRecordComment
{
    use LocksRecordModule;

    /** 本文の文字数の上限(列 varchar(4000)。DB の制約より先にここで 422 にする = データモデルの共通の規則の例外 3) */
    public const BODY_MAX_LENGTH = 4000;

    /** 宛先の人数の上限 */
    public const MENTIONS_MAX = 10;

    /** 宛先の誤りの文言(有効でない・無い・閲覧できないのどれでも同じ。名前と閲覧の可否を返さない) */
    public const MENTION_ERROR = '宛先に指定できないユーザーが含まれています';

    /** 本文が空・文字列でない(不正な UTF-8)ときの文言 */
    public const BODY_EMPTY = 'コメントを入力してください';

    public function __construct(
        private RecordModuleGate $modules,
        private RecordCommentGate $gate,
        private RecordMentionViewers $viewers,
        private EffectiveUsers $effectiveUsers,
        private SendNotices $notices,
    ) {}

    /**
     * @param  list<int>  $mentionUserIds  宛先のユーザー。重なりは除く(添字は送られた位置のまま 422 のキーになる)
     * @return int 書いたコメントの id
     *
     * @throws ModelNotFoundException<RecordModule|Record> 業務モジュールが無い・明細のモジュール、読めるがレコードが見えないとき
     * @throws AuthorizationException 閲覧を許されないとき・ユーザーがいない(ゲスト)とき
     * @throws ValidationException 本文(body)・宛先(mentions・mentions.<添字>)が規則に合わないとき
     */
    public function execute(
        AccessContext $context,
        string $moduleCode,
        int $recordId,
        string $body,
        array $mentionUserIds,
    ): int {
        return DB::transaction(function () use ($context, $moduleCode, $recordId, $body, $mentionUserIds): int {
            $module = $this->modules->topLevelModule($moduleCode);
            $this->lockRecordModuleShared($module);
            $userId = $this->gate->authorize($context, $moduleCode, $recordId);
            $text = mb_check_encoding($body, 'UTF-8') ? (string) InputText::normalize($body) : null;
            $mentions = array_unique($mentionUserIds);
            $this->validate($context, $moduleCode, $recordId, $text, $mentions);
            // 削除と並ぶ(レコードの削除は行を排他で取る)
            Record::query()->where('record_module_id', $module->id)->lock('for key share')->findOrFail($recordId);

            $comment = RecordComment::query()->create([
                'record_module_id' => $module->id, 'record_id' => $recordId, 'user_id' => $userId, 'body' => $text,
            ]);

            foreach ($mentions as $mentionUserId) {
                RecordCommentMention::query()->create([
                    'record_comment_id' => $comment->id, 'user_id' => $mentionUserId,
                ]);
            }

            $this->notify($recordId, $userId, array_values($mentions));

            return $comment->id;
        });
    }

    /**
     * @param  string|null  $text  前後の空白を除いた本文(共通の変換 {@see InputText})。不正な UTF-8 は null
     * @param  array<int, int>  $mentions  送られた位置 → 宛先のユーザー(重なりを除いたもの)
     *
     * @throws ValidationException
     */
    private function validate(
        AccessContext $context,
        string $moduleCode,
        int $recordId,
        ?string $text,
        array $mentions,
    ): void {
        $errors = [];
        $bodyError = self::bodyError($text);

        if ($bodyError !== null) {
            $errors['body'] = [$bodyError];
        }

        if (count($mentions) > self::MENTIONS_MAX) {
            $errors['mentions'] = ['宛先は '.self::MENTIONS_MAX.' 人までです'];
        } else {
            $errors += $this->mentionErrors($context, $moduleCode, $recordId, $mentions);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * 本文の誤り(順 = 不正な UTF-8 → U+0000 → 空 → 長さ。文字列の項目の検査と同じ順)。
     */
    private static function bodyError(?string $text): ?string
    {
        return match (true) {
            $text === null => self::BODY_EMPTY,
            str_contains($text, "\0") => 'コメントに使用できない文字(U+0000)が含まれています',
            $text === '' => self::BODY_EMPTY,
            mb_strlen($text) > self::BODY_MAX_LENGTH => 'コメントは '.self::BODY_MAX_LENGTH.' 文字までです',
            default => null,
        };
    }

    /**
     * 今日に有効で、そのレコードを閲覧できる人だけを宛先にできる(キー mentions.<添字>。誤りの文言は 1 つ)。
     *
     * @param  array<int, int>  $mentions
     * @return array<string, list<string>>
     */
    private function mentionErrors(AccessContext $context, string $moduleCode, int $recordId, array $mentions): array
    {
        $effective = $this->effectiveUsers->idsOn(array_values($mentions), $context->date);
        $viewable = array_flip($this->viewers->among($moduleCode, $recordId, $effective, $context->date));
        $effectiveSet = array_flip($effective);
        $errors = [];

        foreach ($mentions as $index => $mentionUserId) {
            if (! isset($effectiveSet[$mentionUserId]) || ! isset($viewable[$mentionUserId])) {
                $errors["mentions.{$index}"] = [self::MENTION_ERROR];
            }
        }

        return $errors;
    }

    /**
     * 宛先(書いた本人を除く。検証で今日に有効と確かめ済み)に、対象 record-comment の通知を送る。
     *
     * @param  list<int>  $mentions
     */
    private function notify(int $recordId, int $authorUserId, array $mentions): void
    {
        $recipients = array_values(array_diff($mentions, [$authorUserId]));

        $type = RecordNoticeTargets::TARGET_TYPE_COMMENT;

        $this->notices->execute(
            new NoticeMessage(RecordNoticeType::Comment->value, $type, $recordId, $authorUserId, []),
            $recipients,
        );
    }
}
