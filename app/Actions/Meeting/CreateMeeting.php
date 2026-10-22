<?php

namespace App\Actions\Meeting;

use App\Actions\Ai\Api\RequestPrompt;
use App\Actions\Ai\Api\RunRequestDeclined;
use App\Enums\Meeting\MeetingRunOrigin;
use App\Enums\Meeting\MeetingRunStage;
use App\Models\Meeting\MeetingRun;
use App\Queries\Ai\Api\TranscriptionFormats;
use App\Queries\Authz\Api\Requester;
use App\Queries\Meeting\MeetingAudioQuery;
use App\Queries\Meeting\MeetingFieldsQuery;
use App\Queries\Meeting\MeetingTitleQuery;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * 音声(mp3・m4a・wav)とテキスト(.txt・.vtt・.md・貼り付け)から会議を作る(docs の 設計/業務ツール/会議/処理.md の 3.2)。どちらも 1 つのトランザクション。
 * 音声 = 会議のレコード(同じ登録で音声を添付に付ける)→ 文字起こしの依頼と会議と実行の対応({@see MeetingAudioRequest})。
 * テキスト = 会議のレコード → 材料の読み取りと議事録の依頼 → 書き起こしと発言の行 → 会議と実行の対応(鍵の順 = 入れ物の登録の鍵 → AI の費用の鍵)。
 * 書き起こしを依頼の後に書くのは、依頼の口の入力の上限の確かめを、発言の行を書く前に済ませるため。依頼が 422 なら全体が巻き戻る(付けた音声の実体も消える)。
 * テキストのファイルは添付に残さない(書き起こしが同じ中身を持つ)。入口の判定は {@see MeetingRequestGate::ensureCanCreate()}。
 */
class CreateMeeting
{
    public const FILE_OR_TEXT = 'ファイルを選ぶか、テキストを貼り付けてください(どちらか 1 つ)';

    public function __construct(
        private TextImportReader $reader,
        private MeetingRecordWriter $records,
        private MeetingFieldsQuery $fields,
        private MinutesPrompt $prompts,
        private RequestPrompt $requestPrompt,
        private MeetingTranscriptWriter $transcripts,
        private TranscriptionFormats $formats,
        private MeetingAudioQuery $audio,
        private MeetingAudioRequest $audioRequest,
    ) {}

    /**
     * @param  UploadedFile|null  $file  取り込むファイル(貼り付けと両方は受けない)
     * @param  string|null  $text  貼り付けの文
     * @param  array<array-key, mixed>  $values  会議の欄のコード → 値(作成のダイアログの欄だけを使う)
     * @return int 作った会議の id
     *
     * @throws ValidationException 入力のテキストの誤り(キー file・text)・欄の検証の失敗(キー values.<コード>)・音声を添付に付けられないとき
     *                             (キー attachments)
     * @throws RunRequestDeclined 依頼が上限を超える・長すぎる・音声を読み取れない・音声が削除されたとき(キー ai)
     * @throws AuthorizationException 登録を許されない・ゲストのとき
     */
    public function execute(AccessContext $context, ?UploadedFile $file, ?string $text, array $values): int
    {
        $withoutText = $text === null || $text === '';

        if ($file !== null && $withoutText && $this->formats->isAudioName($file->getClientOriginalName())) {
            return $this->fromAudio($context, $file, $values);
        }

        $imported = $this->import($file, $text === '' ? null : $text);

        return DB::transaction(function () use ($context, $values, $imported): int {
            $provisionalTitle = $imported->fileName ?? MeetingTitleQuery::PASTED_TITLE;
            $created = $this->records->create($context, $values, $provisionalTitle);
            $requester = Requester::fromAccessContext($context);
            $fields = $this->fields->read($requester, $created->recordId, $context->date, $context->localeCode);
            $title = $created->writesTitle ? null : $fields->title;
            $request = $this->prompts->request($created->recordId, $fields, $title, $imported->lines);
            $runId = $this->requestPrompt->execute($context, $request);
            $this->transcripts->write($created->recordId, $imported->source, $imported->fileName, $imported->lines);
            MeetingRun::query()->create([
                'record_id' => $created->recordId,
                'ai_run_id' => $runId,
                'stage' => MeetingRunStage::Minutes,
                'origin' => MeetingRunOrigin::Create,
                'writes_title' => $created->writesTitle,
            ]);

            return $created->recordId;
        });
    }

    /**
     * 音声の会議(仮の件名 = ファイルの名前を 255 字で切ったもの)と、付けた音声の文字起こしの依頼。
     *
     * @param  array<array-key, mixed>  $values
     *
     * @throws ValidationException
     * @throws RunRequestDeclined
     * @throws AuthorizationException
     */
    private function fromAudio(AccessContext $context, UploadedFile $file, array $values): int
    {
        return DB::transaction(function () use ($context, $file, $values): int {
            $provisionalTitle = mb_substr($file->getClientOriginalName(), 0, TextImportReader::FILE_NAME_MAX_LENGTH);
            $created = $this->records->create($context, $values, $provisionalTitle, [$file]);
            $uploadId = $this->audio->latestAudioOf($created->recordId)
                ?? throw new LogicException("会議 {$created->recordId} の登録で付けた音声が添付に無い");
            $origin = MeetingRunOrigin::Create;
            $this->audioRequest->execute($context, $created->recordId, $uploadId, $origin, $created->writesTitle);

            return $created->recordId;
        });
    }

    /**
     * @throws ValidationException
     */
    private function import(?UploadedFile $file, ?string $text): ImportedText
    {
        return match (true) {
            $file !== null && $text === null => $this->reader->fromFile($file),
            $file === null && $text !== null => $this->reader->fromPaste($text),
            default => throw ValidationException::withMessages([TextImportReader::FILE => self::FILE_OR_TEXT]),
        };
    }
}
