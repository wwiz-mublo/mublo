# 23. Report 엔진

관리자 화면의 "엑셀 다운로드"를 확장마다 다시 만들지 않기 위한 서브시스템이다. 확장은 **리포트 정의(무슨 데이터를 어떤 컬럼으로)**만 등록하고, 권한 검사·형식별 렌더링(CSV/XLSX/PDF)·대용량 청크 처리·파일 저장·감사 로그는 코어 `src/Core/Report/`가 전부 처리한다. `Mublo\Core\Report\*` 네임스페이스 전체가 안정 API다 (`docs/compatibility-policy.md`의 "관리자·렌더 확장 지점").

이 장이 다루는 기능: 리포트 파일 다운로드·청크 생성·병합·산출 파일 조회(`src/Controller/Admin/ReportController.php`), 리포트 엔진 API 라우트 `/admin/report/{reportName}/*`·`/admin/report/files/{fileId}`(`src/Core/App/Router.php`).

## 책임과 비책임

코어가 책임지는 것: 실행 파이프라인 전체(권한 → 정의 실행 → 렌더 → 저장 → 감사), 3종 형식 렌더러, 대용량을 위한 청크·병합 흐름, 산출물의 만료·정리. 코어가 책임지지 않는 것: 데이터 조회 자체(정의와 RowProvider가 함), 다운로드 버튼 UI(각 확장의 관리자 화면이 함), 정기 리포트 발송 같은 스케줄링(현재는 지원하지 않는다).

## 리포트 정의 등록

### ReportDefinitionInterface

`src/Core/Report/Contract/ReportDefinitionInterface.php` — 메서드 두 개가 전부다.

- `name(): string` — 리포트 식별자. URL의 `{reportName}`이 이 값이다.
- `build(array $filters): ReportDocument` — 필터를 받아 문서를 구성한다. `ReportManager`가 호출 전에 항상 `$filters['domain_id']`를 현재 도메인으로 강제 주입하므로, 정의는 이 값으로 도메인 격리를 지켜야 한다.

### 등록 경로

`src/Core/Report/Engine/ReportDefinitionRegistry.php`는 `name()`을 키로 정의 인스턴스를 보관하는 단순 레지스트리다. 코어 컨테이너에 싱글톤으로 등록되며(`src/Core/Provider/ServiceProvider.php`), 확장은 Provider의 `boot()`에서 꺼내 등록한다. 번들 Shop Package의 실제 등록부(`packages/Shop/ShopProvider.php`의 `boot()`):

```php
$container->get(\Mublo\Core\Report\Engine\ReportDefinitionRegistry::class)->register(
    new \Mublo\Packages\Shop\Report\ShopOrderReportDefinition(
        $container->get(OrderService::class),
        $container->get(OrderStateResolver::class)
    )
);
```

등록되지 않은 이름을 요청하면 `ReportDefinitionRegistry::get()`이 `\RuntimeException`을 던지고, `ReportManager`가 이를 실패 `Result`로 변환한다.

### RowProviderInterface — 데이터 공급

`src/Core/Report/Contract/RowProviderInterface.php` — 테이블 섹션의 행을 공급한다.

- `rows(): iterable` — 전체 행 순회(파일 렌더링 경로가 사용).
- `getChunk(int $offset, int $limit): array` — 구간 조회(청크 경로가 사용).
- `totalCount(): ?int` — 전체 건수. `null`이면 청크 흐름이 "받은 행 수 == limit"으로 다음 페이지 유무를 추정한다.
- `isRewindable(): bool` — 계약에는 선언되어 있으나 현재 코어 엔진이 호출하는 곳은 없다.

작은 데이터는 `src/Core/Report/Data/ArrayRowProvider.php`(배열 래핑, `array_slice` 청크)로 충분하다. DB 기반 대용량은 Shop의 `packages/Shop/Report/OrderReportRowProvider.php`가 레퍼런스다 — `getChunk()`를 서비스의 페이지 조회로 위임하고, `rows()`는 500건 배치로 `getChunk()`를 반복 호출하는 제너레이터다.

## Document 모델

`build()`가 반환하는 것은 렌더러 중립적인 문서 트리다.

