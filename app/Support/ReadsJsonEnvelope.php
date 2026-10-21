<?php

namespace App\Support;

use Closure;
use Illuminate\Validation\Validator;

/**
 * ファイルを multipart で受ける FormRequest の、ファイルの外の入力を JSON の封筒 envelope で受ける部品(multipart の文字列の欄は型を失い、
 * 参照の id が数で届かないため。先例 = ワークフローの添付の口の封筒 = docs の 設計/ワークフロー/申請の周辺/添付/README.md の 3.1 節)。
 * multipart の要求は envelope を JSON の要求と同じ入力に戻し(ファイルはそのまま)、読めなければ印だけを付けて、authorize の後の検証で
 * 422(キー envelope)を出す(403・404 を先に返す順を保つ)。使う口は規則を {@see self::withEnvelopeRules()} で包み、封筒の大きさの上限を渡す。
 */
trait ReadsJsonEnvelope
{
    /** multipart の envelope が無い・JSON のオブジェクトとして読めない・大きすぎる(422 envelope) */
    public const ENVELOPE_UNREADABLE = '送信の形が正しくありません';

    private bool $envelopeUnreadable = false;

    /**
     * 封筒の大きさの上限(バイト。復号の前に止めて、上限を超える文字列を配列にしない)。
     */
    abstract protected function envelopeMaxBytes(): int;

    /**
     * multipart の envelope を入力に置き換える。authorize より前に走るので、読めないときは印だけを付ける。
     */
    protected function prepareForValidation(): void
    {
        if (! str_starts_with(strtolower((string) $this->header('CONTENT_TYPE')), 'multipart/form-data')) {
            return;
        }

        $decoded = $this->decodeEnvelope($this->post('envelope'));

        if ($decoded === null) {
            $this->envelopeUnreadable = true;

            return;
        }

        // JSON の要求に掛かる大域の TrimStrings・ConvertEmptyStringsToNull と同じ変換
        $this->replace(InputText::normalize($decoded));
    }

    /**
     * JSON のオブジェクトだけを 1 回で配列にする。リスト・ほかの値・大きすぎる文字列は読めないものとする。
     *
     * @return array<string, mixed>|null
     */
    private function decodeEnvelope(mixed $envelope): ?array
    {
        if (! is_string($envelope) || strlen($envelope) > $this->envelopeMaxBytes()) {
            return null;
        }

        if (! str_starts_with(ltrim($envelope), '{')) {
            return null;
        }

        $decoded = json_decode($envelope, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * 読めない envelope は envelope の誤り 1 つだけにする(ほかのキーは送られていないので誤りにしない)。
     *
     * @param  array<string, list<string>>  $rules  その口の規則
     * @return array<string, list<string>>
     */
    private function withEnvelopeRules(array $rules): array
    {
        return $this->envelopeUnreadable ? [] : $rules;
    }

    /**
     * 検証の後の印の誤り(FormRequest の after のフック)。
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->envelopeUnreadable) {
                $validator->errors()->add('envelope', self::ENVELOPE_UNREADABLE);
            }
        }];
    }
}
