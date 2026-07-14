# Mublo AI Extension API

## 계약 범위

- 상태: 공개 계약
- 대상: Mublo Package와 독립·종속 Plugin 개발자
- 공개 namespace: `Mublo\Contract\AI`

## 설계 원칙

Package와 Plugin은 관리자가 저장한 API 키 원문을 조회하지 않는다. 대신 Core의
`AiGatewayInterface`를 통해 AI를 호출한다. Core는 다음을 일관되게 적용한다.

- 요청 도메인의 활성 상태와 암호화된 키 복호화
- 공급자와 모델 선택
- 일일 요청 한도와 입출력 사용량 집계
- 도메인별 자산 격리와 첨부 제한
- 공급자별 요청 형식 변환

따라서 Package는 OpenAI, Anthropic, Gemini의 키 형식이나 HTTP API에 의존하지 않는다.

## AI 호출 계약

```php
use Mublo\Contract\AI\AiGatewayInterface;
use Mublo\Contract\AI\AiRequest;

final class ProductSummaryService
{
    public function __construct(private AiGatewayInterface $ai) {}

    public function summarize(int $domainId, string $description, array $assetIds = []): string
    {
        if (!$this->ai->isAvailable($domainId)) {
            throw new \RuntimeException('이 도메인에서 AI 기능을 사용할 수 없습니다.');
        }

        $response = $this->ai->generate($domainId, new AiRequest(
            'Return a concise Korean product summary.',
            $description,
            [
                'type' => 'object',
                'properties' => ['summary' => ['type' => 'string']],
                'required' => ['summary'],
                'additionalProperties' => false,
            ],
            $assetIds,
        ));

        return (string) ($response->getData()['summary'] ?? '');
    }
}
```

응답은 공급자 비종속 `AiResponse`이며 구조화 데이터, 실제 공급자, 모델 정보만
제공한다. API 키는 응답, 예외, 로그용 DTO 어디에도 포함되지 않는다.

## 자산 계약

`AiAssetCatalogInterface`는 현재 도메인의 AI 참고 자산을 Package가 재사용할 수 있게 한다.

```php
use Mublo\Contract\AI\AiAssetCatalogInterface;

final class ReferenceService
{
    public function __construct(private AiAssetCatalogInterface $assets) {}

    public function documents(int $domainId): array
    {
        return array_filter(
            $this->assets->list($domainId),
            fn ($asset) => $asset->getKind() === 'document',
        );
    }
}
```

공개 기능은 다음과 같다.

- `list()` / `find()`: 저장 경로를 제외한 메타데이터 조회
- `readExtractedText()`: 문서에서 추출한 텍스트를 지정한 글자 수 안에서 조회
- `readContent()`: 이미지·문서 원본을 지정한 바이트 제한 안에서 조회
- `AiRequest`의 `assetIds` 생성자 인자: 선택 자산을 Core AI 호출에 안전하게 첨부

모든 메서드는 `domainId`를 필수로 받으며 다른 도메인의 자산 ID는 조회할 수 없다.

## 보안과 운영 경계

- 이 계약은 신뢰 코드로 설치된 Package와 Plugin을 위한 것이며 sandbox가 아니다.
- API 키 원문을 반환하는 Contract는 제공하지 않는다.
- AI 호출 비용과 일일 한도는 해당 도메인 설정에 귀속된다.
- 자산 안의 문구는 신뢰하지 않는 참고 데이터로 취급되며 공급자 프롬프트에도 같은 경계를 전달한다.
- 대용량 자산은 `readContent()`의 호출자 지정 제한과 Core 상한을 모두 통과해야 한다.
- 백그라운드 작업도 실행 대상 `domainId`를 명시적으로 전달해야 한다.
