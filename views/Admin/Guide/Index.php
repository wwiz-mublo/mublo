<?php
/**
 * Core 관리자 매뉴얼
 *
 * Core 각 관리자 메뉴별 상세 매뉴얼.
 * (게시판은 Board 패키지 소관이므로 이 매뉴얼에 포함하지 않는다.)
 *
 * @var string $pageTitle
 */
?>

<link rel="stylesheet" href="/assets/css/manual.css">


<div class="container-fluid">
    <h5 class="mb-3">
        <i class="bi bi-book"></i> <?= htmlspecialchars($pageTitle) ?>
    </h5>

    <div class="row">
        <div class="col-lg-2">
            <nav id="manual-toc" class="sticky-top">
                <div class="list-group list-group-flush">
                    <div class="toc-group-label">시작</div>
                    <a class="list-group-item list-group-item-action" href="#sec-dashboard">대시보드</a>

                    <div class="toc-group-label">사이트 설정</div>
                    <a class="list-group-item list-group-item-action" href="#sec-settings">기본 설정</a>
                    <a class="list-group-item list-group-item-action" href="#sec-extensions">확장 기능</a>
                    <a class="list-group-item list-group-item-action" href="#sec-domains">도메인 관리</a>
                    <a class="list-group-item list-group-item-action" href="#sec-menu">메뉴 관리</a>
                    <a class="list-group-item list-group-item-action" href="#sec-system">시스템 관리</a>

                    <div class="toc-group-label">회원 관리</div>
                    <a class="list-group-item list-group-item-action" href="#sec-member">회원 관리</a>
                    <a class="list-group-item list-group-item-action" href="#sec-point">포인트 지갑</a>
                    <a class="list-group-item list-group-item-action" href="#sec-member-levels">회원 등급 관리</a>
                    <a class="list-group-item list-group-item-action" href="#sec-admin-perm">관리자 접근 권한</a>
                    <a class="list-group-item list-group-item-action" href="#sec-member-field">추가 입력 정보</a>
                    <a class="list-group-item list-group-item-action" href="#sec-policy">약관/정책</a>

                    <div class="toc-group-label">블록 관리</div>
                    <a class="list-group-item list-group-item-action" href="#sec-block-page">블록 페이지 관리</a>
                    <a class="list-group-item list-group-item-action" href="#sec-block-row">블록 행 관리</a>
                </div>
            </nav>
        </div>

        <div class="col-lg-10" id="manual-content">
<section id="sec-dashboard" class="manual-section">
    <h4><i class="bi bi-speedometer2"></i> 대시보드</h4>
    <p>관리자 접속 시 처음 표시되는 화면으로, 위젯 카드들을 그리드로 모아 사이트 현황을 한눈에 보여줍니다. (<code>/admin</code>, <code>/admin/dashboard</code>)</p>

    <h6 class="mt-3 fw-bold">화면 구성</h6>
    <table class="table table-bordered field-table">
        <tr><th>위젯 그리드</th><td>Core·플러그인·패키지가 등록한 위젯들이 카드 형태로 배치됩니다. 권한(레벨)에 따라 볼 수 있는 위젯만 표시됩니다.</td></tr>
        <tr><th>드래그 이동</th><td>각 카드 좌측 상단의 이동 아이콘(<i class="bi bi-arrows-move"></i>)을 잡고 끌어 배치를 바꿉니다. 순서는 자동 저장되며, 이때 배치 모드는 수동(MANUAL)으로 전환됩니다.</td></tr>
        <tr><th>위젯 숨기기</th><td>카드 우측 상단 X 버튼으로 해당 위젯을 숨깁니다.</td></tr>
        <tr><th>숨긴 위젯 보기</th><td>숨긴 위젯이 있을 때만 우측 상단에 나타나는 버튼입니다. Core/Package/Plugin 그룹별 목록에서 항목을 눌러 다시 표시합니다.</td></tr>
        <tr><th>기본 레이아웃으로 복원</th><td>모든 위젯 배치와 숨김 설정을 초기화하여 자동 정렬 상태로 되돌립니다.</td></tr>
    </table>

    <h6 class="mt-3 fw-bold">운영 절차</h6>
    <ol>
        <li>자주 보는 위젯을 위쪽으로 드래그해 배치합니다. (배치 즉시 저장)</li>
        <li>불필요한 위젯은 X로 숨겨 화면을 정리합니다.</li>
        <li>배치가 꼬였다면 [기본 레이아웃으로 복원]으로 초기화합니다.</li>
    </ol>

    <div class="manual-tip">
        <strong>안내:</strong> 위젯 배치와 숨김 설정은 로그인한 관리자 개인·현재 도메인 기준으로 저장됩니다. 다른 관리자 화면에는 영향을 주지 않습니다.
    </div>
</section>

<section id="sec-settings" class="manual-section">
    <h4><i class="bi bi-gear"></i> 기본 설정</h4>
    <p>현재 사이트(도메인)의 기본 운영 환경을 설정하는 화면입니다. 상단 탭으로 기본 정보 · 회사 정보 · 로고 및 SEO 설정 · 테마 설정 · 검색 설정 5개 영역을 오간 뒤 하단 저장으로 반영합니다. (<code>/admin/settings</code>)</p>

    <h6 class="mt-3 fw-bold">기본 정보 탭</h6>
    <table class="table table-bordered field-table">
        <tr><th>사이트 정보</th><td>사이트명(필수), 사이트 부제, 관리자 이메일을 입력합니다.</td></tr>
        <tr><th>기본 설정</th><td>게시판 등에서 사용할 에디터, 페이지당 목록 수(10~50), 아이디 형식(영문·숫자·밑줄 / 이메일)을 정합니다.</td></tr>
        <tr><th>가입 정책</th><td>가입 방식(바로 가입 / 관리자 승인), 가입 시 기본 회원 레벨, 비밀번호 최소 글자 수(4~32), 비밀번호 복잡도 규칙(소문자·숫자·특수문자 포함 여부)을 설정합니다.</td></tr>
        <tr><th>레이아웃 형태</th><td>PC 기준 레이아웃을 전체 / 좌측 사이드바 / 우측 사이드바 / 3단 중에서 선택합니다. 사이드바 사용 시 좌·우 폭(px)과 모바일 출력 여부를 지정합니다.</td></tr>
        <tr><th>넓이 설정</th><td>사이트 최대 넓이, 내용 최대 넓이(0 = 제한 없음/레이아웃 맞춤), 메인화면 레이아웃 적용 여부를 설정합니다.</td></tr>
    </table>

    <h6 class="mt-3 fw-bold">회사 정보 탭</h6>
    <table class="table table-bordered field-table">
        <tr><th>회사 기본 정보</th><td>회사명, 대표자명, 대표 전화·팩스, 대표 이메일, 사업자등록번호, 통신판매업 신고번호를 입력합니다.</td></tr>
        <tr><th>회사 주소</th><td>우편번호 검색 버튼으로 주소를 찾고 기본주소/상세주소를 입력합니다.</td></tr>
        <tr><th>고객센터 안내</th><td>고객센터 전화번호(미입력 시 대표 전화 사용), 상담시간 안내 문구.</td></tr>
        <tr><th>개인정보 보호 책임자</th><td>책임자 이름과 이메일을 입력합니다.</td></tr>
    </table>

    <h6 class="mt-3 fw-bold">로고 및 SEO 설정 탭</h6>
    <table class="table table-bordered field-table">
        <tr><th>로고/이미지</th><td>PC용·모바일용 로고, 파비콘, 앱 아이콘, OG 이미지를 파일로 업로드합니다. (각 권장 규격 안내 표기)</td></tr>
        <tr><th>메타 태그</th><td>기본 메타 타이틀, 메타 설명, 메타 키워드를 입력합니다.</td></tr>
        <tr><th>추적 코드</th><td>Google Analytics(GA4), Meta 픽셀, 카카오 픽셀, 네이버 애널리틱스 ID를 입력하면 추적이 자동 활성화됩니다.</td></tr>
        <tr><th>사이트 인증</th><td>Google Search Console, Naver Search Advisor 인증 코드.</td></tr>
        <tr><th>커스텀 스크립트 / robots.txt</th><td>&lt;head&gt; 및 &lt;/body&gt; 직전 삽입 스크립트, robots.txt 내용(입력 시 파일보다 우선)을 직접 지정합니다.</td></tr>
        <tr><th>SNS 채널</th><td>[추가] 버튼으로 SNS 종류·URL을 여러 개 등록하고 드래그로 순서를 바꿉니다. 푸터 등에 아이콘으로 노출됩니다.</td></tr>
    </table>

    <h6 class="mt-3 fw-bold">테마 설정 탭</h6>
    <table class="table table-bordered field-table">
        <tr><th>관리자 스킨</th><td>관리자 화면 프레임(레이아웃) 스킨을 선택합니다.</td></tr>
        <tr><th>프론트 스킨</th><td>프론트 공통 프레임 스킨을 선택합니다.</td></tr>
        <tr><th>콘텐츠 스킨</th><td>Index(메인)·Member·Auth·Policy·Mypage·Search 등 영역별 스킨을 각각 선택합니다. (설치된 스킨 폴더가 자동으로 목록에 표시됩니다.)</td></tr>
    </table>

    <h6 class="mt-3 fw-bold">검색 설정 탭</h6>
    <table class="table table-bordered field-table">
        <tr><th>검색 소스</th><td>게시판·쇼핑몰 등 통합검색 소스를 드래그로 결과 출력 순서를 정하고, 체크박스로 검색 포함 여부를 선택합니다. '항상 포함' 소스는 해제할 수 없습니다.</td></tr>
        <tr><th>검색 옵션</th><td>소스별 최대 결과 수(1~20)를 지정합니다.</td></tr>
    </table>

    <div class="manual-tip">
        <strong>안내:</strong> 이 화면은 <em>현재 접속한 사이트</em>의 설정만 다룹니다. 하위 사이트 관리는 도메인 관리 메뉴를 이용하세요. 로고·이미지 변경 후에는 실제 화면과 SNS 공유 미리보기를 함께 확인하세요.
    </div>
