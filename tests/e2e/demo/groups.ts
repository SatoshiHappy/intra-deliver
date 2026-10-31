// この下の E2E の組(playwright.config.ts の組の一覧の先頭に入る。組の決まり = docs の 設計/コード構成/検査/一括検査の段.md 6.5 の並列(E))。
// 取り外すときは、このディレクトリごと消し、playwright.config.ts の取り付けの 2 行を消す。
export const demoGroups = [
    {
        // records-admin が作る業務モジュール(コード equipment)を、records-multiple・records-relative-default・records-rich-text・
        // records-row-access が使う
        name: 'demo-records',
        files: [
            'tests/e2e/demo/records-admin.spec.ts',
            'tests/e2e/demo/records-multiple.spec.ts',
            'tests/e2e/demo/records-relative-default.spec.ts',
            'tests/e2e/demo/records-rich-text.spec.ts',
            'tests/e2e/demo/records-row-access.spec.ts',
        ],
    },
    {
        // workflow の件数と並びは、workflow-route の申請・承認を含む。zz-workflow-operations は前の 2 つが案件を変えた後の状態を使う。
        // zz-workflow-proxy は案件を足すだけで、前の spec の見本の案件に触れない。zz-workflow-proxy-screens・zz-workflow-proxy-admin は
        // 代理を足して消すだけ(代理先の通知が残る)で、案件と通知の件数を前提にしないので、この 3 つの順はどれでもよい。
        // 3 つとも zz-workflow-record-apply-* の前に置く。zz-workflow-record-apply-* は番号の順に流す(名前に target を含む 3 つは
        // 申請対象からの申請で、プロジェクトを申請対象にするフローが 1 つのうちに流す。2 で 2 つ目のフローを足し、
        // 3 で申請書の業務モジュールの更新の許可を外すので、最後に置く。名前に attachment を含む 3 つの spec は 2 で足した 2 つ目のフローの入口のダイアログと、
        // 申請書のレコードへの添付の追加(更新の許可)を使うので、2 と 3 の間に置く。名前の順に流れるので、番号をそう振る。処理のダイアログの
        // 添付の spec(名前に attachment-process を含む)は更新の許可が無くても付くことを確かめるので、3 の後に置く)。
        // zz-workflow-case-operations は筋の中で申請した案件だけを変えて筋の最後に消すので、見本の案件も前の spec の件数も変えない
        // (申請と処理担当者の変更の通知が残る)。申請書の業務モジュールの更新の許可を外す apply-3 より前に流す(自分で作った申請だけを
        // 変えるので、どの spec の前提も変えない)
        name: 'demo-workflow',
        files: [
            'tests/e2e/demo/notice.spec.ts',
            'tests/e2e/demo/workflow-route.spec.ts',
            'tests/e2e/demo/workflow.spec.ts',
            'tests/e2e/demo/zz-workflow-operations.spec.ts',
            'tests/e2e/demo/zz-workflow-processing.spec.ts',
            'tests/e2e/demo/zz-workflow-proxy.spec.ts',
            'tests/e2e/demo/zz-workflow-proxy-screens.spec.ts',
            'tests/e2e/demo/zz-workflow-proxy-admin.spec.ts',
            'tests/e2e/demo/zz-workflow-record-apply-1-single.spec.ts',
            'tests/e2e/demo/zz-workflow-record-apply-1b-target-from-record.spec.ts',
            'tests/e2e/demo/zz-workflow-record-apply-1c-target-choose.spec.ts',
            'tests/e2e/demo/zz-workflow-record-apply-1d-target-proxy.spec.ts',
            'tests/e2e/demo/zz-workflow-record-apply-2-choose.spec.ts',
            'tests/e2e/demo/zz-workflow-record-apply-2b-attachment-draft.spec.ts',
            'tests/e2e/demo/zz-workflow-record-apply-2c-attachment-values.spec.ts',
            'tests/e2e/demo/zz-workflow-record-apply-2d-attachment-limits.spec.ts',
            'tests/e2e/demo/zz-workflow-case-operations.spec.ts',
            'tests/e2e/demo/zz-workflow-record-apply-3-others.spec.ts',
            'tests/e2e/demo/zz-workflow-record-apply-4-attachment-process.spec.ts',
        ],
    },
    {
        // ほかのファイルの変えた DB を前提にしないもの(authz・switch-user・workflow-connected は DB に書かず、
        // records-corrections・records-headings-admin は自分で作る業務モジュールだけを使い、
        // records-headings-show は admin が作る業務モジュールを使う = 名前の順で admin が先に走る。
        // records-related-columns は初期データの「課題」の関連レコードの列を変え、筋の最後に未選択に戻す。
        // records-admin-visibility-stop は初期データの申請書の業務モジュールの編集の画面を開くだけで保存しない。
        // app-skeleton-administrator・workflow-home は切替で利用者を替えて画面を開くだけで、DB に書かない。
        // workflow-person-links は初期データの見本の案件の処理のダイアログを開くが決定しない(DB に書かず、見本の案件の初期の状態を前提にする)。
        // app-skeleton-administrator の先頭の画面は初期データの業務の木を前提にする(前に流れる spec が業務の木を変えない))。
        // 時間の長い demo のファイルを上の 2 つの組から分けて、組どうしの時間をそろえる
        name: 'demo-ほか',
        files: [
            'tests/e2e/demo/app-skeleton-administrator.spec.ts',
            'tests/e2e/demo/authz.spec.ts',
            'tests/e2e/demo/records-admin-visibility-stop.spec.ts',
            'tests/e2e/demo/records-corrections.spec.ts',
            'tests/e2e/demo/records-headings-admin.spec.ts',
            'tests/e2e/demo/records-headings-show.spec.ts',
            'tests/e2e/demo/records-related-columns.spec.ts',
            'tests/e2e/demo/switch-user.spec.ts',
            'tests/e2e/demo/workflow-connected.spec.ts',
            'tests/e2e/demo/workflow-home.spec.ts',
            'tests/e2e/demo/workflow-person-links.spec.ts',
        ],
    },
];
