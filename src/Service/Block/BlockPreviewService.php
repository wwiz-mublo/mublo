<?php
declare(strict_types=1);
namespace Mublo\Service\Block;

use Mublo\Entity\Block\BlockRow;
use Mublo\Entity\Block\BlockColumn;
use Mublo\Entity\Block\BlockColumnContent;
use Mublo\Repository\Block\BlockRowRepository;
use Mublo\Repository\Block\BlockColumnRepository;
use Mublo\Core\Session\SessionInterface;

/**
 * BlockPreviewService
 *
 * 블록 미리보기 서비스
 *
 * 역할:
 * - 임시 데이터로 블록 미리보기
 * - 세션 기반 미리보기 데이터 관리
 * - 실제 렌더링은 BlockRenderService에 위임
 */
class BlockPreviewService
{
    private BlockRowRepository $rowRepository;
    private BlockColumnRepository $columnRepository;
    private BlockRenderService $renderService;
    private SessionInterface $session;
    private BlockColumnPayloadNormalizer $columnNormalizer;

    /**
     * 세션 키
     */
    private const SESSION_KEY = 'block_preview';

    /**
     * 미리보기 만료 시간 (초)
     */
    private const PREVIEW_TTL = 3600;

    public function __construct(
        BlockRowRepository $rowRepository,
        BlockColumnRepository $columnRepository,
        BlockRenderService $renderService,
        SessionInterface $session,
        BlockColumnPayloadNormalizer $columnNormalizer
    ) {
        $this->rowRepository = $rowRepository;
        $this->columnRepository = $columnRepository;
        $this->renderService = $renderService;
        $this->session = $session;
        $this->columnNormalizer = $columnNormalizer;
    }

    /**
     * 미리보기 데이터 저장 및 토큰 반환
     *
     * @param array $rowData 행 데이터
     * @param array $columnsData 칸 데이터 배열
     * @return string 미리보기 토큰
     */
    public function createPreview(
        array $rowData,
        array $columnsData,
        ?BlockColumnWriteContext $context = null
    ): ?string
    {
        $domainId = (int) ($rowData['domain_id'] ?? 0);
        if ($domainId <= 0) {
            return null;
        }

        $normalization = $this->columnNormalizer->normalizeMany(
            $columnsData,
            $context ?? BlockColumnWriteContext::preview($domainId)
        );
        if (!$normalization->isOk()) {
            return null;
        }

        $token = $this->generateToken();
        $previews = $this->session->get(self::SESSION_KEY, []);
        $previews[$token] = [
            'row' => $rowData,
            'columns' => $normalization->getNormalizedColumns(),
            'created_at' => time(),
        ];
        $this->session->set(self::SESSION_KEY, $previews);

        return $token;
    }

    /**
     * 미리보기 렌더링
     *
     * @param string $token 미리보기 토큰
     * @return string|null 렌더링된 HTML 또는 null
     */
    public function renderPreview(string $token): ?string
    {
        $previews = $this->session->get(self::SESSION_KEY, []);
        $previewData = $previews[$token] ?? null;

        if (!$previewData) {
            return null;
        }

        // 만료 확인
        if (time() - $previewData['created_at'] > self::PREVIEW_TTL) {
            $this->deletePreview($token);
            return null;
        }

        $domainId = (int) ($previewData['row']['domain_id'] ?? 0);
        if ($domainId === 0) {
            return null;
        }

        $row = $this->createTempRow($previewData['row'], $domainId);
        $columns = $this->createTempColumns($previewData['columns'], $domainId);

        return $this->renderService->renderRowFromEntities($row, $columns);
    }

    /**
     * 기존 행 미리보기 (수정 전)
     *
     * @param int $rowId 행 ID
     * @param int $expectedDomainId 요청 도메인 ID
     * @param array $rowData 수정된 행 데이터
     * @param array $columnsData 수정된 칸 데이터
     * @return string|null 렌더링된 HTML
     */
    public function renderExistingRowPreview(
        int $rowId,
        int $expectedDomainId,
        array $rowData,
        array $columnsData,
        ?BlockColumnWriteContext $context = null,
        bool $replaceColumns = false
    ): ?string
    {
        $existingRow = $this->rowRepository->find($rowId);

        if (!$existingRow || $existingRow->getDomainId() !== $expectedDomainId) {
            return null;
        }

        if ($context !== null && $context->domainId !== $expectedDomainId) {
            return null;
        }

        // 요청 payload로 저장된 행의 식별자/소유 도메인을 바꿀 수 없다.
        unset($rowData['row_id'], $rowData['domain_id']);

        // 기존 데이터와 병합
        $mergedRowData = array_merge($existingRow->toArray(), $rowData);
        $row = BlockRow::fromArray($mergedRowData);

        // 칸 데이터가 없으면 기존 칸 사용
        if (!$replaceColumns && empty($columnsData)) {
            $columns = $this->columnRepository->findByRowForDomain($rowId, $expectedDomainId);
        } else {
            $normalization = $this->columnNormalizer->normalizeMany(
                $columnsData,
                $context ?? BlockColumnWriteContext::preview($existingRow->getDomainId())
            );
            if (!$normalization->isOk()) {
                return null;
            }
            $columns = $this->createTempColumns(
                $normalization->getNormalizedColumns(),
                $existingRow->getDomainId()
            );
        }

        return $this->renderService->renderRowFromEntities($row, $columns);
    }

    /**
     * 미리보기 데이터 삭제
     */
    public function deletePreview(string $token): void
    {
        $previews = $this->session->get(self::SESSION_KEY, []);
        unset($previews[$token]);
        $this->session->set(self::SESSION_KEY, $previews);
    }

    /**
     * 만료된 미리보기 정리
     */
    public function cleanupExpired(): int
    {
        $previews = $this->session->get(self::SESSION_KEY, []);
        $cleaned = 0;
        $now = time();

        foreach ($previews as $token => $data) {
            if ($now - ($data['created_at'] ?? 0) > self::PREVIEW_TTL) {
                unset($previews[$token]);
                $cleaned++;
            }
        }

        if ($cleaned > 0) {
            $this->session->set(self::SESSION_KEY, $previews);
        }

        return $cleaned;
    }

    /**
     * 임시 Row Entity 생성
     */
    private function createTempRow(array $data, int $domainId): BlockRow
    {
        return BlockSnapshotHydrator::row($data, $domainId, (int) ($data['row_id'] ?? 0));
    }

    /**
     * 임시 Column Entity 배열 생성
     */
    private function createTempColumns(array $columnsData, int $domainId): array
    {
        return BlockSnapshotHydrator::columns($columnsData, $domainId);
    }

    /**
     * 토큰 생성
     */
    private function generateToken(): string
    {
        return bin2hex(random_bytes(16));
    }
}
