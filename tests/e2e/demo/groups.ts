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
        // zz-workflow-proxy は案件を足すだけで、前の spec の見本の案件に触れないので最後に置く
        name: 'demo-workflow',
        files: [
            'tests/e2e/demo/notice.spec.ts',
            'tests/e2e/demo/workflow-route.spec.ts',
            'tests/e2e/demo/workflow.spec.ts',
            'tests/e2e/demo/zz-workflow-operations.spec.ts',
            'tests/e2e/demo/zz-workflow-processing.spec.ts',
            'tests/e2e/demo/zz-workflow-proxy.spec.ts',
        ],
    },
    {
        // ほかのファイルの変えた DB を前提にしないもの(authz・switch-user・workflow-connected は DB に書かず、
        // records-corrections・records-headings-admin は自分で作る業務モジュールだけを使い、
        // records-headings-show は admin が作る業務モジュールを使う = 名前の順で admin が先に走る。
        // records-related-columns は初期データの「課題」の関連レコードの列を変え、筋の最後に未選択に戻す)。
        // 時間の長い demo のファイルを上の 2 つの組から分けて、組どうしの時間をそろえる
        name: 'demo-ほか',
        files: [
            'tests/e2e/demo/authz.spec.ts',
            'tests/e2e/demo/records-corrections.spec.ts',
            'tests/e2e/demo/records-headings-admin.spec.ts',
            'tests/e2e/demo/records-headings-show.spec.ts',
            'tests/e2e/demo/records-related-columns.spec.ts',
            'tests/e2e/demo/switch-user.spec.ts',
            'tests/e2e/demo/workflow-connected.spec.ts',
        ],
    },
];
