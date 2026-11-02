import {
    VIEWERS_CLEARED_NOTICE,
    clearedNoticeOf,
    fieldMarksOf,
    hasClearedViewers,
    shownMarksOf,
} from '@/lib/clearedFields';
import type { ClearedMark, FieldMarks } from '@/lib/clearedFields';
import type { WorkflowApplyProps } from '@/types';

/** 申請の画面の空にした欄の知らせ(欄の印・見出しの下の件数の注意・引き継ぐ参照者を外した 1 行) */
export type ApplyCleared = {
    fields: FieldMarks;
    notice: string | null;
    viewersNotice: string | null;
};

/** 知らせを決める材料(useApplyForm の返り値の一部) */
type ApplyClearedSource = {
    props: Pick<WorkflowApplyProps, 'copy'>;
    /** 空にした欄の印(元の申請の値・申請対象の値の写しの順。消えたかを含む) */
    clearedMarks: ClearedMark[][];
};

/**
 * 申請の画面の空にした欄の知らせ(docs の 設計/ワークフロー/申請の周辺/画面.md の 5 節の「空にした項目の知らせ」の表 2)。消えていない
 * 印を合わせ(同じ欄は後から写した申請対象の理由)、注意の 1 行は 1 つで件数を合わせる。引き継ぐ参照者を外したことは、申請の画面に
 * 参照制限の欄が無いので、件数に数えず別の 1 行にする。
 */
export function applyClearedOf({
    props: { copy },
    clearedMarks,
}: ApplyClearedSource): ApplyCleared {
    const shown = shownMarksOf(clearedMarks);

    return {
        fields: fieldMarksOf(shown),
        notice: clearedNoticeOf(shown.length),
        viewersNotice:
            copy !== null && hasClearedViewers(copy.clearedFields)
                ? VIEWERS_CLEARED_NOTICE
                : null,
    };
}
