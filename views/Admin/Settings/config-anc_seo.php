<?php
/**
 * 로고 및 SEO 설정 섹션
 *
 * @var array $seoConfig SEO 설정 데이터
 * @var array $snsTypes SNS 타입 목록 (SnsHelper::getTypes())
 */

use Mublo\Helper\Sns\SnsHelper;

$snsTypes = $snsTypes ?? SnsHelper::getTypes();
$snsChannels = $seoConfig['sns_channels'] ?? [];
?>
<!-- 로고 설정 -->
<div class="card mb-4">
    <div class="card-hero">
        <i class="bi bi-image text-pastel-blue"></i>
        <span>로고 설정</span>
    </div>
    <div class="card-body">
        <div class="row gy-4">
            <!-- PC용 로고 -->
            <div class="col-12 col-md-6">
                <label class="form-label">PC용 로고</label>
                <div class="input-group">
                    <input type="file"
                           name="fileData[logo_pc]"
                           id="file_logo_pc"
                           class="form-control"
                           accept="image/jpeg,image/png,image/gif,image/webp">
                </div>
                <input type="hidden" name="formData[seo][logo_pc]" id="logo_pc" value="">
                <div class="form-text">권장: 높이 40~60px, PNG 투명 배경</div>
                <div id="preview_logo_pc" class="mt-2"></div>
            </div>

            <!-- 모바일용 로고 -->
            <div class="col-12 col-md-6">
                <label class="form-label">모바일용 로고</label>
                <div class="input-group">
                    <input type="file"
                           name="fileData[logo_mobile]"
                           id="file_logo_mobile"
                           class="form-control"
                           accept="image/jpeg,image/png,image/gif,image/webp">
                </div>
                <input type="hidden" name="formData[seo][logo_mobile]" id="logo_mobile" value="">
                <div class="form-text">권장: 높이 30~40px (비워두면 PC용 사용)</div>
                <div id="preview_logo_mobile" class="mt-2"></div>
            </div>

            <!-- 파비콘 -->
            <div class="col-12 col-md-6">
                <label class="form-label">파비콘 (브라우저 표시용)</label>
                <div class="input-group">
                    <input type="file"
                           name="fileData[favicon]"
                           id="file_favicon"
                           class="form-control"
                           accept=".ico,.png,image/x-icon,image/png">
                </div>
                <input type="hidden" name="formData[seo][favicon]" id="favicon" value="">
                <div class="form-text">권장: 32x32 또는 16x16, ICO/PNG</div>
                <div id="preview_favicon" class="mt-2"></div>
            </div>

            <!-- 앱 아이콘 (홈화면 추가용) -->
            <div class="col-12 col-md-6">
                <label class="form-label">앱 아이콘 (홈화면 추가용)</label>
                <div class="input-group">
                    <input type="file"
                           name="fileData[app_icon]"
                           id="file_app_icon"
                           class="form-control"
                           accept="image/png">
                </div>
                <input type="hidden" name="formData[seo][app_icon]" id="app_icon" value="">
                <div class="form-text">권장: 192x192 정사각형, PNG</div>
                <div id="preview_app_icon" class="mt-2"></div>
            </div>

            <!-- OG 이미지 -->
            <div class="col-12 col-md-6">
                <label class="form-label">OG 이미지 (SNS 공유용)</label>
                <div class="input-group">
                    <input type="file"
                           name="fileData[og_image]"
                           id="file_og_image"
                           class="form-control"
                           accept="image/jpeg,image/png,image/webp">
                </div>
                <input type="hidden" name="formData[seo][og_image]" id="og_image" value="">
                <div class="form-text">권장: 1200x630px, SNS 공유 시 표시</div>
                <div id="preview_og_image" class="mt-2"></div>
            </div>
        </div>
    </div>
</div>

