<?php
declare(strict_types=1);
namespace Mublo\Core\Response;

/**
 * XML 문자열을 반환하는 Response
 *
 * sitemap.xml, RSS 피드 등 검색엔진/크롤러 대상 응답에 사용
 */
class XmlResponse extends AbstractResponse
{
    private string $xml;

    public function __construct(string $xml, int $statusCode = 200)
    {
        $this->xml = $xml;
        $this->statusCode = $statusCode;
        $this->headers['Content-Type'] = 'application/xml; charset=UTF-8';
    }

    public function getXml(): string
    {
        return $this->xml;
    }

    public function send(): void
    {
        http_response_code($this->statusCode);
        foreach ($this->headers as $key => $value) {
            header("{$key}: {$value}");
        }
        echo $this->xml;
    }
}
