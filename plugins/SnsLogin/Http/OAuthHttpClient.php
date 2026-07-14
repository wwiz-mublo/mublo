<?php
namespace Mublo\Plugin\SnsLogin\Http;

use RuntimeException;

/**
 * OAuth 토큰 발급·폐기용 form-urlencoded HTTP 클라이언트.
 */
class OAuthHttpClient
{
    /**
     * @param array<string, scalar|null> $data
     * @param string[] $headers
     */
    public function postForm(string $url, array $data, array $headers = []): OAuthHttpResponse
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => array_merge(
                ['Content-Type: application/x-www-form-urlencoded;charset=utf-8'],
                $headers,
            ),
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('SNS OAuth 요청 실패: ' . $error);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return new OAuthHttpResponse($statusCode, $body);
    }
}
