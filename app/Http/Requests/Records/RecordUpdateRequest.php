<?php

namespace App\Http\Requests\Records;

/**
 * レコードの更新の入力の封筒(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 9.1、画面/業務の画面.md 2.2): 登録と同じ封筒に、
 * 開いたときの版 version を足す(登録・ワークフローの申請の封筒は版を受けない)。updated_at が無い行の版は空文字で、
 * 既定のミドルウェアが null にするので、null を許して空文字として渡す。
 */
class RecordUpdateRequest extends RecordInputRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [...parent::rules(), 'version' => ['present', 'nullable', 'string']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'version.present' => '版の指定がありません',
            'version.string' => '版の指定が正しくありません',
        ];
    }

    /**
     * 開いたときの版(業務処理が今の版と比べる)。
     */
    public function recordVersion(): string
    {
        return $this->string('version')->toString();
    }
}
