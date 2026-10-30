import { Link } from '@inertiajs/react';
import { Fragment } from 'react';
import {
    HoldBadge,
    OverdueBadge,
} from '@/components/Workflow/WorkflowStatusBadge';
import { EMPTY_VALUE } from '@/lib/recordValues';
import {
    currentNodeGroups,
    dueOf,
    elapsedDaysOf,
    sublineNumberOf,
} from '@/lib/Workflow/caseList';
import { show } from '@/routes/workflow/cases';
import { edit as editDraft } from '@/routes/workflow/drafts';
import type {
    WorkflowCaseRow,
    WorkflowCurrentNode,
    WorkflowDeadline,
} from '@/types';

/**
 * 申請名の 2 行(1 行目 = 申請名。href があればリンク、2 行目 = 小さく「{申請番号}・{フロー名}」。番号が無ければ「{フロー名}」)。
 * 一覧の行・一括のダイアログ・結果の表が使う。
 */
export function CaseTitle({
    name,
    href,
    number,
    flowName,
}: {
    name: string;
    href: string | null;
    number: string | null;
    flowName: string | null;
}) {
    return (
        <div className="flex flex-col gap-0.5">
            {href === null ? (
                <span>{name}</span>
            ) : (
                <Link
                    href={href}
                    className="text-primary underline-offset-4 hover:underline"
                >
                    {name}
                </Link>
            )}
            <span className="text-xs text-muted-foreground">
                {number !== null && (
                    <>
                        <span className="font-num tabular-nums">{number}</span>
                        {flowName !== null && '・'}
                    </>
                )}
                {flowName}
            </span>
        </div>
    );
}

/**
 * 申請名のセル(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 2 節): リンク = 一時保存は一時保存の画面(申請できる人のときだけ)、
 * ほかは詳細。2 行目は一時保存なら「{フロー名}」。
 */
export function CaseNameCell({ row }: { row: WorkflowCaseRow }) {
    const href =
        row.state !== 'draft'
            ? show.url(row.id)
            : row.can.editDraft
              ? editDraft.url(row.id)
              : null;

    return (
        <CaseTitle
            name={row.name}
            href={href}
            number={sublineNumberOf(row)}
            flowName={row.flowName}
        />
    );
}

/**
 * ノードの名前を「、」で並べる(展開の行は子の名前。保留中のノードは名前の後に札「保留中」)。
 */
function NodeNames({ nodes }: { nodes: WorkflowCurrentNode[] }) {
    return nodes.map((node, position) => (
        <Fragment key={node.id}>
            {position > 0 && '、'}
            {node.childName ?? node.name}
            {node.state === 'hold' && (
                <>
                    {' '}
                    <HoldBadge />
                </>
            )}
        </Fragment>
    ));
}

/**
 * 現在のステップのセル(表示名を「、」で並べ、同じ親の展開の行が続く所は「{親の名前}({子の名前}、…)」にまとめる。
 * 保留中のノードの名前の後に札「保留中」。未完了でなければ「—」)。
 */
export function CurrentStepCell({ row }: { row: WorkflowCaseRow }) {
    if (row.state !== 'active' || row.currentNodes.length === 0) {
        return EMPTY_VALUE;
    }

    return currentNodeGroups(row.currentNodes).map((group, position) => (
        <Fragment key={group.nodes.map((node) => node.id).join('-')}>
            {position > 0 && '、'}
            {group.parentName === null ? (
                <NodeNames nodes={group.nodes} />
            ) : (
                <>
                    {group.parentName}(<NodeNames nodes={group.nodes} />)
                </>
            )}
        </Fragment>
    ));
}

/**
 * 期限のセル(超過 =「{期限日}」と札「期限超過」、期限あり =「{期限日}」、無し =「—」)。
 */
export function DueCell({ deadline }: { deadline: WorkflowDeadline | null }) {
    const { date, overdue } = dueOf(deadline);

    if (date === null) {
        return EMPTY_VALUE;
    }

    return (
        <span className="inline-flex items-center gap-2">
            <span className="font-num tabular-nums">{date}</span>
            {overdue && <OverdueBadge />}
        </span>
    );
}

/**
 * 経過日数のセル(「{n} 日」。無ければ「—」)。
 */
export function ElapsedCell({
    deadline,
}: {
    deadline: WorkflowDeadline | null;
}) {
    const days = elapsedDaysOf(deadline);

    return days === null ? (
        EMPTY_VALUE
    ) : (
        <>
            <span className="font-num tabular-nums">{days}</span> 日
        </>
    );
}

/**
 * 人の名前の並び(「、」でつなぎ、名前の中では折り返さない。無ければ「—」)。一覧の申請者・代理元の列が使う。
 */
export function PersonNames({ names }: { names: string[] }) {
    if (names.length === 0) {
        return EMPTY_VALUE;
    }

    return names.map((name, index) => (
        <Fragment key={`${index}-${name}`}>
            {index > 0 && '、'}
            <span className="whitespace-nowrap">{name}</span>
        </Fragment>
    ));
}