<!-- 메타 태그 설정 -->
<div class="card mb-4">
    <div class="card-hero">
        <i class="bi bi-tags text-pastel-green"></i>
        <span>메타 태그 설정</span>
    </div>
    <div class="card-body">
        <div class="row gy-3 gy-md-0 mb-3">
            <div class="col-12 col-md-6">
                <label for="meta_title" class="form-label">기본 메타 타이틀</label>
                <input type="text" name="formData[seo][meta_title]" value="" id="meta_title" class="form-control" placeholder="페이지 제목 | 사이트명">
                <div class="form-text">검색엔진에 표시될 기본 타이틀</div>
            </div>
        </div>
        <div class="row gy-3 gy-md-0 mb-3">
            <div class="col-12">
                <label for="meta_description" class="form-label">기본 메타 설명</label>
                <textarea name="formData[seo][meta_description]" id="meta_description" class="form-control" rows="2" placeholder="사이트에 대한 간단한 설명 (160자 이내 권장)"></textarea>
            </div>
        </div>
        <div class="row gy-3 gy-md-0">
            <div class="col-12">
                <label for="meta_keywords" class="form-label">기본 메타 키워드</label>
                <input type="text" name="formData[seo][meta_keywords]" value="" id="meta_keywords" class="form-control" placeholder="키워드1, 키워드2, 키워드3">
                <div class="form-text">쉼표로 구분하여 입력</div>
            </div>
        </div>
    </div>
</div>

<!-- 추적 코드 (외부 픽셀) -->
<div class="card mb-4">
    <div class="card-hero">
        <i class="bi bi-bar-chart text-pastel-orange"></i>
        <span>추적 코드</span>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">광고 플랫폼의 추적 픽셀 ID를 입력하면 방문/전환 추적이 자동으로 활성화됩니다.</p>
        <div class="row gy-3">
            <div class="col-12 col-md-6 col-lg-3">
                <label for="google_analytics" class="form-label">Google Analytics (GA4)</label>
                <input type="text" name="formData[seo][google_analytics]" value="" id="google_analytics" class="form-control" placeholder="G-XXXXXXXXXX">
            </div>
            <div class="col-12 col-md-6 col-lg-3">
                <label for="meta_pixel_id" class="form-label">Meta (Facebook) Pixel</label>
                <input type="text" name="formData[seo][meta_pixel_id]" value="" id="meta_pixel_id" class="form-control" placeholder="픽셀 ID">
            </div>
            <div class="col-12 col-md-6 col-lg-3">
                <label for="kakao_pixel_id" class="form-label">카카오 픽셀</label>
                <input type="text" name="formData[seo][kakao_pixel_id]" value="" id="kakao_pixel_id" class="form-control" placeholder="픽셀 ID">
            </div>
            <div class="col-12 col-md-6 col-lg-3">
                <label for="naver_analytics_id" class="form-label">네이버 애널리틱스</label>
                <input type="text" name="formData[seo][naver_analytics_id]" value="" id="naver_analytics_id" class="form-control" placeholder="s_xxxxxxxxxxxx">
            </div>
        </div>
    </div>
</div>

<!-- 사이트 인증 -->
<div class="card mb-4">
    <div class="card-hero">
        <i class="bi bi-patch-check text-pastel-purple"></i>
        <span>사이트 인증</span>
    </div>
    <div class="card-body">
        <div class="row gy-3 gy-md-0">
            <div class="col-12 col-md-6">
                <label for="google_site_verification" class="form-label">Google Search Console</label>
                <input type="text" name="formData[seo][google_site_verification]" value="" id="google_site_verification" class="form-control" placeholder="인증 코드">
            </div>
            <div class="col-12 col-md-6">
                <label for="naver_site_verification" class="form-label">Naver Search Advisor</label>
                <input type="text" name="formData[seo][naver_site_verification]" value="" id="naver_site_verification" class="form-control" placeholder="인증 코드">
            </div>
        </div>
    </div>
</div>

