/**
 * 組織図のページ Master/OrganizationChart の props(サーバーの App\Queries\Master\OrganizationChartQuery が返す形。
 * docs の 設計/共通マスタ/検索と組織図.md 1.1)。
 * 名称は基準日・ロケールの値で、行が無ければ null(画面がコードを出す = docs の 設計/デモユーザー切替/README.md の方針 9)。
 */

/** 組織 */
export type NamedDepartment = { id: number; code: string; name: string | null };

/** 組織図の木の 1 つの組織。children は兄弟の並び(1.2) */
export type OrganizationNode = NamedDepartment & {
    children: OrganizationNode[];
};

/** 選んだ組織の所属ユーザー(基準日に成り立つ所属 1 つにつき 1 行) */
export type OrganizationMember = {
    id: number; // users.id
    code: string;
    name: string | null;
    isMain: boolean; // 主所属か(false = 兼務)
    posts: { id: number; code: string; name: string | null }[]; // 所属役職の役職(1.3 の並び)
};

/**
 * 共通マスタの検索の口 master.search の応答(サーバーの App\Queries\Master\MasterSearchQuery が返す形。
 * docs の 設計/共通マスタ/検索と組織図.md 2 節)。ユーザー・組織とも、並べた先頭から 10 件まで。
 */
export type MasterSearchResult = {
    users: {
        id: number; // users.id
        code: string;
        name: string | null;
        mainDepartment: NamedDepartment | null; // 組織図の木にある主所属(無ければ null)
    }[];
    departments: NamedDepartment[];
};
