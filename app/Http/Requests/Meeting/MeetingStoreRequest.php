<?php

namespace App\Http\Requests\Meeting;

use App\Actions\Meeting\MeetingRequestGate;
use App\Actions\Meeting\TextImportReader;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * テキストからの会議の作成の入力(ファイル 1 つ file か貼り付けの文 text と、欄の値 values。docs の 設計/業務ツール/会議/処理.md の 3.2)。
 * ここは形だけを見て、形式・大きさ・文字コード・行は業務処理が確かめる。PHP の上限で落ちた送信は、大きさの誤りと同じ文にそろえる。
 */
class MeetingStoreRequest extends FormRequest
{
    public const NOT_RECEIVED = 'ファイルを受け取れませんでした。もう一度選択してください。';

    public const TEXT_MALFORMED = '貼り付けたテキストの形が正しくありません';

    public const VALUES_MALFORMED = '値の形が正しくありません';

    /**
     * 判定を検証より前に確かめる(受けた後に必ず失敗する依頼を受けない)。
     *
     * @throws AuthorizationException 許されないとき
     */
    public function authorize(MeetingRequestGate $gate): bool
    {
        $gate->ensureCanCreate(AccessContext::of($this));

        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            TextImportReader::FILE => ['nullable', 'file'],
            TextImportReader::TEXT => ['nullable', 'string'],
            'values' => ['array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $file = $this->file(TextImportReader::FILE);
        $tooLarge = $file instanceof UploadedFile
            && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);

        return [
            'file.file' => self::NOT_RECEIVED,
            'file.uploaded' => $tooLarge ? TextImportReader::FILE_TOO_LARGE : self::NOT_RECEIVED,
            'text.string' => self::TEXT_MALFORMED,
            'values.array' => self::VALUES_MALFORMED,
        ];
    }

    /** 送られたファイル(無ければ null) */
    public function importedFile(): ?UploadedFile
    {
        $file = $this->file(TextImportReader::FILE);

        return $file instanceof UploadedFile ? $file : null;
    }

    /** 貼り付けの文(無ければ null) */
    public function pastedText(): ?string
    {
        $text = $this->input(TextImportReader::TEXT);

        return is_string($text) ? $text : null;
    }

    /**
     * 欄の値(欄のコード → 値。送られた形のまま渡し、業務処理が欄ごとに検証する)。
     *
     * @return array<array-key, mixed>
     */
    public function meetingValues(): array
    {
        $values = $this->input('values', []);

        return is_array($values) ? $values : [];
    }
}
