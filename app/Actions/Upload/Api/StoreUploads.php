<?php

namespace App\Actions\Upload\Api;

use App\Models\Upload\Upload;
use App\Queries\Upload\Api\UploadLimits;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\Mime\MimeTypes;

/**
 * ファイルを上げる(口の保証の正本 = docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/README.md の 2 節の表、書く順 = 5 節)。
 * 主体は呼び手(認可を見ない)。確かめ(422)は鍵の前、台帳の鍵は呼び手の鍵の後(この口の中で最後)に取る。
 * 自分のトランザクションを持ち、呼び手のトランザクションの中では savepoint で含まれる。巻き戻ったら書いた実体を消す。
 */
class StoreUploads
{
    /**
     * 台帳の勧告ロックの鍵(共通マスタ 4,100,000,001・業務モジュール 4,200,000,000,000,000 + id・AI の費用 4,300,000,000,000,000 の
     * どれとも重ならない値。業務モジュールの id が 10 の 14 乗に届かない限り重ならない)
     */
    public const LEDGER_LOCK_KEY = 4_400_000_000_000_000;

    /** 実体を置くディスクの中のディレクトリ */
    private const DIRECTORY = 'uploads';

    public function __construct(private UploadLimits $limits) {}

    /**
     * @param  int|null  $userId  上げた人(ゲストなら null)
     * @param  list<UploadedFile>  $files
     * @return list<int> 書いた uploads の id(入力の順)
     *
     * @throws ValidationException 件数・大きさ・名前・形式・合計の誤り(キー attachments・attachments.<添字>)
     * @throws RuntimeException 実体を書けなかったとき(巻き戻る)
     */
    public function execute(?int $userId, array $files): array
    {
        $mimes = $this->validate($files);

        return DB::transaction(function () use ($userId, $files, $mimes): array {
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::LEDGER_LOCK_KEY]);
            $total = (int) Upload::query()->whereNull('purged_at')->sum('size');

            if ($total + $this->requestBytes($files) > $this->limits->maxTotalBytes()) {
                throw ValidationException::withMessages(['attachments' => [UploadLimits::TOTAL_EXCEEDED]]);
            }

            return array_map(
                fn (UploadedFile $file, string $mime) => $this->store($userId, $file, $mime),
                $files,
                $mimes,
            );
        });
    }

    /**
     * 件数・1 ファイルの大きさ・名前・形式・1 回の合計を確かめる(鍵の前)。
     *
     * @param  list<UploadedFile>  $files
     * @return list<string> ファイルごとの中身から推した型
     *
     * @throws ValidationException
     */
    private function validate(array $files): array
    {
        $this->ensureCount($files);
        $mimes = array_map(fn (UploadedFile $file) => $this->guessedMime($file), $files);
        $errors = [];

        foreach ($files as $index => $file) {
            $error = $this->fileError($file, $mimes[$index]);

            if ($error !== null) {
                $errors["attachments.{$index}"] = [$error];
            }
        }

        if ($this->requestBytes($files) > $this->limits->maxRequestBytes()) {
            $errors['attachments'] = [$this->limits->requestTooLargeMessage()];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $mimes;
    }

    /**
     * @param  list<UploadedFile>  $files
     *
     * @throws ValidationException 0 個か上限より多いとき
     */
    private function ensureCount(array $files): void
    {
        if ($files === [] || count($files) > UploadLimits::MAX_FILES_PER_REQUEST) {
            $message = $files === [] ? UploadLimits::NO_FILES : UploadLimits::TOO_MANY_FILES;

            throw ValidationException::withMessages(['attachments' => [$message]]);
        }
    }

    /**
     * 中身から推した型(受け取りに失敗したファイルは空)。
     */
    private function guessedMime(UploadedFile $file): string
    {
        return $file->isValid() ? (string) MimeTypes::getDefault()->guessMimeType($file->getPathname()) : '';
    }

    private function fileError(UploadedFile $file, string $mime): ?string
    {
        if (! $file->isValid()) {
            return $this->limits->uploadErrorMessage($file->getError());
        }

        if ($file->getSize() > $this->limits->maxFileBytes()) {
            return $this->limits->fileTooLargeMessage();
        }

        if ($file->getSize() === 0) {
            return UploadLimits::EMPTY_FILE;
        }

        $name = $this->limits->fileName($file->getClientOriginalName());

        return UploadLimits::nameShapeError($name)
            ?? UploadLimits::nameFormatError($name)
            ?? ($this->limits->accepts($name, $mime) ? null : UploadLimits::UNSUPPORTED_FORMAT);
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function requestBytes(array $files): int
    {
        return array_sum(array_map(fn (UploadedFile $file) => $file->isValid() ? (int) $file->getSize() : 0, $files));
    }

    /**
     * 実体を乱数の名前(拡張子は推した型から)で書いてから行を書く。巻き戻りで消す登録は書く前に置く(途中で失敗した実体も消す)。
     *
     * @throws RuntimeException 実体を書けなかったとき
     */
    private function store(?int $userId, UploadedFile $file, string $mime): int
    {
        $disk = Storage::disk('local');
        $extension = MimeTypes::getDefault()->getExtensions($mime)[0] ?? 'bin';
        $basename = Str::random(40).'.'.$extension;
        $path = self::DIRECTORY.'/'.$basename;
        DB::afterRollBack(fn () => $disk->delete($path));

        if ($disk->putFileAs(self::DIRECTORY, $file, $basename) === false) {
            throw new RuntimeException("添付の実体を書けなかった: {$path}");
        }

        return Upload::query()->create([
            'path' => $path,
            'size' => (int) $file->getSize(),
            'mime' => $mime,
            'user_id' => $userId,
        ])->id;
    }
}
