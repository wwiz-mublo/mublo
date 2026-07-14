<?php
namespace Mublo\Service\Domain;

/**
 * Class DomainNotFoundException
 *
 * 도메인을 찾을 수 없을 때 발생하는 예외
 */
class DomainNotFoundException extends \RuntimeException
{
    protected string $domainName;

    public function __construct(string $message = '', string $domainName = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->domainName = $domainName;
    }

    public function getDomainName(): string
    {
        return $this->domainName;
    }
}
