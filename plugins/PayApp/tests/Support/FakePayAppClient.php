<?php
declare(strict_types=1);

namespace Tests\PayApp\Support;

use Mublo\Plugin\PayApp\PayAppClient;

/**
 * HTTP 를 보내지 않는 페이앱 클라이언트 — 보낸 요청을 기록하고 cmd 별로 지정한 응답을 돌려준다.
 */
class FakePayAppClient extends PayAppClient
{
    /** @var list<array<string, mixed>> */
    public array $requests = [];

    /** @var array<string, array<string, mixed>|callable> cmd → 응답 또는 callable(array $request): array */
    public array $responses = [];

    protected function post(array $data): array
    {
        $this->requests[] = $data;
        $cmd = (string) ($data['cmd'] ?? '');
        $response = $this->responses[$cmd] ?? ['state' => '0', 'errorMessage' => 'not routed: ' . $cmd];
        return is_callable($response) ? $response($data) : $response;
    }

    /** @return list<array<string, mixed>> */
    public function requestsFor(string $cmd): array
    {
        return array_values(array_filter($this->requests, fn(array $r) => ($r['cmd'] ?? '') === $cmd));
    }
}
