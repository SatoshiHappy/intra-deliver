import type { AuthzGridSetting, AuthzGridSubject } from '@/types';

/** 同じカテゴリの対象者の束(列の並びのまま。サーバーはカテゴリ順に並べて返す) */
export type ColumnGroup = {
    category: AuthzGridSubject['category'];
    subjects: AuthzGridSubject[];
};

/**
 * 列の対象者を、続くカテゴリごとの束にする(列の並びは変えない)。
 */
export function groupByCategory(subjects: AuthzGridSubject[]): ColumnGroup[] {
    return subjects.reduce<ColumnGroup[]>((groups, subject) => {
        const last = groups.at(-1);

        if (last?.category.code === subject.category.code) {
            last.subjects.push(subject);
        } else {
            groups.push({ category: subject.category, subjects: [subject] });
        }

        return groups;
    }, []);
}

/**
 * 対象者の表示名(無ければ #<id>)。
 */
export function subjectLabel(subject: Pick<AuthzGridSubject, 'id' | 'name'>) {
    return subject.name ?? `#${subject.id}`;
}

/**
 * 行の設定を (対象者, アクション) で引けるようにする。
 */
export function indexSettings(
    settings: AuthzGridSetting[],
): Map<string, AuthzGridSetting> {
    return new Map(
        settings.map((setting) => [
            settingKey(setting.subjectGroupId, setting.action),
            setting,
        ]),
    );
}

/**
 * {@link indexSettings} の鍵。
 */
export function settingKey(subjectGroupId: number, action: string): string {
    return `${subjectGroupId}:${action}`;
}
