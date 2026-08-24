<?php
/**
 * 상품 등록/수정 폼
 *
 * @var string $pageTitle 페이지 제목
 * @var bool $isEdit 수정 모드 여부
 * @var array|null $product 상품 데이터
 * @var array $productImages 상품 이미지
 * @var array $productOptions 상품 옵션 [{option_name, option_type, is_required, values: [...]}]
 * @var array $productDetails 상품 상세설명 [{detail_id, detail_type, detail_value}]
 * @var array $productCombos 상품 조합 [{combo_id, combination_key, extra_price, stock_quantity}]
 * @var array|null $productNotice 상품정보제공고시 선택과 값
 * @var array $noticeTemplates 현재 상품정보제공고시 품목 양식
 * @var array $categories 카테고리 트리 (flat)
 * @var array $categoryTree 카테고리 트리 (계층형)
 * @var array $presets 상품옵션 프리셋 목록
 * @var array $shippingTemplates 배송 템플릿 목록
 * @var array $discountTypeOptions 할인 유형 옵션
 * @var array $rewardTypeOptions 적립 유형 옵션
 * @var array $optionModeOptions 옵션 모드 옵션
 * @var \Mublo\Contract\Member\MemberLevelDescriptor[] $memberLevels 회원 레벨 목록
 * @var string $listUrl 목록 복귀 URL (검색 상태 유지)
 */

$memberLevels = $memberLevels ?? [];
$listUrl = $listUrl ?? '/admin/shop/products';

// 레벨별 할인 설정 파싱
$discountLevelSettings = [];
if (!empty($product['discount_level_settings'])) {
    $decoded = is_string($product['discount_level_settings']) ? json_decode($product['discount_level_settings'], true) : $product['discount_level_settings'];
    $discountLevelSettings = is_array($decoded) ? $decoded : [];
}

// 레벨별 적립 설정 파싱
$rewardLevelSettings = [];
if (!empty($product['reward_level_settings'])) {
    $decoded = is_string($product['reward_level_settings']) ? json_decode($product['reward_level_settings'], true) : $product['reward_level_settings'];
    $rewardLevelSettings = is_array($decoded) ? $decoded : [];
}

$currentDiscountType = $product['discount_type'] ?? 'DEFAULT';
$currentRewardType = $product['reward_type'] ?? 'DEFAULT';

$anchor = [
    'basic'    => '기본 정보',
    'category' => '카테고리',
    'price'    => '가격',
    'option'   => '옵션/재고',
    'images'   => '이미지',
    'details'  => '상세설명',
    'notice'   => '상품정보제공고시',
    'shipping' => '배송',
    'extra'    => '추가 정보',
];
?>

<?= editor_css() ?>

