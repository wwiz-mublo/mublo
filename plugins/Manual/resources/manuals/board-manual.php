<?php
declare(strict_types=1);

/**
 * Board 패키지 운영 및 스킨 제작 번들 매뉴얼.
 *
 * 관리자 화면, BoardController payload, Presenter와 블록 렌더러의 실제 계약을
 * 기준으로 작성했다. 가져온 뒤에는 도메인 관리자가 일반 매뉴얼처럼 편집할 수 있다.
 */
return [
    'version' => 2,
    'book' => [
        'title' => 'Mublo 게시판 매뉴얼',
        'slug' => 'board-manual',
        'description' => '게시판 개설·권한·콘텐츠 운영부터 게시판·커뮤니티·블록 스킨 제작까지 다루는 통합 매뉴얼',
        'sort_order' => 10,
        'is_active' => 1,
    ],
    'pages' => [
        [
            'key' => 'start',
            'title' => '게시판 시작하기',
            'slug' => 'start',
            'sort_order' => 10,
            'content' => <<<'HTML'
<!-- mublo-bundle:board:v2 -->
<p>Board 패키지는 게시판 그룹, 게시판, 공용 카테고리, 게시글·댓글·첨부파일·반응, 포인트 정책, 커뮤니티와 블록 연동을 제공합니다.</p>
<h3>권장 개설 순서</h3>
<ol>
<li><strong>관리자 &gt; 확장 기능</strong>에서 Board 패키지를 활성화하고 마이그레이션을 완료합니다.</li>
<li><strong>Mublo Board &gt; 게시판 그룹</strong>에서 그룹과 기본 권한을 만듭니다.</li>
<li>필요한 공용 카테고리를 등록합니다.</li>
<li><strong>게시판 관리</strong>에서 그룹, 슬러그, 권한, 기능, 스킨을 지정합니다.</li>
<li>메뉴 관리에서 <code>/board/{게시판슬러그}</code> 또는 <code>/community</code>를 배치합니다.</li>
<li>비회원·일반 회원·관리자 계정으로 목록, 읽기, 쓰기, 댓글, 다운로드 권한을 각각 확인합니다.</li>
</ol>
<table>
<thead><tr><th>화면</th><th>주소</th><th>용도</th></tr></thead>
<tbody>
<tr><td>대시보드</td><td><code>/admin/board/dashboard</code></td><td>게시판 활동 현황</td></tr>
<tr><td>게시판 그룹</td><td><code>/admin/board/group</code></td><td>게시판 묶음과 기본 권한</td></tr>
<tr><td>카테고리</td><td><code>/admin/board/category</code></td><td>여러 게시판에서 선택해 쓰는 분류</td></tr>
<tr><td>게시판 관리</td><td><code>/admin/board/config</code></td><td>게시판별 상세 설정</td></tr>
<tr><td>게시글 관리</td><td><code>/admin/board/article</code></td><td>게시글 검색·작성·상태 변경</td></tr>
<tr><td>포인트 설정</td><td><code>/admin/board/point</code></td><td>활동별 적립·소비 정책</td></tr>
</tbody>
</table>
<blockquote><p>슬러그는 프론트 URL의 일부입니다. 운영을 시작한 뒤 바꾸면 기존 링크와 검색 색인이 끊길 수 있으므로 개설 단계에서 확정하십시오.</p></blockquote>
HTML,
        ],
        [
            'key' => 'groups',
            'parent' => 'start',
            'title' => '그룹과 대시보드',
            'slug' => 'groups-and-dashboard',
            'sort_order' => 20,
            'content' => <<<'HTML'
<h3>대시보드</h3>
<p>게시글·댓글·조회·활성 게시판 등 현재 도메인의 현황을 먼저 확인합니다. 이상 급증이 보이면 게시글 관리에서 게시판과 상태를 좁혀 원문을 점검합니다.</p>
<h3>게시판 그룹</h3>
<p>그룹은 게시판을 묶는 운영 단위이자 권한 기본값입니다. 슬러그는 <code>/community/group/{slug}</code>에 사용되고, 비활성 그룹의 게시판은 커뮤니티에서 표시되지 않습니다.</p>
<ul>
<li><strong>기본 권한:</strong> 목록 보기, 글 읽기, 글쓰기, 댓글 쓰기, 다운로드 레벨을 지정합니다.</li>
<li><strong>그룹 관리자:</strong> 그룹을 저장한 뒤 회원 아이디를 검색해 추가합니다.</li>
<li><strong>게시판 목록:</strong> 편집 화면에서 소속 게시판과 활성 상태를 확인하고 새 게시판을 바로 추가할 수 있습니다.</li>
</ul>
<p>게시판에 별도 권한을 저장하면 그룹 기본값보다 게시판 설정이 우선합니다. 그룹 권한을 바꾼 뒤에는 예외 게시판도 함께 검토하십시오.</p>
HTML,
        ],
        [
            'key' => 'board-config',
            'parent' => 'start',
            'title' => '게시판 설정',
            'slug' => 'board-config',
            'sort_order' => 30,
            'content' => <<<'HTML'
<p><strong>게시판 관리</strong>에서 게시판을 만들거나 수정합니다.</p>
<table>
<thead><tr><th>영역</th><th>주요 설정</th></tr></thead>
<tbody>
<tr><td>기본 정보</td><td>그룹, 슬러그, 게시판명·설명, 사용 여부, 전역 게시판 여부</td></tr>
<tr><td>권한</td><td>목록·읽기·쓰기·댓글·다운로드 레벨, 레벨별 1일 글/댓글 작성 제한</td></tr>
<tr><td>UI</td><td>게시판 스킨, 에디터, 상단 공지 수, 페이지당 글 수</td></tr>
<tr><td>기능</td><td>비밀글, 전체 비밀 게시판, 카테고리, 파일, 링크, 댓글, 반응</td></tr>
<tr><td>반응</td><td>키·이모지·라벨·색상·사용 여부와 노출 순서</td></tr>
<tr><td>파일</td><td>첨부 개수, 파일당 크기, 허용 확장자</td></tr>
</tbody>
</table>
<ul>
<li><strong>비밀글 사용</strong>은 작성자가 글마다 선택하고, <strong>비밀 게시판</strong>은 모든 글을 비밀글로 만듭니다.</li>
<li>반응을 끄면 기존 집계는 보존되지만, 반응 항목을 삭제하면 해당 유형의 누적 데이터도 삭제될 수 있습니다.</li>
<li>페이지당 글 수가 0이면 도메인의 기본 목록 수를 사용합니다.</li>
<li>전역 게시판은 여러 도메인에서 읽힐 수 있으므로 도메인별 게시판과 같은 감각으로 사용하지 마십시오.</li>
</ul>
HTML,
        ],
        [
            'key' => 'categories-permissions',
            'parent' => 'start',
            'title' => '카테고리와 권한',
            'slug' => 'categories-and-permissions',
            'sort_order' => 40,
            'content' => <<<'HTML'
<h3>카테고리</h3>
<p>카테고리는 도메인 공용으로 만든 뒤 각 게시판 설정에서 사용할 항목만 체크하고 드래그해 순서를 정합니다. 사용 중인 카테고리는 연결된 게시판에서 먼저 해제해야 삭제할 수 있습니다.</p>
<h3>권한 판단</h3>
<p>일반 접근 레벨은 그룹 기본값 위에 게시판 설정이 적용됩니다.</p>
<p>게시글의 읽기·다운로드 레벨이나 게시판-카테고리 매핑에 별도 값이 있으면 더 구체적인 설정이 우선합니다.<br>관리자 권한은 별도의 권한 데이터도 함께 확인합니다.</p>
<ul>
<li>목록을 볼 수 있어도 개별 글 읽기 권한이 없을 수 있습니다.</li>
<li>글을 읽을 수 없는 사용자는 댓글 작성과 첨부 다운로드도 허용되지 않습니다.</li>
<li>비밀글은 작성자와 허용된 관리자 등 별도 조건을 통과해야 합니다.</li>
<li>비회원 글은 작성 시 비밀번호를 받고, 확인된 글 ID를 세션에 보관해 수정·삭제를 허용합니다.</li>
</ul>
<blockquote><p>권한 문제를 확인할 때는 화면에서 버튼이 보이는지만 보지 말고 실제 목록·상세·쓰기·댓글·다운로드 요청이 차단되는지 테스트하십시오. 최종 권한은 Service 계층에서 다시 검사합니다.</p></blockquote>
HTML,
        ],
        [
            'key' => 'operation',
            'title' => '콘텐츠 운영',
            'slug' => 'content-operation',
            'sort_order' => 50,
            'content' => <<<'HTML'
<p>게시글 관리에서는 게시판, 상태, 검색 항목과 키워드로 글을 찾고 새 글을 등록합니다. 여러 글을 선택해 발행·임시저장·삭제 상태로 일괄 변경할 수 있습니다.</p>
<h3>운영 원칙</h3>
<ol>
<li>공지글은 게시판의 상단 고정 개수와 함께 운용합니다.</li>
<li>비밀글·비밀댓글은 비로그인 브라우저에서 본문이 숨겨지는지 확인합니다.</li>
<li>신고·블라인드 같은 추가 동작은 Board 하위 플러그인이 상세 화면의 추가 액션으로 공급할 수 있습니다.</li>
<li>삭제 전 첨부파일, 댓글, 포인트 회수 등 연관 효과를 확인합니다.</li>
</ol>
<p>프론트의 대표 주소는 목록 <code>/board/{slug}</code>, 상세 <code>/board/{slug}/view/{글번호}</code>, 쓰기 <code>/board/{slug}/write</code>, 커뮤니티 <code>/community</code>입니다.</p>
HTML,
        ],
        [
            'key' => 'articles',
            'parent' => 'operation',
            'title' => '글·댓글·첨부파일',
            'slug' => 'articles-comments-files',
            'sort_order' => 60,
            'content' => <<<'HTML'
<h3>글과 댓글</h3>
<ul>
<li>글 작성·수정 본문은 게시판 전용 정화기를 거쳐 저장됩니다.</li>
<li>비회원 글은 이름과 비밀번호가 필요하며, 비밀번호 확인 전에는 관리 작업을 할 수 없습니다.</li>
<li>비밀댓글 본문은 댓글 작성자, 글 작성자 또는 관리 권한이 있는 사용자에게만 노출됩니다.</li>
<li>조회수는 당일 쿠키를 사용해 같은 브라우저의 반복 증가를 줄입니다.</li>
</ul>
<h3>첨부와 링크</h3>
<ul>
<li>첨부파일은 게시판별 개수·크기·확장자 정책을 모두 통과해야 합니다.</li>
<li>다운로드는 글 읽기와 다운로드 권한을 다시 검사하며, 필요한 경우 포인트도 차감합니다.</li>
<li>관련 링크 기능을 켜면 URL과 제목을 저장하고 OG 정보가 사용될 수 있습니다.</li>
</ul>
<p>업로드 일부가 거절되어도 글은 먼저 저장될 수 있으며, 거절된 파일 사유는 경고로 반환됩니다. 등록 완료 뒤 첨부 목록까지 확인하십시오.</p>
HTML,
        ],
        [
            'key' => 'points-blocks',
            'parent' => 'operation',
            'title' => '포인트·커뮤니티·블록',
            'slug' => 'points-community-blocks',
            'sort_order' => 70,
            'content' => <<<'HTML'
<h3>포인트</h3>
<p>게시글 작성, 댓글 작성, 반응 받기는 적립 항목이며 게시글 읽기와 파일 다운로드는 소비 항목입니다. 정책은 <strong>게시판별 → 그룹별 → 도메인 기본값</strong> 순서로 해석됩니다.</p>
<ul>
<li>적립 항목은 삭제 시 회수 여부를 선택할 수 있습니다.</li>
<li>소비 항목은 잔액이 부족하면 접근이 차단됩니다.</li>
<li>본인이 쓴 글 읽기는 차감하지 않으며, 같은 대상에 대한 중복 차감은 멱등 키로 방지됩니다.</li>
<li>반응 받기 포인트는 반응 유형별 값으로 덮어쓸 수 있습니다.</li>
</ul>
<h3>커뮤니티와 블록</h3>
<p><code>/community</code>는 접근 가능한 활성 게시판을 모아 최신글·인기글과 그룹 탭을 제공합니다. 블록 편집기에서는 다음 콘텐츠 타입을 사용할 수 있습니다.</p>
<table>
<thead><tr><th>타입</th><th>선택 항목</th><th>기본 스킨</th></tr></thead>
<tbody>
<tr><td><code>board</code></td><td>게시판</td><td><code>basic</code>, <code>gallery</code>, <code>thumb</code></td></tr>
<tr><td><code>boardcomment</code></td><td>게시판</td><td><code>basic</code></td></tr>
<tr><td><code>boardgroup</code></td><td>게시판 그룹</td><td><code>basic</code>, <code>basic-tab</code>, <code>gallery</code> 등</td></tr>
</tbody>
</table>
HTML,
        ],
        [
            'key' => 'skin',
            'title' => '게시판 스킨 제작',
            'slug' => 'skin-development',
            'sort_order' => 80,
            'content' => <<<'HTML'
<p>게시판 콘텐츠 스킨은 <code>packages/Board/views/Front/Board/{skin}/</code>에 둡니다. 관리자 게시판 설정의 <strong>게시판 스킨</strong> 목록은 이 디렉터리를 탐색해 구성됩니다.</p>
<pre><code>packages/Board/views/Front/Board/my-board/
├── List.php
├── View.php
├── Write.php
├── Password.php
└── _assets/
    ├── css/board.css
    └── js/board.js</code></pre>
<ol>
<li><code>basic</code>의 네 PHP 파일을 계약 참고용으로 읽습니다.</li>
<li>새 폴더를 만들고 필요한 화면과 에셋을 작성합니다.</li>
<li>게시판 설정에서 새 스킨을 선택해 저장합니다.</li>
<li>목록·상세·작성·수정·비회원 비밀번호 화면을 모두 확인합니다.</li>
</ol>
<blockquote>
<p>Board의 프론트 콘텐츠 스킨은 Controller가 선택한 스킨 파일을 직접 지정합니다.</p>
<p>선택한 폴더에 필요한 PHP 파일을 모두 두십시오.<br>Mublo Shop과 달리 누락된 파일을 <code>basic</code>으로 자동 폴백하지 않습니다.</p>
</blockquote>
HTML,
        ],
        [
            'key' => 'skin-contract',
            'parent' => 'skin',
            'title' => '화면별 데이터 계약',
            'slug' => 'skin-data-contract',
            'sort_order' => 90,
            'content' => <<<'HTML'
<table>
<thead><tr><th>파일</th><th>주요 변수</th></tr></thead>
<tbody>
<tr><td><code>List.php</code></td><td><code>$board</code>, <code>$notices</code>, <code>$items</code>, <code>$pagination</code>, <code>$filters</code>, <code>$categories</code>, <code>$canWrite</code></td></tr>
<tr><td><code>View.php</code></td><td><code>$article</code>, <code>$board</code>, <code>$prev</code>, <code>$next</code>, 권한 플래그, <code>$attachments</code>, <code>$links</code>, <code>$comments</code>, <code>$reactionInfo</code>, <code>$enabledReactions</code>, <code>$extraActions</code></td></tr>
<tr><td><code>Write.php</code></td><td><code>$board</code>, <code>$article</code>, <code>$isEdit</code>, <code>$isLoggedIn</code>, <code>$categories</code>, <code>$attachments</code>, <code>$links</code></td></tr>
<tr><td><code>Password.php</code></td><td><code>$boardSlug</code>, <code>$articleId</code>, <code>$board</code></td></tr>
</tbody>
</table>
<p><code>$items</code>와 <code>$article</code>은 <code>ArticlePresenter</code> 변환을 거칩니다. 대표 공개 필드는 다음과 같습니다.</p>
<ul>
<li>원본 표시값: <code>article_id</code>, <code>title</code>, <code>content</code>, <code>author_name</code>, <code>category_name</code>, <code>thumbnail</code></li>
<li>URL: <code>url</code>, <code>edit_url</code></li>
<li>날짜: <code>date_raw</code>, <code>date_full</code>, <code>date_short</code>, <code>date_compact</code>, <code>date_time</code>, <code>date_relative</code>, <code>date_ymd</code></li>
<li>표시 보조: <code>title_safe</code>, <code>author_name_masked</code>, <code>badges</code>, <code>is_new</code>, <code>is_updated</code></li>
<li>통계: <code>view_count_formatted</code>, <code>comment_count_formatted</code>, <code>reaction_count_formatted</code></li>
</ul>
<p>공통 사이트·회원·요청 정보는 <code>$mublo</code>에서 읽습니다. 스킨에서 DB, Repository, 세션이나 컨테이너를 직접 조회하지 마십시오.</p>
HTML,
        ],
        [
            'key' => 'skin-interaction',
            'parent' => 'skin',
            'title' => '폼·에셋·보안',
            'slug' => 'skin-forms-assets-security',
            'sort_order' => 100,
            'content' => <<<'HTML'
<h3>에셋</h3>
<pre><code>$this-&gt;assets-&gt;addCss(
    '/serve/package/Board/views/Front/Board/my-board/_assets/css/board.css'
);
$this-&gt;assets-&gt;addJs(
    '/serve/package/Board/views/Front/Board/my-board/_assets/js/board.js'
);</code></pre>
<h3>출력과 요청</h3>
<ul>
<li><code>title_safe</code>, Presenter가 가공한 작성자명처럼 계약상 이스케이프된 필드와 원본 필드를 구분합니다.</li>
<li>그 밖의 텍스트와 URL 속성은 <code>e()</code> 또는 <code>htmlspecialchars()</code>로 이스케이프합니다.</li>
<li>게시글 본문은 서버 정화 후 전달되므로 상세 화면에서 HTML로 출력합니다. 임의 입력을 같은 방식으로 출력하지 마십시오.</li>
<li>작성 폼의 필드명과 엔드포인트는 <code>basic/Write.php</code> 계약을 유지합니다. JSON 요청은 공통 <code>MubloRequest</code>를 사용합니다.</li>
<li>버튼 노출은 <code>$canWrite</code>, <code>$canModify</code>, <code>$canDelete</code>, <code>$canComment</code>, <code>$canReact</code>, <code>$canDownload</code>를 따릅니다.</li>
</ul>
<p>버튼을 숨기는 것은 보안 경계가 아니지만, 허용되지 않은 동작을 안내하지 않는 올바른 UI를 만드는 데 필요합니다. 서버 권한 검사는 기존 엔드포인트가 담당합니다.</p>
HTML,
        ],
        [
            'key' => 'other-skins',
            'parent' => 'skin',
            'title' => '커뮤니티·마이페이지·블록 스킨',
            'slug' => 'community-mypage-block-skins',
            'sort_order' => 110,
            'content' => <<<'HTML'
<table>
<thead><tr><th>대상</th><th>경로</th><th>핵심 계약</th></tr></thead>
<tbody>
<tr><td>커뮤니티</td><td><code>views/Front/Community/{skin}/Index.php</code></td><td><code>$items</code>, <code>$pagination</code>, <code>$filters</code>, <code>$groups</code>, <code>$currentGroup</code>, <code>$sortBy</code></td></tr>
<tr><td>마이페이지</td><td><code>views/Front/Mypage/{skin}/</code></td><td>허브 통계와 내가 쓴 글·댓글 목록</td></tr>
<tr><td>게시판 블록</td><td><code>views/Block/board/{skin}/{skin}.php</code></td><td><code>$items</code>, <code>$board</code>, 공통 블록 변수</td></tr>
<tr><td>댓글 블록</td><td><code>views/Block/boardcomment/{skin}/{skin}.php</code></td><td><code>$items</code>는 <code>CommentPresenter</code> 변환 결과</td></tr>
<tr><td>그룹 블록</td><td><code>views/Block/boardgroup/{skin}/{skin}.php</code></td><td><code>$group</code>, <code>$boardsData</code>, <code>$displayType</code></td></tr>
</tbody>
</table>
<p>블록 스킨의 CSS·JS는 같은 폴더에 두며 기존 스킨의 AssetManager 등록 방식을 따릅니다. 데이터가 비어 있거나 대상 게시판이 비활성·삭제된 경우에도 오류 없이 빈 상태를 렌더해야 합니다.</p>
<p><code>views/Front/frame/</code>은 Board 전용 프레임 오버라이드용 스캐폴드이지만 현재 런타임 선택 배선은 완료되지 않았습니다. 사이트 프레임 변경은 Core 프레임 스킨을 사용하십시오.</p>
HTML,
        ],
        [
            'key' => 'checklist',
            'parent' => 'skin',
            'title' => '배포 전 점검',
            'slug' => 'release-checklist',
            'sort_order' => 120,
            'content' => <<<'HTML'
<ul>
<li><code>List.php</code>, <code>View.php</code>, <code>Write.php</code>, <code>Password.php</code>가 모두 존재합니다.</li>
<li>빈 목록, 공지, 카테고리, 검색, 페이지 이동을 확인했습니다.</li>
<li>일반 글·비밀글·비회원 글과 이전/다음 글을 확인했습니다.</li>
<li>댓글·비밀댓글·반응·추가 액션의 사용/미사용 상태를 확인했습니다.</li>
<li>첨부 없음/있음, 다운로드 불가, 링크 없음/있음을 확인했습니다.</li>
<li>비로그인, 낮은 레벨, 작성자, 그룹 관리자, 최고관리자 권한을 확인했습니다.</li>
<li>모바일에서 표·폼·긴 제목·긴 본문이 넘치지 않습니다.</li>
<li>사용자 텍스트와 속성값을 이스케이프하고, 스킨에서 DB나 세션을 직접 읽지 않습니다.</li>
<li>게시판 블록과 커뮤니티에서 비활성·삭제 대상이 빈 상태로 안전하게 표시됩니다.</li>
<li><code>composer check</code>를 통과했습니다.</li>
</ul>
HTML,
        ],
    ],
];
