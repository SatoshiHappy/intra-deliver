<?php

namespace App\Actions\Workflow;

use App\Queries\Master\Api\EffectiveUsers;
use App\Support\AccessContext;
use App\Support\InputText;
use Illuminate\Validation\ValidationException;

/**
 * 連絡の入力の規則(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.5 節。文言 = 同時実行と検査.md の 13 節)。
 * 今日に有効かを DB で見るので、入力の変換と文字の規則の {@see CaseInputRules} と分ける。
 */
class ContactValidator
{
    /** 連絡する人の上限 */
    public const MAX_USERS = 10;

    /** ひと言の最大の文字数 */
    public const MESSAGE_MAX = 1000;

    public function __construct(private EffectiveUsers $effectiveUsers) {}

    /**
     * 規則に合う連絡(ひと言は前後の空白を除き空なら null)。連絡する人が無ければ null(連絡しない)。自分 = 実行者(操作した本人)。
     *
     * @throws ValidationException 連絡する人(contact.user_ids)・ひと言(contact.message)の誤り
     */
    public function validated(?CaseContact $contact, AccessContext $context): ?CaseContact
    {
        if ($contact === null) {
            return null;
        }

        $message = InputText::normalize($contact->message);
        $message = is_string($message) ? $message : null;
        $errors = array_filter([
            'contact.user_ids' => $this->userIdsError($contact->userIds, $message, $context),
            'contact.message' => $this->messageError($message),
        ]);

        if ($errors !== []) {
            throw ValidationException::withMessages(array_map(fn (string $error) => [$error], $errors));
        }

        return $contact->userIds === [] ? null : new CaseContact($contact->userIds, $message);
    }

    /**
     * ひと言の誤り(見る順はコメントの規則 {@see CaseInputRules} と同じ = 使えない文字、長さ)。
     */
    private function messageError(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        $isValid = mb_check_encoding($message, 'UTF-8') && ! str_contains($message, "\0");

        return match (true) {
            ! $isValid => CaseOperationErrors::CONTACT_MESSAGE_INVALID_CHARACTER,
            mb_strlen($message) > self::MESSAGE_MAX => CaseOperationErrors::CONTACT_MESSAGE_TOO_LONG,
            default => null,
        };
    }

    /**
     * @param  list<int>  $userIds
     */
    private function userIdsError(array $userIds, ?string $message, AccessContext $context): ?string
    {
        if ($userIds === []) {
            return $message === null ? null : CaseOperationErrors::CONTACT_REQUIRED;
        }

        $unique = array_values(array_unique($userIds));
        $effective = fn () => count($this->effectiveUsers->idsOn($unique, $context->date)) === count($unique);

        return match (true) {
            count($userIds) > self::MAX_USERS => CaseOperationErrors::CONTACT_TOO_MANY,
            ! $effective() => CaseOperationErrors::CONTACT_NOT_EFFECTIVE,
            in_array($context->userId, $unique, true) => CaseOperationErrors::CONTACT_SELF,
            count($unique) !== count($userIds) => CaseOperationErrors::CONTACT_DUPLICATE,
            default => null,
        };
    }
}