<form id="productForm" enctype="multipart/form-data">
    <?php if ($isEdit && !empty($product['goods_id'])): ?>
        <input type="hidden" name="formData[goods_id]" value="<?= (int) $product['goods_id'] ?>">
    <?php endif; ?>

    <div class="page-container form-container">
        <div class="page-title">
            <div class="page-title-text">
                <h3><?= htmlspecialchars($pageTitle) ?></h3>
            </div>
            <div class="page-title-actions">
                <a href="<?= htmlspecialchars($listUrl) ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-list"></i> 목록
                </a>
                <button type="button" class="btn btn-sm btn-primary mublo-submit"
                    data-target="/admin/shop/products/store"
                    data-callback="productSaved">
                    <i class="bi bi-check-lg"></i> <?= $isEdit ? '수정' : '등록' ?>
                </button>
            </div>
        </div>

        <!-- Sticky Tab Navigation -->
        <div class="page-block sticky-spy">
            <div class="sticky-top">
                <nav id="product-nav" class="navbar">
                    <ul class="nav nav-tabs w-100">
                        <?php $isFirst = true; foreach ($anchor as $id => $label): ?>
                        <li class="nav-item">
                            <a class="nav-link <?= $isFirst ? 'active' : '' ?>" href="#<?= $id ?>"><?= $label ?></a>
                        </li>
                        <?php $isFirst = false; endforeach; ?>
                    </ul>
                </nav>
            </div>

            <div class="sticky-section" data-bs-spy="scroll" data-bs-target="#product-nav">

                <!-- ============================================ -->
                <!-- 1. 기본 정보 -->
                <!-- ============================================ -->
                <section id="basic" data-section="basic">
                    <h5 class="mb-4">기본 정보</h5>
                    <div class="card">
                        <div class="card-body">
                            <div class="row mb-3">
                                <label class="col-sm-2 col-form-label">상품명 <span class="text-danger">*</span></label>
                                <div class="col-sm-10">
                                    <input type="text" name="formData[goods_name]" class="form-control"
                                        value="<?= htmlspecialchars($product['goods_name'] ?? '') ?>" required>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <label class="col-sm-2 col-form-label">상품코드 <span class="text-danger">*</span></label>
                                <div class="col-sm-4">
                                    <input type="text" name="formData[item_code]" class="form-control"
                                        value="<?= htmlspecialchars($product['item_code'] ?? '') ?>"
                                        placeholder="미입력 시 자동 생성" required>
                                </div>
                                <label class="col-sm-2 col-form-label">슬러그</label>
                                <div class="col-sm-4">
                                    <input type="text" name="formData[goods_slug]" class="form-control"
                                        value="<?= htmlspecialchars($product['goods_slug'] ?? '') ?>"
                                        placeholder="SEO용 URL 슬러그">
                                </div>
                            </div>
                            <div class="row mb-3">
                                <label class="col-sm-2 col-form-label">배지</label>
                                <div class="col-sm-4">
                                    <input type="text" name="formData[goods_badge]" class="form-control"
                                        value="<?= htmlspecialchars($product['goods_badge'] ?? '') ?>"
                                        placeholder="NEW, SALE, BEST 등">
                                </div>
                                <label class="col-sm-2 col-form-label">판매 상태</label>
                                <div class="col-sm-4">
                                    <div class="form-check form-switch mt-2">
                                        <input type="hidden" name="formData[is_active]" value="0">
                                        <input type="checkbox" name="formData[is_active]" class="form-check-input"
                                            value="1" <?= ($product['is_active'] ?? 1) ? 'checked' : '' ?>>
                                        <label class="form-check-label">판매중</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- ============================================ -->
                <!-- 2. 카테고리 -->
                <!-- ============================================ -->
                <section id="category" data-section="category">
                    <h5 class="mb-4">카테고리</h5>
                    <div class="card">
                        <div class="card-body">
                            <div class="row mb-3">
                                <label class="col-sm-2 col-form-label">대표 카테고리</label>
                                <div class="col-sm-10">
                                    <input type="hidden" name="formData[category_code]" id="categoryCodeInput"
                                        value="<?= htmlspecialchars($product['category_code'] ?? '') ?>">
                                    <div id="category-primary" class="d-flex flex-wrap gap-2"></div>
                                    <div class="form-text">카테고리를 순서대로 선택하세요. 하위 카테고리가 있으면 자동으로 표시됩니다.</div>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <label class="col-sm-2 col-form-label">보조 카테고리</label>
                                <div class="col-sm-10">
                                    <input type="hidden" name="formData[category_code_extra]" id="categoryCodeExtraInput"
                                        value="<?= htmlspecialchars($product['category_code_extra'] ?? '') ?>">
                                    <div id="category-extra" class="d-flex flex-wrap gap-2"></div>
                                    <div class="form-text">선택사항. 상품을 추가 카테고리에도 노출할 수 있습니다.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- ============================================ -->
                <!-- 3. 가격 -->
                <!-- ============================================ -->
                <section id="price" data-section="price">
                    <h5 class="mb-4">가격</h5>
                    <div class="card">
                        <div class="card-body">
                            <div class="row mb-3">
                                <label class="col-sm-2 col-form-label">판매가 <span class="text-danger">*</span></label>
                                <div class="col-sm-4">
                                    <div class="input-group">
                                        <input type="number" name="formData[display_price]" class="form-control"
                                            value="<?= $product['display_price'] ?? 0 ?>" min="0" required
                                            onwheel="this.blur()">
                                        <span class="input-group-text">원</span>
                                    </div>
                                </div>
                                <label class="col-sm-2 col-form-label">원가</label>
                                <div class="col-sm-4">
                                    <div class="input-group">
                                        <input type="number" name="formData[origin_price]" class="form-control"
                                            value="<?= $product['origin_price'] ?? 0 ?>" min="0"
                                            onwheel="this.blur()">
                                        <span class="input-group-text">원</span>
                                    </div>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <label class="col-sm-2 col-form-label">할인 유형</label>
                                <div class="col-sm-4">
                                    <select name="formData[discount_type]" id="productDiscountType" class="form-select">
                                        <?php foreach ($discountTypeOptions as $val => $lbl): ?>
                                        <option value="<?= $val ?>" <?= $currentDiscountType === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <label class="col-sm-2 col-form-label">할인 값</label>
                                <div class="col-sm-4" id="productDiscountValueWrap" style="display:<?= in_array($currentDiscountType, ['DEFAULT', 'NONE', 'LEVEL']) ? 'none' : '' ?>">
                                    <div class="input-group">
                                        <input type="number" name="formData[discount_value]" class="form-control"
                                            value="<?= $product['discount_value'] ?? 0 ?>" step="0.01" min="0">
                                        <span class="input-group-text">%/원</span>
                                    </div>
                                </div>
                            </div>
                            <!-- 등급별 할인 카드 -->
                            <div id="productDiscountLevelTable" style="display:<?= $currentDiscountType === 'LEVEL' ? 'block' : 'none' ?>;" class="mb-3">
                                <?php if (!empty($memberLevels)): ?>
                                <div class="row">
                                    <div class="col-sm-2"></div>
                                    <div class="col-sm-10">
                                        <div class="d-flex flex-wrap gap-2">
                                            <?php foreach ($memberLevels as $level):
                                                $lid = $level->levelId;
                                                $ls = $discountLevelSettings[$lid] ?? [];
                                            ?>
                                            <div class="level-card">
                                                <div class="level-card__name"><?= htmlspecialchars($level->name) ?></div>
                                                <select name="formData[discount_level_settings][<?= $lid ?>][type]" class="form-select form-select-sm level-card__type">
                                                    <option value="PERCENTAGE" <?= ($ls['type'] ?? 'PERCENTAGE') === 'PERCENTAGE' ? 'selected' : '' ?>>정률(%)</option>
                                                    <option value="FIXED" <?= ($ls['type'] ?? '') === 'FIXED' ? 'selected' : '' ?>>정액(원)</option>
                                                </select>
                                                <input type="number" name="formData[discount_level_settings][<?= $lid ?>][value]" class="form-control form-control-sm level-card__value" value="<?= $ls['value'] ?? 0 ?>" step="0.01" min="0">
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="text-muted small ms-3">등록된 회원 레벨이 없습니다.</div>
                                <?php endif; ?>
                            </div>

                            <div class="row mb-3">
                                <label class="col-sm-2 col-form-label">적립 유형</label>
                                <div class="col-sm-4">
                                    <select name="formData[reward_type]" id="productRewardType" class="form-select">
                                        <?php foreach ($rewardTypeOptions as $val => $lbl): ?>
                                        <option value="<?= $val ?>" <?= $currentRewardType === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <label class="col-sm-2 col-form-label">적립 값</label>
                                <div class="col-sm-4" id="productRewardValueWrap" style="display:<?= in_array($currentRewardType, ['DEFAULT', 'NONE', 'LEVEL']) ? 'none' : '' ?>">
                                    <div class="input-group">
                                        <input type="number" name="formData[reward_value]" class="form-control"
                                            value="<?= $product['reward_value'] ?? 0 ?>" step="0.01" min="0">
                                        <span class="input-group-text">%/P</span>
                                    </div>
                                </div>
                            </div>
                            <!-- 등급별 적립 카드 -->
                            <div id="productRewardLevelTable" style="display:<?= $currentRewardType === 'LEVEL' ? 'block' : 'none' ?>;" class="mb-3">
                                <?php if (!empty($memberLevels)): ?>
                                <div class="row">
                                    <div class="col-sm-2"></div>
                                    <div class="col-sm-10">
                                        <div class="d-flex flex-wrap gap-2">
                                            <?php foreach ($memberLevels as $level):
                                                $lid = $level->levelId;
                                                $ls = $rewardLevelSettings[$lid] ?? [];
                                            ?>
                                            <div class="level-card">
                                                <div class="level-card__name"><?= htmlspecialchars($level->name) ?></div>
                                                <select name="formData[reward_level_settings][<?= $lid ?>][type]" class="form-select form-select-sm level-card__type">
                                                    <option value="PERCENTAGE" <?= ($ls['type'] ?? 'PERCENTAGE') === 'PERCENTAGE' ? 'selected' : '' ?>>정률(%)</option>
                                                    <option value="FIXED" <?= ($ls['type'] ?? '') === 'FIXED' ? 'selected' : '' ?>>정액(원)</option>
                                                </select>
                                                <input type="number" name="formData[reward_level_settings][<?= $lid ?>][value]" class="form-control form-control-sm level-card__value" value="<?= $ls['value'] ?? 0 ?>" step="0.01" min="0">
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="text-muted small ms-3">등록된 회원 레벨이 없습니다.</div>
                                <?php endif; ?>
                            </div>
                            <div class="row mb-3">
                                <label class="col-sm-2 col-form-label">구매후기 적립</label>
                                <div class="col-sm-4">
                                    <div class="input-group">
                                        <input type="number" name="formData[reward_review]" class="form-control"
                                            value="<?= $product['reward_review'] ?? 0 ?>" min="0">
                                        <span class="input-group-text">P</span>
                                    </div>
                                </div>
                                <label class="col-sm-2 col-form-label">쿠폰 허용</label>
                                <div class="col-sm-4">
                                    <div class="form-check form-switch mt-2">
                                        <input type="hidden" name="formData[allowed_coupon]" value="0">
                                        <input type="checkbox" name="formData[allowed_coupon]" class="form-check-input"
                                            value="1" <?= ($product['allowed_coupon'] ?? 1) ? 'checked' : '' ?>>
                                        <label class="form-check-label">허용</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- ============================================ -->
                <!-- 4. 옵션/재고 -->
                <!-- ============================================ -->
                <section id="option" data-section="option">
                    <h5 class="mb-4">옵션 / 재고</h5>
                    <div class="card">
                        <div class="card-body">
                            <div class="row mb-3">
                                <label class="col-sm-2 col-form-label">옵션 모드</label>
                                <div class="col-sm-4">
                                    <select name="formData[option_mode]" id="optionModeSelect" class="form-select"
                                        onchange="ShopProductForm.onModeChange(this.value)">
                                        <?php foreach ($optionModeOptions as $val => $lbl): ?>
                                        <option value="<?= $val ?>" <?= ($product['option_mode'] ?? 'NONE') === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- NONE 모드: 단순 재고 -->
                            <div id="stock-simple" class="row mb-3">
                                <label class="col-sm-2 col-form-label">재고 수량</label>
                                <div class="col-sm-4">
                                    <div class="input-group">
                                        <input type="number" name="formData[stock_quantity]" class="form-control"
                                            value="<?= $product['stock_quantity'] ?? '' ?>" min="0"
                                            placeholder="비워두면 재고 미관리">
                                        <span class="input-group-text">개</span>
                                    </div>
                                    <div class="form-text">비워두면 재고를 관리하지 않습니다. 0 입력 시 품절 처리됩니다.</div>
                                </div>
                            </div>

                            <!-- SINGLE/COMBINATION 모드: 옵션 구성 -->
                            <div id="option-area" style="display:none">
                                <hr>
                                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <select id="presetSelect" class="form-select form-select-sm" style="width:auto">
                                            <option value="">프리셋 선택</option>
                                            <?php foreach ($presets ?? [] as $preset): ?>
                                            <option value="<?= $preset['preset_id'] ?>"><?= htmlspecialchars($preset['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="ShopProductForm.loadPreset()">
                                            <i class="bi bi-download"></i> 가져오기
                                        </button>
                                    </div>
                                    <div class="d-flex gap-1">
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="ShopProductForm.addOption('BASIC')">
                                            <i class="bi bi-plus-lg"></i> 기본 옵션
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-success" onclick="ShopProductForm.addOption('EXTRA')">
                                            <i class="bi bi-plus-lg"></i> 추가 옵션
                                        </button>
                                    </div>
                                </div>
                                <div class="small text-muted mb-3">
                                    <strong>기본 옵션</strong> — 상품의 본질적 선택 (색상, 사이즈 등). 재고/가격 관리 대상<br>
                                    <strong>추가 옵션</strong> — 부가 서비스 (각인, 포장 등). 별도 추가금
                                </div>
                                <div id="product-options">
                                    <!-- JS 동적 생성 -->
                                </div>
                                <div id="product-options-empty" class="text-center py-4 text-muted" style="display:none;">
                                    옵션을 추가하거나 프리셋을 불러와주세요.
                                </div>

                                <!-- 조합 관리 (COMBINATION 모드) -->
                                <div id="combo-preview-card" class="mt-4" style="display:none;">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <h6 class="mb-0">조합 옵션</h6>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="badge text-bg-secondary" id="combo-count">0개</span>
                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="ShopProductForm.generateCombos()">
                                                <i class="bi bi-arrow-repeat"></i> 조합 생성
                                            </button>
                                        </div>
                                    </div>
                                    <div class="alert alert-secondary alert-sm py-1 px-2 mb-2 small">
                                        기본 옵션 값을 설정한 후 <strong>조합 생성</strong> 버튼을 클릭하세요. 조합별 추가금액과 재고를 개별 설정할 수 있습니다.
                                    </div>
                                    <div id="combo-preview-content">
                                        <div class="text-center py-3 text-muted">기본 옵션에 값을 추가한 후 조합을 생성해주세요.</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- ============================================ -->
                <!-- 5. 이미지 -->
                <!-- ============================================ -->
                <section id="images" data-section="images">
                    <h5 class="mb-4">이미지</h5>
                    <div class="card">
                        <div class="card-hero">
                            <span>첫 번째 이미지가 대표 이미지로 사용됩니다. (최대 5MB)</span>
                            <button type="button" class="btn btn-xs btn-outline-primary ms-auto" onclick="ShopProductForm.addImage()">
                                <i class="bi bi-plus-lg"></i> 이미지 추가
                            </button>
                        </div>
                        <div class="card-body">
                            <div class="row g-3" id="product-images">
                                <!-- JS 동적 생성 -->
                            </div>
                            <div id="product-images-empty" class="text-center py-4 text-muted" style="display:none;">
                                이미지를 추가해주세요.
                            </div>
                        </div>
                    </div>
                </section>

                <!-- ============================================ -->
                <!-- 6. 상세설명 -->
                <!-- ============================================ -->
                <section id="details" data-section="details">
                    <h5 class="mb-4">상세설명</h5>
                    <div class="card">
                        <div class="card-hero">
                            <span>상품 상세 페이지에 표시될 설명을 추가합니다.</span>
                            <button type="button" class="btn btn-xs btn-outline-primary ms-auto" onclick="ShopProductForm.addDetail()">
                                <i class="bi bi-plus-lg"></i> 설명 추가
                            </button>
                        </div>
                        <div class="card-body">
                            <div id="product-details">
                                <!-- JS 동적 생성 -->
                            </div>
                            <div id="product-details-empty" class="text-center py-4 text-muted" style="display:none;">
                                상세설명을 추가해주세요.
                            </div>
                        </div>
                    </div>
                </section>

                <!-- ============================================ -->
                <!-- 7. 상품정보제공고시 -->
                <!-- ============================================ -->
                <section id="notice" data-section="notice">
                    <h5 class="mb-4">상품정보제공고시</h5>
                    <div class="card">
                        <div class="card-hero">
                            <span>프론트 상품 상세에 고시 정보를 표시하려면 품목을 선택하고 필요한 정보만 입력하세요.</span>
                            <button type="button" id="productNoticeFillReference" class="btn btn-xs btn-outline-secondary ms-auto">
                                빈 항목을 상품 상세설명 참조로 입력
                            </button>
                        </div>
                        <div class="card-body">
                            <div class="row mb-4">
                                <label class="col-sm-2 col-form-label">품목</label>
                                <div class="col-sm-6">
                                    <select id="productNoticeTemplate" name="product_notice[template_id]" class="form-select">
                                        <option value="0">선택하지 않음</option>
                                    </select>
                                    <div class="form-text">모든 항목은 선택 입력이며, 값이 있는 항목만 상품 상세에 표시됩니다.</div>
                                    <div class="form-text text-warning">상세설명이 이미지만으로 구성된 경우 정보 접근을 위해 적절한 대체 텍스트도 제공하세요.</div>
                                </div>
                            </div>
                            <div id="product-notice-fields"></div>
                        </div>
                    </div>
                </section>

                <!-- ============================================ -->
                <!-- 8. 배송 -->
                <!-- ============================================ -->
                <section id="shipping" data-section="shipping">
                    <h5 class="mb-4">배송</h5>
                    <div class="card">
                        <div class="card-body">
                            <div class="row mb-3">
                                <label class="col-sm-2 col-form-label">배송 템플릿</label>
                                <div class="col-sm-4">
                                    <select name="formData[shipping_template_id]" class="form-select">
                                        <option value="">기본 배송 템플릿 사용</option>
                                        <?php foreach ($shippingTemplates ?? [] as $tmpl): ?>
                                        <option value="<?= $tmpl['shipping_id'] ?>"
                                            <?= ($product['shipping_template_id'] ?? '') == $tmpl['shipping_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($tmpl['name'] ?? '템플릿 #' . $tmpl['shipping_id']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <label class="col-sm-2 col-form-label">배송비 적용</label>
                                <div class="col-sm-4">
                                    <select name="formData[shipping_apply_type]" class="form-select">
                                        <option value="COMBINED" <?= ($product['shipping_apply_type'] ?? 'COMBINED') === 'COMBINED' ? 'selected' : '' ?>>묶음 배송</option>
                                        <option value="SEPARATE" <?= ($product['shipping_apply_type'] ?? '') === 'SEPARATE' ? 'selected' : '' ?>>개별 배송</option>
                                    </select>
                                    <div class="form-text">묶음 배송: 다른 상품과 배송비 합산 / 개별 배송: 이 상품만 별도 배송비</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- ============================================ -->
                <!-- 8. 추가 정보 -->
                <!-- ============================================ -->
                <section id="extra" data-section="extra">
                    <h5 class="mb-4">추가 정보</h5>
                    <div class="card">
                        <div class="card-body">
                            <div class="row mb-3">
                                <label class="col-sm-2 col-form-label">원산지</label>
                                <div class="col-sm-4">
                                    <input type="text" name="formData[goods_origin]" class="form-control"
                                        value="<?= htmlspecialchars($product['goods_origin'] ?? '') ?>" placeholder="예: 대한민국">
                                </div>
                                <label class="col-sm-2 col-form-label">제조사</label>
                                <div class="col-sm-4">
                                    <input type="text" name="formData[goods_manufacturer]" class="form-control"
                                        value="<?= htmlspecialchars($product['goods_manufacturer'] ?? '') ?>">
                                </div>
                            </div>
                            <div class="row mb-3">
                                <label class="col-sm-2 col-form-label">관리코드</label>
                                <div class="col-sm-4">
                                    <input type="text" name="formData[goods_code]" class="form-control"
                                        value="<?= htmlspecialchars($product['goods_code'] ?? '') ?>"
                                        placeholder="내부 관리용 코드">
                                </div>
                                <label class="col-sm-2 col-form-label">필터</label>
                                <div class="col-sm-4">
                                    <input type="text" name="formData[goods_filter]" class="form-control"
                                        value="<?= htmlspecialchars($product['goods_filter'] ?? '') ?>"
                                        placeholder="필터 태그">
                                </div>
                            </div>
                            <div class="row mb-3">
                                <label class="col-sm-2 col-form-label">검색 태그</label>
                                <div class="col-sm-10">
                                    <input type="text" name="formData[goods_tags]" class="form-control"
                                        value="<?= htmlspecialchars($product['goods_tags'] ?? '') ?>"
                                        placeholder="쉼표로 구분 (예: 여름, 반팔, 데일리)">
                                    <div class="form-text">검색 시 매칭에 사용됩니다. 쉼표(,)로 구분하여 입력하세요.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

            </div><!-- /.sticky-section -->
        </div><!-- /.sticky-spy -->

        <!-- 하단 버튼 -->
        <div class="sticky-act sticky-status">
            <a href="<?= htmlspecialchars($listUrl) ?>" class="btn btn-secondary me-2">
                <i class="bi bi-list"></i> 목록
            </a>
            <button type="button" class="btn btn-primary mublo-submit"
                data-target="/admin/shop/products/store"
                data-callback="productSaved">
                <i class="bi bi-check-lg"></i> <?= $isEdit ? '수정' : '등록' ?>
            </button>
        </div>
    </div><!-- /.page-container -->
</form>

<?= editor_js() ?>
<script src="<?= asset('/serve/package/Shop/assets/js/category-manager.js') ?>"></script>

<script>
// 카테고리 트리 데이터 (PHP → JS)
const shopCategoryTree = <?= json_encode($categoryTree ?? [], JSON_UNESCAPED_UNICODE) ?>;

const ShopProductForm = {
    optionIndex: 0,
    imageIndex: 0,
    detailIndex: 0,

    // =========================================================================
    // 옵션 모드
    // =========================================================================

    onModeChange(mode) {
        const stockSimple = document.getElementById('stock-simple');
        const optionArea = document.getElementById('option-area');
        const comboCard = document.getElementById('combo-preview-card');

        if (mode === 'NONE') {
            stockSimple.style.display = '';
            optionArea.style.display = 'none';
        } else {
            stockSimple.style.display = 'none';
            optionArea.style.display = '';
        }

        comboCard.style.display = mode === 'COMBINATION' ? '' : 'none';
        if (mode === 'COMBINATION') this.updateComboPreview();
        this.updateEmptyState('product-options', 'product-options-empty');
    },

    // =========================================================================
    // 옵션 CRUD
    // =========================================================================

    addOption(type, data) {
        const idx = this.optionIndex++;
        const mode = document.getElementById('optionModeSelect').value;
        const typeName = type === 'EXTRA' ? '추가 옵션' : '기본 옵션';
        const badgeClass = type === 'EXTRA' ? 'text-bg-success' : 'text-bg-primary';
        const isRequired = data?.is_required !== undefined ? Number(data.is_required) : 1;

        const html = `
            <div class="border rounded p-3 mb-3" id="opt-${idx}">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge ${badgeClass}">${typeName}</span>
                        <strong>옵션 #${idx + 1}</strong>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="ShopProductForm.removeOption(${idx})">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
                <input type="hidden" name="options[${idx}][option_type]" value="${type}">
                <div class="row g-2 mb-2">
                    <div class="col-md-5">
                        <label class="form-label small">옵션명 <span class="text-danger">*</span></label>
                        <input type="text" name="options[${idx}][option_name]" class="form-control form-control-sm"
                            placeholder="${type === 'EXTRA' ? '예: 각인, 포장' : '예: 색상, 사이즈'}"
                            value="${this.esc(data?.option_name || '')}" required
                            oninput="ShopProductForm.updateComboPreview()">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">필수 여부</label>
                        <select name="options[${idx}][is_required]" class="form-select form-select-sm">
                            <option value="1" ${isRequired ? 'selected' : ''}>필수</option>
                            <option value="0" ${!isRequired ? 'selected' : ''}>선택</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small">정렬</label>
                        <input type="number" name="options[${idx}][sort_order]" class="form-control form-control-sm"
                            value="${data?.sort_order ?? idx}" min="0">
                    </div>
                </div>
                <label class="form-label small mt-1">옵션 값</label>
                <div id="vals-${idx}"></div>
                <button type="button" class="btn btn-sm btn-outline-secondary mt-1" onclick="ShopProductForm.addValue(${idx})">
                    <i class="bi bi-plus"></i> 값 추가
                </button>
            </div>`;

        document.getElementById('product-options').insertAdjacentHTML('beforeend', html);

        if (data?.values && data.values.length) {
            data.values.forEach(v => this.addValue(idx, v));
        } else {
            this.addValue(idx);
        }
        this.updateEmptyState('product-options', 'product-options-empty');
        this.updateComboPreview();
    },

    removeOption(idx) {
        document.getElementById('opt-' + idx)?.remove();
        this.updateEmptyState('product-options', 'product-options-empty');
        this.updateComboPreview();
    },

    addValue(optIdx, data) {
        const container = document.getElementById('vals-' + optIdx);
        const vIdx = container.children.length;
        const mode = document.getElementById('optionModeSelect').value;
        const optEl = document.getElementById('opt-' + optIdx);
        const optType = optEl?.querySelector('input[name*="[option_type]"]')?.value || 'BASIC';
        const showStock = (optType === 'BASIC' && mode === 'SINGLE');

        const html = `
            <div class="row g-2 mb-1 align-items-center">
                <div class="col-md-5">
                    <input type="text" name="options[${optIdx}][values][${vIdx}][value_name]"
                        class="form-control form-control-sm" placeholder="값 (예: 빨강, XL)"
                        value="${this.esc(data?.value_name || '')}"
                        oninput="ShopProductForm.updateComboPreview()">
                </div>
                <div class="col-md-3">
                    <div class="input-group input-group-sm">
                        <input type="number" name="options[${optIdx}][values][${vIdx}][extra_price]"
                            class="form-control form-control-sm" placeholder="추가금액"
                            value="${data?.extra_price ?? 0}">
                        <span class="input-group-text">원</span>
                    </div>
                </div>
                ${showStock ? `
                <div class="col-md-2">
                    <div class="input-group input-group-sm">
                        <input type="number" name="options[${optIdx}][values][${vIdx}][stock_quantity]"
                            class="form-control form-control-sm" placeholder="재고"
                            value="${data?.stock_quantity != null ? data.stock_quantity : ''}" min="0"
                            placeholder="미관리">
                        <span class="input-group-text">개</span>
                    </div>
                </div>` : ''}
                <div class="col-auto">
                    <button type="button" class="btn btn-sm btn-outline-danger"
                        onclick="this.closest('.row').remove(); ShopProductForm.updateComboPreview()">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
            </div>`;
        container.insertAdjacentHTML('beforeend', html);
    },

    // =========================================================================
    // 프리셋 불러오기
    // =========================================================================

    loadPreset() {
        const select = document.getElementById('presetSelect');
        const presetId = select.value;
        if (!presetId) { alert('프리셋을 선택해주세요.'); return; }

        MubloRequest.requestJson('/admin/shop/options/detail', { preset_id: presetId })
            .then(response => {
                if (response.result !== 'success' || !response.data?.preset) {
                    alert(response.message || '프리셋을 불러올 수 없습니다.');
                    return;
                }
                const preset = response.data.preset;

                // 기존 옵션 제거
                document.getElementById('product-options').innerHTML = '';
                this.optionIndex = 0;

                // 옵션 모드 설정
                if (preset.option_mode) {
                    document.getElementById('optionModeSelect').value = preset.option_mode;
                    this.onModeChange(preset.option_mode);
                }

                // 옵션 추가
                const options = preset.options || [];
                options.forEach(opt => this.addOption(opt.option_type || 'BASIC', opt));

                this.updateEmptyState('product-options', 'product-options-empty');
            });
    },

    // =========================================================================
    // 조합 미리보기
    // =========================================================================

    // 기존 조합 데이터 (편집 모드에서 초기화됨)
    _existingCombos: {},

    updateComboPreview() {
        const mode = document.getElementById('optionModeSelect').value;
        if (mode !== 'COMBINATION') return;

        const content = document.getElementById('combo-preview-content');
        const countBadge = document.getElementById('combo-count');

        // 이미 생성된 조합 테이블이 있으면 경고만 표시
        if (content.querySelector('.combo-table')) {
            let warn = content.querySelector('.combo-warning');
            if (!warn) {
                warn = document.createElement('div');
                warn.className = 'combo-warning alert alert-info alert-sm py-1 px-2 mb-2 small';
                warn.textContent = '옵션이 변경되었습니다. 조합 생성 버튼을 다시 클릭하면 반영됩니다. (기존 입력값은 조합키가 같으면 유지)';
                content.insertBefore(warn, content.firstChild);
            }
            return;
        }

        const { names, valueSets } = this.getBasicOptionValues();
        if (names.length === 0 || valueSets.some(v => v.length === 0)) {
            content.innerHTML = '<div class="text-center py-3 text-muted">기본 옵션에 값을 추가한 후 조합을 생성해주세요.</div>';
            countBadge.textContent = '0개';
            return;
        }

        const combos = this.cartesianProduct(valueSets);
        countBadge.textContent = combos.length + '개 (미생성)';
        content.innerHTML = '<div class="text-center py-3 text-muted">옵션 값이 준비되었습니다. <strong>조합 생성</strong> 버튼을 클릭해주세요.</div>';
    },

    generateCombos() {
        const { names, valueSets } = this.getBasicOptionValues();
        const content = document.getElementById('combo-preview-content');
        const countBadge = document.getElementById('combo-count');

        // 기존 폼 입력값을 _existingCombos에 백업 (재생성 시 값 유지)
        this._saveCurrentComboInputs();

        if (names.length === 0 || valueSets.some(v => v.length === 0)) {
            content.innerHTML = '<div class="text-center py-3 text-muted">기본 옵션에 값을 추가해주세요.</div>';
            countBadge.textContent = '0개';
            return;
        }

        const combos = this.cartesianProduct(valueSets);

        countBadge.textContent = combos.length + '개';

        let html = '<div class="table-responsive combo-table"><table class="table table-sm table-bordered table-readable mb-0 small"><thead><tr>';
        names.forEach(n => { html += `<th>${this.esc(n || '(미입력)')}</th>`; });
        html += '<th style="width:120px;">추가금액</th>';
        html += '<th style="width:120px;">재고</th>';
        html += '<th class="text-center" style="width:60px;">사용</th>';
        html += '</tr></thead><tbody>';

        combos.forEach((combo, idx) => {
            const key = combo.join('/');
            const existing = this._existingCombos[key] || {};
            const extraPrice = existing.extra_price ?? 0;
            const stockQty = existing.stock_quantity;
            const isActive = existing.is_active !== undefined ? Number(existing.is_active) : 1;

            html += '<tr>';
            combo.forEach((v, vi) => {
                html += `<td class="align-middle">${this.esc(v)}`;
                if (vi === 0) html += `<input type="hidden" name="combos[${idx}][combination_key]" value="${this.esc(key)}">`;
                html += '</td>';
            });
            html += `<td><div class="input-group input-group-sm">
                <input type="number" name="combos[${idx}][extra_price]" class="form-control form-control-sm" value="${extraPrice}">
                <span class="input-group-text">원</span>
            </div></td>`;
            html += `<td><div class="input-group input-group-sm">
                <input type="number" name="combos[${idx}][stock_quantity]" class="form-control form-control-sm"
                    value="${stockQty != null ? stockQty : ''}" min="0" placeholder="미관리">
                <span class="input-group-text">개</span>
            </div></td>`;
            html += `<td class="text-center align-middle">
                <input type="hidden" name="combos[${idx}][is_active]" value="0">
                <input type="checkbox" name="combos[${idx}][is_active]" value="1" class="form-check-input" ${isActive ? 'checked' : ''}>
            </td>`;
            html += '</tr>';
        });

        html += '</tbody></table></div>';
        content.innerHTML = html;
    },

    _saveCurrentComboInputs() {
        const content = document.getElementById('combo-preview-content');
        if (!content) return;
        const rows = content.querySelectorAll('input[name*="[combination_key]"]');
        rows.forEach(hidden => {
            const key = hidden.value;
            const tr = hidden.closest('tr') || hidden.parentElement;
            const extraInput = tr?.querySelector('input[name*="[extra_price]"]');
            const stockInput = tr?.querySelector('input[name*="[stock_quantity]"]');
            const activeCheckbox = tr?.querySelector('input[type="checkbox"][name*="[is_active]"]');
            this._existingCombos[key] = {
                extra_price: extraInput ? Number(extraInput.value) || 0 : 0,
                stock_quantity: stockInput && stockInput.value !== '' ? Number(stockInput.value) : null,
                is_active: activeCheckbox ? (activeCheckbox.checked ? 1 : 0) : 1
            };
        });
    },

    getBasicOptionValues() {
        const names = [];
        const valueSets = [];
        document.querySelectorAll('#product-options [id^="opt-"]').forEach(optEl => {
            const typeInput = optEl.querySelector('input[name*="[option_type]"]');
            if (!typeInput || typeInput.value !== 'BASIC') return;
            const nameInput = optEl.querySelector('input[name*="[option_name]"]');
            names.push(nameInput?.value || '');
            const values = [];
            const valContainer = optEl.querySelector('[id^="vals-"]');
            if (valContainer) {
                valContainer.querySelectorAll('input[name*="[value_name]"]').forEach(vi => {
                    const v = vi.value.trim();
                    if (v) values.push(v);
                });
            }
            valueSets.push(values);
        });
        return { names, valueSets };
    },

    cartesianProduct(sets) {
        let result = [[]];
        for (const set of sets) {
            const temp = [];
            for (const existing of result) {
                for (const item of set) {
                    temp.push([...existing, item]);
                }
            }
            result = temp;
        }
        return result;
    },

    // =========================================================================
    // 이미지
    // =========================================================================

    addImage(data) {
        const idx = this.imageIndex++;
        const hasExisting = data?.image_url;

        const html = `
            <div class="col-sm-6 col-md-4 col-lg-3 shop-product-image-item" id="img-${idx}" style="cursor:move">
                <div class="card h-100">
                    <div class="ratio ratio-1x1">
                        <img src="${hasExisting ? this.esc(data.image_url) : '/assets/images/no-image.svg'}"
                            alt="상품 이미지" class="card-img-top object-fit-cover" id="img-preview-${idx}">
                    </div>
                    <div class="card-body p-2">
                        ${hasExisting ? `<input type="hidden" name="images[${idx}][image_id]" value="${data.image_id || ''}">
                        <input type="hidden" name="images[${idx}][image_url]" value="${this.esc(data.image_url)}">` : ''}
                        <input type="hidden" name="images[${idx}][delete]" value="">
                        <input type="hidden" name="images[${idx}][sort_order]" value="0" data-role="sort-order">
                        <div class="mb-2">
                            <input type="file" name="fileData[images][${idx}]" class="form-control form-control-sm"
                                accept="image/*" onchange="ShopProductForm.onImageFileChange(this, ${idx})">
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="form-check">
                                <input type="radio" name="images_main" class="form-check-input" value="${idx}"
                                    ${idx === 0 ? 'checked' : ''}>
                                <label class="form-check-label small">대표</label>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-danger"
                                onclick="ShopProductForm.removeImage(${idx})">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>`;

        document.getElementById('product-images').insertAdjacentHTML('beforeend', html);

        if (data?.is_main) {
            const radio = document.querySelector(`#img-${idx} input[name="images_main"]`);
            if (radio) radio.checked = true;
        }
        this.refreshImageSortOrder();
        this.initImageSortable();
        this.updateEmptyState('product-images', 'product-images-empty');
    },

    /**
     * #product-images 자식 요소들의 DOM 순서대로 sort_order hidden 값을 갱신
     */
    refreshImageSortOrder() {
        const container = document.getElementById('product-images');
        if (!container) return;
        container.querySelectorAll('[id^="img-"]').forEach((el, position) => {
            const sortInput = el.querySelector('input[data-role="sort-order"]');
            if (sortInput) sortInput.value = position;
        });
    },

    /**
     * SortableJS로 드래그 앤 드롭 활성화 (이미 초기화됐으면 스킵)
     */
    initImageSortable() {
        if (this._imageSortable) return;
        if (typeof Sortable === 'undefined') return;
        const container = document.getElementById('product-images');
        if (!container) return;
        this._imageSortable = Sortable.create(container, {
            animation: 150,
            ghostClass: 'shop-product-image-item--ghost',
            onEnd: () => this.refreshImageSortOrder(),
        });
    },

    removeImage(idx) {
        const el = document.getElementById('img-' + idx);
        if (!el) return;
        const deleteInput = el.querySelector('input[name*="[delete]"]');
        const imageIdInput = el.querySelector('input[name*="[image_id]"]');
        if (imageIdInput && imageIdInput.value) {
            el.classList.toggle('opacity-25');
            deleteInput.value = deleteInput.value ? '' : 'Y';
        } else {
            el.remove();
        }
        this.refreshImageSortOrder();
        this.updateEmptyState('product-images', 'product-images-empty');
    },

    onImageFileChange(input, idx) {
        const img = document.getElementById('img-preview-' + idx);
        if (!img) return;
        if (input.files && input.files[0]) {
            const file = input.files[0];
            if (file.size > 5 * 1024 * 1024) {
                alert('파일이 너무 큽니다 (최대 5MB).');
                input.value = '';
                return;
            }
            const reader = new FileReader();
            reader.onload = (e) => { img.src = e.target.result; };
            reader.readAsDataURL(file);
        }
    },

    // =========================================================================
    // 상세설명
    // =========================================================================

    addDetail(data) {
        const idx = this.detailIndex++;
        const currentType = data?.detail_type || 'description';
        const editorId = 'detail_editor_' + idx;
        const types = {
            description: '상품 설명',
            spec: '상품 사양',
            notice: '구매 안내',
            guide: '사용 가이드',
        };

        let typeOptions = '';
        for (const [val, label] of Object.entries(types)) {
            typeOptions += `<option value="${val}" ${currentType === val ? 'selected' : ''}>${label}</option>`;
        }

        const html = `
            <div class="border rounded p-3 mb-3" id="detail-${idx}">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="d-flex align-items-center gap-2">
                        <select name="details[${idx}][detail_type]" class="form-select form-select-sm" style="width:auto">
                            ${typeOptions}
                        </select>
                        ${data?.detail_id ? `<input type="hidden" name="details[${idx}][detail_id]" value="${data.detail_id}">` : ''}
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="ShopProductForm.removeDetail(${idx})">
                        <i class="bi bi-trash"></i> 삭제
                    </button>
                </div>
                <textarea class="mublo-editor form-control"
                    id="${editorId}"
                    name="details[${idx}][detail_value]"
                    rows="12"
                    style="min-height:300px"
                    data-height="300"
                    data-toolbar="full"
                    data-upload-url="<?= htmlspecialchars(\Mublo\Helper\Editor\EditorHelper::uploadUrl('temp'), ENT_QUOTES) ?>"
                    data-upload-csrf="<?= htmlspecialchars(\Mublo\Helper\Editor\EditorHelper::uploadCsrfToken(), ENT_QUOTES) ?>"
                    data-placeholder="상세 내용을 입력하세요">${this.esc(data?.detail_value || '')}</textarea>
            </div>`;

        document.getElementById('product-details').insertAdjacentHTML('beforeend', html);

        // 동적으로 추가된 textarea에 에디터 초기화
        const textarea = document.getElementById(editorId);
        if (textarea && typeof MubloEditor !== 'undefined') {
            MubloEditor.create(textarea);
        }

        this.updateEmptyState('product-details', 'product-details-empty');
    },

    removeDetail(idx) {
        const el = document.getElementById('detail-' + idx);
        if (!el) return;

        // 에디터 인스턴스 정리
        const editorId = 'detail_editor_' + idx;
        if (typeof MubloEditor !== 'undefined') {
            MubloEditor.destroy(editorId);
        }

        el.remove();
        this.updateEmptyState('product-details', 'product-details-empty');
    },

    // =========================================================================
    // 유틸
    // =========================================================================

    updateEmptyState(containerId, emptyId) {
        const container = document.getElementById(containerId);
        const empty = document.getElementById(emptyId);
        if (!container || !empty) return;
        const hasItems = container.children.length > 0;
        empty.style.display = hasItems ? 'none' : 'block';
    },

    esc(str) {
        const div = document.createElement('div');
        div.textContent = str ?? '';
        return div.innerHTML;
    }
};

// =========================================================================
// 초기화
// =========================================================================

// 상품정보제공고시: 운영자에게 버전 관리를 노출하지 않고 품목과 입력란만 표시한다.
(function() {
    const templates = <?= json_encode($noticeTemplates ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const current = <?= json_encode($productNotice ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    if (current?.template && !templates.some(t => Number(t.template_id) === Number(current.template.template_id))) {
        templates.push({...current.template, fields: current.fields || []});
    }

    const select = document.getElementById('productNoticeTemplate');
    const fields = document.getElementById('product-notice-fields');
    const currentId = Number(current?.notice?.template_id || 0);
    templates.forEach(t => {
        const option = document.createElement('option');
        option.value = t.template_id;
        option.textContent = t.name + (Number(t.is_current) ? '' : ' (기존 양식)');
        option.selected = Number(t.template_id) === currentId;
        select.appendChild(option);
    });

    function render() {
        const template = templates.find(t => Number(t.template_id) === Number(select.value));
        fields.innerHTML = '';
        if (!template) return;
        (template.fields || []).forEach(field => {
            const row = document.createElement('div');
            row.className = 'row mb-3';
            const label = document.createElement('label');
            label.className = 'col-sm-3 col-form-label';
            label.textContent = field.label;
            const wrap = document.createElement('div');
            wrap.className = 'col-sm-9';
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'form-control';
            input.name = `product_notice[values][${field.field_code}]`;
            const sameNoticeType = current?.template?.type_code === template.type_code;
            input.value = sameNoticeType ? (current?.values?.[field.field_code] || '') : '';
            wrap.appendChild(input);
            const reference = document.createElement('div');
            reference.className = 'form-check mt-2';
            const check = document.createElement('input');
            check.type = 'checkbox';
            check.className = 'form-check-input product-notice-reference';
            check.id = `product-notice-reference-${field.field_code}`;
            check.checked = input.value === '상품 상세설명 참조';
            input.readOnly = check.checked;
            const checkLabel = document.createElement('label');
            checkLabel.className = 'form-check-label';
            checkLabel.htmlFor = check.id;
            checkLabel.textContent = '상품 상세설명 참조';
            check.addEventListener('change', function() {
                if (this.checked) {
                    input.dataset.previousValue = input.value;
                    input.value = '상품 상세설명 참조';
                    input.readOnly = true;
                } else {
                    input.value = input.dataset.previousValue || '';
                    input.readOnly = false;
                    input.focus();
                }
            });
            reference.append(check, checkLabel);
            wrap.appendChild(reference);
            if (field.help_text) {
                const help = document.createElement('div');
                help.className = 'form-text';
                help.textContent = field.help_text;
                wrap.appendChild(help);
            }
            row.append(label, wrap);
            fields.appendChild(row);
        });
    }
    select.addEventListener('change', render);
    document.getElementById('productNoticeFillReference').addEventListener('click', function() {
        fields.querySelectorAll('.product-notice-reference').forEach(check => {
            const input = check.closest('.col-sm-9')?.querySelector('input[type="text"]');
            if (input && input.value.trim() === '') {
                check.checked = true;
                check.dispatchEvent(new Event('change'));
            }
        });
    });
    render();
})();

// 카테고리 캐스케이딩 셀렉트 초기화
const categoryPrimary = new CategoryManager({
    wrapperId: 'category-primary',
    categoryTree: shopCategoryTree,
    hiddenInput: 'categoryCodeInput',
    selectedValue: '<?= htmlspecialchars($product['category_code'] ?? '') ?>',
    placeholder: '카테고리 선택',
});

const categoryExtra = new CategoryManager({
    wrapperId: 'category-extra',
    categoryTree: shopCategoryTree,
    hiddenInput: 'categoryCodeExtraInput',
    selectedValue: '<?= htmlspecialchars($product['category_code_extra'] ?? '') ?>',
    placeholder: '카테고리 선택 (선택사항)',
});

// 옵션 모드 초기 상태
ShopProductForm.onModeChange(document.getElementById('optionModeSelect').value);

// 수정 모드: 기존 데이터 로드
<?php if ($isEdit): ?>
    (function() {
        const existingOptions = <?= json_encode($productOptions ?? [], JSON_UNESCAPED_UNICODE) ?>;
        if (existingOptions.length) {
            existingOptions.forEach(opt => ShopProductForm.addOption(opt.option_type || 'BASIC', opt));
        }
        ShopProductForm.updateEmptyState('product-options', 'product-options-empty');

        // 기존 조합 데이터 로드 (combination_key → {extra_price, stock_quantity, is_active} 맵)
        const existingCombos = <?= json_encode($productCombos ?? [], JSON_UNESCAPED_UNICODE) ?>;
        if (existingCombos.length) {
            existingCombos.forEach(c => {
                ShopProductForm._existingCombos[c.combination_key] = {
                    extra_price: c.extra_price,
                    stock_quantity: c.stock_quantity,
                    is_active: c.is_active
                };
            });
            // COMBINATION 모드이면 자동 조합 테이블 생성
            if (document.getElementById('optionModeSelect').value === 'COMBINATION') {
                ShopProductForm.generateCombos();
            }
        }
    })();

    (function() {
        const existingImages = <?= json_encode($productImages ?? [], JSON_UNESCAPED_UNICODE) ?>;
        if (existingImages.length) {
            existingImages.forEach(img => ShopProductForm.addImage(img));
        }
        ShopProductForm.updateEmptyState('product-images', 'product-images-empty');
    })();

    (function() {
        const existingDetails = <?= json_encode($productDetails ?? [], JSON_UNESCAPED_UNICODE) ?>;
        if (existingDetails.length) {
            existingDetails.forEach(d => ShopProductForm.addDetail(d));
        }
        ShopProductForm.updateEmptyState('product-details', 'product-details-empty');
    })();
<?php else: ?>
    ShopProductForm.updateEmptyState('product-options', 'product-options-empty');
    ShopProductForm.updateEmptyState('product-images', 'product-images-empty');
    ShopProductForm.updateEmptyState('product-details', 'product-details-empty');
<?php endif; ?>

// 저장 콜백
MubloRequest.registerCallback('productSaved', function(response) {
    if (response.result === 'success') {
        alert(response.message || '저장되었습니다.');
        <?php if ($isEdit): ?>
        location.reload();
        <?php else: ?>
        location.href = <?= json_encode($listUrl, JSON_UNESCAPED_SLASHES) ?>;
        <?php endif; ?>
    } else {
        alert(response.message || '저장에 실패했습니다.');
    }
});

// ── 할인/적립 유형 변경 시 등급별 테이블 & 값 입력 토글 ──
(function() {
    var discountType = document.getElementById('productDiscountType');
    var discountTable = document.getElementById('productDiscountLevelTable');
    var discountValueWrap = document.getElementById('productDiscountValueWrap');

    if (discountType && discountTable) {
        discountType.addEventListener('change', function() {
            var v = this.value;
            discountTable.style.display = v === 'LEVEL' ? 'block' : 'none';
            discountValueWrap.style.display = (v === 'DEFAULT' || v === 'NONE' || v === 'LEVEL') ? 'none' : '';
        });
    }

    var rewardType = document.getElementById('productRewardType');
    var rewardTable = document.getElementById('productRewardLevelTable');
    var rewardValueWrap = document.getElementById('productRewardValueWrap');

    if (rewardType && rewardTable) {
        rewardType.addEventListener('change', function() {
            var v = this.value;
            rewardTable.style.display = v === 'LEVEL' ? 'block' : 'none';
            rewardValueWrap.style.display = (v === 'DEFAULT' || v === 'NONE' || v === 'LEVEL') ? 'none' : '';
        });
    }
})();
</script>
