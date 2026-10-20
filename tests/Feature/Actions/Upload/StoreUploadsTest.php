<?php

namespace Tests\Feature\Actions\Upload;

use App\Actions\Upload\Api\StoreUploads;
use App\Models\Upload\Upload;
use App\Queries\Upload\Api\UploadLimits;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsUploads;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * ファイルを上げる口(docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/README.md の 2 節・3 節・5 節、検査 = 8 節):
 * 件数・大きさ・名前・形式・合計の確かめ、台帳の鍵、巻き戻りと書き込みの失敗で実体と行が残らないこと。上限はテストで小さい値に置き換える。
 */
final class StoreUploadsTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsUploads;
    use CatchesValidationErrors;

    private const PROBE = 'upload_ledger_lock_probe';

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeUploadDisk();
        $this->limitUploads(1_024, 1_024);
    }

    protected function tearDown(): void
    {
        DB::purge(self::PROBE);
        parent::tearDown();
    }

    public function test_it_writes_the_files_under_random_names_and_the_rows_in_the_input_order(): void
    {
        $user = $this->user();

        $ids = $this->store([$this->uploadedFile('../../報告.pdf'), $this->uploadedFile('図.png', 'png')], $user->id);

        $rows = Upload::query()->whereKey($ids)->orderBy('id')->get();
        $this->assertSame($ids, $rows->modelKeys());
        $this->assertSame(['application/pdf', 'image/png'], $rows->pluck('mime')->all());
        $this->assertSame([$user->id, $user->id], $rows->pluck('user_id')->all());
        $contents = [$this->sampleContent('pdf'), $this->sampleContent('png')];
        $this->assertSame(array_map(strlen(...), $contents), $rows->pluck('size')->all());

        foreach ($rows as $index => $row) {
            $this->assertMatchesRegularExpression('#\Auploads/[A-Za-z0-9]{40}\.(pdf|png)\z#', $row->path);
            $this->assertStringNotContainsString('報告', $row->path);
            Storage::disk('local')->assertExists($row->path);
            $this->assertSame($contents[$index], Storage::disk('local')->get($row->path));
        }
    }

    public function test_a_guest_upload_has_no_user(): void
    {
        [$id] = $this->store([$this->uploadedFile('a.pdf')]);

        $this->assertNull(Upload::query()->findOrFail($id)->user_id);
    }

    public function test_no_files_and_more_than_three_files_are_rejected(): void
    {
        $four = array_map(fn (int $index) => $this->uploadedFile("{$index}.pdf"), range(1, 4));

        $none = $this->validationErrors(fn () => $this->store([]));
        $tooMany = $this->validationErrors(fn () => $this->store($four));

        $this->assertSame(['attachments' => UploadLimits::NO_FILES], $none);
        $this->assertSame(['attachments' => UploadLimits::TOO_MANY_FILES], $tooMany);
        $this->assertSame(0, Upload::query()->count());
    }

    public function test_a_file_over_the_limit_is_rejected_and_one_of_exactly_the_limit_passes(): void
    {
        $this->limitUploads(1_024, 4_096);
        $files = [$this->uploadedFile('a.pdf'), $this->pdfOfSize('b.pdf', 1_025)];
        $errors = $this->validationErrors(fn () => $this->store($files));

        $this->assertSame(['attachments.1' => 'ファイルの大きさは 1KB までです'], $errors);
        $this->assertCount(1, $this->store([$this->pdfOfSize('c.pdf', 1_024)]));
    }

    public function test_files_over_the_limit_of_one_request_are_rejected(): void
    {
        $files = [$this->pdfOfSize('a.pdf', 600), $this->pdfOfSize('b.pdf', 600)];
        $errors = $this->validationErrors(fn () => $this->store($files));

        $this->assertSame(['attachments' => '1 回に追加できるファイルの合計は 1KB までです'], $errors);
    }

    public function test_the_limits_come_from_the_deployment_settings(): void
    {
        config(['uploads.max_file_bytes' => '99614720', 'uploads.max_request_bytes' => 10_737_418_240]);
        $limits = app(UploadLimits::class);

        $this->assertSame('ファイルの大きさは 95MB までです', $limits->fileTooLargeMessage());
        $this->assertSame('1 回に追加できるファイルの合計は 10GB までです', $limits->requestTooLargeMessage());
    }

    public function test_a_limit_that_is_not_a_positive_integer_is_a_mistake_of_the_deployment(): void
    {
        config(['uploads.max_total_bytes' => '']);

        $this->expectException(LogicException::class);

        app(UploadLimits::class)->maxTotalBytes();
    }

    public function test_a_failed_upload_gets_the_message_of_its_cause(): void
    {
        $source = $this->pdfOfSize('a.pdf', 100);
        $tooLarge = new UploadedFile($source->getPathname(), 'a.pdf', null, UPLOAD_ERR_INI_SIZE, true);
        $partial = new UploadedFile($source->getPathname(), 'b.pdf', null, UPLOAD_ERR_PARTIAL, true);

        $errors = $this->validationErrors(fn () => $this->store([$tooLarge, $partial]));

        $this->assertSame(
            ['attachments.0' => 'ファイルの大きさは 1KB までです', 'attachments.1' => UploadLimits::NOT_RECEIVED],
            $errors,
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidNames(): array
    {
        return [
            'control character' => ["a\x01.pdf", UploadLimits::INVALID_NAME],
            '256 characters' => [str_repeat('あ', 252).'.pdf', UploadLimits::INVALID_NAME],
            'only the separator' => ['dir/', UploadLimits::INVALID_NAME],
            'no extension' => ['README', UploadLimits::UNSUPPORTED_FORMAT],
            'an extension out of the table' => ['a.exe', UploadLimits::UNSUPPORTED_FORMAT],
        ];
    }

    #[DataProvider('invalidNames')]
    public function test_a_wrong_name_is_rejected(string $name, string $message): void
    {
        $source = $this->uploadedFile('a.pdf');
        $file = new UploadedFile($source->getPathname(), $name, null, null, true);

        $this->assertSame(['attachments.0' => $message], $this->validationErrors(fn () => $this->store([$file])));
    }

    public function test_a_name_of_255_characters_and_the_last_part_of_a_path_pass(): void
    {
        $limits = app(UploadLimits::class);

        $this->assertCount(1, $this->store([$this->uploadedFile(str_repeat('あ', 251).'.pdf')]));
        $this->assertSame('a.pdf', $limits->fileName('../../a.pdf'));
        $this->assertSame('a.pdf', $limits->fileName('..\\..\\a.pdf'));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function acceptedFormats(): array
    {
        $office = 'application/vnd.openxmlformats-officedocument.';

        return [
            'pdf' => ['a.pdf', 'pdf', 'application/pdf'],
            'png' => ['a.png', 'png', 'image/png'],
            'jpg' => ['a.jpg', 'jpeg', 'image/jpeg'],
            'jpeg' => ['a.jpeg', 'jpeg', 'image/jpeg'],
            'gif' => ['a.gif', 'gif', 'image/gif'],
            'docx' => ['a.docx', 'docx', $office.'wordprocessingml.document'],
            'xlsx' => ['a.xlsx', 'xlsx', $office.'spreadsheetml.sheet'],
            'pptx' => ['a.pptx', 'pptx', $office.'presentationml.presentation'],
            'txt' => ['a.txt', 'txt', 'text/plain'],
            // 名前を分けて書く(docs の参照の照合が拡張子 md の語を文書と読むため)
            'md as text/plain' => ['a.'.'md', 'md', 'text/plain'],
            'csv' => ['a.csv', 'csv', 'text/csv'],
            'csv as text/plain' => ['a.csv', 'txt', 'text/plain'],
            'an upper case extension' => ['a.PDF', 'pdf', 'application/pdf'],
        ];
    }

    #[DataProvider('acceptedFormats')]
    public function test_each_row_of_the_format_table_passes(string $name, string $kind, string $mime): void
    {
        $this->limitUploads(8_192, 8_192);
        [$id] = $this->store([$this->uploadedFile($name, $kind)]);

        $this->assertSame($mime, Upload::query()->findOrFail($id)->mime);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function rejectedFormats(): array
    {
        return [
            'a pdf with the content of a png' => ['a.pdf', 'png'],
            'a docx that is a plain zip' => ['a.docx', 'zip'],
            'a png with the content of a pdf' => ['a.png', 'pdf'],
        ];
    }

    #[DataProvider('rejectedFormats')]
    public function test_a_content_out_of_the_row_of_its_extension_is_rejected(string $name, string $kind): void
    {
        $errors = $this->validationErrors(fn () => $this->store([$this->uploadedFile($name, $kind)]));

        $this->assertSame(['attachments.0' => UploadLimits::UNSUPPORTED_FORMAT], $errors);
    }

    public function test_an_upload_over_the_total_is_rejected_and_purged_rows_are_not_counted(): void
    {
        $this->limitUploads(1_024, 1_024, 1_000_000);
        $current = (int) Upload::query()->whereNull('purged_at')->sum('size');
        $filler = Upload::factory()->create(['size' => 1_000_000 - 1 - $current]);
        Upload::factory()->purged()->create(['size' => 500_000]);
        $twoBytes = UploadedFile::fake()->createWithContent('a.txt', "a\n");

        $errors = $this->validationErrors(fn () => $this->store([$twoBytes]));

        $this->assertSame(['attachments' => UploadLimits::TOTAL_EXCEEDED], $errors);
        $this->assertSame(0, $this->storedFileCount());
        $filler->update(['size' => $filler->size - 1]);
        $this->assertCount(1, $this->store([$twoBytes]));
    }

    /**
     * 台帳の鍵は確かめ(422)の後・合計を読む前に取り、トランザクションの終わりまで持つ(並べた追加は鍵で並ぶ)。
     */
    public function test_it_takes_the_ledger_lock_before_reading_the_total_and_holds_it(): void
    {
        $log = [];
        DB::listen(function (QueryExecuted $query) use (&$log) {
            $log[] = $query->sql;
        });
        $this->assertTrue($this->anotherConnectionCanTakeTheLedgerLock());

        $this->store([$this->uploadedFile('a.pdf')]);

        $lock = $this->firstIndex($log, 'pg_advisory_xact_lock(');
        $this->assertLessThan($this->firstIndex($log, 'sum("size")'), $lock);
        $this->assertLessThan($this->firstIndex($log, 'insert into "uploads"'), $lock);
        $this->assertFalse($this->anotherConnectionCanTakeTheLedgerLock());
    }

    public function test_a_rolled_back_caller_leaves_no_file(): void
    {
        try {
            DB::transaction(function () {
                $this->store([$this->uploadedFile('a.pdf'), $this->uploadedFile('b.png', 'png')]);
                $this->assertSame(2, $this->storedFileCount());

                throw new RuntimeException('呼び手の失敗');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, $this->storedFileCount());
        $this->assertSame(0, Upload::query()->count());
    }

    public function test_a_file_that_cannot_be_written_rolls_back_the_rows(): void
    {
        // ディレクトリの名前の所にファイルを置き、実体を書けなくする
        Storage::disk('local')->put('uploads', 'x');

        $this->expectException(RuntimeException::class);

        try {
            $this->store([$this->uploadedFile('a.pdf')]);
        } finally {
            $this->assertSame(0, Upload::query()->count());
        }
    }

    /**
     * @param  list<UploadedFile>  $files
     * @return list<int>
     */
    private function store(array $files, ?int $userId = null): array
    {
        return app(StoreUploads::class)->execute($userId, $files);
    }

    /**
     * @param  list<string>  $log
     */
    private function firstIndex(array $log, string $needle): int
    {
        foreach ($log as $index => $sql) {
            if (str_contains($sql, $needle)) {
                return $index;
            }
        }

        $this->fail("SQL に {$needle} が無い");
    }

    /**
     * 別の接続(自動コミット)で台帳の鍵を試しに取る(CommonMasterLockTest と同じ形)。
     */
    private function anotherConnectionCanTakeTheLedgerLock(): bool
    {
        config(['database.connections.'.self::PROBE => config('database.connections.'.config('database.default'))]);
        $row = DB::connection(self::PROBE)
            ->selectOne('SELECT pg_try_advisory_xact_lock(?) AS acquired', [StoreUploads::LEDGER_LOCK_KEY]);

        return $row->acquired === true;
    }
}