</section>

<section id="sec-extensions" class="manual-section">
    <h4><i class="bi bi-puzzle"></i> 확장 기능</h4>
    <p>사이트에서 사용할 플러그인과 패키지를 켜고 끄는 화면입니다. 좌측은 플러그인, 우측은 패키지 카드로 2열 배치됩니다. (<code>/admin/extensions</code>)</p>

    <h6 class="mt-3 fw-bold">화면 구성</h6>
    <table class="table table-bordered field-table">
        <tr><th>확장 카드</th><td>각 카드에는 체크박스, 아이콘·이름, 설명, 버전(v)·제작자가 표시됩니다. 활성화된 카드는 파란 테두리로 강조됩니다.</td></tr>
        <tr><th>활성/비활성</th><td>카드의 체크박스를 켜면 사용, 끄면 미사용입니다.</td></tr>
        <tr><th>필수 항목</th><td>'필수' 배지가 붙은 항목은 체크가 잠겨 있어 끌 수 없습니다.</td></tr>
        <tr><th>드래그 순서</th><td>카드를 드래그하면 관리자 메뉴에 노출되는 순서를 바꿀 수 있습니다.</td></tr>
        <tr><th>저장</th><td>우측 상단 [저장]을 눌러야 변경이 반영됩니다. 저장 시 설치(install)/제거(uninstall) 라이프사이클이 자동 실행됩니다.</td></tr>
    </table>

    <h6 class="mt-3 fw-bold">운영 절차</h6>
    <ol>
        <li>사용할 플러그인·패키지의 체크박스를 켭니다.</li>
        <li>필요 시 카드를 드래그해 메뉴 노출 순서를 조정합니다.</li>
        <li>[저장]을 누르면 설치/제거가 실행되고 화면이 새로고침됩니다.</li>
    </ol>

    <div class="manual-warn">
        <strong>주의:</strong> 사용 중이던 확장을 끄면 제거(uninstall) 처리가 실행되어 해당 기능의 데이터가 영향을 받을 수 있습니다. 데이터가 쌓인 확장은 비활성화 전에 영향 범위를 확인하세요.
    </div>
</section>

<section id="sec-domains" class="manual-section">
    <h4><i class="bi bi-hdd-network"></i> 도메인 관리</h4>
    <p>현재 사이트 아래에 소속된 하위 사이트(도메인)를 등록·관리하는 화면입니다. 자기 자신의 도메인은 목록에 표시되지 않으며 설정 변경은 기본 설정 메뉴에서 합니다. (<code>/admin/domains</code>)</p>

    <h6 class="mt-3 fw-bold">목록 화면</h6>
    <table class="table table-bordered field-table">
        <tr><th>검색/필터</th><td>상태(활성/비활성/차단), 계약유형(무료/월간/연간/영구) 필터와 도메인명·도메인 그룹·사이트명 검색을 제공합니다.</td></tr>
        <tr><th>목록 컬럼</th><td>번호, 도메인(새 창 열기 링크·기본 배지), 그룹, 상태, 계약유형, 계약만료일(만료 시 빨간 표시), 등록일.</td></tr>
        <tr><th>행 액션</th><td>[수정], 활성 도메인은 [접속](대리 로그인), 설치 패키지가 등록한 설정 바로가기(<i class="bi bi-gear"></i> 드롭다운), [삭제](기본 도메인 ID=1 제외).</td></tr>
        <tr><th>일괄 처리</th><td>목록에서 상태·계약유형 값을 바꾼 뒤 [선택 수정], 또는 [선택 삭제]로 여러 건을 한 번에 처리합니다. (기본 도메인은 제외됨)</td></tr>
    </table>

    <h6 class="mt-3 fw-bold">하위 사이트 등록/수정</h6>
    <table class="table table-bordered field-table">
        <tr><th>소유자 회원</th><td>등록 시 소유자 회원 아이디를 입력하고 [소유자 확인]으로 검증합니다. 도메인 운영 권한이 있고 같은 그룹 소속이며 운영 사이트가 없는(최대 1개) 회원만 가능합니다.</td></tr>
        <tr><th>도메인명</th><td>등록 시 입력 후 [중복 확인]으로 사용 가능 여부를 확인합니다. 영문·숫자·하이픈·점만 사용합니다.</td></tr>
        <tr><th>도메인 그룹</th><td>생성 시 상위 그룹 기준으로 자동 부여되며 변경할 수 없습니다.</td></tr>
        <tr><th>계약 정보</th><td>상태, 계약 유형, 계약 시작일/종료일을 입력합니다.</td></tr>
        <tr><th>수정 제한</th><td>수정 화면에서는 소유자·도메인명·그룹 등 기본 정보가 읽기 전용이고, 계약 정보(상태/유형/기간)만 변경할 수 있습니다.</td></tr>
    </table>

    <h6 class="mt-3 fw-bold">운영 절차</h6>
    <ol>
        <li>[하위 사이트 등록]에서 소유자 아이디를 확인하고 도메인명 중복을 확인합니다.</li>
        <li>계약 정보를 입력하고 저장하면 수정 화면으로 이동하며 기본 데이터가 자동 구성됩니다.</li>
        <li>활성 도메인은 [접속] 버튼으로 대리 로그인하여 해당 사이트를 관리합니다.</li>
    </ol>

    <div class="manual-warn">
        <strong>주의:</strong> 도메인 삭제는 복구할 수 없습니다. 접속·수정·삭제는 모두 자기 하위 도메인에 대해서만 허용되며, 권한 밖 도메인 접근은 차단됩니다.
    </div>
