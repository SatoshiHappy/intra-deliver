<?php

namespace App\Http\Requests\Records;

use App\Enums\Records\RecordModuleAction;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\RecordModuleGate;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 他モジュール参照の候補の入力(docs の 設計/業務データの入れ物/画面/README.md 4 節)。
 * 空・空白だけの q は、既定のミドルウェアで null になるので「絞らない」として扱う。
 */
class RecordCandidatesRequest extends FormRequest
{
    /** 語の最大の文字数 */
    public const MAX_LENGTH = 100;

    /**
     * 口の順(404 → 403)を検証の 422 より前にするため、ここで認可する(認可とメニュー/README.md 2.2)。
     *
     * @throws AuthorizationException 許されないとき
     */
    public function authorize(RecordModuleGate $gate): bool
    {
        $context = AccessContext::of($this);
        $gate->authorizeTarget(Requester::fromAccessContext($context), (string) $this->route('module'), RecordModuleAction::Read, $context->date);

        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'q' => ['bail', 'nullable', 'string', 'max:'.self::MAX_LENGTH],
            'exclude' => ['bail', 'nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.string' => '検索する語の指定が正しくありません',
            'q.max' => '検索する語は '.self::MAX_LENGTH.' 文字以内にしてください',
            'exclude.integer' => '除くレコードの指定が正しくありません',
            'exclude.min' => '除くレコードの指定が正しくありません',
        ];
    }

    /**
     * 検証を通った語(無い・空なら空文字 = 絞らない)。
     */
    public function keyword(): string
    {
        return $this->string('q')->toString();
    }

    /**
     * 検証を通った、候補から除くレコードの id(無い・空なら null = 除かない)。
     */
    public function excludedId(): ?int
    {
        return $this->filled('exclude') ? $this->integer('exclude') : null;
    }
}
