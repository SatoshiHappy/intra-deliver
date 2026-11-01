<?php

namespace Tests\Unit\Queries\Workflow;

use App\Queries\Workflow\CopiedRowAccess;
use Illuminate\Encryption\Encrypter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 案件からの複製の参照制限の読み書きの 1 か所(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/案件からの複製/写す値.md の
 * 13.2 節の POST での読み方の表と一時保存の形、検査.md の 14 節の 13.2 の暗号化した値)。鍵はテストで作る。
 */
final class CopiedRowAccessTest extends TestCase
{
    private const USER = 7;

    private const FLOW = 3;

    private const PURPOSE = 'workflow.case-copy.row-access';

    private const RESTRICTED_WITHOUT_VIEWERS = ['restricted' => true, 'viewers' => []];

    private const VIEWERS = [['type' => 'user', 'id' => 5], ['type' => 'department', 'id' => 2]];

    private Encrypter $encrypter;

    private CopiedRowAccess $copied;

    protected function setUp(): void
    {
        parent::setUp();
        $this->encrypter = new Encrypter(random_bytes(32), 'aes-256-cbc');
        $this->copied = new CopiedRowAccess($this->encrypter);
    }

    /**
     * 作った値は同じ人・同じフローで解け、印と参照者(並びのまま)が戻る。外れも外れのまま戻る(null にしない)。
     */
    public function test_a_sealed_value_opens_for_the_same_copier_and_flow(): void
    {
        $restrictions = [['restricted' => true, 'viewers' => self::VIEWERS], ['restricted' => false, 'viewers' => []]];

        foreach ($restrictions as $restriction) {
            $sealed = $this->copied->seal($restriction, self::USER, self::FLOW);

            $this->assertSame($restriction, $this->copied->open($sealed, self::USER, self::FLOW));
        }
    }

    /**
     * 表の各行: 無い・null・空文字は案件からの複製でない。文字列でない・解けない・解いた形が合わない・用途の印が違う・結び付けが合わない値は
     * 付き・参照者 = 空。
     *
     * @return array<string, array{mixed, array{restricted: bool, viewers: list<mixed>}|null}>
     */
    public static function postedValues(): array
    {
        return [
            'null' => [null, null],
            '空文字' => ['', null],
            '配列' => [['restricted' => false, 'viewers' => []], self::RESTRICTED_WITHOUT_VIEWERS],
            '数' => [12, self::RESTRICTED_WITHOUT_VIEWERS],
            '解けない文字列' => ['abc', self::RESTRICTED_WITHOUT_VIEWERS],
        ];
    }

    /**
     * @param  array{restricted: bool, viewers: list<mixed>}|null  $expected
     */
    #[DataProvider('postedValues')]
    public function test_a_posted_value_is_read_by_the_table(mixed $posted, ?array $expected): void
    {
        $this->assertSame($expected, $this->copied->open($posted, self::USER, self::FLOW));
    }

    /**
     * 正しい鍵で作った値でも、形の違う JSON・用途の印だけが違う JSON・別の人・ほかのフロー・改ざんした値は付き・参照者 = 空。
     */
    public function test_values_that_do_not_fit_are_restricted_without_viewers(): void
    {
        $payload = self::unrestrictedPayload();
        $sealedJson = fn (mixed $value) => $this->encrypter->encryptString(json_encode($value, JSON_THROW_ON_ERROR));
        $valid = $sealedJson($payload);
        $tampered = substr($valid, 0, -4).(str_ends_with($valid, 'AAAA') ? 'BBBB' : 'AAAA');
        $values = [
            '形の違う JSON' => $sealedJson(['restricted' => false, 'viewers' => []]),
            '参照者の形が違う' => $sealedJson([...$payload, 'viewers' => [['type' => 'role', 'id' => 1]]]),
            '印が真偽でない' => $sealedJson([...$payload, 'restricted' => 'false']),
            '余分なキー' => $sealedJson([...$payload, 'extra' => 1]),
            '用途の印だけが違う' => $sealedJson([...$payload, 'purpose' => 'other']),
            '別の人' => $sealedJson([...$payload, 'copier' => self::USER + 1]),
            'ほかのフロー' => $sealedJson([...$payload, 'flow' => self::FLOW + 1]),
            '改ざん' => $tampered,
        ];

        foreach ($values as $label => $value) {
            $opened = $this->copied->open($value, self::USER, self::FLOW);
            $this->assertSame(self::RESTRICTED_WITHOUT_VIEWERS, $opened, $label);
        }

        $unrestricted = ['restricted' => false, 'viewers' => []];
        $this->assertSame($unrestricted, $this->copied->open($valid, self::USER, self::FLOW));
        $this->assertSame(self::RESTRICTED_WITHOUT_VIEWERS, $this->copied->open($valid, null, self::FLOW));
    }

    /**
     * 中身を PHP の serialize の形にして正しい鍵で暗号化した値は、解いた形が合わない値と同じく付き・参照者 = 空(JSON の復号だけを使い、
     * unserialize を通さない。通すと外れの配列として読めてしまう)。
     */
    public function test_a_serialized_payload_is_not_unserialized(): void
    {
        $payload = self::unrestrictedPayload();
        $values = [$this->encrypter->encryptString(serialize($payload)), $this->encrypter->encrypt($payload)];

        foreach ($values as $value) {
            $this->assertSame(self::RESTRICTED_WITHOUT_VIEWERS, $this->copied->open($value, self::USER, self::FLOW));
        }
    }

    /**
     * 一時保存の入力のキー: 無い = 案件からの複製でない、形に合う値 = その値、合わない値(null・印が真偽でない・参照者が配列でない・相手の形が違う)
     * = 付き・参照者 = 空。上書きはキーを持った値のまま保つ。
     */
    public function test_the_draft_value_is_read_by_its_shape(): void
    {
        $restriction = ['restricted' => true, 'viewers' => self::VIEWERS];
        $this->assertNull($this->copied->ofDraft(['name' => null]));
        $this->assertSame($restriction, $this->copied->ofDraft(['copied_row_access' => $restriction]));
        $shuffled = ['viewers' => [['id' => 5, 'type' => 'user']], 'restricted' => false];
        $ordered = ['restricted' => false, 'viewers' => [['type' => 'user', 'id' => 5]]];
        $this->assertSame($ordered, $this->copied->ofDraft(['copied_row_access' => $shuffled]));

        $broken = [
            null,
            ['restricted' => 'true', 'viewers' => []],
            ['restricted' => true, 'viewers' => 'u'],
            ['restricted' => true, 'viewers' => [['type' => 'user']]],
            ['restricted' => true, 'viewers' => [['type' => 'user', 'id' => '5']]],
            ['restricted' => true, 'viewers' => [5]],
        ];

        foreach ($broken as $value) {
            $read = $this->copied->ofDraft(['copied_row_access' => $value]);
            $this->assertSame(self::RESTRICTED_WITHOUT_VIEWERS, $read);
        }

        $this->assertSame(['copied_row_access' => null], $this->copied->keptInDraft(['copied_row_access' => null]));
        $this->assertSame([], $this->copied->keptInDraft(['name' => 'a']));
    }

    /**
     * 正しい形の外れの中身。
     *
     * @return array<string, mixed>
     */
    private static function unrestrictedPayload(): array
    {
        return [
            'purpose' => self::PURPOSE, 'restricted' => false, 'viewers' => [],
            'copier' => self::USER, 'flow' => self::FLOW,
        ];
    }
}