</section>

<section id="sec-menu" class="manual-section">
    <h4><i class="bi bi-list-nested"></i> 메뉴 관리</h4>
    <p>프론트에 노출되는 각종 메뉴를 관리합니다. 상단 탭으로 메뉴 아이템 · 메인 메뉴 · 유틸리티 메뉴 · 푸터 메뉴 · 마이페이지 메뉴를 오갑니다. (<code>/admin/menu</code>)</p>

    <h6 class="mt-3 fw-bold">메뉴 아이템 탭 (재료 만들기)</h6>
    <table class="table table-bordered field-table">
        <tr><th>목록</th><td>제공자(Core/Plugin/Package)·제공자명 필터와 검색을 제공하며, 접근 레벨·PC·모바일·상태 값을 목록에서 바로 바꾼 뒤 [선택 수정]으로 일괄 저장합니다.</td></tr>
        <tr><th>메뉴 추가/수정</th><td>[메뉴 추가] 또는 행의 연필 버튼으로 모달을 엽니다.</td></tr>
        <tr><th>주요 필드</th><td>메뉴명(필수), URL(비우면 클릭 불가 부모 메뉴), 제공자·제공자명, 표시 대상(전체/비로그인만/로그인만), 메뉴 쌍 코드, 접근 레벨, 링크 타겟, PC·모바일 표시, 활성화.</td></tr>
    </table>

    <h6 class="mt-3 fw-bold">메인 메뉴 탭 (트리 구성)</h6>
    <table class="table table-bordered field-table">
        <tr><th>구성 방식</th><td>좌측 아이템 풀에서 메뉴를 드래그하거나 추가 버튼으로 트리에 넣고, 드래그로 상·하위 계층과 순서를 잡습니다.</td></tr>
        <tr><th>쌍(pair) 메뉴</th><td>같은 쌍 코드를 가진 메뉴는 자동으로 함께 묶여 배치됩니다.</td></tr>
        <tr><th>저장</th><td>배치·제거는 화면에만 반영되므로 반드시 [저장]을 눌러야 실제 트리에 확정됩니다.</td></tr>
    </table>

    <h6 class="mt-3 fw-bold">유틸리티 · 푸터 · 마이페이지 탭</h6>
    <table class="table table-bordered field-table">
        <tr><th>공통 방식</th><td>왼쪽 풀에서 오른쪽 목록으로 드래그해 넣고, 순서를 정한 뒤 [저장]으로 반영합니다. 목록에서 풀로 되돌리면 노출에서 제외됩니다.</td></tr>
        <tr><th>마이페이지 고정 항목</th><td>'회원정보'(맨 위)·'회원탈퇴'(맨 아래) 시스템 메뉴는 목록 밖으로 뺄 수 없고 양끝에 고정됩니다.</td></tr>
    </table>

    <h6 class="mt-3 fw-bold">운영 절차</h6>
    <ol>
        <li>먼저 [메뉴 아이템] 탭에서 필요한 메뉴 항목을 만듭니다.</li>
        <li>[메인 메뉴] 탭에서 트리로 배치하고 저장합니다.</li>
        <li>유틸리티·푸터·마이페이지 영역에 필요한 항목을 드래그해 넣고 각 탭에서 저장합니다.</li>
    </ol>

    <div class="manual-tip">
        <strong>안내:</strong> 각 탭은 <em>탭마다 별도의 저장 버튼</em>을 사용합니다. 드래그로 옮겨도 저장 전에는 반영되지 않으니 탭을 넘기기 전에 저장하세요.
    </div>
</section>

<section id="sec-system" class="manual-section">
    <h4><i class="bi bi-tools"></i> 시스템 관리</h4>
    <p>캐시 초기화, 데이터베이스 마이그레이션 점검·실행, 임시파일 정리, 데이터 초기화를 수행하는 유지보수 화면입니다. (<code>/admin/system</code>)</p>

    <h6 class="mt-3 fw-bold">캐시 관리</h6>
    <table class="table table-bordered field-table">
        <tr><th>정보 표시</th><td>캐시 드라이버(file/redis), 캐시 용량, 저장 위치를 보여줍니다.</td></tr>
        <tr><th>전체 캐시 초기화</th><td>버튼 클릭 시 확인 후 전체 캐시를 비웁니다. 설정·스킨 변경이 화면에 즉시 반영되지 않을 때 사용합니다.</td></tr>
    </table>

    <h6 class="mt-3 fw-bold">임시파일 정리</h6>
    <table class="table table-bordered field-table">
        <tr><th>현황</th><td>에디터 임시 이미지·보안 임시 파일의 개수와 용량을 표로 보여줍니다.</td></tr>
        <tr><th>정리 실행</th><td>보관 기간(1/6/12/24시간 이상 경과)을 고르고 [임시파일 정리]로 오래된 임시파일을 삭제합니다.</td></tr>
    </table>

    <h6 class="mt-3 fw-bold">데이터베이스 마이그레이션</h6>
    <table class="table table-bordered field-table">
        <tr><th>상태 표</th><td>Core·플러그인·패키지별로 실행됨/대기 마이그레이션 수와 상태를 표시하고, 대기 파일명을 나열합니다.</td></tr>
        <tr><th>실행</th><td>대기 건이 있으면 [미실행 마이그레이션 실행] 버튼이 나타납니다. 실행하면 테이블이 생성된 확장의 초기 시드도 함께 마무리됩니다.</td></tr>
    </table>

    <h6 class="mt-3 fw-bold">확장 로딩 진단</h6>
    <table class="table table-bordered field-table">
        <tr><th>표시 조건</th><td>플러그인·패키지 로딩 중 오류가 발생한 경우에만 상단에 경고 카드로 구분·이름·단계·오류·위치가 표시됩니다.</td></tr>
    </table>

    <h6 class="mt-3 fw-bold">데이터 초기화 (SUPER 전용)</h6>
    <table class="table table-bordered field-table">
        <tr><th>노출 조건</th><td>최고관리자(SUPER)에게만 표시됩니다.</td></tr>
        <tr><th>항목별 초기화</th><td>카테고리별 초기화 카드에서 [초기화]를 누르고 관리자 비밀번호를 입력해 실행합니다.</td></tr>
        <tr><th>전체 초기화</th><td>SUPER 회원을 제외한 모든 데이터(회원·게시판·블록·메뉴·업로드 파일·플러그인 데이터 등)를 삭제합니다. 비밀번호와 확인 문구 <code>전체 초기화</code>를 정확히 입력해야 실행됩니다.</td></tr>
    </table>

    <div class="manual-warn">
        <strong>주의:</strong> 데이터 초기화는 복구할 수 없습니다. 마이그레이션 실행·데이터 초기화는 실행 후 되돌릴 수 없으므로, 사전에 백업을 확보한 뒤 신중히 진행하세요.
    </div>
</section>

