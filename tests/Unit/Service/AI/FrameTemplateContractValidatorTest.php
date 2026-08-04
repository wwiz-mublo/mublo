<?php
namespace Tests\Unit\Service\AI;

use Mublo\Service\AI\FrameTemplateContractValidator;
use PHPUnit\Framework\TestCase;

/**
 * 프레임 템플릿 계약 검증기 테스트 (개선 계획 §7·§13.3)
 */
class FrameTemplateContractValidatorTest extends TestCase
{
    private const TOKENS = [
        ['name' => 'site_name', 'kind' => 'variable', 'label' => '사이트명'],
        ['name' => 'logo_url', 'kind' => 'variable', 'label' => '로고 URL'],
        ['name' => 'logo_mobile_url', 'kind' => 'variable', 'label' => '모바일 로고 URL'],
        ['name' => 'menu_main', 'kind' => 'slot', 'label' => '메인 메뉴'],
        ['name' => 'mobile_panel', 'kind' => 'slot', 'label' => '모바일 패널'],
        ['name' => 'theme_switch', 'kind' => 'slot', 'label' => '테마 스위치'],
    ];

    private FrameTemplateContractValidator $v;

    protected function setUp(): void
    {
        parent::setUp();
        $this->v = new FrameTemplateContractValidator();
    }

    private const VALID_HEADER = '<header class="mublo-header"><h1>{{site_name}}</h1>'
        . '<nav>{{menu_main}}</nav><button id="mubloPanelToggle"></button></header>{{mobile_panel}}';

    public function testValidHeaderPasses(): void
    {
        $r = $this->v->validate('header', self::VALID_HEADER, self::TOKENS);

        $this->assertTrue($r->isValid(), implode(' / ', $r->errors));
        $this->assertContains('site_name', $r->usedTokens);
        $this->assertContains('mobile_panel', $r->usedTokens);
    }

    public function testSlotTokenInAttributeIsError(): void
    {
        $html = str_replace('<nav>{{menu_main}}</nav>', '<img src="{{mobile_panel}}" alt="x">', self::VALID_HEADER);
        $r = $this->v->validate('header', $html, self::TOKENS);

        $this->assertFalse($r->isValid());
        $this->assertStringContainsString('속성', implode(' ', $r->errors));
    }

    public function testSlotTokenMixedWithTextIsError(): void
    {
        $html = str_replace('<nav>{{menu_main}}</nav>', '<nav>메뉴: {{menu_main}} 입니다</nav>', self::VALID_HEADER);
        $r = $this->v->validate('header', $html, self::TOKENS);

        $this->assertFalse($r->isValid());
        $this->assertStringContainsString('독립', implode(' ', $r->errors));
    }

    public function testLogoTokenAllowedInImgSrcOnly(): void
    {
        $ok = $this->v->validate('header',
            str_replace('<h1>{{site_name}}</h1>', '<img src="{{logo_url}}" alt="로고">', self::VALID_HEADER),
            self::TOKENS);
        $this->assertTrue($ok->isValid(), implode(' / ', $ok->errors));

        $bad = $this->v->validate('header',
            str_replace('<h1>{{site_name}}</h1>', '<img src="{{site_name}}" alt="x">', self::VALID_HEADER),
            self::TOKENS);
        $this->assertFalse($bad->isValid());
        $this->assertStringContainsString('로고 토큰', implode(' ', $bad->errors));
    }

    public function testUnknownTokenIsErrorNotSilentErase(): void
    {
        $r = $this->v->validate('header', self::VALID_HEADER . '<p>{{ghost.token}}</p>', self::TOKENS);

        $this->assertFalse($r->isValid());
        $this->assertStringContainsString('등록되지 않은', implode(' ', $r->errors));
    }

    public function testDuplicateSlotIsError(): void
    {
        $r = $this->v->validate('header', self::VALID_HEADER . '<div>{{menu_main}}</div><div>{{menu_main}}</div>', self::TOKENS);

        $this->assertFalse($r->isValid());
        $this->assertStringContainsString('한 번만', implode(' ', $r->errors));
    }

    public function testHeaderMobilePairRequired(): void
    {
        $noToggle = $this->v->validate('header', '<header class="mublo-header">{{menu_main}}</header>{{mobile_panel}}', self::TOKENS);
        $this->assertFalse($noToggle->isValid());

        $noPanel = $this->v->validate('header', '<header class="mublo-header"><button id="mubloPanelToggle"></button></header>', self::TOKENS);
        $this->assertFalse($noPanel->isValid());
    }

    public function testFooterThemeSwitchRequired(): void
    {
        $bad = $this->v->validate('footer', '<footer class="mublo-footer">{{site_name}}</footer>', self::TOKENS);
        $this->assertFalse($bad->isValid());
        $this->assertStringContainsString('theme_switch', implode(' ', $bad->errors));

        $ok = $this->v->validate('footer', '<footer class="mublo-footer">{{site_name}}{{theme_switch}}</footer>', self::TOKENS);
        $this->assertTrue($ok->isValid(), implode(' / ', $ok->errors));
    }

    public function testMissingSkinClassIsWarningNotError(): void
    {
        $r = $this->v->validate('header',
            '<header class="custom"><button id="mubloPanelToggle"></button></header>{{mobile_panel}}',
            self::TOKENS);

        $this->assertTrue($r->isValid());
        $this->assertStringContainsString('.mublo-header', implode(' ', $r->warnings));
    }

    public function testExternalImgSrcIsError(): void
    {
        $r = $this->v->validate('header',
            str_replace('<h1>{{site_name}}</h1>', '<img src="https://evil.test/x.png" alt="x">', self::VALID_HEADER),
            self::TOKENS);

        $this->assertFalse($r->isValid());
        $this->assertStringContainsString('/storage/', implode(' ', $r->errors));
    }

    public function testTokensInCommentsAreIgnored(): void
    {
        $r = $this->v->validate('header',
            '<!-- {{ghost.doc}} 안내 --> ' . self::VALID_HEADER,
            self::TOKENS);

        $this->assertTrue($r->isValid(), '주석 안 토큰은 렌더러가 치환하지 않으므로 검증 대상 아님');
    }
}
