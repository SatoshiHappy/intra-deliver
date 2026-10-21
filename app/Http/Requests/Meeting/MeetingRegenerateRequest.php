<?php

namespace App\Http\Requests\Meeting;

use App\Actions\Meeting\MeetingRequestGate;
use App\Actions\Meeting\RegenerateMinutes;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 書き起こしからの議事録の再作成の入力(追加の指示 instructions。docs の 設計/業務ツール/会議/処理.md の 3.7)。長さと使えない文字は業務処理が確かめる。
 */
class MeetingRegenerateRequest extends FormRequest
{
    public const INSTRUCTIONS_MALFORMED = '追加の指示の形が正しくありません';

    /**
     * 判定を検証より前に確かめる(403 → 会議の 404 の順。会議が無い・閲覧できないときは判定の口がモデルの 404 の例外を投げる)。
     *
     * @throws AuthorizationException 許されないとき
     */
    public function authorize(MeetingRequestGate $gate): bool
    {
        $gate->ensureCanRegenerate(AccessContext::of($this), (int) $this->route('meeting'));

        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [RegenerateMinutes::INSTRUCTIONS => ['nullable', 'string']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [RegenerateMinutes::INSTRUCTIONS.'.string' => self::INSTRUCTIONS_MALFORMED];
    }

    /** 追加の指示(無ければ null) */
    public function instructions(): ?string
    {
        $instructions = $this->input(RegenerateMinutes::INSTRUCTIONS);

        return is_string($instructions) ? $instructions : null;
    }
}
