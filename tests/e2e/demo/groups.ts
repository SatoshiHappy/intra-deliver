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
        // workflow の件数と並びは、workflow-route の申請・承認を含む
        name: 'demo-workflow',
        files: [
            'tests/e2e/demo/notice.spec.ts',
            'tests/e2e/demo/workflow-route.spec.ts',
            'tests/e2e/demo/workflow.spec.ts',
        ],
    },
    {
        // ほかのファイルの変えた DB を前提にしないもの(authz・switch-user は DB に書かず、records-corrections は自分で作る
        // 業務モジュールだけを使う)。時間の長い demo のファイルを上の 2 つの組から分けて、組どうしの時間をそろえる
        name: 'demo-ほか',
        files: [
            'tests/e2e/demo/authz.spec.ts',
            'tests/e2e/demo/records-corrections.spec.ts',
            'tests/e2e/demo/switch-user.spec.ts',
        ],
    },
];
