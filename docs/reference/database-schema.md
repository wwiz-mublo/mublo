# DB 스키마

## Core 테이블

### domain_configs

멀티 도메인 설정 테이블.

| 컬럼 | 타입 | 설명 |
|------|------|------|
| domain_id | INT PK | 도메인 ID |
| domain | VARCHAR(200) | 도메인명 (example.com) |
| domain_group | VARCHAR(100) | 도메인 그룹 계층 (1/3/5) |
| member_id | INT | 도메인 소유자 |
| status | ENUM | active, inactive, blocked |
| storage_limit | BIGINT | 저장소 한도 (bytes) |
| member_limit | INT | 회원 수 한도 |
| site_config | JSON | 사이트 기본 설정 |
| company_config | JSON | 회사 정보 |
| seo_config | JSON | SEO 설정 |
| theme_config | JSON | 테마/스킨 설정 |
| extension_config | JSON | 활성 패키지/플러그인 목록 |
| extra_config | JSON | 기타 설정 |

### member_levels

회원 등급 정의. 전역 테이블 (domain_id 없음).

| 컬럼 | 타입 | 설명 |
|------|------|------|
| level_id | INT PK | 등급 ID |
| level_value | TINYINT UK | 등급 값 (1~255) |
| level_name | VARCHAR(50) | 등급명 |
| level_type | ENUM | SUPER, STAFF, PARTNER, SITE_MASTER, SUPPLIER, BASIC |
| is_super | TINYINT | 최고관리자 여부 |
| is_admin | TINYINT | 관리자 모드 접근 권한 |
| can_operate_domain | TINYINT | 도메인 소유/운영 가능 여부 |

### members

회원 테이블.

| 컬럼 | 타입 | 설명 |
|------|------|------|
| member_id | BIGINT PK | 회원 ID |
| public_id | CHAR(22) UK | 화면·공개 URL·폼에서 사용하는 비순차 회원 식별자 |
| domain_id | BIGINT | 도메인 ID |
| user_id | VARCHAR(50) | 로그인 ID |
| password | VARCHAR(255) | bcrypt 해시 |
| nickname | VARCHAR(50) | 닉네임 |
| level_value | TINYINT | 회원 등급 값 |
| domain_group | VARCHAR(100) | 관리 권한 범위 (관리자용) |
| can_create_site | TINYINT | 사이트 생성 가능 여부 |
| point_balance | INT | 포인트 잔액 (스냅샷) |
| status | ENUM | active, inactive, dormant, blocked, pending, withdrawn |
| last_login_at | DATETIME | 최근 로그인 |
| last_login_ip | VARCHAR(45) | 최근 로그인 IP (IPv6 지원) |
| created_at | DATETIME | 가입일 |
| updated_at | DATETIME | 수정일 |
| withdrawn_at | DATETIME | 탈퇴일 |
| withdrawal_reason | VARCHAR(500) | 탈퇴 사유 |

UK: `public_id`, `(domain_id, user_id)`, `(domain_id, nickname)`

### member_level_denied_menus

레벨별 접근 불가 메뉴 (네거티브 ACL). 등록된 메뉴+액션만 차단, 미등록은 허용.

| 컬럼 | 타입 | 설명 |
|------|------|------|
| id | BIGINT PK | |
| domain_id | BIGINT | 도메인 ID |
| level_value | TINYINT | 레벨 값 |
| menu_code | VARCHAR(50) | 메뉴 코드 (activeCode) |
| denied_actions | VARCHAR(50) | 차단 액션 (list,read,write 등 또는 *) |

UK: `(domain_id, level_value, menu_code)`

### member_fields

도메인별 회원 추가 필드 정의.

