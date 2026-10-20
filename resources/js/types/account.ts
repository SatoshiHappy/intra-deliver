/**
 * 今の利用者(共有プロパティ account。サーバーの App\Queries\Account\CurrentAccountQuery::currentAccountOf が返す形。
 * docs の 設計/デモユーザー切替/今の利用者.md 5.7)。user が null ならゲスト。
 * 名称が無いとき name は null で、画面はコードを出す(docs の 設計/デモユーザー切替/README.md の方針 9)。
 */
type CurrentAccountUser = {
    id: number;
    code: string;
    name: string | null;
};

/** 組織。id は組織(departments 表)の ID で、カレント組織の切替で送る department_id に当たる */
type CurrentAccountDepartment = {
    id: number;
    code: string;
    name: string | null;
};

/** カレント組織の候補(所属の組織)。isMain = 主所属 */
export type DepartmentCandidate = CurrentAccountDepartment & {
    isMain: boolean;
};

export type CurrentAccount = {
    user: CurrentAccountUser | null;
    currentDepartment: CurrentAccountDepartment | null;
    departments: DepartmentCandidate[];
};

/**
 * 利用者メニューの節の項目(共有プロパティ userMenuSections。サーバーの App\Queries\Account\UserMenuSections::section が返す形。
 * docs の 設計/デモユーザー切替/今の利用者.md 5.7)。
 */
type UserMenuItem = {
    code: string; // 名称が無いときに画面が代わりに出すコード。節の中で一意(画面が項目の識別 = React の key に使う)
    name: string | null; // 名称(d・ロケールの値)。行が無ければ null(README の方針 9)
    note: string | null; // 名称の後ろに添える文(名称でない固定の文。役どころなど)
    url: string; // 送り先(サーバーが作る)
    method: 'get' | 'post';
    data: Record<string, string | number>; // 送る値
};

export type UserMenuSection = {
    heading: string; // 見出し
    items: UserMenuItem[];
};
