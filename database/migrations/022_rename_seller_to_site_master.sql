-- 회원 등급 타입 SELLER → SITE_MASTER 로 명칭 변경.
-- 이 등급의 실제 역할은 '판매자'가 아니라 '해당 도메인의 최고 권한자(사이트 운영자)'이므로
-- 역할에 맞는 이름으로 정정한다. level_type 은 member_levels 에만 저장되며(members 는 level_value 로 조인),
-- ENUM 값 변경 + 기존 데이터 이관 + 기본 등급명 갱신을 함께 수행한다.
-- 신규 설치(002 가 이미 SITE_MASTER ENUM 으로 생성)에서는 SELLER 행이 없어 UPDATE 가 0행이 되고,
-- ENUM 재정의는 최종적으로 동일 상태(SELLER 없음)로 수렴하므로 안전하다.

-- 1) ENUM 에 SITE_MASTER 를 추가 (데이터 이관을 위해 기존 SELLER 도 잠시 함께 유지)
ALTER TABLE `member_levels`
    MODIFY `level_type`
    ENUM('SUPER', 'STAFF', 'PARTNER', 'SELLER', 'SITE_MASTER', 'SUPPLIER', 'BASIC')
    NOT NULL DEFAULT 'BASIC' COMMENT '레벨 타입';

-- 2) 기존 SELLER 행을 SITE_MASTER 로 이관. 등급명이 시드 기본값('판매자')인 경우에만
--    '사이트 운영자'로 갱신하고, 운영자가 커스텀한 등급명은 보존한다.
UPDATE `member_levels`
    SET `level_type` = 'SITE_MASTER',
        `level_name` = CASE WHEN `level_name` = '판매자' THEN '사이트 운영자' ELSE `level_name` END
    WHERE `level_type` = 'SELLER';

-- 3) 이관 완료 후 ENUM 에서 SELLER 를 제거해 최종 형태로 고정
ALTER TABLE `member_levels`
    MODIFY `level_type`
    ENUM('SUPER', 'STAFF', 'PARTNER', 'SITE_MASTER', 'SUPPLIER', 'BASIC')
    NOT NULL DEFAULT 'BASIC' COMMENT '레벨 타입';
