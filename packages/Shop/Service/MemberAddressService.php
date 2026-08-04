<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Service;

use Mublo\Core\Result\Result;
use Mublo\Packages\Shop\Repository\MemberAddressRepository;
use Mublo\Contract\Security\SensitiveValueCodecInterface;

/**
 * MemberAddress Service
 *
 * 회원 배송지 주소록 비즈니스 로직
 *
 * 규칙:
 * - 최대 10개/회원
 * - 첫 번째 주소 → 자동 기본 배송지
 * - 기본 배송지 삭제 시 → 최근 주소로 이관
 *
 * PII 보호: 수령인/연락처/우편번호/주소는 주문(shop_orders)과 동일하게 저장 시 AES-256-GCM
 * 암호화하고 조회 시 복호화한다. 복호화 실패(null)는 기존 평문 데이터로 간주해 원본을 유지한다
 * (하위 호환). 주소록은 이름/연락처로 검색하지 않으므로 blind index는 두지 않는다.
 */
class MemberAddressService
{
    private const MAX_ADDRESSES = 10;

    /** 암호화 대상 PII 필드 */
    private const ENCRYPTED_FIELDS = [
        'recipient_name',
        'recipient_phone',
        'zip_code',
        'address1',
        'address2',
    ];

    private MemberAddressRepository $repository;
    private ?SensitiveValueCodecInterface $encryptionService;

    public function __construct(
        MemberAddressRepository $repository,
        ?SensitiveValueCodecInterface $encryptionService = null
    ) {
        $this->repository = $repository;
        $this->encryptionService = $encryptionService;
    }

