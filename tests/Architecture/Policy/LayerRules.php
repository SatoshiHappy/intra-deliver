<?php

namespace Tests\Architecture\Policy;

use PHPat\Selector\Selector;
use PHPat\Selector\SelectorInterface;
use PHPat\Test\Attributes\TestRule;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;
use Tests\Architecture\Engine\ComposerNamespaces;
use Tests\Architecture\Engine\PhpStan\SingleClassFileRule;
use Tests\Architecture\Engine\PhpStan\WiringDependenciesRule;

/**
 * 層ごとに依存してよい先と、モジュールどうしの依存(Policy。phpat の規則クラスで、phpstan.neon に phpat.test として登録する)。
 *
 * docs の 設計/コード構成/README.md 2 節の表の各行を「その層が依存してよい先だけに依存できる」規則(canOnly)にし、
 * 3 節の宣言({@see Modules::DECLARATION})からモジュールごとに 1 規則を作る。どの層も自分の層には依存してよい。
 * 書いていない先への依存は禁止なので、起動(App\Providers)を参照できる層が無いことは、各層の依存してよい先に含めないことで表す。
 * 規則は抑制の注釈では消せない(nonIgnorable)。
 *
 * 外部 = PHP 標準のクラスと、composer.lock の packages(--no-dev で入るパッケージ)の名前空間(アプリ自身の名前空間と重なるものを除く)。
 * require-dev のパッケージ(packages-dev)は database/factories から見るときだけ外部に含める。
 * DB = DATABASE_NAMESPACES の名前空間と DATABASE_CLASSES のクラス(「外部(DB を除く)」の層では依存先から外す)。
 *
 * phpat が見ない場所: クラスの外のコード(配線のファイル = routes/・bootstrap/・config/ など。依存は PHPStan の自作規則
 * {@see WiringDependenciesRule} が止める)と、App\ の中の匿名クラス(app/ には書けない =
 * {@see SingleClassFileRule})。DB 定義(database/ の 3 つ)はファイルのパスで選ぶので、匿名クラスにも掛かる。
 */
final class LayerRules
{
    public const ENTRANCE = 'App\Http';

    public const ACTIONS = 'App\Actions';

    public const QUERIES = 'App\Queries';

    public const MODELS = 'App\Models';

    public const ENUMS = 'App\Enums';

    public const SUPPORT = 'App\Support';

    public const PROVIDERS = 'App\Providers';

    /** キューのジョブ(App\Jobs\<M>)と、コマンド(App\Console\Commands\<M>)の層(コード構成 1 節。最初に使うモジュール Ai で足した) */
    public const JOBS = 'App\Jobs';

    public const CONSOLE = 'App\Console';

    /** 層の規則を持つ App\ の名前空間(台帳① の基底ディレクトリはこの中にあること = tests/Architecture/CodeLayoutTest.php) */
    public const LAYER_NAMESPACES = [self::ENTRANCE, self::ACTIONS, self::QUERIES, self::MODELS, self::ENUMS, self::SUPPORT, self::PROVIDERS, self::JOBS, self::CONSOLE];

    /** 入口(共通) = App\Http\Middleware の直下のクラスと、コントローラの基底 */
    public const COMMON_MIDDLEWARE_NAMESPACE = 'App\Http\Middleware';

    public const BASE_CONTROLLER = 'App\Http\Controllers\Controller';

    /**
     * DB(コード構成 2 節)の名前空間とクラス。PHP 標準の PDO は PDO の拡張のクラス(PDO・PDOStatement・PDORow・PDOException と、
     * PHP 8.4 のドライバごとの派生 Pdo\*)として扱う(派生や文のクラスを通して DB に届く抜け道を残さない)。
     */
    public const DATABASE_NAMESPACES = ['Illuminate\Database', 'Illuminate\Contracts\Database', 'Pdo'];

    public const DATABASE_CLASSES = ['Illuminate\Support\Facades\DB', 'Illuminate\Support\Facades\Schema', 'PDO', 'PDOStatement', 'PDORow', 'PDOException'];

    /**
     * DB 定義(コード構成 2 節)。規則を掛けるクラスはファイルのパス(各ディレクトリの配下すべて)で選ぶ
     * (マイグレーションと、メソッドの中に書いた匿名クラスは名前空間を持たないため。名前のあるクラスは PSR-4 の段で名前空間とパスが一致する)。
     */
    public const FACTORIES = 'Database\Factories';

    public const SEEDERS = 'Database\Seeders';

    public const MIGRATIONS_PATH = '#/database/migrations/.+\.php$#D';

    public const FACTORIES_PATH = '#/database/factories/.+\.php$#D';

    public const SEEDERS_PATH = '#/database/seeders/.+\.php$#D';

    /** 外部の名前空間を読む composer.lock と、アプリ自身の名前空間を読む composer.json(アプリのルート) */
    private const COMPOSER_LOCK = __DIR__.'/../../../composer.lock';