| 컬럼 | 타입 | 설명 |
|------|------|------|
| field_id | BIGINT PK | 필드 ID |
| domain_id | BIGINT | 도메인 ID |
| field_name | VARCHAR(50) | 필드명 (영문) |
| field_label | VARCHAR(100) | 필드 라벨 (한글) |
| field_type | ENUM | text, email, tel, number, date, textarea, select, radio, checkbox, address, file |
| field_options | JSON | select, radio, checkbox용 옵션 |
| field_config | JSON | 추가 설정 (파일 크기 제한 등) |
| is_encrypted | TINYINT | 암호화 필드 여부 |
| is_required | TINYINT | 필수 여부 |
| is_unique | TINYINT | 중복 불가 여부 |
| validation_rule | VARCHAR(255) | 검증 규칙 (정규식) |
| sort_order | INT | 표시 순서 |
| is_visible_signup | TINYINT | 회원가입 시 표시 |
| is_visible_profile | TINYINT | 프로필 수정 시 표시 |
| is_visible_list | TINYINT | 회원 목록에 표시 |
| is_admin_only | TINYINT | 관리자 전용 필드 |
| is_searched | TINYINT | 검색 가능 여부 |

UK: `(domain_id, field_name)`

### member_field_values

회원별 추가 필드 값.

| 컬럼 | 타입 | 설명 |
|------|------|------|
| value_id | BIGINT PK | 값 ID |
| member_id | BIGINT | 회원 ID |
| field_id | BIGINT | 필드 ID |
| field_value | TEXT | 필드 값 (암호화 필드면 암호화된 값) |
| search_index | VARCHAR(64) | HMAC 해시 (암호화 필드 검색용) |
| unique_key | VARCHAR(64) NULL | is_unique 필드 전용 유일성 키 (평문=정규화 SHA-256 / 암호화=blind index, NULL=비대상) |

UK: `(member_id, field_id)`, `(field_id, unique_key)` — 후자가 is_unique 를 DB 레벨에서 강제 (NULL 다중 허용으로 비대상 필드 무영향)

### policies

정책/약관 관리.

| 컬럼 | 타입 | 설명 |
|------|------|------|
| policy_id | BIGINT PK | 정책 ID |
| domain_id | BIGINT | 도메인 ID |
| slug | VARCHAR(50) | URL 식별자 (terms, privacy) |
| policy_type | ENUM | terms, privacy, marketing, location, custom |
| title | VARCHAR(200) | 정책 제목 |
| content | LONGTEXT | 정책 내용 (HTML) |
| version | VARCHAR(20) | 정책 버전 |
| is_required | TINYINT | 필수 동의 여부 |
| is_active | TINYINT | 사용 여부 |
| show_in_register | TINYINT | 회원가입 시 출력 여부 |

UK: `(domain_id, slug)`

### member_policy_agreements

회원 정책 동의 내역.

| 컬럼 | 타입 | 설명 |
|------|------|------|
| agreement_id | BIGINT PK | 동의 ID |
| member_id | BIGINT | 회원 ID |
| policy_id | BIGINT | 정책 ID |
| policy_version | VARCHAR(20) | 동의한 정책 버전 |
| agreed_at | DATETIME | 동의 일시 |
| ip_address | VARCHAR(45) | 동의 시 IP |

UK: `(member_id, policy_id)`

### balance_logs

포인트 원장 (INSERT ONLY — 감사 추적용 불변 원장).

| 컬럼 | 타입 | 설명 |
|------|------|------|
| log_id | BIGINT PK | 로그 ID |
| domain_id | BIGINT | 도메인 ID |
| member_id | BIGINT | 회원 ID |
| amount | INT | 변경액 (+지급, -차감) |
| balance_before | INT | 변경 전 잔액 |
| balance_after | INT | 변경 후 잔액 |
| source_type | ENUM | core, plugin, package, admin, system |
| source_name | VARCHAR(50) | MemberPoint, Shop 등 |
| action | VARCHAR(50) | article_write, purchase 등 |
| message | VARCHAR(255) | 사용자 메시지 |
| reference_type | VARCHAR(50) | 참조 타입 (선택) |
| reference_id | VARCHAR(50) | 참조 ID (선택) |
| ip_address | VARCHAR(45) | 요청 IP (IPv6 지원) |
| admin_id | BIGINT | 관리자 ID (수동 조정 시) |
| memo | TEXT | 관리자 메모 |
| idempotency_key | VARCHAR(64) | 중복 방지 키 (선택) |
| created_at | TIMESTAMP | 생성일 |