<!-- 커스텀 스크립트 -->
<div class="card mb-4">
    <div class="card-hero">
        <i class="bi bi-code-slash text-pastel-sky"></i>
        <span>커스텀 스크립트</span>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">Google Tag Manager 등 직접 코드 삽입이 필요한 경우 사용합니다. <code>&lt;script&gt;</code> 태그를 포함하여 입력하세요.</p>
        <div class="row gy-3">
            <div class="col-12">
                <label for="custom_head_script" class="form-label">&lt;head&gt; 삽입 스크립트</label>
                <textarea name="formData[seo][custom_head_script]" id="custom_head_script" class="form-control font-monospace" rows="4" placeholder="<script>...</script>"><?= htmlspecialchars($seoConfig['custom_head_script'] ?? '') ?></textarea>
                <div class="form-text">페이지 &lt;head&gt; 영역 끝에 삽입됩니다.</div>
            </div>
            <div class="col-12">
                <label for="custom_body_script" class="form-label">&lt;/body&gt; 전 스크립트</label>
                <textarea name="formData[seo][custom_body_script]" id="custom_body_script" class="form-control font-monospace" rows="4" placeholder="<script>...</script>"><?= htmlspecialchars($seoConfig['custom_body_script'] ?? '') ?></textarea>
                <div class="form-text">페이지 &lt;/body&gt; 직전에 삽입됩니다.</div>
            </div>
        </div>
    </div>
</div>

<!-- robots.txt -->
<div class="card mb-4">
    <div class="card-hero">
        <i class="bi bi-robot text-pastel-green"></i>
        <span>robots.txt</span>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">
            비워두면 <code>public/robots.txt</code> 파일을 그대로 사용합니다.
            내용을 입력하면 이 설정이 우선 적용됩니다.
        </p>
        <textarea name="formData[seo][robots_txt]"
                  id="robots_txt"
                  class="form-control font-monospace"
                  rows="8"
                  placeholder="User-agent: *&#10;Disallow: /admin/&#10;Disallow: /install/&#10;Sitemap: https://example.com/sitemap.xml"></textarea>
    </div>
</div>

<!-- SNS 채널 -->
<div class="card mb-4">
    <div class="card-hero">
        <i class="bi bi-share text-pastel-red"></i>
        <span>SNS 채널</span>
        <button type="button" class="btn btn-xs btn-outline-primary ms-auto" id="btn-add-sns">
            <i class="bi bi-plus-lg"></i> 추가
        </button>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">연동할 SNS 채널을 추가하세요. 사이트 푸터 등에 아이콘으로 표시됩니다.</p>

        <div id="sns-channels-container">
            <!-- 동적으로 추가되는 SNS 채널 행 -->
        </div>

        <div id="sns-empty-message" class="text-center text-muted py-3" style="display: none;">
            <i class="bi bi-info-circle"></i> 등록된 SNS 채널이 없습니다. [추가] 버튼을 눌러 추가하세요.
        </div>
    </div>
</div>

<!-- SNS 채널 행 템플릿 -->
<template id="sns-row-template">
    <div class="sns-channel-row row gy-2 mb-2 align-items-center">
        <div class="col-auto">
            <span class="drag-handle text-muted" style="cursor:move" title="드래그하여 순서 변경">
                <i class="bi bi-grip-vertical fs-5"></i>
            </span>
        </div>
        <div class="col-12 col-md-4">
            <select name="formData[seo][sns_channels][type][]" class="form-select sns-type-select">
                <option value="">SNS 선택</option>
                <?php foreach ($snsTypes as $type => $info): ?>
                <option value="<?= htmlspecialchars($type) ?>"
                        data-icon="<?= htmlspecialchars($info['icon']) ?>"
                        data-color="<?= htmlspecialchars($info['color']) ?>"
                        data-placeholder="<?= htmlspecialchars($info['placeholder']) ?>">
                    <?= htmlspecialchars($info['label']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md">
            <div class="input-group">
                <span class="input-group-text sns-icon-preview"><i class="bi bi-link-45deg"></i></span>
                <input type="url"
                       name="formData[seo][sns_channels][url][]"
                       class="form-control sns-url-input"
                       placeholder="https://">
            </div>
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-outline-danger btn-sm btn-remove-sns" title="삭제">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    </div>
</template>

<script>
// SNS 타입 데이터 (JavaScript용)
var snsTypesData = <?= SnsHelper::getTypesJson() ?>;
// 기존 SNS 채널 데이터
var existingSnsChannels = <?= json_encode($snsChannels, JSON_UNESCAPED_UNICODE) ?>;
</script>