- `src/Core/Report/Document/ReportDocument.php` — 제목 + `ReportMetadata` + 섹션 배열. `addSection()`·`withMetadata()`는 clone을 반환하는 불변 스타일이다.
- `src/Core/Report/Document/ReportMetadata.php` — 자유 key-value. `filename` 키를 넣으면 다운로드 파일명으로 사용된다(없으면 `{리포트명}_{Ymd_His}`).
- `src/Core/Report/Document/Section/` — 섹션 3종. `TableSection`(컬럼 정의 + RowProvider, `type()` = `table`), `KeyValueSection`(조회 조건·총계 같은 요약, `key_value`), `TextSection`(안내문, `text`). 모두 `SectionInterface`(`type(): string`) 구현.
- `src/Core/Report/Document/ColumnDefinition.php` — `key`(행 배열의 키), `label`(헤더), `type`(`string`/`money`/`number`/`date` — XLSX 셀 서식에 반영), `align`, 그리고 선택적 `formatter`(callable). 렌더러 3종과 병합 흐름 모두 셀 값 산출 시 formatter를 통과시킨다.

`src/Core/Report/Formatter/ReportFormatters.php`는 formatter로 쓸 callable의 팩토리다: `date()`(형식 변환), `money()`(천단위 콤마+접미사), `number()`(소수 자릿수), `maskName()`(이름 마스킹 — 김*수), `boolean()`(Y/N 라벨), `map()`(코드→라벨).

## 렌더러

`src/Core/Report/Contract/ReportRendererInterface.php` — `supports()`, `mimeType()`, `extension()`, `renderToFile(ReportDocument, string $filePath)`. 번들 구현 3종(`src/Core/Report/Renderer/`):

- `CsvReportRenderer` — UTF-8 BOM을 먼저 쓰고 `fputcsv`로 출력. 섹션 3종 모두 지원하며 섹션 사이에 빈 줄을 넣는다.
- `XlsxReportRenderer` — PhpSpreadsheet 사용(`xlsx`와 `excel` 두 형식 문자열 지원). 헤더 볼드·틀 고정·자동 필터·컬럼 자동 너비를 적용하고, `ColumnDefinition::$type`에 따라 셀 숫자 서식(`money` → `#,##0` 등)을 지정한다.
- `PdfReportRenderer` — Dompdf 사용. 문서를 HTML로 조립해 A4 세로로 렌더링하며, 모든 값은 escape되고 `isRemoteEnabled = false`로 외부 리소스를 차단한다.

두 라이브러리는 `composer.json`에 포함된 필수 의존성이지만, 렌더러는 `class_exists` 가드로 부재 시 `ReportRenderException`을 던진다.

**수식 주입 방어** — 엑셀류는 `=`·`+`·`-`·`@`로 시작하는 문자열을 수식으로 평가한다. `src/Core/Report/Security/ReportCellValueSanitizer.php`가 그 경계다. BOM·제어문자·NBSP 선행분까지 감안해 위험 접두사를 판정하고, CSV는 타입 메타가 없으므로 작은따옴표를 앞에 붙이고 XLSX는 셀을 명시적으로 문자열로 표시한다. CSV·XLSX 렌더러와 청크 병합 경로(`ReportManager`)가 모두 이 경계를 통과한다 — 확장이 정의에서 따로 처리할 필요가 없다.

렌더러의 해석은 `src/Core/Report/Engine/ReportRendererResolver.php`가 한다 — `ContractRegistry`에서 `ReportRendererInterface::class` + 형식 키(`csv`/`xlsx`/`pdf`)로 조회한다([16. Contract 카탈로그](16-contract-catalog.md)). 3종은 `src/Core/Provider/ServiceProvider.php`의 ContractRegistry 초기화부에서 등록된다. 등록되지 않은 형식은 `UnsupportedFormatException`이다. 같은 레지스트리 경로이므로 확장이 새 형식의 렌더러를 키만 다르게 등록하는 것도 구조상 열려 있다(번들 외 등록 사례는 현재 없다).

## 실행 파이프라인

중심은 `src/Core/Report/Engine/ReportManager.php`다. 모든 공개 메서드는 예외를 밖으로 던지지 않고 `Result` 성공/실패로 변환한다.

### 다운로드 — generateDownload()

