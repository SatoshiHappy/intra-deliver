import { Fragment } from 'react';
import { NameLinkMenu } from '@/components/Workflow/NameLinkMenu';
import { usePersonLinks } from '@/hooks/Workflow/use-person-links';
import type { NameSegment } from '@/lib/Workflow/nameSegments';
import { personLinkItemsOf } from '@/lib/Workflow/personLinks';
import { personName } from '@/lib/Workflow/workflowLabels';
import type { WorkflowLinkedPerson } from '@/types';

/**
 * 名前の札の「…」(docs の 設計/ワークフロー/申請の周辺/メニューと導線.md 2.1・2.2、申請の周辺/画面.md 7 節): 「組織図で見る」「認可設定で見る」を
 * 新しいタブで開く。出す項目が無ければ「…」も出さない({@link NameLinkMenu})。
 */
export function PersonMenu({ person }: { person: WorkflowLinkedPerson }) {
    return (
        <NameLinkMenu
            label={`${personName(person)}のメニュー`}
            items={personLinkItemsOf(person, usePersonLinks())}
        />
    );
}

/**
 * 名前の札を含む文(文字列はそのまま、人は名前と「…」)。文の並びと区切りは {@link NameSegment} のまま。
 */
export function NameSegmentsText({ segments }: { segments: NameSegment[] }) {
    return segments.map((segment, index) =>
        typeof segment === 'string' ? (
            <Fragment key={index}>{segment}</Fragment>
        ) : (
            <span key={index} className="whitespace-nowrap">
                {segment.text}
                <PersonMenu person={segment.person} />
            </span>
        ),
    );
}
