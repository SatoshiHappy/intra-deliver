<?php

namespace App\Http\Requests\Demo;

use App\Queries\Demo\DemoUser;
use App\Queries\Demo\DemoUserQuery;
use App\Support\AccessContext;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * 切替の入力(docs の 設計/デモユーザー切替/デモの取り付け.md 6.3・6.5)。
 *
 * 最初に(入力の検証より前に)要求の時点の設定 demo.enabled を見て、true でなければ 404 で止める(ルートのキャッシュが残っていても
 * 切替は開かない)。user_id は必須の整数で、d の候補({@see DemoUserQuery::candidatesOn()})のユーザーであること。
 * 失敗は Laravel の既定の応答(docs の 設計/デモユーザー切替/README.md の方針 7)。最初に失敗した規則の文言だけを返す。
 */
class SwitchUserRequest extends FormRequest
{
    public function authorize(): Response
    {
        return config('demo.enabled') === true ? Response::allow() : Response::denyAsNotFound();
    }

    /**
     * @return array<string, list<string|In>>
     */
    public function rules(DemoUserQuery $demoUsers): array
    {
        $context = AccessContext::of($this);
        $candidateIds = array_map(fn (DemoUser $user) => $user->id, $demoUsers->candidatesOn($context->date, $context->localeCode));

        return [
            'user_id' => ['bail', 'required', 'integer', Rule::in($candidateIds)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.required' => '利用者を選択してください',
            'user_id.integer' => '利用者の指定が正しくありません',
            'user_id.in' => '選択した利用者は今日の切替の候補にありません',
        ];
    }
}