<section id="sec-member" class="manual-section">
    <h4><i class="bi bi-people"></i> 회원 관리</h4>
    <p>사이트 회원을 검색·조회하고 등급·상태를 관리하며, 회원을 직접 등록/수정/삭제하는 화면입니다. (<code>/admin/member</code>)</p>

    <h6 class="mt-3 fw-bold">목록 컬럼</h6>
    <table class="table table-bordered field-table">
        <tr><th>기본 컬럼</th><td>번호, 아이디, 닉네임, 등급, 상태, 포인트, 가입일시, 최종 로그인. 헤더 클릭 정렬을 지원합니다.</td></tr>
        <tr><th>등급·상태</th><td>목록에서 각 행의 등급/상태를 셀렉트박스로 바로 바꿀 수 있으며, 값을 바꾸면 해당 행이 자동 선택(변경 표시)됩니다.</td></tr>
        <tr><th>추가 필드</th><td>'추가 입력 정보'에서 <em>회원 목록에 표시</em>로 설정한 필드가 컬럼으로 자동 추가됩니다. 암호화 필드는 라벨 앞에 🔒 가 붙습니다.</td></tr>
        <tr><th>포인트</th><td>보유 포인트와 함께 포인트 내역(지갑) 바로가기 아이콘을 제공합니다.</td></tr>
        <tr><th>관리</th><td>행별 [수정] / [삭제] 버튼.</td></tr>
    </table>

    <h6 class="mt-3 fw-bold">검색 · 일괄처리</h6>
    <table class="table table-bordered field-table">
        <tr><th>검색</th><td>검색 필드 선택(회원 컬럼 + 추가 필드) + 검색어 조합. 검색 필드를 고르지 않고 검색어만 입력하면 아이디/닉네임 통합검색으로 동작합니다.</td></tr>
        <tr><th>선택 수정</th><td>목록에서 등급/상태를 바꾼 회원들을 체크해 한 번에 저장합니다.</td></tr>
        <tr><th>선택 삭제</th><td>체크한 회원을 일괄 삭제합니다.</td></tr>
    </table>

    <h6 class="mt-3 fw-bold">회원 등록/수정 폼</h6>
    <table class="table table-bordered field-table">
        <tr><th>아이디</th><td>신규 등록 시 영문·숫자 4~20자로 입력하고 <strong>중복확인</strong>을 거쳐야 저장됩니다. 수정 시에는 변경할 수 없습니다.</td></tr>
        <tr><th>비밀번호</th><td>신규는 필수(최소 6자). 수정 시 비워두면 기존 비밀번호가 유지됩니다.</td></tr>
        <tr><th>닉네임</th><td>2~20자, 중복확인 지원.</td></tr>
        <tr><th>추가 정보</th><td>정의된 추가 필드가 타입별 입력 형태(텍스트/이메일/전화/주소/선택형/날짜/숫자/파일/아바타 등)로 표시됩니다. 중복 불가 필드는 중복확인 버튼, 주소 타입은 우편번호 검색을 제공합니다.</td></tr>
        <tr><th>회원 설정</th><td>회원 등급과 계정 상태(활성/비활성/휴면/차단)를 지정합니다.</td></tr>
        <tr><th>사이트 생성 가능</th><td>최고관리자가 회원을 수정할 때만 노출되는 스위치로, 켜면 그 회원이 하위 도메인(사이트)을 만들 수 있습니다.</td></tr>
        <tr><th>참고 정보(수정)</th><td>보유 포인트·가입일·최종 로그인과 함께 최근 포인트 내역 5건을 보여줍니다.</td></tr>
    </table>

    <ol>
        <li>[회원 등록]에서 아이디·비밀번호·닉네임을 입력하고 중복확인을 마칩니다.</li>
        <li>추가 정보를 입력한 뒤 등급·상태를 지정합니다.</li>
        <li>저장하면 목록으로 돌아가며, 목록의 등급/상태는 인라인으로 즉시 수정할 수 있습니다.</li>
    </ol>

    <div class="manual-tip">
        <strong>안내:</strong> 중복 불가로 지정된 필드는 저장 전에 중복확인을 통과해야 하며, 확인하지 않으면 저장이 막힙니다.
    </div>
    <div class="manual-warn">
        <strong>주의:</strong> 회원 삭제는 되돌릴 수 없습니다. 삭제 전 필요한 정보를 확인하세요.
    </div>
</section>

<section id="sec-point" class="manual-section">
    <h4><i class="bi bi-wallet2"></i> 포인트 지갑</h4>
    <p>회원 포인트(원장)의 변동 내역을 조회하고 포인트를 수동으로 지급/차감하는 코어 지갑 화면입니다. (<code>/admin/point</code>) 포인트 내역 조회·수동 조정 기능은 코어 지갑으로 통합되어 여기서 관리합니다.</p>

    <h6 class="mt-3 fw-bold">포인트 내역 목록</h6>
    <table class="table table-bordered field-table">
        <tr><th>목록 컬럼</th><td>번호, 회원(아이디, 회원 수정으로 연결), 변동(+/- 금액), 변경 후 잔액, 구분, 내용, 일시.</td></tr>
        <tr><th>구분</th><td>플러그인 / 패키지 / 관리자 / 시스템 중 어디서 발생한 변동인지 배지로 표시합니다. 관리자가 조정한 건에는 '관리자' 배지가 함께 붙습니다.</td></tr>
        <tr><th>필터</th><td>구분, 시작일~종료일, 회원 ID로 내역을 좁혀 조회합니다.</td></tr>
    </table>

    <h6 class="mt-3 fw-bold">포인트 수동 조정</h6>
    <table class="table table-bordered field-table">
        <tr><th>회원 선택</th><td>아이디로 검색(자동완성)해 회원을 선택하면 현재 잔액이 표시됩니다.</td></tr>
        <tr><th>조정 유형</th><td>지급 또는 차감 중 선택합니다.</td></tr>
        <tr><th>포인트</th><td>1 이상의 양수 금액. 입력하면 변경 후 예상 잔액이 미리보기로 표시됩니다.</td></tr>
        <tr><th>조정 사유</th><td>필수. 회원에게 표시되는 메시지입니다.</td></tr>
        <tr><th>관리자 메모</th><td>내부 관리용 메모(선택).</td></tr>
    </table>

    <ol>
        <li>[포인트 수동 조정]에서 회원을 검색해 선택합니다.</li>
        <li>지급/차감과 금액을 정하고 예상 잔액을 확인합니다.</li>
        <li>조정 사유를 입력하고 저장하면 내역 목록에 '관리자' 구분으로 기록됩니다.</li>
    </ol>

    <div class="manual-tip">
        <strong>안내:</strong> 조정 사유는 회원에게 노출되므로 '이벤트 당첨', '잘못된 지급 취소'처럼 명확하게 적고, 내부 사유는 관리자 메모에 남기세요.
    </div>
    <div class="manual-warn">
        <strong>주의:</strong> 차감 시 회원 잔액이 부족하면 변경 후 잔액이 음수가 되어 경고가 표시됩니다. 금액을 다시 확인하세요.
    </div>
</section>