```text
권한 게이트 assertDownloadAllowed(domainId, menuCode)
→ filters['domain_id'] 강제 주입 → 정의 조회·build() → 렌더러 해석
→ ReportFileStore::createExportPath() → renderer->renderToFile()
→ 파일명 결정(metadata 'filename' → sanitize)
→ 감사 'report.generated' → Result(filePath, fileName, mimeType)
```

컨트롤러(`src/Controller/Admin/ReportController.php`)는 성공 시 `FileResponse`(attachment), 실패 시 JSON 에러를 반환한다.

### 청크·병합 — 대용량 경로

브라우저 타임아웃 없이 수만 행을 내보내기 위한 3단계 흐름이다.

1. `generateChunk()` — 커서(base64url JSON의 offset) 기반으로 첫 `TableSection`의 `getChunk()`를 호출해 rows를 JSON 파일로 저장하고 `chunkRef`를 돌려준다. `limit`은 1~5000, 응답에 `nextCursor`/`hasMore`/직렬화된 컬럼이 포함된다. `TableSection`이 없는 문서는 `ReportValidationException`으로 거부된다.
2. `mergeChunks()` — `chunkRef` 목록(최대 1,000개)을 순서대로 읽어 하나의 CSV로 병합한다(현재 CSV만 지원). 헤더·formatter는 정의를 다시 `build()`해 얻은 컬럼 기준이다.
3. `ReportFileStore::registerMergedFile()` — 병합 파일을 TTL 3600초의 메타와 함께 등록하고 `fileId`·`downloadUrl`(`/admin/report/files/{fileId}`)을 반환한다. 실제 다운로드 시 `resolveMergedFile()`이 도메인 일치와 저장된 `menu_code`로 권한을 **다시** 검사한다.

각 단계는 `report.chunk.generated`·`report.merge.generated`로 감사된다.

### 권한 게이트

`src/Core/Report/Contract/PermissionGateInterface.php`의 `assertDownloadAllowed(int $domainId, string $menuCode): void`가 유일한 관문이며, 기본 구현은 `src/Core/Report/Security/AdminPermissionGate.php`다: 관리자 인증 필수 → 슈퍼는 통과 → 일반 관리자는 `menuCode` 필수 + `AdminPermissionService::isDenied(domainId, level, menuCode, 'download')` 검사. 즉 리포트 권한은 별도 체계가 아니라 [20장](20-permission-model.md)의 관리자 메뉴 권한의 `download` 액션을 그대로 쓴다. `menuCode`는 클라이언트가 요청 본문으로 보낸다.

### 저장소와 정리

`src/Core/Report/Store/ReportFileStore.php`는 `MUBLO_STORAGE_PATH/report/` 아래 `exports/`(산출물)·`chunks/`(청크 JSON)·`meta/`(병합 파일 메타)를 관리한다. 모든 경로 접근은 `basename()` + `realpath` 기반 디렉토리 내부 검증을 거친다. `cleanupExpired()`가 만료 메타·파일과 1시간 지난 청크를 삭제한다(cron 등 외부 호출 전제 — 자동 스케줄은 코어에 없다).

### 감사와 예외

`src/Core/Report/Audit/ReportAuditLogger.php`는 이벤트명·시각·컨텍스트를 JSON으로 `error_log`에 남긴다(현재 [10장](10-infrastructure.md)의 Logger 인프라와는 별개 경로다). 예외 5종(`src/Core/Report/Exception/`)은 모두 `\RuntimeException` 파생으로, 실패 지점을 구분한다: `ReportPermissionDeniedException`(게이트), `ReportValidationException`(커서·문서 구조), `UnsupportedFormatException`(렌더러 해석), `ReportRenderException`(렌더링), `ReportOutputException`(파일 저장). 전부 `ReportManager` 안에서 `Result::failure`로 흡수되므로 컨트롤러·확장 코드는 try/catch가 필요 없다.

## HTTP 표면

`src/Core/App/Router.php` — 4개 라우트, 전부 관리자 미들웨어를 통과한다.

| 메서드·경로 | 처리 |
|---|---|
| `POST /admin/report/{reportName}/download` | `ReportController::download` — 즉시 파일 생성·응답 |
| `POST /admin/report/{reportName}/chunks` | `ReportController::chunks` — 커서 기반 청크 |
| `POST /admin/report/{reportName}/merge` | `ReportController::merge` — 청크 병합·fileId 발급 |
| `GET /admin/report/files/{fileId}` | `ReportController::file` — 병합 산출물 다운로드 |

