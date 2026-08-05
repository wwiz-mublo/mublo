<?php
declare(strict_types=1);

/**
 * Manual 플러그인에서 선택적으로 가져오는 번들 스킨 제작 가이드.
 *
 * 저장소의 상세 문서 docs/dev-guide/skin-development.md를 기준으로 하며,
 * 가져온 뒤에는 일반 매뉴얼과 동일하게 도메인 관리자가 편집할 수 있다.
 */
return [
    // 첫 페이지의 mublo-bundle 표식과 짝을 이룬다. 이 값이 없으면 이미 가져간
    // 설치본은 번들을 고쳐도 영원히 옛 내용을 그대로 둔다(ManualService::refreshOutdatedBundle).
    'version' => 1,
    'book' => [
        'title' => 'Mublo 스킨 제작 가이드',
        'slug' => 'skin-development',
        'description' => '콘텐츠 스킨부터 프레임·블록·게시판·쇼핑몰 스킨까지 실제 파일을 만들며 익히는 개발자 가이드',
        'sort_order' => 0,
        'is_active' => 1,
    ],
    'pages' => [
        [
            'key' => 'overview',
            'title' => '스킨 구조 이해하기',
            'slug' => 'overview',
            'sort_order' => 10,
            'content' => <<<'HTML'
<!-- mublo-bundle:skin-development:v1 -->
<p>Mublo 스킨은 적용 범위에 따라 콘텐츠, 프레임, 블록, Package 스킨으로 나뉩니다. 처음에는 한 파일만 바꿀 수 있는 <strong>Front 콘텐츠 스킨</strong>으로 시작하는 것이 좋습니다.</p>
<table>
<thead><tr><th>종류</th><th>경로</th><th>선택 위치</th></tr></thead>
<tbody>
<tr><td>Front 콘텐츠</td><td><code>views/Front/{Group}/{skin}/</code></td><td>환경설정 &gt; 테마</td></tr>
<tr><td>Front 프레임</td><td><code>views/Front/frame/{skin}/</code></td><td>환경설정 &gt; 테마</td></tr>
<tr><td>Core 블록</td><td><code>views/Block/{type}/{skin}/</code></td><td>블록 설정</td></tr>
<tr><td>Package 화면</td><td><code>packages/{Package}/views/Front/.../{skin}/</code></td><td>Package 설정</td></tr>
</tbody>
</table>
<blockquote><p>커스텀 콘텐츠 스킨에 없는 파일은 <code>basic</code>의 같은 파일로 자동 폴백됩니다. 기본 스킨 전체를 복사하지 말고 바꿀 파일만 만드십시오.</p></blockquote>
HTML,
        ],
        [
            'key' => 'first-content-skin',
            'title' => '첫 로그인 스킨 만들기',
            'slug' => 'first-content-skin',
            'sort_order' => 20,
            'content' => <<<'HTML'
<p><code>starter</code>라는 Auth 스킨으로 로그인 화면 하나만 교체해 봅니다.</p>
<pre><code>views/Front/Auth/starter/
├── Login.php
└── _assets/
    └── css/login.css</code></pre>
<p><code>Login.php</code>에서는 레이아웃과 CSS를 먼저 선언하고, 공통 정보는 <code>$mublo</code>에서 읽습니다.</p>
<pre><code>&lt;?php
$this-&gt;layout(['header' =&gt; false, 'footer' =&gt; false]);
$this-&gt;assets-&gt;addCss('/serve/front/view/auth/starter/css/login.css');

$siteName = $mublo['site']['config']['site_title'] ?? 'MUBLO';
$isEmailId = $useEmailAsUserId ?? false;
?&gt;

&lt;main class="starter-login"&gt;
    &lt;h1&gt;&lt;?= e($siteName) ?&gt; 로그인&lt;/h1&gt;
    &lt;form id="login-form" autocomplete="off"&gt;
        &lt;input type="hidden" name="redirect" value="&lt;?= e($redirect ?? '/') ?&gt;"&gt;
        &lt;input name="user_id" type="&lt;?= $isEmailId ? 'email' : 'text' ?&gt;" required&gt;
        &lt;input name="password" type="password" required&gt;
        &lt;button type="button" class="mublo-submit"
                data-target="/login" data-callback="starterLoginComplete"&gt;
            로그인
        &lt;/button&gt;
    &lt;/form&gt;
&lt;/main&gt;</code></pre>
<p>관리자 <strong>환경설정 &gt; 테마 &gt; 프론트 콘텐츠 스킨 설정</strong>에서 Auth 스킨을 <code>starter</code>로 선택하고, 로그아웃 상태로 <code>/login</code>을 확인합니다.</p>
HTML,
        ],
        [
            'key' => 'data-contract',
            'title' => '$mublo 데이터 계약',
            'slug' => 'data-contract',
            'sort_order' => 30,
            'content' => <<<'HTML'
<p>모든 Front 프레임·콘텐츠·블록·확장 삽입 뷰에는 같은 <code>$mublo</code> 구조가 전달됩니다.</p>
<pre><code>$site       = $mublo['site'];
$viewer     = $mublo['viewer'];
$request    = $mublo['request'];
$navigation = $mublo['navigation'];
$theme      = $mublo['theme'];
$security   = $mublo['security'];</code></pre>
<p>회원 정보는 사용 가능 여부부터 확인합니다.</p>
<pre><code>&lt;?php if ($mublo['viewer']['available']
    &amp;&amp; $mublo['viewer']['authenticated']): ?&gt;
    &lt;strong&gt;&lt;?= e($mublo['viewer']['member']['displayName'] ?? '') ?&gt;&lt;/strong&gt;
&lt;?php endif; ?&gt;</code></pre>
<p>관리자 미리보기와 캐시 가능한 블록에서는 회원·요청·CSRF 정보가 비활성화될 수 있습니다. 스킨에서 서비스, DB, 세션, DI 컨테이너를 직접 조회하지 마십시오.</p>
HTML,
        ],
        [
            'key' => 'payload-and-assets',
            'title' => '화면 데이터와 에셋 사용하기',
            'slug' => 'payload-and-assets',
            'sort_order' => 40,
            'content' => <<<'HTML'
<p><code>$mublo</code>는 공통 정보만 담당합니다. 게시글의 <code>$article</code>, 상품의 <code>$product</code>, 목록의 <code>$items</code>처럼 화면 고유 데이터는 이름 있는 변수로 전달됩니다.</p>
<ol>
<li>같은 화면의 <code>basic</code> 스킨 상단 docblock을 확인합니다.</li>
<li>Board는 <code>ArticlePresenter</code>, Shop은 <code>ProductPresenter</code>의 공개 필드를 사용합니다.</li>
<li>사용자 입력은 <code>e()</code>로 이스케이프하고 빈 상태를 처리합니다.</li>
</ol>
<pre><code>&lt;?php foreach ($items ?? [] as $item): ?&gt;
    &lt;a href="&lt;?= e($item['url'] ?? '#') ?&gt;"&gt;
        &lt;?= $item['title_safe'] ?? '' ?&gt;
    &lt;/a&gt;
&lt;?php endforeach; ?&gt;</code></pre>
<p>CSS와 JavaScript는 AssetManager로 등록합니다.</p>
<pre><code>$this-&gt;assets-&gt;addCss('/serve/front/view/auth/starter/css/login.css');
$this-&gt;assets-&gt;addJs('/serve/front/view/auth/starter/js/login.js');</code></pre>
HTML,
        ],
        [
            'key' => 'frame-skin',
            'title' => '프레임 스킨 만들기',
            'slug' => 'frame-skin',
            'sort_order' => 50,
            'content' => <<<'HTML'
<p>사이트 전체 셸은 Front 프레임 스킨이 담당합니다.</p>
<pre><code>views/Front/frame/starter/
├── Head.php
├── Header.php
├── LayoutOpen.php
├── LayoutClose.php
├── Footer.php
├── Foot.php
└── _assets/
    ├── css/front.css
    └── js/front.js</code></pre>
<p>처음에는 <code>basic</code> 프레임을 기준으로 시작하고 다음 에셋 마커를 유지하십시오.</p>
<pre><code>&lt;!-- MUBLO_CSS_component --&gt;
&lt;!-- MUBLO_CSS --&gt;
&lt;!-- MUBLO_JS --&gt;</code></pre>
<p>Header와 Footer의 사이트·메뉴·회원 정보도 <code>$mublo</code>에서 읽습니다. 프레임 내부에서 서비스를 다시 호출하지 않습니다.</p>
HTML,
        ],
        [
            'key' => 'block-skin',
            'title' => '블록 스킨 만들기',
            'slug' => 'block-skin',
            'sort_order' => 60,
            'content' => <<<'HTML'
<p>HTML 블록에 <code>card</code> 스킨을 추가한다면 다음 파일을 만듭니다.</p>
<pre><code>views/Block/html/card/
├── card.php
└── style.css</code></pre>
<pre><code>&lt;?php
$assets?-&gt;addCss('/serve/block/html/card/style.css');
?&gt;
&lt;section class="html-card"&gt;
    &lt;?php include $titlePartial; ?&gt;
    &lt;div class="html-card__body"&gt;&lt;?= $html ?&gt;&lt;/div&gt;
&lt;/section&gt;</code></pre>
<p>캐시 가능한 블록에서는 방문자별 데이터가 제거됩니다. 회원이나 현재 요청에 따라 출력되는 새 콘텐츠 타입은 <code>BlockRegistry</code> 등록 시 <code>options: ['noCache' =&gt; true]</code>를 지정해야 합니다.</p>
HTML,
        ],
        [
            'key' => 'package-skins',
            'title' => '게시판·쇼핑몰 스킨',
            'slug' => 'package-skins',
            'sort_order' => 70,
            'content' => <<<'HTML'
<p>게시판과 쇼핑몰도 같은 원칙을 사용하지만 파일은 Package가 소유합니다.</p>
<pre><code>packages/Board/views/Front/Board/{skin}/
├── List.php
├── View.php
├── Write.php
└── Password.php

packages/Shop/views/Front/Product/{skin}/
├── List.php
├── View.php
└── _assets/</code></pre>
<ul>
<li>Board 스킨은 <code>Password.php</code>(비회원 글 비밀번호 확인)까지 네 파일입니다.</li>
<li>Board 스킨은 관리자 게시판 설정의 <strong>게시판 스킨</strong>에서 선택합니다.</li>
<li>Shop 스킨은 관리자 Shop 설정의 기능별 콘텐츠 스킨에서 선택합니다.</li>
<li>Board 데이터는 <code>ArticlePresenter</code>, Shop 데이터는 <code>ProductPresenter</code> 계약을 따릅니다.</li>
<li>필요한 정보가 없다면 스킨에서 Repository를 호출하지 말고 Presenter 또는 Controller payload에 추가합니다.</li>
</ul>
HTML,
        ],
        [
            'key' => 'checklist',
            'title' => '배포 전 체크리스트',
            'slug' => 'checklist',
            'sort_order' => 80,
            'content' => <<<'HTML'
<ul>
<li>바꿀 파일만 추가하고 <code>basic</code> 폴백을 확인했습니다.</li>
<li><code>$mublo</code>와 이름 있는 화면 payload만 사용합니다.</li>
<li>사용자 입력은 <code>e()</code>로 이스케이프합니다.</li>
<li>CSS·JS는 <code>/serve/...</code> 경로와 AssetManager로 등록합니다.</li>
<li>빈 목록, 비로그인, 관리자 미리보기, 모바일 화면을 확인합니다.</li>
<li>프레임의 <code>MUBLO_CSS</code>·<code>MUBLO_JS</code> 마커를 유지합니다.</li>
<li>캐시 블록에서 회원·요청 데이터에 의존하지 않습니다.</li>
<li><code>composer check</code>를 통과합니다.</li>
</ul>
<p>저장소에서 개발할 때는 <code>docs/dev-guide/skin-development.md</code>와 <code>docs/reference/front-view-data-contract.md</code>를 함께 확인하십시오.</p>
HTML,
        ],
    ],
];
