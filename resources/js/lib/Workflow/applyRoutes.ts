import { candidateQueryOf } from '@/lib/Workflow/applyApplicant';
import {
    create as applicationCreate,
    store as applicationStore,
} from '@/routes/workflow/applications';
import { index as casesIndex, show as caseShow } from '@/routes/workflow/cases';
import {
    apply as draftApply,
    edit as draftEdit,
    store as draftStore,
    update as draftUpdate,
} from '@/routes/workflow/drafts';
import { index as flowsIndex } from '@/routes/workflow/flows';
import {
    create as reapplicationCreate,
    store as reapplicationStore,
} from '@/routes/workflow/reapplications';
import { recordCandidates } from '@/routes/workflow';
import type { WorkflowApplyProps } from '@/types';
import type { RouteDefinition } from '@/wayfinder';

type Props = WorkflowApplyProps;

/**
 * 一時保存・再申請の案件の id(この 2 つの mode は案件を持つ。新規で呼ばない)。
 */
function caseIdOf(props: Props): number {
    if (props.case === null) {
        throw new Error('新規の申請の画面には案件が無い');
    }

    return props.case.id;
}

/** 申請の送り先(新規 = 申請、一時保存 = 一時保存からの申請、再申請 = 再申請) */
export function applyActionOf(props: Props): RouteDefinition<'post'> {
    if (props.mode === 'new') {
        return applicationStore(props.flow.code);
    }

    return props.mode === 'draft'
        ? draftApply(caseIdOf(props))
        : reapplicationStore(caseIdOf(props));
}

/** 一時保存の送り先(新規 = 作成、一時保存 = 更新) */
export function draftActionOf(props: Props): RouteDefinition<'post' | 'put'> {
    return props.mode === 'new'
        ? draftStore(props.flow.code)
        : draftUpdate(caseIdOf(props));
}

/** キャンセルの戻り先(新規 = フローの選択、一時保存 = 一覧の一時保存、再申請 = 案件の詳細) */
export function backHrefOf(props: Props) {
    return props.mode === 'new'
        ? flowsIndex()
        : props.mode === 'draft'
          ? casesIndex({ query: { tab: 'draft' } })
          : caseShow(caseIdOf(props));
}

/** パンくず(再申請 = 申請一覧 → 案件 → 再申請、ほか = 申請の種類を選ぶ → フロー) */
export function breadcrumbsOf(props: Props) {
    if (props.mode === 'reapply') {
        return [
            { title: '申請一覧', href: casesIndex() },
            {
                title: props.initial?.name ?? '',
                href: caseShow(caseIdOf(props)),
            },
            { title: '再申請', href: reapplicationCreate(caseIdOf(props)) },
        ];
    }

    return [
        { title: '申請の種類を選ぶ', href: flowsIndex() },
        {
            title: props.flow.name,
            href:
                props.mode === 'draft'
                    ? draftEdit(caseIdOf(props))
                    : applicationCreate(props.flow.code),
        },
    ];
}

/**
 * 参照項目の候補の口 workflow.record-candidates の URL(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 6.1 節)。query = 語と除くレコード
 * (共有の部品が付ける)、scope = 候補のクエリの元(再申請は case、ほかは flow、既存のデータからは record も)、applicant = 申請者が代理元ならその人。
 */
export function recordCandidatesUrlOf(
    moduleCode: string,
    query: { q?: string; exclude?: number },
    scope: Parameters<typeof candidateQueryOf>[0],
    applicant: number | null,
): string {
    return recordCandidates.url(
        { module: moduleCode },
        { query: { ...query, ...candidateQueryOf(scope, applicant) } },
    );
}