요청 본문 공통 키: `menuCode`, `filters`(정의로 전달), `format`(download·merge), `cursor`/`limit`(chunks), `chunkRefs`/`filename`(merge).

## 확장 개발자 관점 — 내 Package의 리포트 추가

번들 Shop Package의 주문 내보내기(`packages/Shop/Report/ShopOrderReportDefinition.php`, name `shop_orders`)가 실동작 레퍼런스다. 절차는 두 단계 + 호출뿐이다.

1. **정의 작성** — `ReportDefinitionInterface` 구현. `build()`에서 `ColumnDefinition` 배열과 RowProvider로 `TableSection`을 만든다. Shop 정의는 `filters['columns']`로 출력 필드를 고르는 필드 피커까지 구현했다(허용 목록 `FIELDS` 밖의 키는 무시). 최소 골격은 다음과 같다 (Shop 정의의 축약형 — 실제 컬럼·RowProvider 구성은 `packages/Shop/Report/ShopOrderReportDefinition.php` 참조):

```php
use Mublo\Core\Report\Contract\ReportDefinitionInterface;
use Mublo\Core\Report\Document\ColumnDefinition;
use Mublo\Core\Report\Document\ReportDocument;
use Mublo\Core\Report\Document\Section\TableSection;
use Mublo\Core\Report\Formatter\ReportFormatters;

class MySalesReportDefinition implements ReportDefinitionInterface
{
    public function name(): string
    {
        return 'my_sales';
    }

    public function build(array $filters): ReportDocument
    {
        $domainId = (int) ($filters['domain_id'] ?? 0); // 코어가 강제 주입

        $columns = [
            new ColumnDefinition('order_no', '주문번호'),
            new ColumnDefinition('created_at', '주문일시', 'date', 'center', ReportFormatters::date()),
            new ColumnDefinition('final_amount', '결제금액', 'money', 'right', ReportFormatters::money('원')),
        ];

        $provider = /* RowProviderInterface 구현 — 도메인 격리된 조회 */;

        return ReportDocument::create('매출 목록')
            ->addSection(new TableSection($columns, $provider));
    }
}
```

2. **Provider `boot()`에서 등록** — 위 "등록 경로"의 코드처럼 컨테이너에서 `ReportDefinitionRegistry`를 받아 `register()` 한 줄.
3. **관리자 화면에서 호출** — 자기 Package의 관리자 뷰에서 `POST /admin/report/shop_orders/download`(또는 chunks→merge)를 호출한다. `menuCode`는 그 화면의 관리자 메뉴 코드를 넘긴다. Shop은 주문 목록 화면(`packages/Shop/views/Admin/Order/List.php`)에서 필드 피커 UI와 함께 이 API를 쓴다.

컨트롤러·라우트·권한 코드·파일 응답 처리는 한 줄도 쓰지 않는다는 점이 이 서브시스템의 요지다.

**Best Practice** — 대용량 리포트의 RowProvider는 `OrderReportRowProvider`처럼 `getChunk()`를 실제 페이지 쿼리로 구현하라. `ArrayRowProvider`에 전체 결과를 담으면 청크 API가 매 호출마다 전체를 메모리에 올린다. **Anti Pattern** — `build()`에서 `filters['domain_id']`를 무시하고 전 도메인 데이터를 조회하는 것. 게이트는 "이 관리자가 이 메뉴에서 다운로드 가능한가"만 검사하며, 데이터의 도메인 격리는 정의의 책임이다.

## 관련 문서

- [16. Contract 카탈로그](16-contract-catalog.md) — 렌더러가 등록되는 ContractRegistry의 일반 규약
- [20. 권한 모델](20-permission-model.md) — `AdminPermissionService`와 메뉴 코드별 액션 권한
- [09. 멀티 도메인](09-multi-domain.md) — `domain_id` 강제 주입이 전제하는 도메인 격리
- [33. Reference Packages](33-reference-packages.md) — Shop 주문 리포트를 포함한 번들 확장 해설
- `docs/compatibility-policy.md` — `Mublo\Core\Report\*`의 안정 API 지위
