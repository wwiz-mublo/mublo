<?php
declare(strict_types=1);

/**
 * Mublo Shop 패키지 운영 및 스킨 제작 번들 매뉴얼.
 *
 * packages/Shop의 관리자 화면, Service 검증 규칙, Front Controller payload,
 * ProductPresenter와 기본 스킨의 실제 계약을 기준으로 작성했다.
 */
return [
    'version' => 4,
    'book' => [
        'title' => 'Mublo Shop 매뉴얼',
        'slug' => 'shop-manual',
        'description' => 'Mublo Shop 개설·상품·결제·배송·주문 운영과 기능별 프론트 스킨 제작을 위한 통합 매뉴얼',
        'sort_order' => 20,
        'is_active' => 1,
    ],
    'pages' => [
        [
            'key' => 'start',
            'title' => 'Mublo Shop 시작하기',
            'slug' => 'start',
            'sort_order' => 10,
            'content' => <<<'HTML'
<!-- mublo-bundle:shop:v3 -->
<p>Mublo Shop은 일반 상품을 판매하는 쇼핑몰 패키지입니다.</p>
<p>상품·카테고리·옵션부터 장바구니와 바로구매, 결제, 배송, 쿠폰, 등급 혜택을 제공합니다.<br>구매후기·상품문의, 기획전과 주문 상태 자동화도 한 도메인 안에서 운영할 수 있습니다.</p>
<h3>최초 개설 권장 순서</h3>
<ol>
<li>Shop 패키지를 활성화하고 마이그레이션을 완료합니다.</li>
<li><strong>배송 템플릿</strong>을 최소 1개 만든 뒤 <strong>쇼핑몰 설정</strong>에서 기본 배송 템플릿으로 지정합니다.</li>
<li>결제수단·입금계좌·포인트, 할인·적립, 약관과 주문 추가 필드를 설정합니다.</li>
<li>주문상태 설정은 우선 기본 흐름을 유지하고 알림·재고·포인트 액션만 검토합니다.</li>
<li>카테고리, 상품옵션 프리셋, 상품정보 템플릿을 만든 뒤 상품을 등록합니다.</li>
<li>테스트 상품으로 회원·비회원 주문, 결제, 송장, 취소·반품·환불을 끝까지 검증합니다.</li>
</ol>
<table><thead><tr><th>프론트 화면</th><th>주소</th></tr></thead><tbody>
<tr><td>쇼핑몰/상품 목록</td><td><code>/shop</code>, <code>/shop/products</code></td></tr>
<tr><td>상품 상세</td><td><code>/shop/products/{id}/{slug?}</code></td></tr>
<tr><td>장바구니/주문서</td><td><code>/shop/cart</code>, <code>/shop/checkout</code></td></tr>
<tr><td>주문내역/비회원 조회</td><td><code>/shop/orders</code>, <code>/shop/order/lookup</code></td></tr>
<tr><td>마이페이지 허브</td><td><code>/mypage/shop</code></td></tr>
</tbody></table>
HTML,
        ],
        [
            'key' => 'launch-checklist',
            'parent' => 'start',
            'title' => '오픈 전 필수 점검',
            'slug' => 'launch-checklist',
            'sort_order' => 20,
            'content' => <<<'HTML'
<h3>판매를 시작하기 전에</h3>
<ul>
<li><strong>배송:</strong> 활성 배송 템플릿과 기본 템플릿이 존재하는지, 무료 기준·도서산간 추가비·반품/교환비·출고지/반품지가 맞는지 확인합니다. 배송 정책을 해석하지 못하면 결제가 차단될 수 있습니다.</li>
<li><strong>결제:</strong> 운영 PG와 결제수단, 무통장 계좌, 포인트 사용 단위와 한도를 확인합니다. 실결제와 취소·부분환불은 PG 테스트 환경에서 먼저 검증합니다.</li>
<li><strong>가격:</strong> 전역 할인·적립, 등급별 혜택, 상품별 오버라이드, 쿠폰이 겹친 최종 금액을 확인합니다.</li>
<li><strong>재고:</strong> 재고 미관리(null)와 0(품절)을 구분하고, 옵션 상품은 옵션 값 또는 조합 재고를 확인합니다.</li>
<li><strong>주문:</strong> 기본 상태 전이, 송장 입력 가능 상태, 자동 구매확정, 재고 차감/복구와 포인트 지급/환수 시점을 확인합니다.</li>
<li><strong>고객 정보:</strong> 필수 약관, 주문 추가 필드, 암호화 여부, 비회원 주문 조회와 개인정보 접근권한을 확인합니다.</li>
</ul>
<h3>권장 테스트 주문 4종</h3>
<ol>
<li>회원 + 일반 상품 + PG 결제</li>
<li>비회원 + 무통장 입금</li>
<li>옵션/추가금액 + 쿠폰 + 포인트 복합 결제</li>
<li>서로 다른 배송 템플릿 또는 개별배송 상품이 함께 든 주문</li>
</ol>
<p>각 주문을 주문접수부터 구매확정까지 이동하고, 별도 주문으로 취소·반품·환불을 수행해 금액, 재고, 쿠폰, 포인트, 알림 기록을 대조하십시오.</p>
HTML,
        ],
        [
            'key' => 'admin',
            'title' => '관리자 운영 설정',
            'slug' => 'admin-settings',
            'sort_order' => 30,
            'content' => <<<'HTML'
<p><strong>Mublo Shop</strong> 관리자 메뉴는 다음과 같은 업무 영역으로 구성됩니다.</p>
<ul>
<li><strong>기본 운영:</strong> 대시보드, 쇼핑몰 설정</li>
<li><strong>상품:</strong> 카테고리, 상품옵션 프리셋, 상품정보 템플릿, 상품 관리</li>
<li><strong>주문:</strong> 주문 관리, 주문상태 설정, 배송 템플릿</li>
<li><strong>고객·마케팅:</strong> 등급별 혜택, 쿠폰, 구매후기, 상품문의, 찜한상품, 기획전</li>
</ul>
<h3>운영자가 기억할 원칙</h3>
<ul>
<li>설정 변경 전 테스트 주문 번호와 현재 설정을 기록하고, 변경 후 새 주문으로 검증합니다. 이미 생성된 주문의 스냅샷과 새 계산 결과가 다를 수 있습니다.</li>
<li>가격·배송·쿠폰 값은 화면 표시만 믿지 않고 장바구니 재계산과 주문서 서버 재계산 결과를 확인합니다.</li>
<li>삭제보다 비활성화를 우선합니다. 특히 발급 이력이 있는 쿠폰은 감사 추적을 보호하기 위해 삭제할 수 없습니다.</li>
<li>주문 상태와 자동 액션은 서로 연결됩니다. 상태만 바꾸지 말고 진입 시 실행되는 재고·포인트·알림 액션도 함께 점검합니다.</li>
</ul>
<p>관리자 주소는 <code>/admin/shop/...</code>이며 모든 데이터는 현재 도메인 범위로 관리됩니다.</p>
HTML,
        ],
        [
            'key' => 'dashboard',
            'parent' => 'admin',
            'title' => '대시보드와 일일 점검',
            'slug' => 'dashboard-daily-check',
            'sort_order' => 40,
            'content' => <<<'HTML'
<p><strong>대시보드</strong>(<code>/admin/shop/dashboard</code>)는 주문·매출 요약과 최근 주문을 빠르게 확인하는 시작 화면입니다.</p>
<h3>오전 점검</h3>
<ol>
<li>전일 주문 수와 결제금액이 PG/무통장 입금 내역과 맞는지 확인합니다.</li>
<li>주문접수·결제완료에 오래 머문 주문을 찾아 결제 검증 또는 입금 확인 상태를 점검합니다.</li>
<li>배송준비 주문의 재고와 출고 가능 여부를 확인하고 송장을 등록합니다.</li>
<li>취소요청·반품요청, 미답변 문의, 숨김/신고 대상 후기를 처리합니다.</li>
</ol>
<h3>마감 점검</h3>
<ul>
<li>당일 출고 주문이 배송중으로 전환되었는지, 송장번호와 택배사가 정확한지 확인합니다.</li>
<li>환불 완료 금액과 PG 환불 내역, 포인트 환수·재고 복구 여부를 대조합니다.</li>
<li>비정상 할인, 배송비 0원, 재고 음수 등 이상 주문은 주문 상세의 결제·상태·메모 기록을 남깁니다.</li>
</ul>
HTML,
        ],
        [
            'key' => 'config-basic',
            'parent' => 'admin',
            'title' => '쇼핑몰 설정: 기본·스킨',
            'slug' => 'config-basic-skins',
            'sort_order' => 50,
            'content' => <<<'HTML'
<p><strong>쇼핑몰 설정</strong>(<code>/admin/shop/config</code>)의 기본 영역은 판매 가능 여부에 직접 영향을 줍니다.</p>
<table><thead><tr><th>항목</th><th>운영 의미</th><th>주의</th></tr></thead><tbody>
<tr><td>쇼핑몰 제목</td><td>쇼핑몰 표시명</td><td>도메인·브랜드 표기와 일치시킵니다.</td></tr>
<tr><td>기본 배송 템플릿</td><td>상품에 별도 템플릿이 없을 때 적용</td><td><strong>필수</strong>. 활성 템플릿을 먼저 만들고 선택합니다.</td></tr>
<tr><td>자동 구매확정</td><td>발송 후 N일이 지나면 구매확정</td><td>기본 8일, 0은 미사용. 반품 가능 기간과 맞춥니다.</td></tr>
<tr><td>회원 장바구니 보관</td><td>회원 장바구니 유지 기간</td><td>기본 15일, 최소 1일</td></tr>
<tr><td>비회원 장바구니 보관</td><td>세션 기반 비회원 장바구니 유지</td><td>기본 7일, 최소 1일</td></tr>
</tbody></table>
<h3>기능별 프론트 스킨</h3>
<p>상품, 장바구니, 주문, 찜한상품, 구매후기, 상품문의, 쿠폰, 기획전, 마이페이지, 공용 UI의 스킨을 각각 선택합니다.</p>
<p>선택한 스킨에 요청된 PHP 파일이 없으면 해당 파일만 <code>basic</code>으로 자동 대체됩니다.<br>따라서 상품 목록만 먼저 커스텀하고 상세 화면은 기본 스킨으로 유지하는 단계적 배포도 가능합니다.</p>
<blockquote><p>스킨 변경 후 상품 목록만 보지 말고 빈 목록, 품절, 비회원, 주문 실패, 404 같은 예외 화면까지 확인하십시오.</p></blockquote>
HTML,
        ],
        [
            'key' => 'config-price',
            'parent' => 'admin',
            'title' => '쇼핑몰 설정: 할인·적립·등급',
            'slug' => 'config-price-reward',
            'sort_order' => 60,
            'content' => <<<'HTML'
<p>가격 설정은 쇼핑몰 전체의 기본값입니다.</p>
<p>상품에서 할인·적립 유형을 <strong>기본값 사용</strong>으로 선택하면 이 설정이 적용됩니다.<br>상품별 정률·정액 또는 등급 설정을 선택한 경우에는 상품 설정이 우선합니다.</p>
<h3>할인</h3>
<ul>
<li><strong>사용 안 함:</strong> 전역 할인 없음</li>
<li><strong>정률:</strong> 판매 표시가의 백분율 할인</li>
<li><strong>정액:</strong> 고정 금액 할인. 최종가가 음수가 되지 않는지 테스트합니다.</li>
<li><strong>레벨별:</strong> 회원 레벨마다 정률 또는 정액 값을 지정합니다.</li>
</ul>
<h3>적립</h3>
<p>구매 적립은 할인 적용 후 판매가를 기준으로 정률 또는 정액 계산할 수 있으며, 회원 레벨별 값도 지정할 수 있습니다.</p>
<p>구매후기 적립금은 전 등급 공통값입니다.<br>별도의 <strong>등급별 혜택</strong> 화면에도 할인율·적립률이 있으므로, 전체 정책을 먼저 설계한 뒤 혜택이 의도치 않게 중복되지 않는지 확인하십시오.</p>
<h3>검증 예시</h3>
<p>예: 정가 50,000원 + 전역 할인 10% + 적립 2% + 5,000원 쿠폰</p>
<ol>
<li>상품 상세 표시가를 확인합니다.</li>
<li>장바구니와 주문서의 상품금액·할인·쿠폰 금액을 비교합니다.</li>
<li>주문 상세의 결제금액과 적립 예정액을 대조합니다.</li>
<li>옵션 추가금액과 배송비가 할인 기준에 포함되는지도 실제 주문으로 확인합니다.</li>
</ol>
HTML,
        ],
        [
            'key' => 'config-payment',
            'parent' => 'admin',
            'title' => '쇼핑몰 설정: 결제·무통장·포인트',
            'slug' => 'config-payment-point',
            'sort_order' => 70,
            'content' => <<<'HTML'
<h3>PG 결제</h3>
<p>설치된 결제 플러그인이 있을 때 사용할 PG를 선택할 수 있습니다. 실제 카드·휴대폰·가상계좌 등 세부 결제수단은 선택 PG가 제공하고 Shop은 서버 검증 후 주문을 확정합니다.</p>
<ul>
<li>운영 키를 적용하기 전에 테스트 키로 승인, 실패, 중복 콜백, 취소와 부분환불을 확인합니다.</li>
<li>브라우저의 결제 성공 화면만으로 입금 완료 처리하지 않습니다. 서버의 결제 검증 결과와 금액·주문번호가 일치해야 합니다.</li>
<li>PG 플러그인을 제거하기 전에 해당 PG 주문의 환불·영수증 조회 운영 절차를 마련합니다.</li>
</ul>
<h3>무통장 입금</h3>
<p><strong>무통장 입금 사용</strong>을 켜고 은행명·계좌번호·예금주를 등록합니다.<br>계좌가 여러 개면 주문서에서 고객이 선택합니다.</p>
<p>입금 계좌를 변경할 때는 이미 주문한 고객에게 안내된 기존 계좌도 일정 기간 유지하십시오.</p>
<h3>포인트 결제</h3>
<table><thead><tr><th>항목</th><th>의미</th></tr></thead><tbody>
<tr><td>사용 단위</td><td>100이면 100P 단위로만 입력</td></tr>
<tr><td>최소 사용</td><td>한 주문에서 사용 가능한 최소 포인트</td></tr>
<tr><td>최대 사용</td><td>한 주문에서 사용할 수 있는 상한</td></tr>
<tr><td>레벨별 최소/최대</td><td>회원 레벨별로 전역 한도를 대체</td></tr>
</tbody></table>
<p>기본값은 사용 단위 100, 최소 100, 최대 30,000입니다. 잔액보다 많이 쓰거나 결제금액을 초과하지 않는지, 취소·환불 시 사용 포인트가 복원되는지 확인합니다.</p>
HTML,
        ],
        [
            'key' => 'config-content',
            'parent' => 'admin',
            'title' => '쇼핑몰 설정: 상세탭·CS·약관·주문필드',
            'slug' => 'config-content-policy-fields',
            'sort_order' => 80,
            'content' => <<<'HTML'
<h3>상품 상세 탭</h3>
<p>다음 상품 상세 탭의 사용 여부와 순서를 정합니다.</p>
<ul>
<li><code>detail</code>: 상품에 직접 입력한 상세 내용</li>
<li><code>template</code>: 상품정보 템플릿</li>
<li>구매후기</li>
<li>상품문의</li>
</ul>
<p>후기·문의 탭을 꺼도 데이터가 삭제되지는 않습니다.<br>이 설정은 프론트의 노출 구성만 변경합니다.</p>
<h3>SEO와 고객센터</h3>
<ul>
<li>SEO 키워드는 쉼표로 구분하고, SEO 설명은 검색 결과에서 이해하기 쉬운 문장으로 작성합니다.</li>
<li>고객센터 전화, 카카오 상담 URL, 운영시간은 실제 응대 채널과 맞추고 휴무·점심시간을 명시합니다.</li>
</ul>
<h3>주문서 약관</h3>
<p>코어에 등록된 활성 약관 중 주문서에 표시할 항목을 선택합니다. 필수/선택 동의 성격은 약관 정책과 함께 검토하고, 약관 개정 시 주문 테스트로 체크박스와 본문 링크를 확인합니다.</p>
<h3>주문 추가 필드</h3>
<p>필드명은 영문 소문자로 시작해야 하며 소문자·숫자·밑줄만 사용할 수 있습니다.<br>생성 후 식별자로 사용되므로 변경하지 않습니다.</p>
<p>라벨, 타입, 선택지, 안내 문구와 함께 필수·활성·암호화·관리자 전용 여부 및 노출 순서를 설정합니다.</p>
<ul>
<li>select/radio/checkbox는 선택지를 등록합니다.</li>
<li>file은 최대 MB와 허용 확장자를 제한하며 보안 파일 처리기가 없는 설치에서는 저장되지 않을 수 있습니다.</li>
<li>주민번호, 계좌 등 민감정보는 꼭 필요한 경우에만 받고 암호화 저장을 켭니다.</li>
<li>관리자 전용 필드는 고객 주문서 입력용이 아닙니다. 필수와 관리자 전용을 동시에 쓸 때 실제 체크아웃 검증을 확인합니다.</li>
</ul>
HTML,
        ],
        [
            'key' => 'catalog',
            'title' => '상품·판매 정책',
            'slug' => 'catalog-operation',
            'sort_order' => 90,
            'content' => <<<'HTML'
<p>카탈로그는 다음 순서로 준비하면 반복 입력과 분류 오류를 줄일 수 있습니다.</p>
<p><strong>카테고리 → 상품옵션 프리셋 → 상품정보 템플릿 → 상품</strong></p>
<p>공개 전에는 비활성 상품으로 등록하십시오.<br>상세·가격·옵션·배송을 검수한 뒤 판매중으로 전환합니다.</p>
HTML,
        ],
        [
            'key' => 'categories',
            'parent' => 'catalog',
            'title' => '카테고리 관리',
            'slug' => 'categories',
            'sort_order' => 100,
            'content' => <<<'HTML'
<p><strong>카테고리 관리</strong>(<code>/admin/shop/categories</code>)에서 트리 구조를 만들고 드래그로 순서를 정합니다.</p>
<table><thead><tr><th>항목</th><th>설명</th></tr></thead><tbody>
<tr><td>카테고리명/코드</td><td>표시명과 URL·연결에 쓰이는 식별값</td></tr>
<tr><td>설명</td><td>분류 안내 또는 스킨 활용용 설명</td></tr>
<tr><td>접근 허용 레벨</td><td>특정 회원 레벨 이상만 접근</td></tr>
<tr><td>쿠폰 사용 허용</td><td>해당 분류 상품의 쿠폰 적용 정책</td></tr>
<tr><td>성인인증 필요</td><td>성인 상품 분류 접근 제어</td></tr>
<tr><td>활성화</td><td>프론트 노출 여부</td></tr>
</tbody></table>
<p><strong>메뉴 등록</strong>으로 카테고리 URL을 사이트 메뉴에 연결할 수 있습니다.</p>
<p>트리의 부모를 옮기거나 코드를 변경한 경우 다음 항목을 함께 확인하십시오.</p>
<ul>
<li>기존 카테고리 링크</li>
<li>연결된 상품 분류</li>
<li>사이트 메뉴 연결</li>
<li>권한별 접근 제한과 성인인증</li>
</ul>
HTML,
        ],
        [
            'key' => 'options',
            'parent' => 'catalog',
            'title' => '상품옵션 프리셋',
            'slug' => 'option-presets',
            'sort_order' => 110,
            'content' => <<<'HTML'
<p><strong>상품옵션 프리셋</strong>(<code>/admin/shop/options</code>)은 반복되는 색상·사이즈·각인·포장 구성을 재사용합니다. 상품에 적용하면 옵션 모드도 함께 설정됩니다.</p>
<ul>
<li><strong>단독형(SINGLE):</strong> 각 기본 옵션 값을 독립적으로 선택·재고 관리하는 상품에 적합합니다.</li>
<li><strong>조합형(COMBINATION):</strong> 색상×사이즈처럼 선택값 조합마다 추가금액·재고·사용 여부가 다른 상품에 적합합니다.</li>
<li><strong>기본 옵션:</strong> 판매 변형을 구성합니다. 옵션명, 필수 여부, 정렬, 값, 추가금액을 입력합니다.</li>
<li><strong>추가 옵션(EXTRA):</strong> 각인·포장처럼 본 조합 외 부가 선택에 사용합니다.</li>
</ul>
<p>조합형은 옵션명을 바꾸거나 값을 삭제하면 조합키가 다시 생성됩니다.<br>변경 후 기존 SKU와 재고가 올바르게 연결되었는지 확인하십시오.</p>
<p><strong>미관리 재고는 빈값, 품절은 0</strong>입니다.<br>프리셋을 적용한 후에도 상품 편집 화면의 실제 옵션과 조합을 반드시 검토합니다.</p>
HTML,
        ],
        [
            'key' => 'info-templates',
            'parent' => 'catalog',
            'title' => '상품정보 템플릿',
            'slug' => 'product-info-templates',
            'sort_order' => 120,
            'content' => <<<'HTML'
<p><strong>상품정보 템플릿</strong>(<code>/admin/shop/info-templates</code>)은 규격, 소재, 취급주의 등 공통 고시 내용을 상품 상세 탭에 재사용합니다.</p>
<ul>
<li><strong>제목:</strong> 관리자 식별용 이름</li>
<li><strong>탭명:</strong> 프론트 상품 상세에 표시되는 이름</li>
<li><strong>본문:</strong> 공통 안내 HTML</li>
<li><strong>적용 카테고리:</strong> 선택 카테고리와 하위 카테고리 상품에 적용</li>
<li><strong>상태/정렬:</strong> 활성 템플릿만 노출되고 작은 숫자가 먼저 표시</li>
</ul>
<p>카테고리 범위를 넓게 지정하면 의도하지 않은 하위 상품에도 템플릿이 표시될 수 있습니다.</p>
<p>법정 고시 내용은 상품별 실제 정보와 일치해야 합니다.<br>공통 본문으로 표현할 수 없는 값은 상품의 직접 상세 내용으로 분리하십시오.</p>
HTML,
        ],
        [
            'key' => 'products',
            'parent' => 'catalog',
            'title' => '상품 등록과 재고',
            'slug' => 'products-stock',
            'sort_order' => 130,
            'content' => <<<'HTML'
<p><strong>상품 관리</strong>(<code>/admin/shop/products</code>)에서 기본정보, 가격, 분류, 옵션·재고, 배송, 이미지와 상세를 한 상품 단위로 관리합니다.</p>
<h3>기본·분류</h3>
<ul>
<li>상품명과 상품코드는 필수이며 코드는 비워두면 자동 생성됩니다. 슬러그는 SEO URL에 사용됩니다.</li>
<li>대표 카테고리는 경로를 순서대로 선택하고, 보조 카테고리로 추가 노출할 수 있습니다.</li>
<li>배지는 NEW, SALE, BEST 같은 표시값이며 판매중을 꺼두면 프론트 판매 대상에서 제외됩니다.</li>
</ul>
<h3>가격·혜택</h3>
<p>판매가, 원가, 할인·적립 유형과 값, 후기 적립, 쿠폰 허용을 설정합니다. 쇼핑몰 기본값을 상속할지 상품별 값으로 대체할지 구분하고 등급별 계정으로 최종가를 검증합니다.</p>
<h3>옵션·재고</h3>
<ul>
<li>옵션 없음은 상품 재고, 단독형은 활성 옵션 값 재고, 조합형은 활성 조합 재고가 품절 판단 기준입니다.</li>
<li>재고 빈값은 미관리, 0은 품절입니다. 단독/조합 중 하나라도 미관리 값이면 합산할 수 없어 상품 전체를 미관리로 볼 수 있습니다.</li>
<li>옵션 추가금액·재고·사용 여부를 모바일 화면에서도 확인합니다.</li>
</ul>
<h3>배송·검색·상세</h3>
<p>상품별 배송 템플릿이 없으면 쇼핑몰 기본 템플릿을 사용합니다.</p>
<ul>
<li><strong>묶음 배송:</strong> 같은 템플릿을 사용하는 상품을 한 그룹으로 계산</li>
<li><strong>개별 배송:</strong> 상품별 배송비를 각각 계산</li>
</ul>
<p>원산지, 제조사, 내부 관리코드, 필터와 검색 태그도 입력할 수 있습니다.<br>검색 태그는 쉼표로 구분합니다.</p>
<p>여러 이미지 중 대표 이미지를 지정한 뒤 상세 콘텐츠와 정보 템플릿의 실제 노출을 확인하십시오.</p>
HTML,
        ],
        [
            'key' => 'shipping',
            'title' => '배송·주문 운영',
            'slug' => 'shipping-orders',
            'sort_order' => 140,
            'content' => <<<'HTML'
<p>장바구니, 주문서, 실제 주문 청구의 배송비는 모두 같은 계산기를 사용합니다.</p>
<ol>
<li>상품에 지정된 배송 템플릿을 먼저 사용합니다.</li>
<li>상품 템플릿이 없으면 쇼핑몰 기본 배송 템플릿을 사용합니다.</li>
<li>두 설정을 모두 해석할 수 없으면 결제를 진행할 수 없습니다.</li>
</ol>
HTML,
        ],
        [
            'key' => 'shipping-templates',
            'parent' => 'shipping',
            'title' => '배송 템플릿 상세 설정',
            'slug' => 'shipping-templates',
            'sort_order' => 150,
            'content' => <<<'HTML'
<p><strong>배송 템플릿</strong>(<code>/admin/shop/shipping</code>)을 만든 뒤 쇼핑몰 기본 또는 상품별 템플릿으로 지정합니다.</p>
<table><thead><tr><th>방식</th><th>계산</th></tr></thead><tbody>
<tr><td>무료</td><td>기본 배송비 0원</td></tr>
<tr><td>조건부</td><td>주문금액이 무료 기준 이상이면 무료, 미만이면 기본 배송비</td></tr>
<tr><td>유료/정액</td><td>기본 배송비 부과</td></tr>
<tr><td>수량</td><td>지정 수량 단위마다 기본 배송비 반복</td></tr>
<tr><td>금액 구간</td><td>최소·최대 주문금액별 배송비</td></tr>
</tbody></table>
<p>우편번호 구간별 지역명·시작·끝·추가비를 등록하면 도서산간 추가비가 기본 배송비와 별도로 더해집니다. 무료배송 상품에도 지역 추가비는 부과될 수 있습니다.</p>
<ul>
<li>반품 배송비와 교환 배송비는 고객 안내와 실제 회수 비용에 사용합니다.</li>
<li>배송 수단, 택배사, 배송 안내, 출고지와 반품지 주소를 정확히 입력합니다.</li>
<li>비활성화 또는 삭제 전 해당 템플릿을 쓰는 상품과 기본 설정을 다른 템플릿으로 변경합니다.</li>
</ul>
<p>검증할 때는 무료 기준 바로 아래/같은 금액, 수량 단위 경계, 복수 템플릿, 개별배송, 도서산간 우편번호를 각각 테스트합니다.</p>
HTML,
        ],
        [
            'key' => 'order-states',
            'parent' => 'shipping',
            'title' => '주문상태와 자동 액션',
            'slug' => 'order-states-actions',
            'sort_order' => 160,
            'content' => <<<'HTML'
<p><strong>주문상태 설정</strong>(<code>/admin/shop/order-states</code>)은 주문 처리 흐름을 상태 그래프로 관리합니다.</p>
<p><strong>기본 흐름</strong><br>주문접수 → 결제완료 → 배송준비 → 배송중 → 배송완료 → 구매확정</p>
<p>별도로 취소요청 → 주문취소, 반품요청 → 반품완료 흐름이 있습니다.</p>
<ul>
<li><strong>이동 가능(to):</strong> 현재 상태에서 선택할 수 있는 다음 상태입니다.</li>
<li><strong>배송:</strong> 체크된 상태에서만 택배사·송장정보를 편집할 수 있습니다. 기본값은 배송준비와 배송중입니다.</li>
<li><strong>종료:</strong> 다음 이동이 없는 최종 상태입니다. 종료 상태에는 이동 대상을 둘 수 없습니다.</li>
<li><strong>커스텀 상태:</strong> 검수중 같은 단계를 추가할 수 있으나 시작 상태 <code>received</code>와 시스템 상태는 유지해야 합니다.</li>
</ul>
<h3>상태 진입 액션</h3>
<table><thead><tr><th>액션</th><th>용도</th></tr></thead><tbody>
<tr><td>알림 발송</td><td>채널·템플릿·수신자별 안내, 다중 등록 가능</td></tr>
<tr><td>재고 차감/복구</td><td>결제완료 등 차감 시점과 취소·반품 복구 시점</td></tr>
<tr><td>포인트 적립/환수</td><td>정액·비율 지급 또는 전액/정액/비율 회수</td></tr>
<tr><td>주문 확정</td><td>아이템 전체를 완료 처리</td></tr>
<tr><td>웹훅</td><td>외부 URL로 주문 상태 변경 정보 전송</td></tr>
</tbody></table>
<blockquote><p>같은 상태 재진입, 관리자 수동 변경, 자동 구매확정에서 액션이 중복 실행되지 않는지 테스트하십시오. 기본값으로 운영을 시작한 뒤 필요한 최소 변경만 적용하는 것이 안전합니다.</p></blockquote>
HTML,
        ],
        [
            'key' => 'order-operation',
            'parent' => 'shipping',
            'title' => '주문 접수·출고·송장',
            'slug' => 'order-operation',
            'sort_order' => 170,
            'content' => <<<'HTML'
<p><strong>주문 관리</strong>(<code>/admin/shop/orders</code>)에서 주문번호, 주문인, 수령인, 상품, 주문·배송·할인·결제금액, 결제수단, 주문/변경일시를 확인합니다.</p>
<h3>표준 처리 절차</h3>
<ol>
<li>PG 승인 또는 무통장 입금을 대조하고 결제완료로 처리합니다.</li>
<li>상품별 옵션·수량·재고, 주문 추가 필드, 배송지와 고객 요청을 확인합니다.</li>
<li>배송준비로 이동한 뒤 택배사·송장번호를 등록합니다. 필요하면 주문 아이템별 상태도 관리합니다.</li>
<li>배송중으로 전환하고 고객 알림이 발송되었는지 확인합니다.</li>
<li>배송완료 후 고객 구매확정 또는 자동 구매확정을 확인합니다.</li>
</ol>
<p>목록에서 여러 주문을 선택해 송장을 일괄 업로드할 수 있습니다.<br>업로드 전에 주문번호·택배사·송장번호 열을 검증하고, 처리 후 일부 실패 결과가 없는지 반드시 확인합니다.</p>
<p>주문 상세 메모는 고객 안내가 아니라 내부 운영 이력으로 사용합니다.<br>담당자, 처리 일시와 처리 근거를 함께 남기십시오.</p>
<p>주문 전체 상태와 개별 아이템 상태가 다를 수 있습니다. 부분출고·부분취소가 있는 주문은 합계만 보지 말고 각 아이템, 배송, 취소/반품, 결제 기록을 함께 확인하십시오.</p>
HTML,
        ],
        [
            'key' => 'returns',
            'parent' => 'shipping',
            'title' => '취소·반품·교환·환불',
            'slug' => 'cancel-return-refund',
            'sort_order' => 180,
            'content' => <<<'HTML'
<h3>취소</h3>
<p>고객 취소 요청 또는 관리자 취소 시 사유를 기록합니다. 출고 전인지, 이미 PG 승인되었는지, 쿠폰·포인트·재고 복원이 필요한지 확인한 뒤 상태를 변경합니다.</p>
<h3>반품·교환</h3>
<p>주문 아이템 단위로 반품 또는 교환을 접수합니다.<br>사유 유형과 상세 사유를 확인하고 승인·거절 등 처리 결과를 기록합니다.</p>
<p>회수 송장, 반품지 도착 여부, 상품 검수 결과와 반품·교환 배송비 부담 주체를 메모로 남기십시오.</p>
<h3>환불</h3>
<ul>
<li>환불 가능 잔액 안에서 금액과 방법을 선택합니다.</li>
<li>계좌 환불은 은행명·계좌번호·예금주를 확인하고 민감정보 접근을 최소화합니다.</li>
<li>PG 환불은 Shop 기록뿐 아니라 PG 관리자에서도 성공 여부를 대조합니다.</li>
<li>부분환불은 상품금액, 배송비, 쿠폰 할인, 사용 포인트를 어떤 기준으로 배분했는지 기록합니다.</li>
</ul>
<h3>마감 대조</h3>
<p>환불 마감 시 다음 항목을 한 체크리스트로 확인합니다.</p>
<ul>
<li>환불 금액과 완료일, 결제 기록</li>
<li>주문 및 개별 아이템 상태</li>
<li>재고 복구와 지급 포인트 환수</li>
<li>사용 쿠폰 복원</li>
</ul>
<blockquote><p>주문 상태를 취소로 변경한 것만으로 외부 PG 환불이 완료되었다고 판단하지 마십시오.</p></blockquote>
HTML,
        ],
        [
            'key' => 'promotion',
            'title' => '혜택·고객 콘텐츠',
            'slug' => 'promotion-customer',
            'sort_order' => 190,
            'content' => <<<'HTML'
<p>할인과 고객 콘텐츠는 매출뿐 아니라 주문 금액, 포인트, 상담량에 영향을 줍니다. 캠페인 시작·종료 일시와 책임자를 정하고 테스트 계정으로 발급부터 사용·취소 복원까지 검증합니다.</p>
HTML,
        ],
        [
            'key' => 'coupons-level',
            'parent' => 'promotion',
            'title' => '등급별 혜택과 쿠폰',
            'slug' => 'level-pricing-coupons',
            'sort_order' => 200,
            'content' => <<<'HTML'
<h3>등급별 혜택</h3>
<p><code>/admin/shop/level-pricing</code>에서 회원 등급별로 다음 혜택을 설정합니다.</p>
<ul>
<li>할인율</li>
<li>적립률</li>
<li>무료배송 여부</li>
<li>무료배송 기준액</li>
</ul>
<blockquote><p>기준액 0은 무조건 무료배송을 의미할 수 있습니다. 의도하지 않은 전면 무료배송이 되지 않도록 반드시 테스트하십시오.</p></blockquote>
<h3>쿠폰 정책</h3>
<ul>
<li><strong>발행 유형:</strong> 관리자 발행, 조건 자동 발행, 고객 다운로드</li>
<li><strong>대상:</strong> 전체, 특정 상품 또는 카테고리. 비우면 전체 적용되는 항목을 확인합니다.</li>
<li><strong>할인:</strong> 정액 또는 정률, 정률은 최대 할인, 공통으로 최소 주문금액 설정</li>
<li><strong>제한:</strong> 중복 사용, 1인 사용/발급 횟수, 첫 주문 전용, 총 발행 수량</li>
<li><strong>발급:</strong> 프로모션 코드, 발행 기간, 발행일부터 N일 또는 사용 기간, 활성 상태</li>
</ul>
<p>할인 값은 0보다 커야 하며 시작일은 종료일보다 늦을 수 없습니다.</p>
<p>정률 쿠폰은 최대 할인액을 지정하지 않으면 고액 주문에서 할인액이 크게 늘어날 수 있습니다.<br>발급 이력이 있는 쿠폰 정책은 감사 추적 보호를 위해 삭제할 수 없으므로 <strong>비활성화</strong>하십시오.</p>
<p>복수 쿠폰은 적용 가능한 대상과 남은 할인 기준액 안에서 합산됩니다.<br>할인 총액은 대상 상품 금액을 넘지 않습니다.</p>
<p>상품·카테고리의 쿠폰 허용 설정, 첫 주문 조건, 사용 횟수와 취소 시 쿠폰 복원까지 함께 검증하십시오.</p>
HTML,
        ],
        [
            'key' => 'reviews-inquiries',
            'parent' => 'promotion',
            'title' => '구매후기와 상품문의',
            'slug' => 'reviews-inquiries',
            'sort_order' => 210,
            'content' => <<<'HTML'
<h3>구매후기</h3>
<p><code>/admin/shop/reviews</code>에서 상품·주문항목, 내용, 사진, 작성자, 평점, 공개 여부, 베스트 여부와 관리자 답변을 관리합니다.</p>
<ul>
<li>사진은 최대 3장까지 등록할 수 있습니다.</li>
<li>지원 형식은 JPG, PNG, WEBP, GIF입니다.</li>
<li>파일당 최대 크기는 5MB입니다.</li>
</ul>
<p>관리자 답변은 별도로 저장되어 즉시 반영됩니다.<br>후기 본문 수정 저장과 혼동하지 마십시오.</p>
<ul>
<li>주문 기반 후기인지 확인하고 허위·중복 후기를 구분합니다.</li>
<li>숨김은 데이터를 보존한 채 노출만 중지할 때 사용합니다.</li>
<li>후기 적립금이 있다면 지급 조건과 삭제 시 회수 정책을 운영 규정으로 정합니다.</li>
</ul>
<h3>상품문의</h3>
<p><code>/admin/shop/inquiries</code>에서 상품, 제목·내용, 작성자, 문의 유형, 비밀글, 상태와 답변을 관리합니다.</p>
<p>문의 유형은 상품·재고·배송·기타로 구분됩니다.<br>관리자 답변은 문의 본문과 별도로 저장되어 즉시 반영됩니다.</p>
<p>비밀글의 제목과 내용이 다른 고객에게 노출되지 않는지 확인하십시오.<br>답변에는 주문자 개인정보, 내부 원가 또는 보안 정보를 작성하지 않습니다.</p>
<p>답변을 저장한 후 <strong>미답변 → 답변완료</strong> 상태가 실제 프론트에도 반영되는지 확인합니다.</p>
HTML,
        ],
        [
            'key' => 'exhibitions-wishlist',
            'parent' => 'promotion',
            'title' => '기획전과 찜한상품',
            'slug' => 'exhibitions-wishlist',
            'sort_order' => 220,
            'content' => <<<'HTML'
<h3>기획전</h3>
<p><code>/admin/shop/exhibitions</code>에서 제목, URL 슬러그, 설명, 배너, 상품·카테고리 항목, 활성화, 정렬과 시작·종료일을 설정합니다.</p>
<p>슬러그에는 영문 소문자·숫자·하이픈만 사용할 수 있습니다.<br>슬러그를 비우면 ID 기반 URL을 사용합니다.</p>
<ul>
<li>PC/모바일 등 제공되는 배너 슬롯별 이미지를 올리고 업로드 상태를 확인합니다.</li>
<li>상품과 카테고리를 섞어 배치한 뒤 순서를 동기화합니다.</li>
<li>기간이 비어 있으면 상시로 보일 수 있으므로 캠페인 종료가 필요한 기획전은 종료일을 지정합니다.</li>
<li>메뉴 등록 후 슬러그 변경 시 기존 메뉴 링크를 갱신합니다.</li>
</ul>
<h3>찜한상품</h3>
<p><code>/admin/shop/wishlists</code>는 회원별 찜한상품을 검색하고 정리하는 운영 화면입니다.</p>
<p>고객 선호 분석에는 집계 데이터를 사용하고 개별 회원 식별정보를 불필요하게 반출하지 마십시오.</p>
<p>상품을 비활성화하거나 품절 처리해도 기존 찜 데이터가 바로 사라지는 것은 아닙니다.<br>찜 목록에서 해당 상품이 어떻게 표시되는지 확인하십시오.</p>
HTML,
        ],
        [
            'key' => 'extensions',
            'title' => 'Shop 종속 플러그인 개발',
            'slug' => 'shop-plugin-development',
            'sort_order' => 225,
            'content' => <<<'HTML'
<p>Mublo Shop은 <code>packages/Shop/Plugins/{PluginName}</code> 위치의 종속 플러그인을 자동으로 발견합니다.</p>
<p>활성 이름은 <code>Shop/{PluginName}</code>이며, 부모 Shop 패키지가 비활성화되면 종속 플러그인도 함께 로드되지 않습니다.</p>
<h3>공개 Extension API</h3>
<p>외부 플러그인은 Shop의 내부 Service, Repository, Entity 또는 DB 테이블을 직접 사용하지 않습니다.<br><code>ShopExtensionApiInterface</code>를 Provider에 주입해 다음 공개 계약만 사용합니다.</p>
<ul>
<li><code>products()</code>: 현재 도메인의 상품을 readonly <code>ProductSnapshot</code>으로 조회</li>
<li><code>orders()</code>: 현재 도메인의 주문을 개인정보가 제거된 readonly <code>OrderSnapshot</code>으로 조회</li>
<li><code>commands()-&gt;deleteProduct()</code>: 도메인 소유권을 확인한 상품 삭제</li>
<li><code>commands()-&gt;changeOrderStatus()</code>: 도메인 소유권과 기존 FSM 전이를 모두 확인한 주문상태 변경</li>
</ul>
<pre><code>$container-&gt;singleton(MyShopPluginService::class, fn($c) =&gt;
    new MyShopPluginService($c-&gt;get(ShopExtensionApiInterface::class))
);</code></pre>
<h3>디렉터리와 Manifest</h3>
<pre><code>packages/Shop/Plugins/{PluginName}/
├── manifest.json
├── {PluginName}Provider.php
├── routes.php
└── database/migrations/</code></pre>
<p><code>manifest.json</code>에는 <code>"parent": "Shop"</code>과 검증한 <code>package:Shop</code> 버전 범위를 선언합니다.<br>현재 Extension API를 사용하는 플러그인은 <code>&gt;=1.0.0 &lt;2.0.0</code>으로 시작합니다.</p>
<blockquote><p>주문 Snapshot에는 주문자·수령인·전화번호·주소가 포함되지 않습니다. 개인정보가 필요한 연동은 임의로 내부 Entity를 읽지 말고 별도의 최소 권한 공개 Contract를 먼저 설계하십시오.</p></blockquote>
<h3>기존 확장 지점</h3>
<ul>
<li>PG 연동: 코어 <code>PaymentGatewayInterface</code></li>
<li>주문상태 커스텀 액션: <code>ActionHandlerInterface</code>와 <code>ActionTypeRegistry</code></li>
<li>후처리 연동: Shop의 상품·주문·결제·카테고리·기획전 Event 구독</li>
</ul>
HTML,
        ],
        [
            'key' => 'skins',
            'title' => 'Mublo Shop 스킨 제작',
            'slug' => 'skin-development',
            'sort_order' => 230,
            'content' => <<<'HTML'
<p>Mublo Shop의 프론트 콘텐츠 스킨은 다음 경로에 둡니다.</p>
<pre><code>packages/Shop/views/Front/{Feature}/{skin}/</code></pre>
<p>프레임 스킨과는 별개의 구조입니다.<br>관리자 쇼핑몰 설정에서 기능별로 사용할 스킨을 선택합니다.</p>
<pre><code>packages/Shop/views/Front/
├── Product/{skin}/List.php, View.php
├── Cart/{skin}/List.php, Checkout.php
├── Order/{skin}/Index.php, View.php, Complete.php, Lookup.php
├── Review/{skin}/List.php, Form.php, MyReviews.php
├── Inquiry/{skin}/List.php, Form.php, MyInquiries.php
├── Exhibition/{skin}/List.php, View.php
├── Coupon/{skin}/Index.php
├── Wishlist/{skin}/Index.php
├── Mypage/{skin}/Index.php
└── Ui/{skin}/GuestOrderButton.php, GuestOrderLookupButton.php</code></pre>
<p>새 스킨은 같은 기능의 <code>basic</code> 폴더를 복사한 뒤 폴더명을 변경해 시작하는 것이 안전합니다.</p>
<p>필요한 파일부터 하나씩 수정하십시오.<br>선택한 스킨에 파일이 없으면 요청된 파일 단위로 <code>basic</code>이 대신 사용됩니다.</p>
HTML,
        ],
        [
            'key' => 'skin-structure',
            'parent' => 'skins',
            'title' => '선택·폴백·에셋 구조',
            'slug' => 'skin-structure-assets',
            'sort_order' => 240,
            'content' => <<<'HTML'
<h3>폴더와 선택 규칙</h3>
<p>기능 폴더명은 <code>Product</code>, <code>Cart</code>처럼 대소문자를 정확히 맞춰야 합니다.<br>스킨명은 실제 폴더명을 사용합니다.</p>
<p>설정에는 기능의 소문자 키와 스킨명이 저장됩니다.<br><code>basic</code>은 기본값이므로 별도 오버라이드가 저장되지 않을 수 있습니다.</p>
<p>예를 들어 <code>Product/myshop/List.php</code>만 만들고 상품 스킨을 <code>myshop</code>으로 선택했다고 가정합니다.</p>
<ul>
<li>상품 목록: <code>Product/myshop/List.php</code> 사용</li>
<li>상품 상세: <code>Product/basic/View.php</code>로 폴백</li>
</ul>
<p>폴백은 PHP 뷰 파일을 기준으로 합니다.<br>커스텀 뷰가 참조하는 CSS와 JS까지 자동으로 대체되지는 않습니다.</p>
<h3>에셋</h3>
<pre><code>Product/myshop/
├── List.php
├── View.php
└── _assets/
    ├── css/product-list.css
    └── js/product-list.js</code></pre>
<p>뷰에서 다음과 같이 에셋을 등록합니다.</p>
<pre><code>$this-&gt;assets-&gt;addCss(
    '/serve/package/Shop/views/Front/Product/myshop/_assets/css/product-list.css'
);</code></pre>
<p>PHP 경로와 공개 serve URL의 대소문자를 일치시키십시오.<br>파일을 직접 읽는 로컬 시스템 경로는 HTML에 노출하지 않습니다.</p>
<p>기존 basic 클래스와 JS를 그대로 쓸 때는 DOM의 id, data 속성, 폼 target 계약도 함께 유지하십시오.</p>
HTML,
        ],
        [
            'key' => 'skin-product',
            'parent' => 'skins',
            'title' => '상품 목록·상세 데이터 계약',
            'slug' => 'skin-product-contract',
            'sort_order' => 250,
            'content' => <<<'HTML'
<h3>상품 목록 <code>Product/List.php</code></h3>
<p>주요 변수는 다음과 같습니다.</p>
<ul>
<li><code>$products</code>: Presenter 변환이 끝난 상품 목록</li>
<li><code>$pagination</code>: 페이지 정보</li>
<li><code>$categoryTree</code>: 카테고리 트리</li>
<li><code>$filters</code>: 카테고리 코드, 검색어, 정렬값</li>
<li><code>$wishlistedIds</code>: 현재 회원이 찜한 상품 ID</li>
</ul>
<p>기본 정렬 선택지는 최신순, 낮은가격순, 높은가격순, 인기순입니다.</p>
<h3>ProductPresenter 공통 필드</h3>
<ul>
<li>URL/안전 문자열: <code>url</code>, <code>goods_name_safe</code>, <code>goods_origin_safe</code>, <code>goods_manufacturer_safe</code></li>
<li>가격: <code>display_price_formatted</code>, <code>sales_price</code>, <code>sales_price_formatted</code>, <code>discount_amount</code>, <code>discount_percent</code>, <code>has_discount</code></li>
<li>적립: <code>point_amount</code>, <code>point_amount_formatted</code>, <code>has_reward</code></li>
<li>상태: <code>is_soldout</code>, <code>stock_label</code>, <code>badges</code>, <code>is_new</code></li>
<li>통계: <code>review_count</code>, <code>average_rating</code>, <code>wishlist_count</code>와 formatted 값</li>
<li>이미지: <code>main_image_url</code>, <code>main_thumbnail_url</code></li>
</ul>
<h3>상품 상세 <code>Product/View.php</code></h3>
<p><code>$product</code>에는 목록 필드 외에 다음 상세 데이터가 포함됩니다.</p>
<ul>
<li><code>images</code>, <code>tags_array</code>, <code>details</code></li>
<li><code>options</code>, <code>combos</code></li>
<li>상품문의 통계와 구매후기 적립 필드</li>
</ul>
<p>상품을 찾지 못하면 <code>$product</code>는 null이고 <code>$message</code>가 전달됩니다.</p>
<blockquote>
<p><code>goods_name_safe</code>처럼 Presenter가 이스케이프한 필드는 그대로 출력합니다.</p>
<p>일반 문자열은 <code>htmlspecialchars</code> 또는 <code>e()</code>로 이스케이프하십시오.<br>상세 HTML로 명시된 필드만 정해진 정제 정책에 따라 출력합니다.</p>
</blockquote>
HTML,
        ],
        [
            'key' => 'skin-cart-order',
            'parent' => 'skins',
            'title' => '장바구니·주문 스킨 계약',
            'slug' => 'skin-cart-order-contract',
            'sort_order' => 260,
            'content' => <<<'HTML'
<h3>장바구니</h3>
<p><code>Cart/List.php</code>는 다음 데이터를 받습니다.</p>
<ul>
<li><code>$groups</code>: 배송 그룹별 상품</li>
<li><code>$totals</code>: 상품·배송·포인트·결제 합계</li>
<li><code>$productData</code>: 옵션 변경용 상품 데이터</li>
</ul>
<p>수량이나 옵션을 변경한 뒤 금액을 브라우저에서 임의로 계산하지 마십시오.<br>제공된 재계산 API의 결과로 화면을 갱신합니다.</p>
<h3>주문서</h3>
<p><code>Cart/Checkout.php</code>는 다음 데이터를 사용합니다.</p>
<ul>
<li><code>$cartItems</code>, <code>$totals</code>, <code>$gateways</code></li>
<li><code>$member</code>, <code>$isGuest</code>, <code>$checkoutMode</code></li>
<li>주소록, <code>$orderFields</code>, <code>$pointUsage</code></li>
</ul>
<p>배송지 우편번호가 바뀌면 배송비를 서버에서 다시 계산합니다.<br>약관, 필수 추가필드와 CSRF 토큰도 그대로 유지해야 합니다.</p>
<h3>주문 화면</h3>
<ul>
<li><code>Order/Complete.php</code>: <code>$order</code>, <code>$orderItems</code>, <code>$receiptUrl</code>, 오류 <code>$message</code></li>
<li><code>Order/Index.php</code>: <code>$orders</code>, <code>$pagination</code>, <code>$allStates</code>, 검색어와 비회원 여부</li>
<li><code>Order/View.php</code>: 복호화된 <code>$order</code>, 아이템, 추가필드 값, 상태 라벨·타임라인, 비회원 여부</li>
<li><code>Order/Lookup.php</code>: 비회원 이름·연락처 입력, 오류, 전역 CSRF·사이트 이미지·설정</li>
</ul>
<p>결제 요청 금액, 할인, 배송비, 포인트는 hidden 값만 신뢰하지 않습니다. 서버가 다시 계산하고 검증하므로 스킨은 서버 응답을 사용자에게 정확히 표시해야 합니다.</p>
HTML,
        ],
        [
            'key' => 'skin-other',
            'parent' => 'skins',
            'title' => '후기·문의·기획전 등 스킨 계약',
            'slug' => 'skin-other-contracts',
            'sort_order' => 270,
            'content' => <<<'HTML'
<table><thead><tr><th>기능</th><th>주요 파일/변수</th></tr></thead><tbody>
<tr><td>Review</td><td>List: items, pagination, goodsId, avgRating / Form: orderDetailId / MyReviews: items, pagination</td></tr>
<tr><td>Inquiry</td><td>List: items, pagination, goodsId, currentMemberId / Form: goodsId / MyInquiries: items, pagination</td></tr>
<tr><td>Exhibition</td><td>List: pageTitle, exhibitions, error / View: exhibition, items, Presenter 변환 products, wishlistedIds</td></tr>
<tr><td>Coupon</td><td>고객 보유·다운로드·프로모션 코드 등록 화면 데이터</td></tr>
<tr><td>Wishlist</td><td>items, pagination. 비활성·품절 여부를 고려해 버튼을 표시</td></tr>
<tr><td>Mypage</td><td>최근 주문/후기/문의, 각 전체 건수, 쿠폰·찜 건수, allStates</td></tr>
<tr><td>Ui</td><td>비회원 주문 버튼의 redirectUrl, 비회원 주문조회 버튼</td></tr>
</tbody></table>
<p>비밀 문의는 현재 회원과 작성자 정보를 기준으로 본문과 답변을 가립니다.<br>따라서 <code>basic</code> 스킨의 비밀글 조건을 제거하지 마십시오.</p>
<p>후기·문의 삭제, 찜 토글과 쿠폰 다운로드에서는 로그인, CSRF, 권한 검사와 오류 처리를 유지합니다.</p>
<p>기획전 상품은 <code>ProductPresenter</code>를 거친 상품 목록과 같은 규격입니다.<br>따라서 기존 상품 카드 컴포넌트를 재사용할 수 있습니다.</p>
<p>기획전 전용 배너·기간·설명·빈 상태와 종료된 기획전 접근은 별도로 처리하십시오.</p>
HTML,
        ],
        [
            'key' => 'skin-security',
            'parent' => 'skins',
            'title' => '보안·블록·호환성',
            'slug' => 'skin-security-blocks',
            'sort_order' => 280,
            'content' => <<<'HTML'
<h3>스킨 보안</h3>
<ul>
<li>출력 문자열은 기본 이스케이프하고 URL·data 속성도 따로 이스케이프합니다.</li>
<li>POST 폼과 AJAX 요청의 CSRF 토큰, 실제 HTTP method, 기존 요청 필드명을 유지합니다.</li>
<li>가격·재고·배송비·쿠폰·포인트·주문 소유권을 클라이언트 값으로 판정하지 않습니다.</li>
<li>비회원 주문번호와 연락처, 회원 주문 개인정보를 HTML 주석·JS 전역·로그에 노출하지 않습니다.</li>
<li>업로드 필드는 허용 확장자·크기와 서버 응답을 따르고 파일 경로를 조립하지 않습니다.</li>
</ul>
<h3>블록과 검색</h3>
<p>Shop은 상품 수동·자동 블록, 후기 자동 블록과 통합 검색 연동을 제공합니다.</p>
<p>블록 스킨은 다음과 같은 별도 구조를 사용합니다.</p>
<pre><code>packages/Shop/views/Block/{type}/{skin}</code></pre>
<p>블록 스킨은 프론트 콘텐츠 스킨 선택과 독립적입니다.<br>상품 카드를 공유하려면 두 렌더러가 제공하는 Presenter 필드의 차이를 먼저 확인하십시오.</p>
<h3>업데이트 호환성</h3>
<p><code>basic</code> 스킨과 Controller payload를 데이터 계약의 기준으로 삼으십시오.<br>커스텀 스킨에서 사용하는 필드는 별도로 목록화합니다.</p>
<p>업데이트 후에는 다음 항목을 회귀 테스트합니다.</p>
<ul>
<li>PHP 및 브라우저 콘솔 오류</li>
<li>빈 데이터와 기본값</li>
<li>새 주문 상태와 결제수단</li>
<li>옵션 조합과 접근성</li>
</ul>
HTML,
        ],
        [
            'key' => 'release-checklist',
            'title' => '운영·스킨 배포 체크리스트',
            'slug' => 'release-checklist',
            'sort_order' => 290,
            'content' => <<<'HTML'
<h3>관리 설정</h3>
<ul>
<li>활성 기본 배송 템플릿, 주소, 반품/교환비, 도서산간 비용 확인</li>
<li>PG·무통장 계좌·포인트 단위/최소/최대와 환불 경로 확인</li>
<li>전역/등급/상품 할인·적립과 쿠폰 중첩 최종가 확인</li>
<li>필수 약관, 주문 추가 필드, 암호화·파일 업로드 확인</li>
<li>주문 상태 전이와 재고·포인트·알림·웹훅 액션 확인</li>
</ul>
<h3>상품과 고객 여정</h3>
<ul>
<li>카테고리 권한·성인인증·메뉴 링크, 검색·정렬 확인</li>
<li>옵션 없음/단독/조합, 미관리/품절 재고, 추가금액 확인</li>
<li>회원/비회원, 장바구니/바로구매, 주소록, 쿠폰+포인트 주문 확인</li>
<li>송장·배송완료·구매확정, 취소·반품·교환·부분환불 확인</li>
<li>후기·비밀문의·찜·기획전·마이페이지 확인</li>
</ul>
<h3>스킨</h3>
<ul>
<li>모든 기능별 스킨 선택과 누락 파일 basic 폴백 확인</li>
<li>데스크톱/모바일, 키보드 조작, 빈 목록·품절·오류·404 확인</li>
<li>HTML 이스케이프, CSRF, 주문 소유권, 개인정보 노출 확인</li>
<li>CSS/JS serve URL, 브라우저 콘솔·네트워크 오류 확인</li>
</ul>
<p>마지막으로 테스트 주문의 표시금액과 서버 저장금액, PG 승인금액, 배송비, 재고, 쿠폰, 포인트가 모두 일치해야 배포를 완료합니다.</p>
HTML,
        ],
    ],
];
