import { Head, Link, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { create } from '@/routes/workflow/applications';
import { index } from '@/routes/workflow/flows';
import type { WorkflowApplicableFlow } from '@/types';

type Props = {
    flows: WorkflowApplicableFlow[]; // 申請できるフロー(並びの順)
};

/**
 * 行の「申請する」(申請の画面へ)。
 */
function ApplyLink({ flow }: { flow: WorkflowApplicableFlow }) {
    return (
        <div className="flex items-center justify-end">
            <Button variant="outline" size="sm" asChild>
                <Link
                    href={create(flow.code)}
                    aria-label={`${flow.name}を申請する`}
                >
                    申請する
                </Link>
            </Button>
        </div>
    );
}

/**
 * フローの一覧(docs の 設計/ワークフロー/画面/README.md 2 節): 申請できるフローの表(フロー名・説明・業務モジュール・「申請する」)。0 件なら「申請できるフローはありません」、
 * ゲスト(共有プロパティ account の利用者が無い)には続けて「ゲストは申請できません」。
 */
export default function FlowIndex({ flows }: Props) {
    const { account } = usePage().props;

    return (
        <>
            <Head title="申請の種類を選ぶ" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-col gap-1">
                    <h1 className="text-2xl font-semibold">申請の種類を選ぶ</h1>
                    <p className="text-sm text-muted-foreground">
                        申請できるフローの一覧です。フローを選択して申請します。
                    </p>
                </div>
                <section
                    aria-labelledby="flows-heading"
                    className="flex flex-col gap-4 rounded-xl border bg-card p-5 text-card-foreground"
                >
                    <div className="flex items-center justify-between gap-4">
                        <h2
                            id="flows-heading"
                            className="text-[15px] font-semibold"
                        >
                            申請できるフロー
                        </h2>
                        <span className="text-sm text-muted-foreground">
                            全{' '}
                            <span className="font-num tabular-nums">
                                {flows.length}
                            </span>{' '}
                            件
                        </span>
                    </div>
                    {flows.length === 0 ? (
                        <div className="flex flex-col gap-1 text-sm text-muted-foreground">
                            <p>申請できるフローはありません</p>
                            {account.user === null && (
                                <p>ゲストは申請できません</p>
                            )}
                        </div>
                    ) : (
                        <Table aria-label="申請できるフロー">
                            <TableHeader>
                                <TableRow>
                                    <TableHead>フロー名</TableHead>
                                    <TableHead>説明</TableHead>
                                    <TableHead>業務モジュール</TableHead>
                                    <TableHead>
                                        <span className="sr-only">操作</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {flows.map((flow) => (
                                    <TableRow key={flow.code}>
                                        <TableCell>{flow.name}</TableCell>
                                        <TableCell className="wrap-anywhere whitespace-normal">
                                            {flow.description ?? '—'}
                                        </TableCell>
                                        <TableCell>{flow.moduleName}</TableCell>
                                        <TableCell>
                                            <ApplyLink flow={flow} />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </section>
            </div>
        </>
    );
}

FlowIndex.layout = {
    breadcrumbs: [{ title: '申請の種類を選ぶ', href: index() }],
};
