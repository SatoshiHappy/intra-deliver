<?php

namespace App\Http\Requests\Records;

use App\Queries\Records\RecordCommentGate;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * コメントの投稿の入力(docs の 設計/業務データの入れ物/レコードの共通機能/コメント.md の 6 節)。ここは形だけを見て、本文の長さ・宛先の人数と
 * 閲覧の可否は業務処理(PostRecordComment)が確かめる。口の順(業務モジュール 404 → 閲覧 403 → レコード 404 → ユーザー 403)を検証の 422 より前に
 * 確かめるため、ここで認可する。
 */
class RecordCommentRequest extends FormRequest
{
    /** 形の誤りの文言(宛先) */
    private const MENTIONS_SHAPE = '宛先の指定が正しくありません';

    /**
     * @throws AuthorizationException 許されないとき(業務モジュール・レコードが無ければ 404 の例外が出る)
     */
    public function authorize(RecordCommentGate $gate): bool
    {
        $gate->authorize(AccessContext::of($this), (string) $this->route('module'), (int) $this->route('record'));

        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string'],
            'mentions' => ['nullable', 'array'],
            'mentions.*' => ['integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.string' => 'コメントを入力してください',
            'mentions.array' => self::MENTIONS_SHAPE,
            'mentions.*.integer' => self::MENTIONS_SHAPE,
            'mentions.*.min' => self::MENTIONS_SHAPE,
        ];
    }

    /**
     * 本文(形は検証済み。無ければ空)。
     */
    public function commentBody(): string
    {
        return $this->string('body')->toString();
    }

    /**
     * 宛先のユーザー(形は検証済み。無ければ空)。重なりは業務処理が除く。
     *
     * @return list<int>
     */
    public function mentionUserIds(): array
    {
        return array_values(array_map('intval', $this->array('mentions')));
    }
}