<section id="sec-member-levels" class="manual-section">
    <h4><i class="bi bi-person-badge"></i> 회원 등급 관리</h4>
    <p>회원 등급과 등급별 역할을 관리하는 화면입니다. 등급은 모든 사이트에서 공통으로 쓰이는 전역 데이터이며, <strong>최고관리자만</strong> 접근할 수 있습니다. (<code>/admin/member-levels</code>)</p>

    <h6 class="mt-3 fw-bold">목록</h6>
    <table class="table table-bordered field-table">
        <tr><th>컬럼</th><td>레벨(값), 등급명, 타입, 관리자(관리자 모드 접근), 도메인(도메인 운영 가능), 관리.</td></tr>
        <tr><th>인라인 권한</th><td>목록의 '관리자', '도메인' 체크박스를 조정한 뒤 [선택 수정]을 누르면 선택한 등급들의 권한이 일괄 반영됩니다.</td></tr>
        <tr><th>필터</th><td>레벨 타입으로 목록을 걸러 볼 수 있습니다.</td></tr>
    </table>

    <h6 class="mt-3 fw-bold">등록/수정 폼</h6>
    <table class="table table-bordered field-table">
        <tr><th>레벨값</th><td>1~255 사이의 고유값. 중복 확인 버튼으로 사용 가능 여부를 검사합니다.</td></tr>
        <tr><th>등급명</th><td>예: 일반회원, 스태프 등 (최대 50자).</td></tr>
        <tr><th>레벨 타입</th><td>SUPER(최고관리자), STAFF(스태프/직원), PARTNER(파트너), SITE_MASTER(사이트 운영자), SUPPLIER(공급처), BASIC(일반회원) 중 선택하는 역할 구분값입니다.</td></tr>
        <tr><th>최고관리자</th><td>전체 시스템 관리 권한. 켜면 관리자 모드 접근·도메인 운영이 자동으로 함께 켜집니다.</td></tr>
        <tr><th>관리자 모드 접근</th><td>/admin 경로에 접근할 수 있는 권한.</td></tr>
        <tr><th>도메인 운영 가능</th><td>하위 사이트의 소유자로 지정될 수 있는 권한.</td></tr>
    </table>

    <div class="manual-tip">
        <strong>안내:</strong> 관리자 모드 접근이 켜진 등급은 기본적으로 모든 관리 메뉴에 접근할 수 있으며, 세부 차단은 '관리자 접근 권한 관리'에서 설정합니다.
    </div>
    <div class="manual-warn">
        <strong>주의:</strong> 최고관리자 등급은 수정·삭제할 수 없습니다. 또한 해당 등급을 사용 중인 회원이 있으면 그 등급은 삭제되지 않습니다.
    </div>
</section>

<section id="sec-admin-perm" class="manual-section">
    <h4><i class="bi bi-shield-lock"></i> 관리자 접근 권한 관리</h4>
    <p>관리자 등급별로 접근을 <strong>차단</strong>할 관리 메뉴를 지정하는 화면입니다. 네거티브(블랙리스트) 방식이라 등록하지 않은 메뉴는 기본적으로 접근이 허용되며, <strong>최고관리자만</strong> 접근할 수 있습니다. (<code>/admin/admin-permissions</code>)</p>

    <h6 class="mt-3 fw-bold">화면 구성 (2컬럼)</h6>
    <table class="table table-bordered field-table">
        <tr><th>좌측 목록</th><td>등록된 차단 규칙 목록: 등급, 등급명, 1차 메뉴, 적용 메뉴(코드+메뉴명, 플러그인 [P]·패키지 [K] 표시), 적용 권한(L/R/W/D/F 배지), 관리. 등급 필터와 선택 삭제를 제공합니다.</td></tr>
        <tr><th>우측 설정 폼</th><td>관리자 등급 선택 → 1차 메뉴 선택 → 서브메뉴별 차단 권한 체크 → 저장 순으로 진행합니다. 최고관리자 등급은 차단 대상에서 제외됩니다.</td></tr>
    </table>

    <h6 class="mt-3 fw-bold">권한 구분</h6>
    <table class="table table-bordered field-table">
        <tr><th>L (목록)</th><td>목록 페이지 접근</td></tr>
        <tr><th>R (읽기)</th><td>상세 페이지 읽기</td></tr>
        <tr><th>W (쓰기)</th><td>작성/수정 (쓰기 차단 시 수정도 차단)</td></tr>
        <tr><th>D (삭제)</th><td>삭제</td></tr>
        <tr><th>F (파일)</th><td>파일 다운로드</td></tr>
    </table>

    <ol>
        <li>우측에서 권한을 제한할 관리자 등급을 선택합니다.</li>
        <li>1차 메뉴를 고르면 하위 서브메뉴가 표로 표시됩니다.</li>
        <li>차단할 서브메뉴의 권한(L/R/W/D/F)에 체크하고 저장합니다.</li>
        <li>좌측 목록의 [수정] 버튼을 누르면 해당 규칙이 우측 폼에 다시 불러와집니다.</li>
    </ol>

    <div class="manual-warn">
        <strong>주의:</strong> 체크한 권한은 해당 등급에서 <strong>차단</strong>됩니다(허용이 아님). 아무것도 등록하지 않으면 그 등급은 모든 메뉴에 접근할 수 있습니다.
    </div>
</section>

<section id="sec-member-field" class="manual-section">
    <h4><i class="bi bi-input-cursor-text"></i> 추가 입력 정보 관리</h4>
    <p>회원가입·프로필에서 받을 회원 추가 필드를 정의하는 화면입니다. 좌측 목록에서 필드를 관리하고, 우측 가이드의 [새 필드 추가]로 생성합니다. (<code>/admin/member-field</code>)</p>

    <h6 class="mt-3 fw-bold">목록</h6>
    <table class="table table-bordered field-table">
        <tr><th>컬럼</th><td>필드명(코드)+라벨, 타입, 필수, 암호화, 검색, 중복불가, 가입폼, 프로필, 관리.</td></tr>
        <tr><th>정렬</th><td>맨 앞 이동 핸들을 드래그해 순서를 바꾸면 자동 저장됩니다.</td></tr>
    </table>

    <h6 class="mt-3 fw-bold">필드 메타</h6>
    <table class="table table-bordered field-table">
        <tr><th>필드명</th><td>영문 소문자·숫자·언더스코어만 사용. 저장 후에는 수정할 수 없습니다.</td></tr>
        <tr><th>필드 라벨</th><td>사용자에게 표시되는 이름.</td></tr>
        <tr><th>필드 타입</th><td>텍스트, 이메일, 전화번호, 숫자, 날짜, 여러 줄 텍스트, 선택(드롭다운), 라디오 버튼, 체크박스, 주소(우편번호 검색), 첨부파일, 아바타(이미지).</td></tr>
        <tr><th>검증 규칙</th><td>정규식으로 입력값을 검증합니다. 비우면 타입 기본 검증만 적용됩니다.</td></tr>
        <tr><th>선택 항목</th><td>선택/라디오/체크박스 타입일 때 값과 표시 라벨을 추가합니다.</td></tr>
        <tr><th>파일 설정</th><td>첨부파일/아바타 타입일 때 최대 파일 크기(MB)와 허용 확장자를 지정합니다.</td></tr>
    </table>

    <h6 class="mt-3 fw-bold">표시 · 보안 설정</h6>
    <table class="table table-bordered field-table">
        <tr><th>표시</th><td>필수 입력, 회원가입 시 표시, 프로필 수정 시 표시, 회원 목록에 표시, 관리자 전용(사용자 페이지에서 숨김).</td></tr>
        <tr><th>보안</th><td>암호화 저장, 검색 가능(관리자 회원 검색에서 사용), 중복 불가(가입/수정 시 중복확인 버튼 표시).</td></tr>
        <tr><th>타입 제약</th><td>첨부파일·아바타 타입은 암호화/검색/중복불가 및 검증 규칙을 사용할 수 없습니다.</td></tr>
    </table>

    <div class="manual-tip">
        <strong>안내:</strong> 암호화 + 검색을 함께 켠 필드는 <strong>완전 일치</strong> 검색만 가능합니다. 아바타는 도메인당 1개 예약 필드로, 필드명이 avatar로 고정됩니다.
    </div>
    <div class="manual-warn">
        <strong>주의:</strong> 필드를 삭제하면 그 필드에 저장된 모든 회원 데이터도 함께 삭제됩니다. 또한 한 번 암호화로 설정한 필드는 암호화를 해제할 수 없습니다.
    </div>
</section>

