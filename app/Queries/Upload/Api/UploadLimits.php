<?php

namespace App\Queries\Upload\Api;

use LogicException;

/**
 * 上限・名前・形式の規則の 1 か所(docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/README.md の 2 節・3 節)。
 * 入口の検証・StoreUploads・持ち主の口の名前・画面の案内が同じ値と文言を使う。上限は配備の設定(config/uploads.php)から読む。
 */
class UploadLimits
{
    /** 1 回に上げられるファイルの数 */
    public const MAX_FILES_PER_REQUEST = 3;

    public const NO_FILES = 'ファイルを選択してください';

    public const TOO_MANY_FILES = '1 回に追加できるファイルは '.self::MAX_FILES_PER_REQUEST.' つまでです';

    public const INVALID_NAME = 'ファイル名が正しくありません';

    public const UNSUPPORTED_FORMAT = 'この形式のファイルは添付できません';

    /** 0 バイトのファイル(中身から型を推せないので、形式の誤りと分けて理由を出す) */
    public const EMPTY_FILE = '空のファイルは添付できません';

    public const TOTAL_EXCEEDED = '添付ファイルの合計の容量が上限に達したため、追加できません';

    /** 受け取れなかった送信(大きさの上限のほかの理由。docs の 設計/ワークフロー/申請の周辺/添付/README.md の 3.1 節) */
    public const NOT_RECEIVED = 'ファイルを受け取れませんでした。もう一度選択してください。';

    /** 名前の文字数の上限(record_attachments.name の varchar(255)) */
    public const MAX_NAME_LENGTH = 255;

