<?php

namespace Tests\Architecture;

use App\Enums\Workflow\WorkflowNodeKind;
use App\Queries\Workflow\CaseDesignations;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 図の写しの整合(docs の 設計/ワークフロー/ルートの深さ/画面/README.md の図の節)。図の配置(resources/js/lib/Workflow/diagramLayout.ts)と枠の見出し
 * (resources/js/components/Workflow/WorkflowDiagram.tsx)は、サーバーの値の写しを TS に持つ(二重の定義を受け入れ、このテストで結ぶ。片方だけ変わると失敗)。
 * - `ROUTING_KINDS`(分岐・同期の箱の種類)= {@see WorkflowNodeKind::routing()}
 * - `FRAME_KINDS`(子を持つ枠の種類)= {@see WorkflowNodeKind::expanding()}
 * - `FRAME_KIND_LABELS`(枠の見出しの語)のキーは `FRAME_KINDS` と同じで、語は {@see CaseDesignations::KIND_LABELS}(サーバーの語を正本とする。
 *   案件の運用で足す管理と通知の画面はサーバーの kindLabel を使う)と同じ。
 * 値は順序に依らず集合で比べる。
 *
 * 自己証明: 種類を 1 つ変えた TS、枠の見出しの語を変えた TS で、指摘になること。
 */
final class WorkflowDiagramMirrorTest extends TestCase
{
    public function test_the_screen_mirrors_the_kinds_and_labels_of_the_server(): void
    {
        $this->assertSame([], self::problems(self::source('/resources/js/lib/Workflow/diagramLayout.ts'), self::source('/resources/js/components/Workflow/WorkflowDiagram.tsx')));
    }

    public function test_detects_a_routing_kind_that_differs(): void
    {
        $layout = str_replace("'sync_end',", "'sync_finish',", self::source('/resources/js/lib/Workflow/diagramLayout.ts'));

        $this->assertSame(['ROUTING_KINDS がサーバーの routing() と違う'], self::problems($layout, self::source('/resources/js/components/Workflow/WorkflowDiagram.tsx')));
    }

    public function test_detects_a_frame_kind_that_differs(): void
    {
        $layout = str_replace("['serial', 'parallel']", "['serial']", self::source('/resources/js/lib/Workflow/diagramLayout.ts'));

        $this->assertSame(['FRAME_KINDS がサーバーの expanding() と違う', 'FRAME_KIND_LABELS のキーが FRAME_KINDS と違う'], self::problems($layout, self::source('/resources/js/components/Workflow/WorkflowDiagram.tsx')));
    }

    public function test_detects_a_label_that_differs(): void
    {
        $diagram = str_replace("'順次承認'", "'直列'", self::source('/resources/js/components/Workflow/WorkflowDiagram.tsx'));

        $this->assertSame(['FRAME_KIND_LABELS の serial の語がサーバーの KIND_LABELS と違う'], self::problems(self::source('/resources/js/lib/Workflow/diagramLayout.ts'), $diagram));
    }

    public function test_detects_a_declaration_that_is_missing(): void
    {
        $this->assertSame(
            ['ROUTING_KINDS の宣言が TS に無い', 'FRAME_KINDS の宣言が TS に無い', 'FRAME_KIND_LABELS の宣言が TS に無い'],
            self::problems('', ''),
        );
    }

    /**
     * @return list<string>
     */
    private static function problems(string $layout, string $diagram): array
    {
        $frames = self::kindsOf($layout, 'FRAME_KINDS');

        return [
            ...self::kindProblems(self::kindsOf($layout, 'ROUTING_KINDS'), 'ROUTING_KINDS', 'routing()', WorkflowNodeKind::routing()),
            ...self::kindProblems($frames, 'FRAME_KINDS', 'expanding()', WorkflowNodeKind::expanding()),
            ...self::labelProblems(self::labelsOf($diagram), $frames),
        ];
    }

    /**
     * @param  list<string>|null  $declared  TS の宣言の値(宣言が無ければ null)
     * @param  list<WorkflowNodeKind>  $expected
     * @return list<string>
     */
    private static function kindProblems(?array $declared, string $name, string $method, array $expected): array
    {
        if ($declared === null) {
            return ["{$name} の宣言が TS に無い"];
        }

        return $declared === self::sorted(self::valuesOf($expected)) ? [] : ["{$name} がサーバーの {$method} と違う"];
    }

    /**
     * @param  array<string, string>|null  $labels  TS の FRAME_KIND_LABELS(宣言が無ければ null)
     * @param  list<string>|null  $frames  TS の FRAME_KINDS
     * @return list<string>
     */
    private static function labelProblems(?array $labels, ?array $frames): array
    {
        if ($labels === null) {
            return ['FRAME_KIND_LABELS の宣言が TS に無い'];
        }

        $problems = $frames !== null && self::sorted(array_keys($labels)) !== $frames ? ['FRAME_KIND_LABELS のキーが FRAME_KINDS と違う'] : [];

        foreach ($labels as $kind => $label) {
            if ((CaseDesignations::KIND_LABELS[$kind] ?? null) !== $label) {
                $problems[] = "FRAME_KIND_LABELS の {$kind} の語がサーバーの KIND_LABELS と違う";
            }
        }

        return $problems;
    }

    /**
     * @param  list<WorkflowNodeKind>  $kinds
     * @return list<string>
     */
    private static function valuesOf(array $kinds): array
    {
        return array_map(fn (WorkflowNodeKind $kind) => $kind->value, $kinds);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /**
     * `const <名前> = new Set<…>([ '値', … ]);` の値(並べ替えた一覧。宣言が無ければ null)。
     *
     * @return list<string>|null
     */
    private static function kindsOf(string $ts, string $name): ?array
    {
        if (preg_match('/const '.$name.' = new Set<[^>]*>\(\[([^\]]*)\]\)/', $ts, $declaration) !== 1) {
            return null;
        }

        preg_match_all("/'([a-z_]+)'/", $declaration[1], $values);

        return self::sorted($values[1]);
    }

    /**
     * `const FRAME_KIND_LABELS … = { <種類>: '<語>', … };` の種類 → 語(宣言が無ければ null)。
     *
     * @return array<string, string>|null
     */
    private static function labelsOf(string $ts): ?array
    {
        if (preg_match('/const FRAME_KIND_LABELS[^=]*=\s*\{([^}]*)\}/', $ts, $declaration) !== 1) {
            return null;
        }

        preg_match_all("/([a-z_]+):\s*'([^']*)'/", $declaration[1], $pairs, PREG_SET_ORDER);

        return array_column($pairs, 2, 1);
    }

    private static function source(string $relativePath): string
    {
        $path = dirname(__DIR__, 2).$relativePath;
        $source = file_get_contents($path);

        if ($source === false) {
            throw new RuntimeException("{$path} を読めない");
        }

        return $source;
    }
}
