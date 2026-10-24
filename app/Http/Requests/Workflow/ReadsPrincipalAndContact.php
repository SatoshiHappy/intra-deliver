<?php

namespace App\Http\Requests\Workflow;

use App\Actions\Workflow\CaseContact;
use Closure;
use Illuminate\Validation\Validator;

/**
 * 処理の操作が送る処理担当者 principal_user_id と連絡 contact: { user_ids, message }(docs の
 * 設計/ワークフロー/案件の運用/画面/README.md の 1.1 節、案件の運用/案件の操作/操作.md の 10.5 節)。入口は形だけを見て、
 * 人数・文字数・有効でない人などの中身は業務処理が 422 にする。連絡する人は整数に変えてから {@see CaseContact} を作る。
 */
trait ReadsPrincipalAndContact
{
    /** 連絡する人の形の誤り(画面の欄からは起きない) */
    private const CONTACT_USERS_MALFORMED = '連絡する人の指定が正しくありません';

    /**
     * @return array<string, list<string>>
     */
    private function principalAndContactRules(): array
    {
        return [
            'principal_user_id' => ['nullable', 'integer'],
            'contact.message' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function principalAndContactMessages(): array
    {
        return [
            'principal_user_id.integer' => '処理担当者の指定が正しくありません',
            'contact.message.string' => 'ひと言の形が正しくありません',
        ];
    }

    /**
     * 連絡する人の形(contact が配列で、user_ids が整数か数字の文字列の並び)の検証の後のフック。誤りは画面が欄の下に出すキー
     * contact.user_ids に付ける(contact そのものが配列でないときも同じキー = 設計/ワークフロー/案件の運用/画面/案件の画面.md の 5 節)。
     * 使う要求の after がほかのフックと並べて返す。
     *
     * @return Closure(Validator): void
     */
    private function contactShapeCheck(): Closure
    {
        return function (Validator $validator): void {
            if (! $this->hasContactShape()) {
                $validator->errors()->add('contact.user_ids', self::CONTACT_USERS_MALFORMED);
            }
        };
    }

    /**
     * 処理担当者(null = 自分として。規則が形を確かめた後に呼ぶ)。
     */
    public function principalUserId(): ?int
    {
        return $this->input('principal_user_id') === null ? null : $this->integer('principal_user_id');
    }

    /**
     * 連絡(contact が無ければ null = 連絡しない。規則と {@see self::contactShapeCheck()} が形を確かめた後に呼ぶ)。
     */
    public function contact(): ?CaseContact
    {
        $contact = $this->input('contact');

        if (! is_array($contact)) {
            return null;
        }

        $userIds = is_array($contact['user_ids'] ?? null) ? $contact['user_ids'] : [];
        $message = $contact['message'] ?? null;

        $integers = array_values(array_map(fn (mixed $id) => (int) $id, $userIds));

        return new CaseContact($integers, is_string($message) ? $message : null);
    }

    private function hasContactShape(): bool
    {
        $contact = $this->input('contact');

        if ($contact === null) {
            return true;
        }

        $userIds = is_array($contact) ? ($contact['user_ids'] ?? null) : false;

        if ($userIds === null) {
            return true;
        }

        return is_array($userIds) && array_is_list($userIds) && array_filter($userIds, self::isNotIntegral(...)) === [];
    }

    /**
     * 整数か、整数として読める文字列でない値(真偽・小数・数字でない文字列など)。
     */
    private static function isNotIntegral(mixed $value): bool
    {
        return ! is_int($value) && ! (is_string($value) && filter_var($value, FILTER_VALIDATE_INT) !== false);
    }
}