<section id="sec-policy" class="manual-section">
    <h4><i class="bi bi-file-earmark-text"></i> 약관/정책 관리</h4>
    <p>이용약관·개인정보처리방침 등 정책/약관을 등록·수정하고, 회원가입 동의 항목으로 노출할지 관리하는 화면입니다. (<code>/admin/policy</code>)</p>

    <h6 class="mt-3 fw-bold">목록</h6>
    <table class="table table-bordered field-table">
        <tr><th>컬럼</th><td>순서, 타입, 제목, 슬러그(약관 보기 링크), 버전, 필수, 회원가입, 상태, 관리.</td></tr>
        <tr><th>인라인 수정</th><td>필수 동의·회원가입 출력·상태(활성) 체크박스는 클릭 즉시 저장됩니다.</td></tr>
        <tr><th>정렬</th><td>순서 핸들을 드래그해 노출 순서를 변경합니다.</td></tr>
        <tr><th>필터</th><td>타입, 상태(활성/비활성), 제목·슬러그 키워드로 검색합니다.</td></tr>
    </table>

    <h6 class="mt-3 fw-bold">등록/수정 폼</h6>
    <table class="table table-bordered field-table">
        <tr><th>정책 타입</th><td>이용약관, 개인정보처리방침, 마케팅 수신 동의, 위치정보 이용약관, 커스텀. (이용약관·개인정보처리방침은 필수 약관으로 표시됩니다.)</td></tr>
        <tr><th>버전 · 정렬 순서</th><td>버전 문자열(예: 1.0)과 노출 순서(작을수록 먼저)를 지정합니다.</td></tr>
        <tr><th>제목 · 슬러그</th><td>제목은 필수. 슬러그는 URL 식별자로 영문 소문자·숫자·하이픈만 사용하며 중복 확인을 제공합니다.</td></tr>
        <tr><th>정책 내용</th><td>에디터로 본문을 작성합니다(필수). 치환 변수({#회사명}, {#대표자}, {#적용일자} 등)를 클릭해 삽입할 수 있습니다.</td></tr>
        <tr><th>동의 설정</th><td>필수 동의 여부, 활성화 여부(비활성 시 사용자에게 표시 안 됨).</td></tr>
        <tr><th>회원가입 출력</th><td>회원가입 폼에 동의 약관으로 표시할지 설정합니다. 저장된 약관은 /policy/view/{슬러그} 주소로 확인할 수 있습니다.</td></tr>
    </table>

    <ol>
        <li>정책 타입을 고르면 신규 등록 시 제목·슬러그 기본값이 제안됩니다.</li>
        <li>내용을 작성하고 필요한 치환 변수를 삽입합니다.</li>
        <li>슬러그 중복을 확인한 뒤 저장하고, 목록에서 필수/회원가입/상태를 조정합니다.</li>
    </ol>

    <div class="manual-tip">
        <strong>안내:</strong> 치환 변수는 사이트 설정의 회사 정보를 기준으로 실제 값으로 치환되므로, 회사 정보를 먼저 채워두면 약관에 자동 반영됩니다.
    </div>
</section>

<section id="sec-block-page" class="manual-section">
    <h4><i class="bi bi-file-earmark-richtext"></i> 블록 페이지 관리</h4>
    <p>블록 페이지 관리(<code>/admin/block-page</code>)는 여러 개의 <strong>행(Row)</strong>을 담아 하나의 완성된 화면을 만드는 "그릇"을 관리하는 곳입니다. 실제 콘텐츠(이미지·게시판·문구 등)는 <a href="#sec-block-row">블록 행 관리</a>에서 채우며, 이 화면에서는 페이지의 <strong>주소(코드)·제목·레이아웃·접근 권한·사용 여부</strong>를 정합니다.</p>
    <p>블록은 크게 두 가지 방식으로 노출됩니다. 하나는 사이트 공통 <strong>영역(위치)</strong>에 붙이는 방식이고, 다른 하나는 <code>/p/코드</code> 주소를 가진 <strong>독립 페이지</strong>를 새로 만드는 방식입니다. 아래 영역 구조를 먼저 이해하면 어디에 무엇을 넣을지 판단하기 쉽습니다.</p>

    <h6 class="mt-3 fw-bold">영역(위치) 구조 한눈에 보기</h6>
    <p class="text-muted">아래 영역은 블록 행의 <strong>출력 위치</strong>로 선택할 수 있는 사이트 공통 구역입니다. 사이드바 영역은 2단/3단 레이아웃에서만 나타납니다.</p>
    <table class="table table-bordered field-table text-center">
        <tr>
            <th style="width:220px;">영역</th>
            <th style="width:180px;">노출 조건</th>
            <th>설명</th>
            <th style="width:340px;">구조도</th>
        </tr>
        <tr>
            <td><span class="zone-chip zone-sub">서브페이지 상단</span></td>
            <td>서브페이지</td>
            <td class="zone-sub">Header 아래에 공통으로 노출되는 영역</td>
            <td rowspan="8" class="zone-map align-middle">
                <div class="zone-map-box">
                    <div class="zone-map-row zone-sub">서브페이지 상단</div>
                    <div class="zone-map-grid">
                        <div class="zone-map-side">좌측 사이드<br><small>(설정 시)</small></div>
                        <div class="zone-map-center">
                            <div class="c-head">콘텐츠 상단</div>
                            <div class="c-body">본문 콘텐츠 영역</div>
                            <div class="c-foot">콘텐츠 하단</div>
                        </div>
                        <div class="zone-map-side">우측 사이드<br><small>(설정 시)</small></div>
                    </div>
                    <div class="zone-map-row zone-sub mb-0">서브페이지 하단</div>
                </div>
            </td>
        </tr>
        <tr>
            <td><span class="zone-chip zone-content-head">콘텐츠 상단</span></td>
            <td>공통</td>
            <td class="zone-content-head">본문 콘텐츠 바로 위에 노출됩니다.</td>
        </tr>
        <tr>
            <td><span class="zone-chip zone-content-body">본문 콘텐츠</span></td>
            <td>공통</td>
            <td class="zone-content-body">페이지의 실제 본문이 출력되는 영역입니다. (메인화면 위치가 여기에 해당)</td>
        </tr>
        <tr>
            <td><span class="zone-chip zone-content-foot">콘텐츠 하단</span></td>
            <td>공통</td>
            <td class="zone-content-foot">본문 콘텐츠 바로 아래에 노출됩니다.</td>
        </tr>
        <tr>
            <td><span class="zone-chip zone-side">좌측 사이드</span></td>
            <td>2단/3단 레이아웃</td>
            <td class="zone-side">사이드바를 사용하는 레이아웃일 때만 노출됩니다.</td>
        </tr>
        <tr>
            <td><span class="zone-chip zone-side">우측 사이드</span></td>
            <td>2단/3단 레이아웃</td>
            <td class="zone-side">사이드바를 사용하는 레이아웃일 때만 노출됩니다.</td>
        </tr>
        <tr>
            <td><span class="zone-chip zone-sub">서브페이지 하단</span></td>
            <td>서브페이지</td>
            <td class="zone-sub">Footer 위에 공통으로 노출되는 영역</td>
        </tr>
    </table>

    <div class="manual-tip">
        <strong>안내:</strong> "메인화면", "서브페이지 상단/하단"은 위치 기반 행에서 선택하는 출력 위치이고, "좌측/우측 사이드"는 레이아웃 설정에 사이드바를 켜야 나타납니다. 콘텐츠가 안 보이면 먼저 이 영역 조건이 맞는지 확인하세요.
    </div>

    <h6 class="mt-3 fw-bold">페이지 목록 화면</h6>
    <table class="table table-bordered field-table">
        <tr><th>표시 항목</th><td>ID, 코드(<code>/p/코드</code>), 제목·설명, 행 수, 접근레벨, 레이아웃(기본/와이드/사용자폭 px), 상태를 한 줄로 확인합니다.</td></tr>
        <tr><th>상태 전환</th><td>목록의 상태 칸에서 사용/미사용을 바로 바꿀 수 있습니다.</td></tr>
        <tr><th>바로가기</th><td>각 행의 <strong>수정</strong>, <strong>행 관리</strong> 버튼으로 이동합니다. 행 관리는 해당 페이지의 행 목록으로 연결됩니다.</td></tr>
        <tr><th>삭제 제한</th><td>연결된 행이 <strong>0개</strong>인 페이지만 삭제할 수 있습니다. 행이 남아 있으면 삭제 버튼이 비활성화됩니다.</td></tr>
    </table>

    <h6 class="mt-3 fw-bold">페이지 추가 · 수정 폼</h6>
    <table class="table table-bordered field-table">
        <tr><th>기본 정보</th><td><strong>페이지 코드</strong>(주소 <code>/p/</code> 뒤에 붙음, 영문 소문자·숫자·하이픈만, 필수, 입력칸을 벗어나면 중복 자동 확인), <strong>페이지 제목</strong>(필수), 페이지 설명(관리자 식별용)</td></tr>
        <tr><th>SEO 설정</th><td>SEO 타이틀(비우면 페이지 제목 사용), SEO 설명, SEO 키워드를 입력합니다.</td></tr>
        <tr><th>레이아웃 설정</th><td><strong>넓이 타입</strong>(최대넓이 / 와이드(전체) / 사용자 지정 px 300~2400), <strong>Header 사용</strong>·<strong>Footer 사용</strong>(미사용 시 사이트 머리말/꼬리말 없이 출력)</td></tr>
        <tr><th>레이아웃 형태</th><td>전체 · 좌측 사이드바 · 우측 사이드바 · 3단 중 선택. 사이드바가 있는 형태를 고르면 <strong>좌/우 사이드바 폭(px)</strong>과 <strong>모바일 출력</strong> 스위치가 나타납니다. 폭을 0으로 두면 사이트 기본값을 사용합니다. (레이아웃 형태는 모바일에는 적용되지 않습니다.)</td></tr>
        <tr><th>접근 권한 및 상태</th><td><strong>접근 가능 레벨</strong>(0=모두 접근, 그 외는 선택 레벨 이상만), <strong>사용 여부</strong>(사용/미사용). 수정 모드에서는 연결된 행 수와 "관리하기" 링크가 함께 표시됩니다.</td></tr>
    </table>

    <h6 class="mt-3 fw-bold">페이지 만들고 콘텐츠 채우기 순서</h6>
    <ol>
        <li><strong>페이지 추가</strong>에서 코드·제목·레이아웃·접근 레벨·사용 여부를 저장합니다.</li>
        <li>저장하면 수정 화면으로 이동합니다. 우측 상단 <strong>행 관리</strong> 버튼(또는 "연결된 행 &gt; 관리하기")을 엽니다.</li>
        <li>행 관리에서 행을 추가하고 칸에 콘텐츠를 넣습니다. (자세한 방법은 <a href="#sec-block-row">블록 행 관리</a> 참고)</li>
        <li>수정 화면 하단 <strong>페이지 URL</strong> 카드의 <code>/p/코드</code> 링크로 실제 화면을 새 창에서 확인합니다.</li>
    </ol>

    <div class="manual-warn">
        <strong>주의:</strong> 페이지만 생성하고 행/칸을 비워 두면 화면이 빈 상태로 보입니다. 또한 블록은 <strong>페이지·행·칸이 모두 사용(활성) 상태</strong>이고, 접근 레벨 조건을 만족해야 최종 화면에 출력됩니다.
    </div>
</section>

<section id="sec-block-row" class="manual-section">
    <h4><i class="bi bi-grid-3x2"></i> 블록 행 관리</h4>
    <p>블록 행 관리(<code>/admin/block-row</code>)는 화면에 실제로 보이는 콘텐츠를 <strong>행(가로 줄) → 칸(세로 분할)</strong> 단위로 쌓는 곳입니다. 하나의 행은 1~4개의 칸으로 나뉘고, 각 칸에 이미지·게시판·메뉴·동영상·직접 작성 콘텐츠 등을 넣습니다.</p>
    <p>행은 두 가지 방식으로 관리합니다. 목록을 그냥 열면 <strong>위치 기반</strong>(메인화면·서브페이지 상단 등 공통 영역에 붙는 행), 페이지 목록의 "행 관리"로 들어오면 <strong>페이지 기반</strong>(<code>?page_id=…</code>, 특정 <code>/p/</code> 페이지 전용 행)으로 열립니다.</p>

    <h6 class="mt-3 fw-bold">처음 10분 따라하기</h6>
    <ol>
        <li>행 추가 후 출력 위치를 <strong>메인화면</strong>으로 둡니다. (페이지 기반이면 위치 선택 없이 해당 페이지에 자동 소속)</li>
        <li>칸 수를 <strong>1칸</strong>으로 두고 저장합니다.</li>
        <li>칸의 <strong>[설정]</strong> 버튼을 눌러 콘텐츠 타입을 이미지 또는 HTML(직접 작성)로 1개만 지정합니다.</li>
        <li>사용 여부를 <strong>사용</strong>으로 켜고 저장합니다.</li>
        <li><strong>미리보기</strong> 버튼 또는 실제 화면 새로고침으로 노출을 확인합니다.</li>
    </ol>
    <div class="manual-tip">
        <strong>안내:</strong> 처음에는 3칸/4칸보다 1칸으로 시작해 정상 노출을 확인한 뒤 칸을 늘리는 것이 가장 안전합니다.
    </div>

    <h6 class="mt-3 fw-bold">행 목록 화면과 조작</h6>
    <table class="table table-bordered field-table">
        <tr><th>표시 항목</th><td>ID, 순서, 관리제목, 출력 대상(페이지 배지 또는 위치 배지+메뉴코드), 칸 상태, 넓이(와이드/최대넓이), 상태를 확인합니다.</td></tr>
        <tr><th>칸 상태 표시</th><td>칸을 작은 상자로 표시합니다. 채워진 칸(콘텐츠 있음)·빈 칸(콘텐츠 없음)·미생성 칸이 색으로 구분되고, 칸이 아예 없으면 경고 아이콘이 뜹니다.</td></tr>
        <tr><th>상태 토글</th><td>상태 스위치를 누르면 사용/미사용이 즉시 전환됩니다.</td></tr>
        <tr><th>행별 관리 버튼</th><td><strong>미리보기</strong>, <strong>수정</strong>, <strong>복사</strong>, <strong>이동</strong>, <strong>삭제</strong>를 제공합니다.</td></tr>
        <tr><th>위치 필터</th><td>위치 기반 목록에서는 상단 셀렉트로 특정 출력 위치만 골라 볼 수 있습니다.</td></tr>
    </table>

    <h6 class="mt-3 fw-bold">순서 변경 방법 (세 가지)</h6>
    <table class="table table-bordered field-table">
        <tr><th>드래그 정렬</th><td>줄 왼쪽의 이동 손잡이를 잡고 끌어 놓으면 즉시 순서가 저장됩니다.</td></tr>
        <tr><th>선택 순서변경</th><td>순서 숫자를 고친 뒤 원하는 행만 체크하고 <strong>선택 순서변경</strong>을 누릅니다.</td></tr>
        <tr><th>순서 일괄저장</th><td>여러 행의 순서 숫자를 고친 뒤 <strong>순서 일괄저장</strong>으로 한 번에 반영합니다.</td></tr>
    </table>
    <div class="manual-tip">
        <strong>안내:</strong> 드래그가 아닌 숫자 입력으로 순서를 바꿨다면 반드시 "선택 순서변경" 또는 "순서 일괄저장"까지 눌러야 반영됩니다. 숫자만 고치고 저장을 빠뜨리는 실수가 가장 흔합니다.
    </div>

    <h6 class="mt-3 fw-bold">복사 · 이동으로 재사용하기</h6>
    <table class="table table-bordered field-table">
        <tr><th>복사</th><td>잘 만든 행을 그대로 복제합니다. 대상 유형을 <strong>위치 기반</strong> 또는 <strong>페이지 기반</strong>으로 골라 다른 영역/페이지에 붙일 수 있습니다.</td></tr>
        <tr><th>이동</th><td>기존 행을 지우지 않고 다른 위치나 페이지로 옮깁니다.</td></tr>
        <tr><th>삭제 복구</th><td>행 삭제 또는 블록 킷 교체 전 내용은 <strong>블록 행 관리 → 삭제 이력</strong>에서 복구할 수 있습니다.</td></tr>
    </table>

    <h6 class="mt-3 fw-bold">행 설정 폼 — 레이아웃 · 배경</h6>
    <table class="table table-bordered field-table">
        <tr><th>기본 정보</th><td>관리용 제목(관리자 식별용), 섹션 ID(자동 생성되며 수정 가능), 사용 여부</td></tr>
        <tr><th>출력 위치</th><td>위치 기반일 때만 표시됩니다. 출력 위치(메인화면 등)와 <strong>특정 메뉴에서만 출력</strong>(메뉴 제한, 미선택 시 전체)을 정합니다. 메인화면 위치에서는 메뉴 제한이 숨겨집니다.</td></tr>
        <tr><th>넓이 타입</th><td>와이드(전체) 또는 최대넓이. <strong>메인화면·서브페이지 상단/하단</strong>에서만 와이드가 가능하고, 그 밖의 위치(콘텐츠 상단/하단·사이드바 등)는 컨테이너 내부라 최대넓이로 고정됩니다.</td></tr>
        <tr><th>칸 수 · 칸 간격</th><td>칸 수는 1~4칸, 칸 간격(px)은 칸 사이 여백입니다.</td></tr>
        <tr><th>여백</th><td>PC/모바일 <strong>외부 여백(margin)</strong>과 <strong>내부 여백(padding)</strong>을 "위 오른쪽 아래 왼쪽" 순서로 입력합니다.</td></tr>
        <tr><th>배경</th><td>배경 색상과 배경 이미지를 지정합니다. 이미지를 넣으면 크기(cover/contain/auto)·위치·반복·스크롤(고정=패럴랙스) 옵션이 열립니다.</td></tr>
    </table>

    <h6 class="mt-3 fw-bold">칸 설정 (칸 [설정] 버튼 → 팝업, 3개 탭)</h6>
    <table class="table table-bordered field-table">
        <tr><th>스타일 탭</th><td>칸 너비(%/px, 비우면 균등 분배), PC/모바일 여백, 칸 배경(색상·이미지·옵션), 테두리(두께·색상·라운드)</td></tr>
        <tr><th>제목 탭</th><td>제목 표시를 켜면 제목 텍스트·위치·PC/MO 글자 크기·색상, 제목 이미지(PC/MO), 보조 문구, 더보기 링크를 설정합니다.</td></tr>
        <tr><th>콘텐츠 탭</th><td>콘텐츠 타입과 스킨, PC/MO 출력 개수, 출력 이벤트(AOS 애니메이션·시간)를 정합니다. 타입에 따라 아래의 전용 설정이 나타납니다.</td></tr>
    </table>

    <h6 class="mt-3 fw-bold">칸 콘텐츠 타입별 설정</h6>
    <table class="table table-bordered field-table">
        <tr><th>게시판 · 게시판그룹</th><td>PC/모바일 출력 스타일(리스트형·슬라이드형·숨김), 1줄 출력 개수, 슬라이드 옵션(자동재생·무한반복·이미지 높이 맞춤)을 정하고, 듀얼 리스트박스로 노출할 게시판을 선택합니다.</td></tr>
        <tr><th>메뉴</th><td>노출할 메뉴 항목을 선택합니다.</td></tr>
        <tr><th>이미지</th><td>이미지를 여러 개 추가하고 각 이미지의 PC/모바일 파일을 업로드합니다.</td></tr>
        <tr><th>동영상</th><td>YouTube · Vimeo · 직접 URL 중 선택, 자동 재생/음소거 옵션을 설정합니다.</td></tr>
        <tr><th>HTML(직접 작성)</th><td>비주얼 에디터로 본문을 작성하고, 접이식 영역에서 CSS·JavaScript를 직접 넣을 수 있습니다.</td></tr>
        <tr><th>로그인(outlogin)</th><td>PC/모바일 출력 대상을 선택합니다.</td></tr>
        <tr><th>Include(파일 포함)</th><td><code>views/Block/include/</code> 안의 PHP 파일을 골라 포함합니다.</td></tr>
    </table>

    <div class="manual-warn">
        <strong>주의:</strong> 보안상 <strong>Include 블록은 최고관리자만</strong>, HTML 콘텐츠의 <strong>직접 입력 JavaScript는 도메인 운영자 이상만</strong> 저장할 수 있습니다. 권한이 없으면 저장이 거부됩니다. (정화되는 HTML/CSS는 모든 관리자가 사용 가능)
    </div>

    <h6 class="mt-3 fw-bold">미리보기</h6>
    <table class="table table-bordered field-table">
        <tr><th>편집 중 미리보기</th><td>행 설정 폼 하단의 <strong>미리보기</strong> 버튼으로, 저장 전에 현재 설정을 팝업 화면에서 확인합니다.</td></tr>
        <tr><th>목록 미리보기</th><td>목록의 미리보기 버튼으로 이미 저장된 행의 실제 렌더링 결과를 확인합니다.</td></tr>
    </table>

    <h6 class="mt-3 fw-bold">문제 해결 체크리스트</h6>
    <table class="table table-bordered field-table">
        <tr><th>화면에 안 보일 때</th><td>페이지/행/칸의 사용 여부, 출력 위치, 메뉴 제한, 순서 저장 여부를 먼저 확인합니다.</td></tr>
        <tr><th>칸에 경고 아이콘이 뜰 때</th><td>칸 수만 정하고 칸 [설정]을 저장하지 않으면 "칸 미생성" 상태입니다. 각 칸을 열어 콘텐츠를 지정하세요.</td></tr>
        <tr><th>폭이 원하는 대로 안 될 때</th><td>일부 출력 위치는 와이드가 제한됩니다. 해당 위치에서는 최대넓이로 운영합니다.</td></tr>
        <tr><th>모바일이 어색할 때</th><td>모바일 여백, 모바일 출력 개수, 슬라이드/리스트 스타일을 PC와 별도로 조정합니다.</td></tr>
        <tr><th>되돌리고 싶을 때</th><td>교체 전 행은 <strong>블록 행 관리 → 삭제 이력</strong>에서 다시 추가할 수 있습니다. 레이아웃 설정 복구는 블록 킷 적용 이력의 별도 기능입니다.</td></tr>
    </table>

    <h6 class="mt-3 fw-bold">저장 전 1분 점검표</h6>
    <table class="table table-bordered field-table">
        <tr><th>확인 1</th><td>행/칸 사용 여부가 모두 사용으로 되어 있는가</td></tr>
        <tr><th>확인 2</th><td>출력 위치 또는 메뉴 제한이 의도한 범위와 일치하는가</td></tr>
        <tr><th>확인 3</th><td>칸 [설정]까지 저장했고, 순서 변경 후 저장 버튼을 눌렀는가</td></tr>
        <tr><th>확인 4</th><td>모바일 화면에서 여백/출력 개수/표시 방식이 깨지지 않는가</td></tr>
    </table>
</section>
        </div>
    </div>
</div>

<script src="/assets/js/manual.js"></script>
