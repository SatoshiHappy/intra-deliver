<?php

namespace App\Providers;

use App\Actions\Ai\Api\PromptKind;
use App\Actions\Ai\Api\RequestPrompt;
use App\Actions\Ai\RunBudget;
use App\Models\Master\User;
use App\Queries\Authz\ResourceTypes;
use App\Queries\Authz\ServiceResourceTypes;
use App\Queries\Authz\UserRoleReferences;
use App\Queries\Master\UserReferences;
use App\Queries\Menu\Api\HomeSections;
use App\Queries\Menu\Api\MenuItemVisibility;
use App\Queries\Menu\MenuResourceTypes;
use App\Queries\Menu\QuickActionsHomeSection;
use App\Queries\Notice\Api\NoticeTargets;
use App\Queries\Records\Api\RecordHolds;
use App\Queries\Records\Api\RecordShowSections;
use App\Queries\Records\RecordMenuItemVisibility;
use App\Queries\Records\RecordModuleResourceTypes;
use App\Queries\Workflow\ActiveApplicationsHomeSection;
use App\Queries\Workflow\CaseTargetTerm;
use App\Queries\Workflow\OwnCaseTargetTerm;
use App\Queries\Workflow\PendingCasesHomeSection;
use App\Queries\Workflow\ProcessedThisMonthHomeSection;
use App\Queries\Workflow\ProxyTargetTerm;
use App\Queries\Workflow\WorkflowCountersHomeSection;
use App\Queries\Workflow\WorkflowLeadHomeSection;
use App\Queries\Workflow\WorkflowRecordHolds;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // 配線のファイルはモデルを名指せないため起動で設定し、ほかのプロバイダの boot() より前にガードへ渡す。
        config(['auth.providers.users.model' => User::class]);

        // 依存の逆転(docs の 設計/コード構成/README.md 3 節): Master の業務処理が、後ろのモジュールのユーザーを指す期間の行まで確かめる
        $this->app->tag([UserRoleReferences::class], UserReferences::TAG);

        // 依存の逆転: 認可の業務処理が、各モジュールの定義するリソースタイプとアクションの一覧の和で検査する(docs の 設計/メニュー/認可との結び付け.md 2.1)
        $this->app->tag([ServiceResourceTypes::class, MenuResourceTypes::class, RecordModuleResourceTypes::class], ResourceTypes::TAG);

        // 依存の逆転: 業務データの入れ物の書き込みが、未完了の案件が指すレコードを持ち主の規則で守る(docs の 設計/ワークフロー/業務データとの接続/README.md 4 節)
        $this->app->tag([WorkflowRecordHolds::class], RecordHolds::TAG);

        // 依存の逆転: メニューの表示判定が、ルートを受け持つモジュールの追加の判定で隠す(docs の 設計/ワークフロー/申請の周辺/メニューと導線.md 1.2)
        $this->app->tag([RecordMenuItemVisibility::class], MenuItemVisibility::TAG);

        // 依存の逆転: AI の実行の種類(docs の 設計/AI/公開の口.md 3.1)。呼び手のモジュール(Meeting など)が種類の実装をここに足す。
        // コードは config/ai_runs.php の kinds にあるものだけ(同じコードが 2 つ・設定に無いコードは起動のテストで落とす)
        $this->app->tag([], PromptKind::TAG);

        // 依存の逆転: 通知の行の移り先を、対象の種類を持つモジュールの実装が答える(docs の 設計/ワークフロー/案件の運用/通知.md の 5 節)。
        // 対象の種類を持つモジュールが実装をここに足す(実装の無い対象の種類の行は共通の 1 行になる)
        $this->app->tag([], NoticeTargets::TAG);

        // 依存の逆転: レコードの詳細のサイドパネルに、後ろのモジュールが節を足す(docs の
        // 設計/業務データの入れ物/レコードの共通機能/詳細の画面/節の口.md の 2 節。並び = この配列の順)。節を足すモジュールが実装をここに足す
        $this->app->tag([], RecordShowSections::TAG);

        // 処理対象者に当たる条件の項(docs の 設計/ワークフロー/ルートの深さ/認可と一覧.md 3 節。代理の項 = 設計/ワークフロー/案件の運用/案件の操作/README.md の 4.2 節)
        $this->app->tag([OwnCaseTargetTerm::class, ProxyTargetTerm::class], CaseTargetTerm::TAG);

        // 依存の逆転: ホームの入口が、各モジュールの足す節を登録の順に出す(docs の 設計/メニュー/ホーム.md 6.2)。
        // 右列 = 主な操作 → 進行中の申請(主な操作をワークフローの経路の節より前に = ホーム.md 6.5、ワークフローの 5 つはこの順 = 設計/ワークフロー/画面.md 7 節)
        $this->app->tag([
            QuickActionsHomeSection::class,
            WorkflowLeadHomeSection::class,
            WorkflowCountersHomeSection::class,
            PendingCasesHomeSection::class,
            ProcessedThisMonthHomeSection::class,
            ActiveApplicationsHomeSection::class,
        ], HomeSections::TAG);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();

        // 本番以外(開発・E2E のサーバー・phpunit)では関係の遅延読み込みを例外にする(docs の 設計/コード構成/検査.md 6 節)。
        // 2 行以上の結果から作ったモデルの遅延読み込みだけが例外になる(Laravel の仕様)。1 件からの N+1 はレビューで見る
        Model::preventLazyLoading(! $this->app->isProduction());
    }

    /**
     * 名前つきの要求の頻度の制限(Laravel 標準の RateLimiter)。
     *
     * ai-requests = AI の依頼の入口に掛ける(docs の 設計/AI/実行と費用.md 5.2。訪問者 1 人が日の費用の上限を使い切らないよう、接続元の IP ごとに 1 日の回数)。
     * 超えたときは、上限を超えた依頼と同じ見え方にするため検証の失敗(422・キー ai)にする。付け忘れは tests/Feature/Ai/AiRequestRoutesTest が止める。
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('ai-requests', fn (Request $request): Limit => Limit::perDay(RequestPrompt::REQUESTS_PER_DAY)->by((string) $request->ip())->response(
            fn () => throw ValidationException::withMessages([RunBudget::ERROR_KEY => RequestPrompt::TOO_MANY_REQUESTS]),
        ));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // 本番は TLS を終端する手前のプロキシの後ろで動くので、生成する URL(資産・302 の行き先)をいつも https にする
        // (X-Forwarded-Proto が届かなくても。docs の 設計/公開の配備/入口.md 5.4 の (c))
        URL::forceHttps(app()->isProduction());
    }
}
