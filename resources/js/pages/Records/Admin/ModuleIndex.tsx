import { Head, Link } from '@inertiajs/react';
import { FolderTree } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { index as categories } from '@/routes/records/admin/categories';
import { create, index, show } from '@/routes/records/admin/modules';
import { index as recordList } from '@/routes/records/data';
import type { RecordAdminModuleRow } from '@/types';

type Props = {
    modules: RecordAdminModuleRow[];
};

/** 画面名(見出し・パンくず・題) */
const HEADING = '業務モジュール';

/**
 * 管理の業務モジュールの一覧(docs の 設計/業務データの入れ物/画面/管理の画面.md 3.1): 表の列 = 名前(定義へのリンク。明細は字下げと「明細」の札)・
 * コード。項目の数・レコードの数の列は置かない(一覧を開くたびにレコードの総数に比例して数えることになる)。親の無い業務モジュールの行には業務の一覧へのリンク(非表示でも URL では開けるので残す)。右上に「モジュール分類」
 * (モジュール分類の画面へ = モジュール分類/画面と検査.md 4.1。表に分類の列は足さない)と「作成」。
 * 非表示(isVisible が偽)の行は名前の後に札「非表示」を出し、行の字を薄くする。表示の切替は一覧に置かない(編集の画面だけ = 3.5)。
 */
export default function ModuleIndex({ modules }: Props) {
    return (
        <>
            <Head title={HEADING} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-center justify-between gap-4">
                    <h1 className="text-2xl font-semibold">{HEADING}</h1>
                    <div className="flex items-center gap-2">
                        <Button variant="outline" asChild>
                            <Link href={categories()}>
                                <FolderTree />
                                モジュール分類
                            </Link>
                        </Button>
                        <Button asChild>
                            <Link href={create()}>作成</Link>
                        </Button>
                    </div>
                </div>
                <section
                    aria-label="業務モジュールの一覧"
                    className="rounded-xl border bg-card p-5 text-card-foreground"
                >
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>名前</TableHead>
                                <TableHead>コード</TableHead>
                                <TableHead>
                                    <span className="sr-only">業務の一覧</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {modules.map((module) => (
                                <TableRow
                                    key={module.code}
                                    className={
                                        module.isVisible === false
                                            ? 'text-muted-foreground'
                                            : undefined
                                    }
                                >
                                    <TableCell>
                                        <div
                                            className={
                                                module.parentCode === null
                                                    ? 'flex items-center gap-2'
                                                    : 'flex items-center gap-2 pl-6'
                                            }
                                        >
                                            <Link
                                                href={show(module.code)}
                                                className="text-primary underline-offset-4 hover:underline"
                                            >
                                                {module.name}
                                            </Link>
                                            {module.parentCode !== null && (
                                                <Badge variant="secondary">
                                                    明細
                                                </Badge>
                                            )}
                                            {module.isVisible === false && (
                                                <Badge variant="outline">
                                                    非表示
                                                </Badge>
                                            )}
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {module.code}
                                    </TableCell>
                                    <TableCell>
                                        {module.parentCode === null && (
                                            <Link
                                                href={recordList(module.code)}
                                                className="text-primary underline-offset-4 hover:underline"
                                            >
                                                レコード一覧
                                            </Link>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </section>
            </div>
        </>
    );
}

ModuleIndex.layout = () => ({
    breadcrumbs: [{ title: HEADING, href: index() }],
});
