<?php
/**
 * 쇼핑몰 설정 - SEO / 고객센터
 *
 * @var array $config 쇼핑몰 설정
 */
?>
<!-- SEO 설정 -->
<div class="card mb-4">
    <div class="card-hero">
        <i class="bi bi-search text-pastel-blue"></i>
        <span>SEO 설정</span>
    </div>
    <div class="card-body">
        <div class="row gy-3 gy-md-0">
            <div class="col-12 col-md-6">
                <label for="seo_keyword" class="form-label">SEO 키워드</label>
                <input type="text" name="formData[seo_keyword]" id="seo_keyword" class="form-control"
                       value="<?= htmlspecialchars($config['seo_keyword'] ?? '') ?>">
                <div class="form-text">쉼표로 구분 (예: 쇼핑몰,온라인스토어)</div>
            </div>
            <div class="col-12 col-md-6">
                <label for="seo_description" class="form-label">SEO 설명</label>
                <textarea name="formData[seo_description]" id="seo_description" class="form-control" rows="2"><?= htmlspecialchars($config['seo_description'] ?? '') ?></textarea>
                <div class="form-text">검색 결과에 표시되는 설명</div>
            </div>
        </div>
    </div>
</div>

<!-- 고객센터 -->
<div class="card mb-4">
    <div class="card-hero">
        <i class="bi bi-headset text-pastel-green"></i>
        <span>고객센터</span>
    </div>
    <div class="card-body">
        <div class="row gy-3">
            <!-- 왼쪽: 전화 + 카카오 -->
            <div class="col-12 col-md-6 d-flex flex-column gap-3">
                <div>
                    <label for="customer_tel" class="form-label">고객센터 전화</label>
                    <input type="text" name="formData[customer_tel]" id="customer_tel" class="form-control"
                           value="<?= htmlspecialchars($config['customer_tel'] ?? '') ?>" placeholder="02-1234-5678">
                </div>
                <div>
                    <label for="kakao_chat_url" class="form-label">카카오톡 상담 URL</label>
                    <input type="url" name="formData[kakao_chat_url]" id="kakao_chat_url" class="form-control"
                           value="<?= htmlspecialchars($config['kakao_chat_url'] ?? '') ?>" placeholder="https://pf.kakao.com/...">
                </div>
            </div>
            <!-- 오른쪽: 운영시간 (왼쪽 높이에 맞춤) -->
            <div class="col-12 col-md-6 d-flex flex-column">
                <label for="customer_time" class="form-label">운영시간</label>
                <textarea name="formData[customer_time]" id="customer_time" class="form-control flex-grow-1"
                          placeholder="예: 평일 09:00~18:00 (점심 12:00~13:00)&#10;토요일 09:00~13:00&#10;일요일/공휴일 휴무" rows="4"><?= htmlspecialchars($config['customer_time'] ?? '') ?></textarea>
            </div>
        </div>
    </div>
</div>