    /**
     * PII 필드 암호화 (저장 직전)
     */
    private function encryptFields(array $data): array
    {
        if (!$this->encryptionService) {
            return $data;
        }
        foreach (self::ENCRYPTED_FIELDS as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $data[$field] = $this->encryptionService->encrypt((string) $data[$field]);
            }
        }
        return $data;
    }

    /**
     * PII 필드 복호화 (조회 후). 복호화 실패는 평문으로 간주해 원본 유지.
     */
    private function decryptRow(array $row): array
    {
        if (!$this->encryptionService) {
            return $row;
        }
        foreach (self::ENCRYPTED_FIELDS as $field) {
            if (isset($row[$field]) && $row[$field] !== '') {
                $decrypted = $this->encryptionService->decrypt((string) $row[$field]);
                if ($decrypted !== null) {
                    $row[$field] = $decrypted;
                }
            }
        }
        return $row;
    }

    /**
     * 배송지 목록 조회
     */
    public function getList(int $memberId, int $domainId): Result
    {
        $entities = $this->repository->findByMember($memberId, $domainId);
        $addresses = array_map(fn($e) => $this->decryptRow($e->toArray()), $entities);

        return Result::success('', ['addresses' => $addresses]);
    }

    /**
     * 배송지 추가
     */
    public function create(int $memberId, int $domainId, array $data): Result
    {
        $count = $this->repository->countByMember($memberId, $domainId);

        if ($count >= self::MAX_ADDRESSES) {
            return Result::failure('배송지는 최대 ' . self::MAX_ADDRESSES . '개까지 저장할 수 있습니다.');
        }

        $recipientName = trim($data['recipient_name'] ?? '');
        $recipientPhone = trim($data['recipient_phone'] ?? '');
        $zipCode = trim($data['zip_code'] ?? '');
        $address1 = trim($data['address1'] ?? '');

        if ($recipientName === '' || $zipCode === '' || $address1 === '') {
            return Result::failure('수령인, 우편번호, 주소는 필수 입력입니다.');
        }

        // 첫 번째 주소면 자동 기본 배송지
        $isDefault = ($count === 0) ? 1 : (int) ($data['is_default'] ?? 0);

        $addressId = $this->repository->create($this->encryptFields([
            'member_id' => $memberId,
            'domain_id' => $domainId,
            'address_name' => trim($data['address_name'] ?? ''),
            'recipient_name' => $recipientName,
            'recipient_phone' => $recipientPhone,
            'zip_code' => $zipCode,
            'address1' => $address1,
            'address2' => trim($data['address2'] ?? ''),
            'is_default' => $isDefault,
        ]));

        // 기본 배송지 설정 시 기존 해제
        if ($isDefault && $addressId > 0) {
            $this->repository->setDefault($memberId, $domainId, $addressId);
        }

        return Result::success('배송지가 저장되었습니다.', ['address_id' => $addressId]);
    }

    /**
     * 배송지 수정
     */
    public function update(int $memberId, int $addressId, array $data): Result
    {
        $existing = $this->repository->find($addressId);

        if (!$existing || $existing->getMemberId() !== $memberId) {
            return Result::failure('배송지를 찾을 수 없습니다.');
        }

        $recipientName = trim($data['recipient_name'] ?? '');
        $zipCode = trim($data['zip_code'] ?? '');
        $address1 = trim($data['address1'] ?? '');

        if ($recipientName === '' || $zipCode === '' || $address1 === '') {
            return Result::failure('수령인, 우편번호, 주소는 필수 입력입니다.');
        }

        $updateData = $this->encryptFields([
            'address_name' => trim($data['address_name'] ?? ''),
            'recipient_name' => $recipientName,
            'recipient_phone' => trim($data['recipient_phone'] ?? ''),
            'zip_code' => $zipCode,
            'address1' => $address1,
            'address2' => trim($data['address2'] ?? ''),
        ]);

        $this->repository->updateAddress($addressId, $updateData);

        return Result::success('배송지가 수정되었습니다.');
    }

    /**
     * 배송지 삭제
     */
    public function delete(int $memberId, int $domainId, int $addressId): Result
    {
        $existing = $this->repository->find($addressId);

        if (!$existing || $existing->getMemberId() !== $memberId) {
            return Result::failure('배송지를 찾을 수 없습니다.');
        }

        $wasDefault = $existing->isDefault();

        $this->repository->delete($addressId);

        // 기본 배송지 삭제 시 → 최근 주소로 이관
        if ($wasDefault) {
            $remaining = $this->repository->findByMember($memberId, $domainId);
            if (!empty($remaining)) {
                $this->repository->setDefault($memberId, $domainId, $remaining[0]->getAddressId());
            }
        }

        return Result::success('배송지가 삭제되었습니다.');
    }

    /**
     * 체크아웃 "기본 배송지로 설정" 처리 (회원 전용).
     *
     * 입력 배송지와 동일한 주소록 항목이 있으면 그것을 기본으로 지정하고,
     * 없으면 새 배송지로 추가하며 기본으로 설정한다. (주소는 비결정적 암호화라
     * 값 조회가 불가하므로 복호화 후 비교해 중복 저장을 방지한다.)
     * 주문 흐름을 막지 않도록 실패도 Result로만 반환한다.
     */
    public function saveDefaultFromCheckout(int $memberId, int $domainId, array $data): Result
    {
        $recipientName = trim($data['recipient_name'] ?? '');
        $recipientPhone = trim($data['recipient_phone'] ?? '');
        $zipCode = trim($data['zip_code'] ?? '');
        $address1 = trim($data['address1'] ?? '');
        $address2 = trim($data['address2'] ?? '');

        if ($recipientName === '' || $zipCode === '' || $address1 === '') {
            return Result::failure('배송지 정보가 부족합니다.');
        }

        // 기존 주소록에서 동일 주소 탐색 (복호화 비교)
        $entities = $this->repository->findByMember($memberId, $domainId);
        foreach ($entities as $entity) {
            $row = $this->decryptRow($entity->toArray());
            $same = trim((string) ($row['recipient_name'] ?? '')) === $recipientName
                && trim((string) ($row['recipient_phone'] ?? '')) === $recipientPhone
                && trim((string) ($row['zip_code'] ?? '')) === $zipCode
                && trim((string) ($row['address1'] ?? '')) === $address1
                && trim((string) ($row['address2'] ?? '')) === $address2;
            if ($same) {
                if (!$entity->isDefault()) {
                    $this->repository->setDefault($memberId, $domainId, $entity->getAddressId());
                }
                return Result::success('기본 배송지로 설정되었습니다.', ['address_id' => $entity->getAddressId()]);
            }
        }

        // 동일 주소가 없으면 신규 추가(기본). create()가 최대 개수 제한·기존 기본 해제를 처리한다.
        return $this->create($memberId, $domainId, [
            'recipient_name'  => $recipientName,
            'recipient_phone' => $recipientPhone,
            'zip_code'        => $zipCode,
            'address1'        => $address1,
            'address2'        => $address2,
            'is_default'      => 1,
        ]);
    }

    /**
     * 기본 배송지 설정
     */
    public function setDefault(int $memberId, int $domainId, int $addressId): Result
    {
        $existing = $this->repository->find($addressId);

        if (!$existing || $existing->getMemberId() !== $memberId) {
            return Result::failure('배송지를 찾을 수 없습니다.');
        }

        $this->repository->setDefault($memberId, $domainId, $addressId);

        return Result::success('기본 배송지가 변경되었습니다.');
    }
}
