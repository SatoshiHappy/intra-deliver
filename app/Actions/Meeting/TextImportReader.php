<?php

namespace App\Actions\Meeting;

use App\Enums\Meeting\TranscriptSource;
use App\Queries\Meeting\MeetingLimits;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * テキストのファイル(.txt・.md・.vtt)と貼り付けの文を確かめ、発言の行に分ける(docs の 設計/業務ツール/会議/処理.md の 3.2 の 1、
 * README.md の 2 節の単位と文字コード)。読む前に大きさで断り、行は 1 行ずつ読み進めて数の上限で止める(メモリを使い切らないため)。
 * 422 のキーは出どころの入力の名前(ファイル = file、貼り付け = text)。
 */
class TextImportReader
{
    /** ファイルのキー */
    public const FILE = 'file';

    /** 貼り付けのキー */
    public const TEXT = 'text';

    /** 発言の行の数の上限 */
    public const MAX_LINES = 10_000;

    /** ファイルの名前の最大の文字数(表 transcripts の file_name) */
    public const FILE_NAME_MAX_LENGTH = 255;

    public const UNSUPPORTED = 'この形式のファイルは取り込めません';

    public const FILE_TOO_LARGE = 'テキストのファイルは '.MeetingLimits::TEXT_MAX_MEGABYTES.'MB までにしてください';

    public const PASTE_TOO_LARGE = '貼り付けたテキストは '.MeetingLimits::TEXT_MAX_MEGABYTES.'MB までにしてください';

    public const NOT_UTF8 = 'テキストのファイルは UTF-8 で保存してください';

    public const PASTE_INVALID_CHARACTER = '貼り付けたテキストに使えない文字が含まれています';

    public const TOO_MANY_LINES = '取り込むテキストの行が多すぎます(10,000 行まで)';

    public const EMPTY = '取り込むテキストが空です';

    private const PLAIN_EXTENSIONS = ['txt', 'md'];

    private const SUBTITLE_EXTENSION = 'vtt';

    private const BOM = "\u{FEFF}";

    public function __construct(private WebVttReader $subtitles) {}

    /**
     * @throws ValidationException 形式・大きさ・文字コード・.vtt の形・行の数・空のとき(キー file)
     */
    public function fromFile(UploadedFile $file): ImportedText
    {
        $name = $file->getClientOriginalName();
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (! in_array($extension, [...self::PLAIN_EXTENSIONS, self::SUBTITLE_EXTENSION], true)) {
            throw self::rejected(self::FILE, self::UNSUPPORTED);
        }

        if ($file->getSize() > MeetingLimits::TEXT_MAX_BYTES) {
            throw self::rejected(self::FILE, self::FILE_TOO_LARGE);
        }

        $content = self::readable((string) $file->get(), self::FILE, self::NOT_UTF8);
        $lines = $extension === self::SUBTITLE_EXTENSION
            ? $this->subtitles->lines($content, self::FILE)
            : self::plainLines($content, self::FILE);

        $fileName = mb_substr($name, 0, self::FILE_NAME_MAX_LENGTH);

        return new ImportedText(TranscriptSource::TextFile, $fileName, self::nonEmpty($lines, self::FILE));
    }

    /**
     * @throws ValidationException 大きさ・使えない文字・行の数・空のとき(キー text)
     */
    public function fromPaste(string $text): ImportedText
    {
        if (strlen($text) > MeetingLimits::TEXT_MAX_BYTES) {
            throw self::rejected(self::TEXT, self::PASTE_TOO_LARGE);
        }

        $content = self::readable($text, self::TEXT, self::PASTE_INVALID_CHARACTER);

        $lines = self::plainLines($content, self::TEXT);

        return new ImportedText(TranscriptSource::Paste, null, self::nonEmpty($lines, self::TEXT));
    }

    /**
     * 発言の行が上限を超えたら止める(行を足すたびに呼ぶ)。
     *
     * @throws ValidationException
     */
    public static function ensureLineCount(int $count, string $errorKey): void
    {
        if ($count > self::MAX_LINES) {
            throw self::rejected($errorKey, self::TOO_MANY_LINES);
        }
    }

    /**
     * 先頭の BOM を外した、DB の文字列に書ける UTF-8 の文字列(UTF-8 でない・U+0000 を含むなら断る)。
     *
     * @throws ValidationException
     */
    private static function readable(string $content, string $errorKey, string $message): string
    {
        $withoutBom = str_starts_with($content, self::BOM) ? substr($content, strlen(self::BOM)) : $content;

        if (! mb_check_encoding($withoutBom, 'UTF-8') || str_contains($withoutBom, "\0")) {
            throw self::rejected($errorKey, $message);
        }

        return $withoutBom;
    }

    /**
     * 前後の空白を除いて空でない行を 1 行にする(話者・時刻なし)。
     *
     * @return list<TranscriptLine>
     *
     * @throws ValidationException
     */
    private static function plainLines(string $content, string $errorKey): array
    {
        $lines = [];

        foreach (TextLines::of($content) as $line) {
            $text = TextLines::trim($line);

            if ($text !== '') {
                $lines[] = new TranscriptLine($text);
                self::ensureLineCount(count($lines), $errorKey);
            }
        }

        return $lines;
    }

    /**
     * @param  list<TranscriptLine>  $lines
     * @return non-empty-list<TranscriptLine>
     *
     * @throws ValidationException
     */
    private static function nonEmpty(array $lines, string $errorKey): array
    {
        return $lines === [] ? throw self::rejected($errorKey, self::EMPTY) : $lines;
    }

    private static function rejected(string $errorKey, string $message): ValidationException
    {
        return ValidationException::withMessages([$errorKey => $message]);
    }
}
