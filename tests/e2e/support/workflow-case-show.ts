import type { Page } from '@playwright/test';

// 申請の詳細(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の 8 節の E2E)の筋が使う手助け。

/**
 * 申請書の業務モジュール「プロジェクト計画の申請」の read の許可の効果を替える tinker の文(認可設定の画面のセルを替えるのと同じ業務処理)。
 * 初期の許可はゲストユーザと認証済みユーザへの許可だけなので、effect = Deny で全員から外し、Permit で初期の形に戻す。
 */
export function moduleReadEffect(effect: 'Deny' | 'Permit'): string {
    return [
        '$group = App\\Models\\Authz\\AuthzResourceGroup::query()->where("code",',
        'App\\Enums\\Records\\RecordModuleAction::resourceGroupCode("project_plan_application"))->sole();',
        '$policies = App\\Models\\Authz\\AuthzPolicy::query()->where("authz_resource_group_id", $group->id)',
        '->where("resource_type", App\\Enums\\Records\\RecordModuleAction::RESOURCE_TYPE)',
        '->where("action", "read")->get();',
        'foreach ($policies as $policy) {',
        'app(App\\Actions\\Authz\\UpdatePolicyEffect::class)',
        `->execute($policy->id, App\\Enums\\Authz\\PolicyEffect::${effect});`,
        '}',
    ].join('');
}

/**
 * サイドパネルの帯(右端の細い帯)。
 */
export function rail(page: Page) {
    return page.getByRole('navigation', { name: 'サイドパネル', exact: true });
}

/**
 * 帯の欄のボタン(読み上げは正式の名前で始まり、件数を出す欄は件数が続く)。
 */
export function railButton(page: Page, name: string) {
    return rail(page).getByRole('button', { name: new RegExp(`^${name}`) });
}

/**
 * 開いた欄のパネル。
 */
export function pane(page: Page, name: string) {
    return page.getByRole('region', { name, exact: true });
}

/**
 * 申請の詳細の左のタブを入れるカード(名前 = 「<申請名>の詳細」)。
 */
export function tabsCard(page: Page, caseName: string) {
    return page.getByRole('region', { name: `${caseName}の詳細`, exact: true });
}

/** タブ「参照制限」の項目「参照者」の名前の横の注の文(申請の詳細.md の 4.2) */
export const VIEWERS_NOTE =
    '申請書のレコードを表示できる人です。申請を表示できる人は、承認者やフローの参照者など、申請の閲覧の規則で判定されます。';
