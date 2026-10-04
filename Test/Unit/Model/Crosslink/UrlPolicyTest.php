<?php
declare(strict_types=1);

namespace Panth\Crosslinks\Test\Unit\Model\Crosslink;

use Panth\Crosslinks\Model\Crosslink\UrlPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UrlPolicyTest extends TestCase
{
    public static function allowedUrls(): array
    {
        return [
            'https'          => ['https://example.com/page'],
            'http'           => ['http://example.com'],
            'uppercase'      => ['HTTPS://EXAMPLE.COM'],
            'mailto'         => ['mailto:info@example.com'],
            'tel'            => ['tel:+441234567'],
            'relative'       => ['/women/tops.html'],
            'bare path'      => ['shoes.html'],
            'query only'     => ['?page=2'],
            'padded'         => ['  /padded.html  '],
            'colon in query' => ['/search?q=a:b'],
        ];
    }

    public static function rejectedUrls(): array
    {
        return [
            'empty'         => [''],
            'whitespace'    => ['   '],
            'javascript'    => ['javascript:alert(1)'],
            'mixed case js' => ['JaVaScRiPt:alert(1)'],
            'data'          => ['data:text/html;base64,AAAA'],
            'vbscript'      => ['vbscript:msgbox'],
            'ftp'           => ['ftp://example.com'],
            'tab inside'    => ["java\tscript:alert(1)"],
            'null byte'     => ["/a\0b"],
            'backslash'     => ['/\\evil.com'],
            'delete char'   => ["/a\x7Fb"],
        ];
    }

    #[DataProvider('allowedUrls')]
    public function testAllowedUrls(string $url): void
    {
        $this->assertTrue(UrlPolicy::isAllowed($url));
    }

    #[DataProvider('rejectedUrls')]
    public function testRejectedUrls(string $url): void
    {
        $this->assertFalse(UrlPolicy::isAllowed($url));
    }
}
