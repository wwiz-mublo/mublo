-- 회원 추가필드 is_unique 를 DB 레벨에서 강제하기 위한 유일성 키
--
-- EAV 구조에서는 field_value(TEXT) 에 직접 유니크 인덱스를 걸 수 없고,
-- is_unique 가 필드별 설정이라 (field_id, field_value) 전역 유니크도 불가하다.
-- 대신 is_unique=1 필드의 값에만 애플리케이션이 결정적 해시를 채우는
-- nullable 컬럼을 두고 유니크 인덱스를 건다 (MySQL 유니크 인덱스는 NULL 다중 허용).
--   - 평문 필드: SHA-256(strtolower(trim(value)))
--   - 암호화 필드: blind index 와 동일한 HMAC (FieldEncryptionService::createSearchIndex)
-- field_id 가 도메인 소유(uk_domain_field)라 domain_id 는 키에 불필요하다.
-- 기존 데이터 백필은 마이그레이션이 아니라 관리자 화면의 is_unique 토글 시
-- 애플리케이션이 수행한다 (기존 중복 발견 시 토글 거부 UX 포함).
ALTER TABLE `member_field_values`
    ADD COLUMN `unique_key` VARCHAR(64) NULL COMMENT 'is_unique 필드 전용 유일성 키 (해시, NULL=유일성 비대상)' AFTER `search_index`;

ALTER TABLE `member_field_values`
    ADD UNIQUE KEY `uk_field_unique` (`field_id`, `unique_key`);
