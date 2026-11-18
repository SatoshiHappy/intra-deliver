import { noticeTypeLabel } from '@/lib/Notice/noticeText';
import type {
    NoticeAdminSettingRow,
    NoticeOwnSettingRow,
    NoticeSettingSurface,
} from '@/types';

/** ゲストの画面の文(docs の 設計/ワークフロー/申請の周辺/通知の受け取りの設定/ルートと文言と検査.md の 7 節) */
export const GUEST_TEXT = '設定できる通知はありません';

/** 必須の行の札(利用者の画面) */
const REQUIRED_BADGE = '必須(管理者の設定)';

/** 部分再読み込みで取り直す props(行を作り直す) */
export const SETTING_PROPS = ['surfaces'];

/** 1 行の見え方(スイッチの値・押せるか・語・読み上げの名前・脇の札) */
export type NoticeSettingRowView = {
    type: string;
    /** 種類の画面の語(鈴の種類の札と同じ) */
    label: string;
    /** スイッチの値(利用者の画面 = ミュート中、管理の画面 = 必須) */
    checked: boolean;
    /** 押せない行(必須の種類。利用者の画面だけ) */
    locked: boolean;
    /** スイッチの脇の今の値の語 */
    word: string;
    /** スイッチの読み上げの名前 */
    switchName: string;
    /** 行の脇の札。無ければ null */
    badge: string | null;
};

/** 面の見え方(見出しの語と行。並びは props のまま) */
export type NoticeSettingSurfaceView = {
    prefix: string;
    heading: string;
    rows: NoticeSettingRowView[];
};

/**
 * 面の見出しの語(docs の 設計/ワークフロー/申請の周辺/通知の受け取りの設定/種類と作る規則.md の 1 節の表。種類の値の接頭辞で引く)。
 * 無い接頭辞は札と同じく「通知」。拡張点(送るモジュールを足すときは case を足す)。
 */
export function surfaceHeading(prefix: string): string {
    switch (prefix) {
        case 'workflow':
            return 'ワークフロー';
        case 'records':
            return 'レコード';
        case 'meeting':
            return '会議';
        default:
            return '通知';
    }
}

/**
 * 利用者の画面の 1 行(行の見え方の表 = 利用者と管理の画面.md の 3.2)。
 */
function ownRowView(row: NoticeOwnSettingRow): NoticeSettingRowView {
    const label = noticeTypeLabel(row.type);
    const checked = !row.isRequiredType && row.muted;

    return {
        type: row.type,
        label,
        checked,
        locked: row.isRequiredType,
        word: checked ? 'ミュート中' : 'ミュートしない',
        switchName: `${label}をミュートする`,
        badge: row.isRequiredType ? REQUIRED_BADGE : null,
    };
}

/**
 * 管理の画面の 1 行(利用者と管理の画面.md の 4.2)。
 */
function adminRowView(row: NoticeAdminSettingRow): NoticeSettingRowView {
    const label = noticeTypeLabel(row.type);

    return {
        type: row.type,
        label,
        checked: row.isRequiredType,
        locked: false,
        word: row.isRequiredType ? '必須' : '必須でない',
        switchName: `${label}を必須にする`,
        badge: null,
    };
}

function surfaceViews<TRow>(
    surfaces: NoticeSettingSurface<TRow>[],
    rowView: (row: TRow) => NoticeSettingRowView,
): NoticeSettingSurfaceView[] {
    return surfaces.map((surface) => ({
        prefix: surface.prefix,
        heading: surfaceHeading(surface.prefix),
        rows: surface.rows.map(rowView),
    }));
}

/**
 * 利用者の画面の面と行(並びは props のまま)。
 */
export function ownSurfaceViews(
    surfaces: NoticeSettingSurface<NoticeOwnSettingRow>[],
): NoticeSettingSurfaceView[] {
    return surfaceViews(surfaces, ownRowView);
}

/**
 * 管理の画面の面と行(並びは props のまま)。
 */
export function adminSurfaceViews(
    surfaces: NoticeSettingSurface<NoticeAdminSettingRow>[],
): NoticeSettingSurfaceView[] {
    return surfaceViews(surfaces, adminRowView);
}

/** 保存で送る値のキー(書き込みごとの意味の名前。利用者の画面 = ミュートするか、管理の画面 = 必須か) */
export type SettingValueKey = 'muted' | 'required';

/**
 * 保存で送る値(クリックした後の値。同じ値を 2 度送っても結果は同じ)。キーはサーバーの 422 のキーと同じ。
 */
export function settingPayload<K extends SettingValueKey>(
    valueKey: K,
    type: string,
    checked: boolean,
): { type: string } & Record<K, boolean> {
    return { type, [valueKey]: checked } as { type: string } & Record<
        K,
        boolean
    >;
}

/**
 * 422 のエラーから、同じ文の重複を除く(サーバーは当たるキーごとに同じ文を返すので、そのままではトーストが重なる)。
 */
export function distinctErrors(
    errors: Record<string, string>,
): Record<string, string> {
    const seen = new Set<string>();

    return Object.fromEntries(
        Object.entries(errors).filter(([, message]) => {
            const isNew = !seen.has(message);
            seen.add(message);

            return isNew;
        }),
    );
}
