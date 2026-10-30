<?php

namespace App\Providers;

use App\Actions\Ai\Api\PromptKind;
use App\Actions\Ai\Api\TranscriptionKind;
use App\Actions\Ai\CarryOverSpending;
use App\Actions\Meeting\MeetingMinutesKind;
use App\Actions\Meeting\MeetingTranscriptionKind;
use App\Actions\Reset\Api\ResetParticipant;
use App\Models\Master\User;
use App\Queries\Account\Api\UserMenuSections;
use App\Queries\Authz\Api\ResourceTypes;
use App\Queries\Authz\ServiceResourceTypes;
use App\Queries\Authz\UserRoleReferences;
use App\Queries\Master\UserReferences;
use App\Queries\Meeting\MeetingScreenAddons;
use App\Queries\Meeting\MeetingTranscriptSection;
use App\Queries\Menu\Api\HomeSections;
use App\Queries\Menu\Api\MenuItemVisibility;
use App\Queries\Menu\MenuResourceTypes;
use App\Queries\Menu\QuickActionsHomeSection;
use App\Queries\Notice\Api\NoticeTargets;
use App\Queries\Records\Api\RecordApplyEntries;
use App\Queries\Records\Api\RecordHolds;
use App\Queries\Records\Api\RecordModuleVisibilityStops;
use App\Queries\Records\Api\RecordScreenAddons;
use App\Queries\Records\Api\RecordShowSections;
use App\Queries\Records\RecordMenuItemVisibility;
use App\Queries\Records\RecordModuleResourceTypes;
use App\Queries\Records\RecordNoticeTargets;
use App\Queries\Reset\ResetSettings;
use App\Queries\Workflow\ActiveApplicationsHomeSection;
use App\Queries\Workflow\CaseTargetTerm;
use App\Queries\Workflow\OwnCaseTargetTerm;
use App\Queries\Workflow\PendingCasesHomeSection;
use App\Queries\Workflow\ProcessedThisMonthHomeSection;
use App\Queries\Workflow\ProxyTargetTerm;
use App\Queries\Workflow\WorkflowApplicationTargetSection;
use App\Queries\Workflow\WorkflowCountersHomeSection;
use App\Queries\Workflow\WorkflowFlowResourceTypes;
use App\Queries\Workflow\WorkflowLeadHomeSection;
use App\Queries\Workflow\WorkflowNoticeTargets;
use App\Queries\Workflow\WorkflowRecordApplyEntries;
use App\Queries\Workflow\WorkflowRecordHolds;
use App\Queries\Workflow\WorkflowRecordModuleVisibilityStops;
use App\Queries\Workflow\WorkflowUserMenuSection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

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
        $this->app->tag([ServiceResourceTypes::class, MenuResourceTypes::class, RecordModuleResourceTypes::class, WorkflowFlowResourceTypes::class], ResourceTypes::TAG);

        $this->tagRecordWriteGuards();

        // 依存の逆転: 業務データの入れ物の詳細の「申請する」が、レコードから申請できる入口を聞く(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/README.md の 2 節)
        $this->app->tag([WorkflowRecordApplyEntries::class], RecordApplyEntries::TAG);

        // 依存の逆転: メニューの表示判定が、ルートを受け持つモジュールの追加の判定で隠す(docs の 設計/ワークフロー/申請の周辺/メニューと導線.md 1.2)
        $this->app->tag([RecordMenuItemVisibility::class], MenuItemVisibility::TAG);

        // 依存の逆転: AI の実行の種類(文章・文字起こし。docs の 設計/AI/公開の口.md 3.1)。呼び手のモジュールが実装を足す(コードは設定にあり型をまたいで一意)
        $this->app->tag([MeetingMinutesKind::class], PromptKind::TAG);
        $this->app->tag([MeetingTranscriptionKind::class], TranscriptionKind::TAG);

        // 依存の逆転: 毎日の初期化が残す表と、表を空にする直前の処理(AI の費用の繰り越し = docs の 設計/公開の配備/毎日の初期化.md 7 節)。
        // 初期化で消えては困る表を持つモジュールが実装をここに足す
        $this->app->tag([CarryOverSpending::class], ResetParticipant::TAG);

        // 依存の逆転: 通知の行の移り先を、対象の種類を持つモジュールの実装が答える(docs の 設計/ワークフロー/案件の運用/通知.md の 5 節)。
        // 対象の種類を持つモジュールが実装をここに足す(実装の無い対象の種類の行は共通の 1 行になる)
        $this->app->tag([WorkflowNoticeTargets::class, RecordNoticeTargets::class], NoticeTargets::TAG);

        // 依存の逆転: レコードの詳細のサイドパネルに、後ろのモジュールが節を足す(docs の
        // 設計/業務データの入れ物/レコードの共通機能/詳細の画面/節の口.md の 2 節。並び = この配列の順)。節を足すモジュールが実装をここに足す
        $this->app->tag(
            [MeetingTranscriptSection::class, WorkflowApplicationTargetSection::class],
            RecordShowSections::TAG,
        );

        // 依存の逆転: 業務の一覧とレコードの詳細に、後ろのモジュールがダイアログを開く操作・札・注を足す(docs の
        // 設計/業務データの入れ物/画面/業務の画面.md の 2.5。並び = この配列の順)。足すモジュールが実装をここに足す
        $this->app->tag([MeetingScreenAddons::class], RecordScreenAddons::TAG);

        // 処理対象者に当たる条件の項(docs の 設計/ワークフロー/ルートの深さ/認可と一覧.md 3 節。代理の項 = 設計/ワークフロー/案件の運用/案件の操作/README.md の 4.2 節)
        $this->app->tag([OwnCaseTargetTerm::class, ProxyTargetTerm::class], CaseTargetTerm::TAG);

        $this->tagHomeSections();
        $this->tagUserMenuSections();
    }

    /**
     * 業務データの入れ物の書き込みを守る依存の逆転の口(レコードの持ち主・業務モジュールの表示の止め)を、ワークフローの実装で登録する。
     */
    private function tagRecordWriteGuards(): void
    {
        // 依存の逆転: 業務データの入れ物の書き込みが、未完了の案件が指すレコードを持ち主の規則で守る(docs の 設計/ワークフロー/業務データとの接続/申請と書き込み.md 4 節)
        $this->app->tag([WorkflowRecordHolds::class], RecordHolds::TAG);

        // 依存の逆転: 業務モジュールを変える業務処理と管理の編集の画面が、コンテンツが指す業務モジュールの表示の止めを聞く(ADR 0031。
        // docs の 設計/ワークフロー/業務データとの接続/公開の口.md 7 節)
        $this->app->tag([WorkflowRecordModuleVisibilityStops::class], RecordModuleVisibilityStops::TAG);
    }

    /**
     * 依存の逆転: 利用者メニューに、後ろのモジュールが節を足す(docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 5 節)。
     * ほかのプロバイダより先に登録するので、利用者の切替の節より前に並ぶ。
     */
    private function tagUserMenuSections(): void
    {
        $this->app->tag([WorkflowUserMenuSection::class], UserMenuSections::TAG);
    }

    /**
     * 依存の逆転: ホームの入口が、各モジュールの足す節を登録の順に出す(docs の 設計/メニュー/ホーム.md 6.2)。
     * 右列 = 主な操作 → 進行中の申請(主な操作をワークフローの経路の節より前に = ホーム.md 6.5、ワークフローの 5 つはこの順 = 設計/ワークフロー/画面/ホームと意匠とE2E.md 7 節)
     */
    private function tagHomeSections(): void
    {
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

        // 本番以外(開発・E2E のサーバー・phpunit)では関係の遅延読み込みを例外にする(docs の 設計/コード構成/検査/README.md 6.2)。
        // 2 行以上の結果から作ったモデルの遅延読み込みだけが例外になる(Laravel の仕様)。1 件からの N+1 はレビューで見る
        Model::preventLazyLoading(! $this->app->isProduction());

        // 毎日の初期化がオンのときの置き場の確かめ(docs の 設計/公開の配備/毎日の初期化.md 5 節の「置き場の確かめ」)
        $this->app->make(ResetSettings::class)->ensureSharedStores();
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
        // (X-Forwarded-Proto が届かなくても。docs の 設計/公開の配備/入口/README.md 5.4 の (c))
        URL::forceHttps(app()->isProduction());
    }
}
