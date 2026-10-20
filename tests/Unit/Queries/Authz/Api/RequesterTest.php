<?php

namespace Tests\Unit\Queries\Authz\Api;

use App\Queries\Authz\Api\Requester;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 認可の判定を求める者(docs の 設計/共通マスタ/ロールと認可/対象者.md: ユーザー・接続元の IPv4 アドレス・選んだ組織)。
 * IPv4 アドレスの形でない接続元は、黙って「合わない」にせず拒む(IPv6 の接続を null にするのは入口の仕事)。
 */
final class RequesterTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function addressesThatAreNotIpv4(): array
    {
        return [
            'IPv6' => ['2001:db8::1'],
            'IPv4 を埋め込んだ IPv6' => ['::ffff:192.168.0.1'],
            '数値の先頭の 0' => ['192.168.000.1'],
            '部分が 3 つ' => ['192.168.0'],
            '256 以上' => ['192.168.0.256'],
            '前に空白' => [' 192.168.0.1'],
            '空文字' => [''],
        ];
    }

    public function test_keeps_a_user_an_ipv4_address_and_a_selected_department(): void
    {
        $requester = new Requester(7, '192.168.0.1', 12);

        $this->assertSame(7, $requester->userId);
        $this->assertSame('192.168.0.1', $requester->ipv4Address);
        $this->assertSame(12, $requester->selectedDepartmentId);
    }

    public function test_accepts_a_requester_without_a_user_an_address_or_a_selected_department(): void
    {
        $requester = new Requester(null, null);

        $this->assertNull($requester->userId);
        $this->assertNull($requester->ipv4Address);
        $this->assertNull($requester->selectedDepartmentId);
    }

    #[DataProvider('addressesThatAreNotIpv4')]
    public function test_rejects_an_address_that_is_not_ipv4(string $address): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Requester(null, $address);
    }

    public function test_takes_the_user_the_address_and_the_selected_department_from_the_access_context(): void
    {
        $requester = Requester::fromAccessContext(new AccessContext(7, '192.168.0.1', 12, CarbonImmutable::parse('2026-10-04'), 'ja'));

        $this->assertSame([7, '192.168.0.1', 12], [$requester->userId, $requester->ipv4Address, $requester->selectedDepartmentId]);
    }
}