UK: `(domain_id, idempotency_key)`

### block_pages

블록 페이지.

| 컬럼 | 타입 | 설명 |
|------|------|------|
| page_id | INT PK | 페이지 ID |
| domain_id | INT | 도메인 ID |
| page_code | VARCHAR(50) | 페이지 코드 |
| page_title | VARCHAR(200) | 페이지 제목 |
| layout_type | TINYINT | 1(전체), 2(좌측), 3(우측), 4(양측) |
| is_active | TINYINT | 활성 여부 |

> **참고:** 실제 테이블에는 SEO 설정(`seo_title`, `seo_description`, `seo_keywords`), 사이드바 설정, 헤더/푸터 사용 여부, 접근 레벨(`allow_level`), 페이지 설정 JSON(`page_config`) 등 추가 컬럼이 포함됩니다. 위 표는 주요 컬럼만 정리한 것입니다.

### block_rows

블록 행.

| 컬럼 | 타입 | 설명 |
|------|------|------|
| row_id | INT PK | 행 ID |
| domain_id | INT | 도메인 ID |
| page_id | INT | 소속 페이지 (NULL이면 위치 기반) |
| section_id | INT | 소속 섹션 |
| position | VARCHAR(20) | 위치 (index, left, right 등) |
| column_count | INT | 열 개수 |
| sort_order | INT | 정렬 순서 |
| is_active | TINYINT | 활성 여부 |

> **참고:** PC/모바일별 높이, 패딩, 배경 설정(`background_config` JSON) 등 레이아웃 상세 컬럼은 생략했습니다.

### block_columns

블록 열.

| 컬럼 | 타입 | 설명 |
|------|------|------|
| column_id | INT PK | 열 ID |
| row_id | INT | 소속 행 |
| domain_id | INT | 도메인 ID |
| content_type | VARCHAR(50) | 콘텐츠 타입 (html, banner, board 등) |
| content_kind | VARCHAR(20) | core, plugin, package |
| content_skin | VARCHAR(50) | 스킨명 |
| content_config | JSON | 콘텐츠 설정 |
| content_items | JSON | 선택된 항목 목록 |
| title_config | JSON | 제목 설정 |
| sort_order | INT | 정렬 순서 |
| is_active | TINYINT | 활성 여부 |

> **참고:** PC/모바일별 패딩, 배경/테두리 설정 JSON 등 스타일 관련 컬럼은 생략했습니다.

### menu_items

메뉴 항목.

| 컬럼 | 타입 | 설명 |
|------|------|------|
| item_id | INT PK | 항목 ID |
| domain_id | INT | 도메인 ID |
| menu_type | VARCHAR(20) | main, footer, utility, mypage |
| menu_code | VARCHAR(20) | 메뉴 코드 |
| parent_id | INT | 상위 메뉴 ID |
| label | VARCHAR(100) | 표시 텍스트 |
| url | VARCHAR(500) | 이동 URL |
| icon | VARCHAR(100) | 아이콘 클래스 |
| visibility | ENUM | all, guest, member |
| min_level | TINYINT | 최소 접근 레벨 |
| show_on_pc | TINYINT | PC 표시 여부 |
| show_on_mobile | TINYINT | 모바일 표시 여부 |
| sort_order | INT | 정렬 순서 |
| is_system | TINYINT | 시스템 메뉴 여부 |

### menu_tree

메뉴 계층 구조.

| 컬럼 | 타입 | 설명 |
|------|------|------|
| tree_id | INT PK | |
| domain_id | INT | 도메인 ID |
| path_code | VARCHAR(100) | 경로 코드 |
| path_name | VARCHAR(200) | 경로명 |
| parent_code | VARCHAR(20) | 상위 코드 |
| depth | TINYINT | 깊이 |

### schema_migrations

마이그레이션 이력.

| 컬럼 | 타입 | 설명 |
|------|------|------|
| id | INT PK | |
| source | ENUM | core, plugin, package |
| name | VARCHAR(100) | core, Banner, Board 등 |
| file | VARCHAR(200) | 001_create_tables.sql |
| checksum | CHAR(64), NULL | 실행 당시 migration 파일의 SHA-256 |
| executed_at | DATETIME | 실행일 |

