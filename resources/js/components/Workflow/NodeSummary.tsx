import { Fragment } from 'react';
import { NameLinkMenu } from '@/components/Workflow/NameLinkMenu';
import { usePersonLinks } from '@/hooks/Workflow/use-person-links';
import { splitNameTail } from '@/lib/Workflow/nameTail';
import { departmentLinkItemsOf } from '@/lib/Workflow/personLinks';
import type { WorkflowSummaryPart } from '@/types';

/**
 * 具体の組織の名前と、横の「…」(組織図で見る。出す条件はページの links)。名前は折り返せ、最後の 1 文字だけを「…」と同じ行に残す。
 */
function DepartmentName({ id, name }: { id: number; name: string }) {
    const { head, tail } = splitNameTail(name);

    return (
        <>
            {head}
            <span className="whitespace-nowrap">
                {tail}
                <NameLinkMenu
                    label={`${name}のメニュー`}
                    items={departmentLinkItemsOf(id, usePersonLinks())}
                />
            </span>
        </>
    );
}

/**
 * 要約の 1 文(docs の 設計/ワークフロー/申請の周辺/メニューと導線.md 2.2): 文字列はそのまま、具体の組織の名前は名前と「…」。
 */
export function NodeSummary({ parts }: { parts: WorkflowSummaryPart[] }) {
    return parts.map((part, index) =>
        typeof part === 'string' ? (
            <Fragment key={index}>{part}</Fragment>
        ) : (
            <DepartmentName
                key={index}
                id={part.departmentId}
                name={part.text}
            />
        ),
    );
}
