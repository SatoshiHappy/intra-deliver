<?php

namespace Tests\Architecture;

use App\Actions\Ai\RunBudget;
use App\Actions\Master\Api\CommonMasterWriteLock;
use App\Actions\Meeting\MeetingLock;
use App\Actions\Records\LocksRecordModule;
use App\Actions\Upload\Api\StoreUploads;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClassConstant;

/**
 * 勧告ロックの鍵が、モジュールをまたいで重ならないことの検査(docs の 設計/コード構成/検査/README.md 6.2。重なると、別の目的の処理どうしが
 * 互いを待つ。例: 初期化の鎖錠と添付の台帳が同じ鍵で、台帳を取る要求が初期化の手順 5 を待たせた)。
 *
 * 1. app/ の中で勧告ロックの関数(pg_advisory…・pg_try_advisory…)を呼ぶファイルは、一覧({@see self::declaredKeys()})にある。
 *    一覧にあるファイルは、実際に勧告ロックを呼ぶ(鍵を取る箇所を足す・外すと、一覧の更新を強いる)。
 * 2. 一覧の鍵(単独の鍵は幅 1、起点 + id の鍵は起点以上・起点 + {@see self::RANGE_WIDTH} 未満)どうしが重ならない。
 * 3. 自己証明: 重なりと一覧に無いファイルを、1・2 の判定が見つける。
 *
 * 鍵の値は定数(と config/reset.php)から読む。字句の照合なので、鍵を実行時に組み立てて別の関数で取る経路は止まらない(レビューで見る)。
 */
final class AdvisoryLockKeysTest extends TestCase
{
    /** 起点 + id の鍵の幅(id はこれより小さい) */
    private const RANGE_WIDTH = 100_000_000_000_000;

    private const CALL = '/pg_(try_)?advisory/';

    public function test_every_file_taking_an_advisory_lock_is_listed_and_every_listed_file_takes_one(): void
    {
        $root = dirname(__DIR__, 2);

        $calling = self::filesCalling($root.'/app', $root);
        $declared = array_keys(self::declaredKeys($root));
        sort($calling);
        sort($declared);

        $this->assertSame($declared, $calling);
    }

    public function test_the_declared_keys_do_not_overlap(): void
    {
        $keys = self::declaredKeys(dirname(__DIR__, 2));

        $this->assertGreaterThan(1, count($keys));
        $this->assertSame([], self::overlaps($keys));
    }

    public function test_detects_overlapping_keys_and_files_missing_from_the_list(): void
    {
        $keys = [
            'a.php' => [10, 1],
            'b.php' => [10, 1],
            'c.php' => [100, self::RANGE_WIDTH],
            'd.php' => [100 + self::RANGE_WIDTH - 1, 1],
            'e.php' => [100 + self::RANGE_WIDTH, 1],
        ];

        $this->assertSame(['a.php と b.php', 'c.php と d.php'], self::overlaps($keys));

        $directory = sys_get_temp_dir().'/advisory-keys-'.bin2hex(random_bytes(8));
        mkdir($directory);
        file_put_contents($directory.'/calls.php', "<?php\nDB::select('SELECT pg_try_advisory_lock(?)', [1]);\n");
        file_put_contents($directory.'/quiet.php', "<?php\n// no lock\n");

        try {
            $this->assertSame(['calls.php'], self::filesCalling($directory, $directory));
        } finally {
            unlink($directory.'/calls.php');
            unlink($directory.'/quiet.php');
            rmdir($directory);
        }
    }

    /**
     * 一覧: 勧告ロックを取るファイル($root 起点のパス) => [鍵か起点, 幅]。値は定数(と config/reset.php)から読む。
     *
     * @return array<string, array{int, int}>
     */
    private static function declaredKeys(string $root): array
    {
        $reset = require $root.'/config/reset.php';
        $resetKey = is_array($reset) && is_int($reset['lock_key'] ?? null) ? $reset['lock_key'] : 0;

        $master = self::constant(CommonMasterWriteLock::class, 'COMMON_MASTER_LOCK_KEY');
        $records = self::constant(LocksRecordModule::class, 'RECORD_MODULE_LOCK_BASE');
        $ledger = self::constant(StoreUploads::class, 'LEDGER_LOCK_KEY');
        $meeting = self::constant(MeetingLock::class, 'KEY_BASE');

        return [
            'app/Actions/Master/Api/CommonMasterWriteLock.php' => [$master, 1],
            'app/Actions/Records/LocksRecordModule.php' => [$records, self::RANGE_WIDTH],
            'app/Actions/Ai/RunBudget.php' => [self::constant(RunBudget::class, 'LOCK_KEY'), 1],
            'app/Actions/Upload/Api/StoreUploads.php' => [$ledger, 1],
            'app/Actions/Meeting/MeetingLock.php' => [$meeting, self::RANGE_WIDTH],
            'app/Actions/Reset/ResetLock.php' => [$resetKey, 1],
        ];
    }

    /**
     * @param  class-string  $class
     */
    private static function constant(string $class, string $name): int
    {
        $value = (new ReflectionClassConstant($class, $name))->getValue();

        return is_int($value) ? $value : 0;
    }

    /**
     * $directory の下で勧告ロックの関数を呼ぶファイル($root 起点のパス)。
     *
     * @return list<string>
     */
    private static function filesCalling(string $directory, string $root): array
    {
        $found = [];
        $tree = new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($tree);

        foreach ($files as $file) {
            $path = (string) $file;

            if (str_ends_with($path, '.php') && preg_match(self::CALL, (string) file_get_contents($path)) === 1) {
                $found[] = substr($path, strlen($root) + 1);
            }
        }

        return $found;
    }

    /**
     * 範囲([起点, 起点 + 幅))が重なる 2 つのファイルの組。
     *
     * @param  array<string, array{int, int}>  $keys
     * @return list<string>
     */
    private static function overlaps(array $keys): array
    {
        $names = array_keys($keys);
        $overlaps = [];

        foreach ($names as $index => $first) {
            foreach (array_slice($names, $index + 1) as $second) {
                [$start1, $width1] = $keys[$first];
                [$start2, $width2] = $keys[$second];

                if ($start1 < $start2 + $width2 && $start2 < $start1 + $width1) {
                    $overlaps[] = "{$first} と {$second}";
                }
            }
        }

        return $overlaps;
    }
}