    /**
     * 形式の対応表(拡張子(小文字)→ 受ける型)。音声・動画の型は見本を流して実測した(型の根拠 = 見本の実測。
     * 実測した環境は audio/webm・audio/wav を推さないが、正本の表にある型として受ける(配備の PHP の fileinfo の版で変わりうる)。
     * m4a に video/mp4 を足すのは、Android の録音など中身のブランドが isom・mp42 の m4a が video/mp4 と推されるため)。
     *
     * @var array<string, list<string>>
     */
    private const FORMATS = [
        'pdf' => ['application/pdf'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif' => ['image/gif'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        'txt' => ['text/plain'],
        'md' => ['text/plain', 'text/markdown'],
        'csv' => ['text/csv', 'text/plain'],
        'mp3' => ['audio/mpeg'],
        'm4a' => ['audio/x-m4a', 'audio/mp4', 'video/mp4'],
        'wav' => ['audio/x-wav', 'audio/wav'],
        'mp4' => ['video/mp4', 'audio/mp4'],
        'webm' => ['video/webm', 'audio/webm'],
    ];

    /**
     * 画面の案内に出す形式の名前(案内の並び)→ その拡張子。対応表に行を足すときはここにも足す(漏れは {@see self::guide()} が止める)。
     *
     * @var array<string, list<string>>
     */
    private const FORMAT_NAMES = [
        'PDF' => ['pdf'],
        'PNG' => ['png'],
        'JPEG' => ['jpg', 'jpeg'],
        'GIF' => ['gif'],
        'Word' => ['docx'],
        'Excel' => ['xlsx'],
        'PowerPoint' => ['pptx'],
        'テキスト' => ['txt'],
        'CSV' => ['csv'],
        'Markdown' => ['md'],
        '音声(MP3・M4A・WAV)' => ['mp3', 'm4a', 'wav'],
        '動画(MP4・WebM)' => ['mp4', 'webm'],
    ];

    private const UNITS = ['GB' => 1_073_741_824, 'MB' => 1_048_576, 'KB' => 1_024];

    /** @var list<string>|null {@see self::mediaMimes()} の結果(最初に呼んだとき組む) */
    private ?array $mediaMimes = null;

    public function maxFileBytes(): int
    {
        return $this->bytes('max_file_bytes');
    }

    public function maxRequestBytes(): int
    {
        return $this->bytes('max_request_bytes');
    }

    public function maxTotalBytes(): int
    {
        return $this->bytes('max_total_bytes');
    }

    /** 1 ファイルの上限を超えたときの文言(数は設定から作る) */
    public function fileTooLargeMessage(): string
    {
        return 'ファイルの大きさは '.self::size($this->maxFileBytes()).' までです';
    }

    /** 1 回の合計の上限を超えたときの文言 */
    public function requestTooLargeMessage(): string
    {
        return '1 回に追加できるファイルの合計は '.self::size($this->maxRequestBytes()).' までです';
    }

    /**
     * 添付ファイルの欄の案内の 1 行(数は設定から、形式は対応表から作る = docs の
     * 設計/業務データの入れ物/レコードの共通機能/添付ファイル/README.md の 3 節の最後)。
     *
     * @throws LogicException 対応表の拡張子に案内の名前が無いとき
     */
    public function guide(): string
    {
        $named = array_merge(...array_values(self::FORMAT_NAMES));
        $unnamed = array_diff(array_keys(self::FORMATS), $named);

        if ($unnamed !== []) {
            throw new LogicException('案内の名前が無い形式: '.implode('、', $unnamed));
        }

        $accepted = array_filter(
            self::FORMAT_NAMES,
            fn (array $extensions) => array_intersect($extensions, array_keys(self::FORMATS)) !== [],
        );

        $formats = implode('・', array_keys($accepted));
        // 半角の英数字の後ろだけ空ける(「Markdown です」「WebM)です」。文のほかの所と同じ)
        $space = preg_match('/[A-Za-z0-9]\z/', $formats) === 1 ? ' ' : '';

        return '1 ファイル '.self::size($this->maxFileBytes()).' まで、1 回 '.self::MAX_FILES_PER_REQUEST.' ファイル・合計 '
            .self::size($this->maxRequestBytes()).' までです。使用できる形式は '.$formats.$space.'です。';
    }

    /**
     * 受け取りに失敗した送信の文言(PHP の上限で落ちたものは 1 ファイルの上限と同じ文言にそろえる)。
     */
    public function uploadErrorMessage(int $error): string
    {
        return in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? $this->fileTooLargeMessage()
            : self::NOT_RECEIVED;
    }

    /**
     * 送られた名前の最後の部分(パスの区切り / と \ の後ろ)。保存のパスには使わない。
     */
    public function fileName(string $clientName): string
    {
        $parts = preg_split('#[/\\\\]#', $clientName);

        return $parts === false ? $clientName : (string) end($parts);
    }

    /**
     * 名前の形の誤り(空・制御文字・255 文字超・パスの区切りを含む)。無ければ null。設定を読まないので、上げ済みの実体の名前を
     * 受ける値の作り手も使う(形式の対応表は当てない = レコードの添付.md の 1.1 節の「名前」)。
     */
    public static function nameShapeError(string $name): ?string
    {
        $length = mb_strlen($name, 'UTF-8');
        $broken = preg_match('/\p{Cc}/u', $name) !== 0 || str_contains($name, '/') || str_contains($name, '\\');

        return $length === 0 || $length > self::MAX_NAME_LENGTH || $broken ? self::INVALID_NAME : null;
    }

    /**
     * 名前の拡張子(小文字にしたもの)が形式の対応表に無いときの誤り。無ければ null。
     */
    public static function nameFormatError(string $name): ?string
    {
        return array_key_exists(self::extension($name), self::FORMATS) ? null : self::UNSUPPORTED_FORMAT;
    }

    /**
     * 名前の拡張子と中身から推した型が、対応表の同じ行に当たるか。
     */
    public function accepts(string $name, string $mime): bool
    {
        return in_array($mime, self::FORMATS[self::extension($name)] ?? [], true);
    }

    /**
     * 受ける形式の対応表(画面の案内を作る所が読む)。
     *
     * @return array<string, list<string>>
     */
    public function formats(): array
    {
        return self::FORMATS;
    }

    /**
     * 対応表が受ける音声・動画の型(型が audio/*・video/* のもの。ブラウザの再生の部品で開ける型 = プレビューの型)。
     *
     * @return list<string>
     */
    public function mediaMimes(): array
    {
        // 定数の表から組むだけなので、この実体の中で 1 回だけ組む(欄の行ごとに組み直さない。静的な状態は持たない)
        return $this->mediaMimes ??= array_values(array_filter(
            array_unique(array_merge(...array_values(self::FORMATS))),
            fn (string $mime) => str_starts_with($mime, 'audio/') || str_starts_with($mime, 'video/'),
        ));
    }

    private static function extension(string $name): string
    {
        $dot = strrpos($name, '.');

        return $dot === false ? '' : mb_strtolower(substr($name, $dot + 1), 'UTF-8');
    }

    /**
     * @throws LogicException 設定の値が正の整数でないとき(上限なしに倒さない)
     */
    private function bytes(string $key): int
    {
        $value = config("uploads.{$key}");

        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        return is_int($value) && $value > 0 ? $value : throw new LogicException("config/uploads.php の {$key} が正の整数でない");
    }

    /**
     * バイト数を割り切れる最大の単位で書く(例 99,614,720 → 95MB)。
     */
    private static function size(int $bytes): string
    {
        foreach (self::UNITS as $unit => $base) {
            if ($bytes % $base === 0) {
                return intdiv($bytes, $base).$unit;
            }
        }

        return $bytes.'B';
    }
}