Unique key는 `(source, name, file)`이다. Package와 Plugin이 같은 name/file을 사용해도 실행 이력은 분리된다.

## Board 패키지 테이블

### board_groups

| 컬럼 | 설명 |
|------|------|
| group_id | 그룹 ID |
| domain_id | 도메인 ID |
| group_name | 그룹명 |
| group_slug | URL 슬러그 |
| sort_order | 정렬 순서 |

### board_configs

게시판 설정. 50개 이상의 설정 컬럼.

| 주요 컬럼 | 설명 |
|----------|------|
| board_id | 게시판 ID |
| domain_id | 도메인 ID |
| group_id | 소속 그룹 |
| board_name | 게시판명 |
| board_slug | URL 슬러그 |
| board_skin | 스킨명 |
| board_editor | 에디터명 |
| use_comment | 댓글 사용 여부 |
| use_reaction | 리액션 사용 여부 |
| use_file | 파일 첨부 여부 |
| use_link | 링크 사용 여부 |
| use_category | 카테고리 사용 여부 |
| is_secret_board | 비밀게시판 여부 |
| list_level / read_level / write_level / comment_level / download_level | 권한 레벨 |
| file_count_limit / file_size_limit | 파일 제한 |
| reaction_config | JSON (리액션 타입 설정) |
| daily_write_limit / daily_comment_limit | JSON (레벨별 일일 제한) |

### board_articles

| 주요 컬럼 | 설명 |
|----------|------|
| article_id | 글 ID |
| domain_id, board_id | 소속 |
| category_id | 카테고리 (선택) |
| author_name | 작성자 이름 (스냅샷) |
| author_password | 비회원 비밀번호 (bcrypt) |
| title | 제목 |
| content | 본문 (HTML) |
| thumbnail | 썸네일 URL |
| status | published, draft, deleted |
| is_notice | 공지 여부 |
| is_secret | 비밀글 여부 |
| view_count | 조회수 |
| comment_count | 댓글수 |
| reaction_count | 리액션수 |

### board_comments

| 주요 컬럼 | 설명 |
|----------|------|
| comment_id | 댓글 ID |
| article_id | 소속 글 |
| parent_id | 상위 댓글 (중첩) |
| depth | 깊이 (0=댓글, 1=답글) |
| path | 계층 경로 (1/3/12) |
| content | 본문 |
| is_secret | 비밀 댓글 |

### board_categories

| 컬럼 | 설명 |
|------|------|
| category_id | 카테고리 ID |
| domain_id | 도메인 ID |
| category_name | 카테고리명 |
| category_slug | URL 슬러그 |
| sort_order | 정렬 순서 |

### board_category_mapping

게시판 ↔ 카테고리 다대다 매핑. 매핑 단위로 권한 재정의 가능.

### board_reactions

| 컬럼 | 설명 |
|------|------|
| reaction_id | 리액션 ID |
| target_type | article / comment |
| target_id | 대상 ID |
| member_id | 회원 ID |
| reaction_type | like 등 |

UK: `(target_type, target_id, member_id)`

### board_attachments

| 컬럼 | 설명 |
|------|------|
| attachment_id | 첨부파일 ID |
| article_id | 소속 글 |
| original_name | 원본 파일명 |
| stored_path | 저장 경로 |
| file_size | 파일 크기 |
| mime_type | MIME 타입 |
| is_image | 이미지 여부 |
| download_count | 다운로드 수 |

### board_links

외부 링크 (OG 메타 자동 추출).

### board_permissions

통합 권한 테이블 (그룹/카테고리/게시판 대상).

| 컬럼 | 설명 |
|------|------|
| target_type | group, category, board |
| target_id | 대상 ID |
| member_id | 회원 ID |
| permission_type | admin, moderator, editor |

### board_point_configs / board_point_scope_configs

포인트 설정 (도메인별 전역 + 그룹/게시판별 재정의).

---

[< 레퍼런스 목록](README.md)
