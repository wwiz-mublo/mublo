<?php
/**
 * 회사 정보 설정 섹션
 *
 * formData[company] 그룹으로 저장
 */
?>
<!-- 회사 기본 정보 -->
<div class="card mb-4">
    <div class="card-hero">
        <i class="bi bi-building text-pastel-blue"></i>
        <span>회사 기본 정보</span>
    </div>
    <div class="card-body">
        <div class="row gy-3 gy-md-0 mb-3">
            <div class="col-12 col-sm-6 col-md-3">
                <label for="company_name" class="form-label">회사명</label>
                <input type="text" name="formData[company][name]" value="" id="company_name" class="form-control" placeholder="회사명">
            </div>
            <div class="col-12 col-sm-6 col-md-3">
                <label for="company_owner" class="form-label">대표자명</label>
                <input type="text" name="formData[company][owner]" value="" id="company_owner" class="form-control" placeholder="대표자명">
            </div>
            <div class="col-12 col-sm-6 col-md-3">
                <label for="company_tel" class="form-label">대표 전화번호</label>
                <input type="text" name="formData[company][tel]" value="" id="company_tel" class="form-control" placeholder="02-1234-5678">
            </div>
            <div class="col-12 col-sm-6 col-md-3">
                <label for="company_fax" class="form-label">팩스번호</label>
                <input type="text" name="formData[company][fax]" value="" id="company_fax" class="form-control" placeholder="02-1234-5679">
            </div>
        </div>
        <div class="row gy-3 gy-md-0">
            <div class="col-12 col-sm-6 col-md-3">
                <label for="company_email" class="form-label">대표 이메일</label>
                <input type="email" name="formData[company][email]" value="" id="company_email" class="form-control" placeholder="contact@example.com">
            </div>
            <div class="col-12 col-sm-6 col-md-3">
                <label for="company_business_number" class="form-label">사업자등록번호</label>
                <input type="text" name="formData[company][business_number]" value="" id="company_business_number" class="form-control" placeholder="123-45-67890">
            </div>
            <div class="col-12 col-sm-6 col-md-3">
                <label for="company_tongsin_number" class="form-label">통신판매업 신고번호</label>
                <input type="text" name="formData[company][tongsin_number]" value="" id="company_tongsin_number" class="form-control" placeholder="제2024-서울강남-0000호">
            </div>
        </div>
    </div>
</div>

<!-- 회사 주소 -->
<div class="card mb-4">
    <div class="card-hero">
        <i class="bi bi-geo-alt text-pastel-green"></i>
        <span>회사 주소</span>
    </div>
    <div class="card-body">
        <div class="row gy-2">
            <div class="col-12 col-sm-3">
                <label for="company_zipcode" class="form-label">우편번호</label>
                <div class="row g-2">
                    <div class="col">
                        <input type="text" name="formData[company][zipcode]" id="company_zipcode" class="form-control" placeholder="우편번호" readonly>
                    </div>
                    <div class="col-auto">
                        <button type="button" class="btn btn-outline-secondary" onclick="MubloAddress.open('frm', 'formData[company][zipcode]', 'formData[company][address]', 'formData[company][address_detail]')">
                            <i class="bi bi-search"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-9">
                <label class="form-label">주소</label>
                <div class="row gy-2 gy-sm-0">
                    <div class="col-12 col-md-6">
                        <input type="text" name="formData[company][address]" id="company_address" class="form-control" placeholder="기본주소">
                    </div>
                    <div class="col-12 col-md-6">
                        <input type="text" name="formData[company][address_detail]" id="company_address_detail" class="form-control" placeholder="상세주소">
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 고객센터 안내 -->
<div class="card mb-4">
    <div class="card-hero">
        <i class="bi bi-headset text-pastel-orange"></i>
        <span>고객센터 안내</span>
    </div>
    <div class="card-body">
        <div class="row gy-3">
            <div class="col-12 col-sm-6 col-md-4">
                <label for="company_cs_tel" class="form-label">고객센터 전화번호</label>
                <input type="text" name="formData[company][cs_tel]" value="" id="company_cs_tel" class="form-control" placeholder="1588-0000">
                <div class="form-text">미입력 시 대표 전화번호가 사용됩니다.</div>
            </div>
            <div class="col-12 col-sm-6 col-md-8">
                <label for="company_cs_time" class="form-label">상담시간 안내</label>
                <textarea name="formData[company][cs_time]" id="company_cs_time" class="form-control" rows="3"
                          placeholder="평일: 09:00 ~ 18:00&#10;토요일: 09:00 ~ 13:00&#10;일요일/공휴일 휴무"></textarea>
            </div>
        </div>
    </div>
</div>

<!-- 개인정보 보호 책임자 -->
<div class="card mb-4">
    <div class="card-hero">
        <i class="bi bi-shield-check text-pastel-purple"></i>
        <span>개인정보 보호 책임자</span>
    </div>
    <div class="card-body">
        <div class="row gy-3 gy-md-0">
            <div class="col-12 col-sm-6 col-md-4">
                <label for="company_privacy_officer" class="form-label">책임자 이름</label>
                <input type="text" name="formData[company][privacy_officer]" value="" id="company_privacy_officer" class="form-control" placeholder="홍길동">
            </div>
            <div class="col-12 col-sm-6 col-md-4">
                <label for="company_privacy_email" class="form-label">책임자 이메일</label>
                <input type="email" name="formData[company][privacy_email]" value="" id="company_privacy_email" class="form-control" placeholder="privacy@example.com">
            </div>
        </div>
    </div>
</div>
