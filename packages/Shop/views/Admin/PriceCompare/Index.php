<?php
/**
 * 가격비교 사이트 안내
 *
 * 채널이 늘어도 이 화면은 고치지 않는다 — 등록된 채널을 순회해 카드를 쌓는다.
 * 안내문도 채널이 직접 들고 온다(PriceCompareChannelInterface::guide).
 *
 * @var array $channels [{code, label, format, builtin, active, campaignKey, defaultCampaignKey,
 *                        guide[], feedUrl, summaryUrl}, ...]
 */
?>

<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3>가격비교 사이트</h3>
            <p>상품 정보를 가격비교 사이트에 보내는 피드 주소입니다. 사용할 채널을 켜고, 각 채널 관리자에 주소를 등록하면 수집이 시작됩니다.</p>
        </div>
    </div>

    <div class="page-block">
        <div class="card mb-3">
            <div class="card-hero">
                <i class="bi bi-info-circle text-pastel-blue"></i>
                <span>공통 안내</span>
            </div>
            <div class="card-body">
                <ul class="mb-0 ps-3">
                    <li>피드는 요청받는 시점에 만들어집니다. 상품을 수정하면 다음 수집에 바로 반영됩니다.</li>
                    <li>
                        <strong>전체</strong>는 판매 중인 상품 전부, <strong>변경분</strong>은 당일 바뀐 상품만 담습니다.
                        채널이 두 주소를 따로 받는 경우 함께 등록하면 가격 변동이 더 빨리 반영됩니다.
                    </li>
                    <li>판매하지 않는 상품은 나가지 않습니다 — 미사용, 품절, 판매가 0원, 회원 전용·성인인증 카테고리 상품은 제외됩니다.</li>
                    <li>
                        피드 주소는 로그인 없이 열립니다. 담기는 값은 상품 페이지에 이미 공개된 이름·가격·이미지·링크이지만,
                        전 상품을 한 번에 받아갈 수 있는 형태이므로 <strong>사용하는 채널만 켜두시는 것을 권합니다.</strong> 꺼진 채널의 주소는 응답하지 않습니다.
                    </li>
                </ul>
            </div>
        </div>

        <?php if (empty($channels)): ?>
            <div class="card">
                <div class="card-body text-center text-muted py-5">
                    등록된 가격비교 채널이 없습니다.
                </div>
            </div>
        <?php else: ?>
            <form id="priceCompareForm">
                <?php foreach ($channels as $channel): ?>
                    <div class="card mb-3">
                        <div class="card-hero">
                            <i class="bi bi-graph-up-arrow text-pastel-blue"></i>
                            <span><?= htmlspecialchars($channel['label']) ?></span>
                            <span class="badge bg-light text-secondary border"><?= htmlspecialchars($channel['format']) ?></span>
                            <?php if (!$channel['builtin']): ?>
                                <span class="badge bg-light text-secondary border">확장 제공</span>
                            <?php endif; ?>

                            <div class="ms-auto form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch"
                                    id="channel_<?= htmlspecialchars($channel['code']) ?>"
                                    name="channels[]"
                                    value="<?= htmlspecialchars($channel['code']) ?>"
                                    <?= $channel['active'] ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="channel_<?= htmlspecialchars($channel['code']) ?>">사용</label>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row g-2 mb-3">
                                <div class="col-lg-6">
                                    <label class="form-label small text-muted mb-1">전체 피드</label>
                                    <div class="input-group input-group-sm">
                                        <input type="text" class="form-control" readonly
                                            value="<?= htmlspecialchars($channel['feedUrl']) ?>"
                                            onclick="this.select()">
                                        <a class="btn btn-outline-secondary" target="_blank" rel="noopener"
                                            href="<?= htmlspecialchars($channel['feedUrl']) ?>">
                                            <i class="bi bi-box-arrow-up-right"></i>
                                        </a>
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <label class="form-label small text-muted mb-1">변경분 피드</label>
                                    <div class="input-group input-group-sm">
                                        <input type="text" class="form-control" readonly
                                            value="<?= htmlspecialchars($channel['summaryUrl']) ?>"
                                            onclick="this.select()">
                                        <a class="btn btn-outline-secondary" target="_blank" rel="noopener"
                                            href="<?= htmlspecialchars($channel['summaryUrl']) ?>">
                                            <i class="bi bi-box-arrow-up-right"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-lg-6">
                                    <label class="form-label small text-muted mb-1"
                                        for="campaign_<?= htmlspecialchars($channel['code']) ?>">유입 추적 키</label>
                                    <input type="text" class="form-control form-control-sm"
                                        id="campaign_<?= htmlspecialchars($channel['code']) ?>"
                                        name="campaign_keys[<?= htmlspecialchars($channel['code']) ?>]"
                                        value="<?= htmlspecialchars($channel['campaignKey']) ?>"
                                        placeholder="<?= htmlspecialchars($channel['defaultCampaignKey']) ?>">
                                    <div class="form-text">
                                        피드 링크에 <code>?k=</code> 로 붙어 방문자 통계에서 이 채널 유입이 갈립니다.
                                        비우면 <code><?= htmlspecialchars($channel['defaultCampaignKey']) ?></code> 를 씁니다.
                                    </div>
                                </div>
                            </div>

                            <?php if (!empty($channel['guide'])): ?>
                                <hr class="my-3">
                                <ul class="form-text mb-0 ps-3">
                                    <?php foreach ($channel['guide'] as $line): ?>
                                        <li><?= htmlspecialchars($line) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="text-end">
                    <button type="button" class="btn btn-sm btn-primary mublo-submit"
                        data-target="/admin/shop/price-compare/store">
                        <i class="bi bi-check-lg"></i> 사용 설정 저장
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
