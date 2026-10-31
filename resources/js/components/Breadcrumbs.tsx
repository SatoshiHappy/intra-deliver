import { Fragment } from 'react';
import { DestinationLink } from '@/components/DestinationLink';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

type Props = {
    breadcrumbs: BreadcrumbItemType[];
};

/**
 * パンくず。1 行で出し、あふれたときだけ最後でない段が縮む(最後の段は省かず、リンクにしない)。
 * 規則は docs の 設計/メニュー/表示.md 3.3.6、置き場は 設計/画面の意匠/骨格とヘッダー.md 3 節の 3。
 */
export function Breadcrumbs({ breadcrumbs }: Props) {
    return (
        <Breadcrumb>
            <BreadcrumbList className="flex-nowrap">
                {breadcrumbs.map((item, index) => {
                    const isLast = index === breadcrumbs.length - 1;

                    return (
                        <Fragment key={index}>
                            <BreadcrumbItem
                                className={
                                    isLast
                                        ? 'shrink-0 whitespace-nowrap'
                                        : 'min-w-0'
                                }
                            >
                                {isLast ? (
                                    <BreadcrumbPage className="font-medium text-muted-foreground">
                                        {item.title}
                                    </BreadcrumbPage>
                                ) : item.href === null ? (
                                    <span className="truncate">
                                        {item.title}
                                    </span>
                                ) : (
                                    <BreadcrumbLink asChild>
                                        <DestinationLink
                                            destination={item}
                                            className="truncate"
                                        >
                                            {item.title}
                                        </DestinationLink>
                                    </BreadcrumbLink>
                                )}
                            </BreadcrumbItem>
                            {!isLast && (
                                <BreadcrumbSeparator className="shrink-0" />
                            )}
                        </Fragment>
                    );
                })}
            </BreadcrumbList>
        </Breadcrumb>
    );
}