    private const COMPOSER_JSON = __DIR__.'/../../../composer.json';

    /** @var list<SelectorInterface>|null */
    private ?array $external = null;

    /**
     * @param  array<string, list<string>>  $modules  モジュールの宣言(自己証明の fixture 用の phpstan 設定だけが差し替える)
     */
    public function __construct(private readonly array $modules = Modules::DECLARATION) {}

    #[TestRule]
    public function entranceDependencies(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::ENTRANCE))
            ->excluding(...self::commonEntrance())
            ->canOnly()->dependOn()
            ->classes(...self::namespaces(self::ENTRANCE, self::ACTIONS, self::QUERIES, self::ENUMS, self::SUPPORT), ...$this->external())
            ->excluding(...self::database())
            ->because('コード構成 2 節: 入口 → 業務処理・読み取り・入口・列挙・共有・外部(DB を除く)')
            ->nonIgnorable();
    }

    #[TestRule]
    public function commonEntranceDependencies(): Rule
    {
        return PHPat::rule()
            ->classes(...self::commonEntrance())
            ->canOnly()->dependOn()
            ->classes(...self::commonEntrance(), ...self::namespaces(self::SUPPORT), ...$this->external())
            ->excluding(...self::database())
            ->because('コード構成 2 節: 入口(共通)→ 共有・外部(DB を除く)。どのモジュールにも依存しない')
            ->nonIgnorable();
    }

    #[TestRule]
    public function actionsDependencies(): Rule
    {
        return $this->layer(self::ACTIONS, [self::QUERIES, self::MODELS, self::ENUMS, self::SUPPORT, self::JOBS], true, 'コード構成 2 節: 業務処理 → 読み取り・永続化・列挙・共有・ジョブ(投入)・外部');
    }

    /**
     * キューのジョブとコマンドは業務処理を呼んで動き、DB を直接書かない。規則 ID の末尾が層の名前(Jobs・Console)になる。
     *
     * @return iterable<string, Rule>
     */
    #[TestRule]
    public function workerDependencies(): iterable
    {
        foreach (['Jobs' => self::JOBS, 'Console' => self::CONSOLE] as $name => $layer) {
            yield $name => $this->layer($layer, [self::ACTIONS, self::QUERIES, self::ENUMS, self::SUPPORT], false, 'コード構成 2 節: ジョブ・コマンド → 業務処理・読み取り・列挙・共有・外部(DB を除く)');
        }
    }

    #[TestRule]
    public function queriesDependencies(): Rule
    {
        return $this->layer(self::QUERIES, [self::MODELS, self::ENUMS, self::SUPPORT], true, 'コード構成 2 節: 読み取り → 永続化・列挙・共有・外部');
    }

    #[TestRule]
    public function modelsDependencies(): Rule
    {
        return $this->layer(self::MODELS, [self::ENUMS, self::SUPPORT], true, 'コード構成 2 節: 永続化 → 列挙・共有・外部');
    }

    #[TestRule]
    public function enumsDependencies(): Rule
    {
        return $this->layer(self::ENUMS, [self::SUPPORT], false, 'コード構成 2 節: 列挙 → 共有・外部(DB を除く)');
    }

    #[TestRule]
    public function supportDependencies(): Rule
    {
        return $this->layer(self::SUPPORT, [], false, 'コード構成 2 節: 共有 → 外部(DB を除く)');
    }

    #[TestRule]
    public function providersDependencies(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::PROVIDERS))
            ->canOnly()->dependOn()
            ->classes(Selector::inNamespace('App'), ...$this->external())
            ->because('コード構成 2 節: 起動 → App\ のすべての層と外部')
            ->nonIgnorable();
    }

    #[TestRule]
    public function migrationsDependencies(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::withFilepath(self::MIGRATIONS_PATH, true))
            ->canOnly()->dependOn()
            ->classes(...$this->external())
            ->because('コード構成 2 節: DB 定義 database/migrations → 外部')
            ->nonIgnorable();
    }

    #[TestRule]
    public function factoriesDependencies(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::withFilepath(self::FACTORIES_PATH, true))
            ->canOnly()->dependOn()
            ->classes(
                ...self::namespaces(self::FACTORIES, self::MODELS, self::ENUMS, self::SUPPORT),
                ...$this->external(),
                ...self::packageNamespaces('packages-dev'),
            )
            ->because('コード構成 2 節: DB 定義 database/factories → 永続化・列挙・共有・外部(require-dev のパッケージを含む)')
            ->nonIgnorable();
    }

    #[TestRule]
    public function seedersDependencies(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::withFilepath(self::SEEDERS_PATH, true))
            ->canOnly()->dependOn()
            ->classes(...self::namespaces(self::SEEDERS, self::ACTIONS, self::MODELS, self::ENUMS, self::SUPPORT), ...$this->external())
            ->because('コード構成 2 節: DB 定義 database/seeders → 業務処理・永続化・列挙・共有・外部')
            ->nonIgnorable();
    }

    /**
     * 業務処理は、業務処理に依存できない層(読み取り・永続化・列挙・共有)の interface を実装しない。
     * 依存の逆転(コード構成 3 節)でその層の interface を業務処理が実装すると、業務処理に依存できない層から書き込みに届くため
     * (コード構成 2 節「読み取りは業務処理へ依存できない」の抜け道を塞ぐ)。
     */
    #[TestRule]
    public function actionsImplementation(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::ACTIONS))
            ->shouldNot()->implement()
            ->classes(...self::namespaces(self::QUERIES, self::MODELS, self::ENUMS, self::SUPPORT))
            ->because('業務処理に依存できない層の interface を業務処理が実装すると、その層から書き込みに届く')
            ->nonIgnorable();
    }

    /**
     * 業務処理は、業務処理に依存できない層(読み取り・永続化・列挙・共有)のクラスを継承しない(祖先のすべて)。
     * その層の抽象クラスを業務処理が継承して起動が束縛すると、interface と同じく、その層から書き込みに届くため。
     */
    #[TestRule]
    public function actionsInheritance(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::ACTIONS))
            ->shouldNot()->extend()
            ->classes(...self::namespaces(self::QUERIES, self::MODELS, self::ENUMS, self::SUPPORT))
            ->because('業務処理に依存できない層のクラスを業務処理が継承すると、その層から書き込みに届く')
            ->nonIgnorable();
    }

    /**
     * モジュールごとに 1 規則(自分・宣言した依存先・どのモジュールにも属さないコードだけ)。規則 ID の末尾がモジュールの名前になる。
     * どのモジュールにも属さないコード = モジュールの置き場({@see Modules::NAMESPACES})の下の、宣言したモジュールの名前空間の外のすべて
     * (層の規則と両方を満たす)。
     *
     * @return iterable<string, Rule>
     */
    #[TestRule]
    public function moduleDependencies(): iterable
    {
        $everyModule = array_merge(...array_map(Modules::selectors(...), array_keys($this->modules)));

        foreach ($this->modules as $module => $dependencies) {
            $allowed = array_merge(...array_map(Modules::selectors(...), [$module, ...$dependencies]));

            yield $module => PHPat::rule()
                ->classes(...Modules::selectors($module))
                ->canOnly()->dependOn()
                ->classes(...[...$allowed, Selector::NoneOf(...$everyModule)])
                ->because('コード構成 3 節: モジュール → 自分・宣言した依存先・どのモジュールにも属さないコード')
                ->nonIgnorable();
        }
    }

    /**
     * @param  non-empty-string  $layer
     * @param  list<non-empty-string>  $targets  自分の層のほかに依存してよい App\ の層
     */
    private function layer(string $layer, array $targets, bool $database, string $because): Rule
    {
        $rule = PHPat::rule()
            ->classes(Selector::inNamespace($layer))
            ->canOnly()->dependOn()
            ->classes(...self::namespaces($layer, ...$targets), ...$this->external());

        return ($database ? $rule : $rule->excluding(...self::database()))
            ->because($because)
            ->nonIgnorable();
    }

    /**
     * 外部 = PHP 標準のクラスと、composer.lock の packages の名前空間。
     *
     * @return list<SelectorInterface>
     */
    private function external(): array
    {
        return $this->external ??= [Selector::isStandardClass(), ...self::packageNamespaces('packages')];
    }

    /**
     * @return list<SelectorInterface>
     */
    private static function packageNamespaces(string $section): array
    {
        return self::namespaces(...ComposerNamespaces::fromLock(self::COMPOSER_LOCK, $section, ComposerNamespaces::fromManifest(self::COMPOSER_JSON)));
    }

    /**
     * @return list<SelectorInterface>
     */
    private static function database(): array
    {
        return [
            ...self::namespaces(...self::DATABASE_NAMESPACES),
            ...array_map(fn (string $class): SelectorInterface => Selector::classname($class), self::DATABASE_CLASSES),
        ];
    }

    /**
     * @return list<SelectorInterface>
     */
    private static function commonEntrance(): array
    {
        return [
            Selector::inNamespace('/^'.preg_quote(self::COMMON_MIDDLEWARE_NAMESPACE, '/').'$/D', true),
            Selector::classname(self::BASE_CONTROLLER),
        ];
    }

    /**
     * @param  non-empty-string  ...$namespaces
     * @return list<SelectorInterface>
     */
    private static function namespaces(string ...$namespaces): array
    {
        return array_values(array_map(fn (string $namespace): SelectorInterface => Selector::inNamespace($namespace), $namespaces));
    }
}
