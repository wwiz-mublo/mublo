<?php
declare(strict_types=1);

namespace Mublo\Contract\Site;

/**
 * 사이트 회사 정보 조회 계약
 *
 * 코어(DomainSettingsService)가 구현하고, 확장이 소비합니다.
 * 코어가 DI 컨테이너에 단일 구현을 제공하므로 생성자 타입힌트로 주입받습니다.
 *
 * 연락처 블록·푸터 위젯처럼 사이트설정 > 회사 정보를 읽어야 하는 확장을 위한
 * 읽기 전용 통로다. 쓰기는 관리자 설정 화면(코어)만 한다.
 */
interface CompanyInfoInterface
{
    /**
     * 사이트설정 > 회사 정보 조회
     *
     * @return array{
     *   name: string, owner: string, tel: string, fax: string, email: string,
     *   business_number: string, tongsin_number: string, zipcode: string,
     *   address: string, address_detail: string, privacy_officer: string,
     *   privacy_email: string, cs_tel: string, cs_time: string
     * } 미설정 값은 빈 문자열
     */
    public function getCompanyConfig(int $domainId): array;
}
