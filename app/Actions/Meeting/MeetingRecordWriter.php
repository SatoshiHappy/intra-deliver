<?php

namespace App\Actions\Meeting;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordInput;
use App\Enums\Builtin\Meeting;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * 人の操作の作成が会議のレコードを作る 1 か所(docs の 設計/業務ツール/会議/処理.md の 3.2 の 2、機械検査の (b) = 口と検査.md の 12 節)。
 * 入力の欄は作成のダイアログの欄だけを渡し(削除予定日は相対の既定値が入る)、件名が空なら仮の値を入れて AI が件名を書く印を立てる(README.md の 1.2)。
 * 音声は同じ登録で添付に付ける(登録の版 1 つに添付が入る = 処理.md の 3.2 の 2)。
 */
class MeetingRecordWriter
{
    /** 作成の入力に受ける欄 */
    private const INPUT_FIELDS = [
        Meeting::Title,
        Meeting::HeldAt,
        Meeting::ViewerUsers,
        Meeting::ViewerDepartments,
        Meeting::Project,
        Meeting::Agenda,
        Meeting::Notes,
    ];

    public function __construct(private CreateRecord $createRecord) {}

    /**
     * @param  array<array-key, mixed>  $values  欄のコード → 値(入力の欄のほかは渡さない)
     * @param  string  $provisionalTitle  件名が空のときに入れる仮の件名
     * @param  list<UploadedFile>  $attachFiles  同じ登録で添付に付けるファイル(音声)
     *
     * @throws AuthorizationException 登録を許されないとき
     * @throws ValidationException 欄の検証に失敗したとき(キー values.<コード>)・添付を付けられないとき(キー attachments)
     */
    public function create(
        AccessContext $context,
        array $values,
        string $provisionalTitle,
        array $attachFiles = [],
    ): CreatedMeeting {
        $input = [];

        foreach (self::INPUT_FIELDS as $field) {
            if (array_key_exists($field->value, $values)) {
                $input[$field->value] = $values[$field->value];
            }
        }

        $title = $input[Meeting::Title->value] ?? null;
        $writesTitle = $title === null || (is_string($title) && TextLines::trim($title) === '');

        if ($writesTitle) {
            $input[Meeting::Title->value] = $provisionalTitle;
        }

        $recordInput = new RecordInput($input, attachFiles: $attachFiles);
        $recordId = $this->createRecord->execute($context, Meeting::CODE, $recordInput);

        return new CreatedMeeting($recordId, $writesTitle);
    }
}
