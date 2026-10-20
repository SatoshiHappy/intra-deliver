import type { AuthzSubjectKind } from '@/types';

/** 「列を追加」の種類(ラジオの並び。docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 3.1) */
export const SUBJECT_KINDS: { value: AuthzSubjectKind; label: string }[] = [
    { value: 'user', label: 'ユーザー' },
    { value: 'role', label: 'ロール' },
    { value: 'department', label: '組織' },
    { value: 'post', label: '役職' },
];

/**
 * 種類の値を型にする(ラジオの値は文字列で届くので、並びに無い値は undefined)。
 */
export function subjectKindOf(value: string): AuthzSubjectKind | undefined {
    return SUBJECT_KINDS.find((kind) => kind.value === value)?.value;
}

/**
 * 種類の画面の語。
 */
export function subjectKindLabel(kind: AuthzSubjectKind): string {
    return SUBJECT_KINDS.find((item) => item.value === kind)?.label ?? kind;
}
