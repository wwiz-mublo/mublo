<?php
declare(strict_types=1);

/** @var string $section */
/** @var string|null $unavailable */
/** @var array<string,mixed>|null $principal */
/** @var array<string,int> $summary */
/** @var list<array<string,mixed>> $customers */
/** @var list<array<string,mixed>> $batches */
/** @var list<array<string,mixed>> $schedules */
/** @var list<array<string,mixed>> $devices */
/** @var array<string,mixed>|null $subscription */
/** @var list<array<string,mixed>> $companyUsers */

$this->assets->addCss('/serve/package/AiAssistant/views/Front/Workspace/_assets/css/workspace.css');
$this->layout(['layout' => 'full']);

$menus = [
    'dashboard' => ['오늘의 업무', 'bi-grid-1x2-fill'],
    'customers' => ['고객', 'bi-people'],
    'analysis' => ['AI 분석', 'bi-stars'],
    'schedules' => ['일정·발신', 'bi-calendar2-check'],
    'devices' => ['기기·동기화', 'bi-phone'],
    'company' => ['회사·구독', 'bi-building'],
];
if (!isset($menus[$section])) {
    $section = 'dashboard';
}
$statusLabels = [
    'COMPLETED' => '완료', 'INSUFFICIENT_DATA' => '데이터 부족', 'ACTION_REQUIRED' => '확인 필요',
    'FAILED_FINAL' => '최종 실패', 'IN_PROGRESS' => '진행 중', 'QUEUED' => '대기 중',
    'LEASED' => '처리 중', 'ACTIVE' => '정상', 'APPROVED' => '예약됨', 'DISPATCHED' => '전달됨',
    'ACKED' => '단말 수신', 'PENDING' => '대기 중', 'WAITING_DEVICE_READY' => '단말 준비 대기',
    'SENT' => '발신 완료', 'FAILED' => '실패', 'CANCELED' => '취소', 'EXPIRED' => '만료',
];
$statusTone = static fn(string $status): string => match ($status) {
    'COMPLETED', 'ACTIVE', 'SENT', 'ACKED' => 'success',
    'ACTION_REQUIRED', 'FAILED_FINAL', 'FAILED' => 'danger',
    'INSUFFICIENT_DATA', 'CANCELED', 'EXPIRED' => 'muted',
    'WAITING_DEVICE_READY' => 'warning',
    default => 'primary',
};
$displayDate = static function (?string $value, bool $withTime = true): string {
    if ($value === null || $value === '') return '-';
    $time = strtotime($value . (str_contains($value, 'T') || str_contains($value, '+') ? '' : ' UTC'));
    if ($time === false) return $value;
    return date($withTime ? 'Y.m.d H:i' : 'Y.m.d', $time);
};
$shortId = static fn(string $id): string => substr($id, 0, 8);
$roleLabels = ['OWNER' => '소유자', 'MANAGER' => '관리자', 'STAFF' => '직원'];
$channelMeta = [
    'SMS' => ['문자', 'bi-chat-dots-fill', 'purple'],
    'KAKAO' => ['카카오톡', 'bi-chat-fill', 'yellow'],
    'KAKAO_TALK' => ['카카오톡', 'bi-chat-fill', 'yellow'],
    'CALL' => ['전화', 'bi-telephone-fill', 'blue'],
];
?>
<div class="ai-workspace">
    <aside class="ai-workspace__sidebar">
        <a class="ai-workspace__brand" href="/workspace"><span><i class="bi bi-stars" aria-hidden="true"></i></span><div><strong>머?비서?</strong><small>AI WORKSPACE</small></div></a>
        <?php if ($principal !== null): ?>
            <div class="ai-workspace__company"><small>현재 회사</small><strong><?= htmlspecialchars((string) $principal['company_name']) ?></strong><span><?= htmlspecialchars($roleLabels[(string) $principal['role']] ?? (string) $principal['role']) ?></span></div>
        <?php endif; ?>
        <nav aria-label="워크스페이스 메뉴">
            <?php foreach ($menus as $key => [$label, $icon]): ?>
                <a href="<?= $key === 'dashboard' ? '/workspace' : '/workspace/' . $key ?>" class="<?= $section === $key ? 'is-active' : '' ?>">
                    <i class="bi <?= htmlspecialchars($icon) ?>" aria-hidden="true"></i><span><?= htmlspecialchars($label) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="ai-workspace__sidebar-foot"><a href="/mypage/profile"><i class="bi bi-person-circle" aria-hidden="true"></i> 내 계정</a><a href="/"><i class="bi bi-house" aria-hidden="true"></i> 서비스 홈</a></div>
    </aside>

    <main class="ai-workspace__main">
        <?php if ($unavailable !== null): ?>
            <section class="ai-workspace__empty ai-workspace__empty--setup">
                <span><i class="bi bi-building-add" aria-hidden="true"></i></span>
                <small>WORKSPACE SETUP</small>
                <h1>AI 비서 회사 연결이 필요합니다</h1>
                <p><?= htmlspecialchars($unavailable) ?><br>가입한 계정과 Android 앱의 회사 계정을 확인해 주세요.</p>
                <div><a href="/mypage/profile" class="ai-workspace__button ai-workspace__button--primary">내 계정 확인</a><a href="/" class="ai-workspace__button">서비스 홈</a></div>
            </section>
        <?php else: ?>
            <header class="ai-workspace__mobile-head">
                <div><small><?= htmlspecialchars((string) $principal['company_name']) ?></small><strong><?= htmlspecialchars($menus[$section][0]) ?></strong></div>
                <button type="button" aria-label="워크스페이스 메뉴 열기" aria-expanded="false"><i class="bi bi-list" aria-hidden="true"></i></button>
            </header>

            <?php if ($section === 'dashboard'): ?>
                <section class="ai-workspace__welcome">
                    <div><span>오늘의 업무</span><h1><?= htmlspecialchars((string) $principal['nickname']) ?>님, 고객과의 다음 약속을 확인하세요.</h1><p>원문은 노출하지 않고 회사의 고객·분석·기기·발신 상태만 안전하게 보여드립니다.</p></div>
                    <div class="ai-workspace__welcome-date"><i class="bi bi-calendar3" aria-hidden="true"></i><span><?= date('Y.m.d') ?></span></div>
                </section>
                <div class="ai-workspace__metrics">
                    <?php foreach ([
                        ['관리 고객', (int)($summary['customers'] ?? 0), '명', 'bi-people-fill', 'blue'],
                        ['분석 진행', (int)($summary['analysis_active'] ?? 0), '건', 'bi-stars', 'purple'],
                        ['확인 필요', (int)($summary['analysis_action_required'] ?? 0), '건', 'bi-exclamation-circle-fill', 'orange'],
                        ['활성 기기', (int)($summary['active_devices'] ?? 0), '대', 'bi-phone-fill', 'green'],
                    ] as [$label,$value,$unit,$icon,$tone]): ?>
                        <article><span class="is-<?= $tone ?>"><i class="bi <?= $icon ?>" aria-hidden="true"></i></span><div><small><?= $label ?></small><strong><?= number_format($value) ?><em><?= $unit ?></em></strong></div></article>
                    <?php endforeach; ?>
                </div>

                <div class="ai-workspace__dashboard-grid">
                    <section class="ai-workspace__panel">
                        <div class="ai-workspace__panel-head"><div><h2>다가오는 일정</h2><p>서버에 등록된 최근 예약 발신 상태입니다.</p></div><a href="/workspace/schedules">전체 보기</a></div>
                        <?php if ($schedules === []): ?><div class="ai-workspace__empty"><i class="bi bi-calendar2" aria-hidden="true"></i><p>등록된 예약 일정이 없습니다.</p><span>Android 앱에서 다음 연락을 추가해 보세요.</span></div>
                        <?php else: ?><div class="ai-workspace__schedule-list">
                            <?php foreach ($schedules as $schedule): $meta=$channelMeta[(string)$schedule['channel']]??[(string)$schedule['channel'],'bi-send','purple']; $state=(string)($schedule['device_status']?:$schedule['status']); ?>
                                <article><span class="is-<?= $meta[2] ?>"><i class="bi <?= $meta[1] ?>" aria-hidden="true"></i></span><div><strong><?= htmlspecialchars((string)$schedule['display_name']) ?></strong><small><?= htmlspecialchars($meta[0]) ?> · <?= $displayDate((string)$schedule['scheduled_at']) ?></small></div><em class="ai-status is-<?= $statusTone($state) ?>"><?= htmlspecialchars($statusLabels[$state]??$state) ?></em></article>
                            <?php endforeach; ?>
                        </div><?php endif; ?>
                    </section>
                    <section class="ai-workspace__panel ai-workspace__health">
                        <div class="ai-workspace__panel-head"><div><h2>연결 상태</h2><p>앱을 계속 깨우지 않고 상태만 확인합니다.</p></div><a href="/workspace/devices">기기 관리</a></div>
                        <div class="ai-workspace__health-row"><span><i class="bi bi-phone" aria-hidden="true"></i> 등록 기기</span><strong><?= (int)($summary['active_devices']??0) ?>/<?= (int)($summary['devices']??0) ?> 정상</strong></div>
                        <div class="ai-workspace__health-row"><span><i class="bi bi-check2-circle" aria-hidden="true"></i> AI 분석 완료</span><strong><?= number_format((int)($summary['analysis_completed']??0)) ?>건</strong></div>
                        <div class="ai-workspace__health-row"><span><i class="bi bi-database-check" aria-hidden="true"></i> 데이터 부족</span><strong><?= number_format((int)($summary['analysis_insufficient']??0)) ?>건</strong></div>
                        <?php if ($subscription !== null): $used=(int)($summary['customers']??0);$limit=(int)$subscription['customer_limit'];$percent=$limit>0?min(100,(int)round($used/$limit*100)):0; ?>
                            <div class="ai-workspace__usage"><div><span><?= htmlspecialchars((string)$subscription['name']) ?> 고객 사용량</span><strong><?= $used ?>/<?= $limit ?>명</strong></div><div><span style="width:<?= $percent ?>%"></span></div></div>
                        <?php endif; ?>
                    </section>
                </div>

                <section class="ai-workspace__panel">
                    <div class="ai-workspace__panel-head"><div><h2>최근 고객</h2><p>고객 원문 기록 없이 분석 상태만 표시합니다.</p></div><a href="/workspace/customers">전체 보기</a></div>
                    <?php if ($customers === []): ?><div class="ai-workspace__empty"><i class="bi bi-person-plus" aria-hidden="true"></i><p>관리 중인 고객이 없습니다.</p></div><?php else: ?>
                        <div class="ai-workspace__table-wrap"><table><thead><tr><th>고객</th><th>관리 상태</th><th>AI 분석</th><th>마지막 갱신</th></tr></thead><tbody>
                        <?php foreach ($customers as $customer): $state=(string)($customer['analysis_status']??''); ?><tr><td><strong><?= htmlspecialchars((string)$customer['display_name']) ?></strong><small><?= htmlspecialchars($shortId((string)$customer['customer_id'])) ?></small></td><td><?= (string)$customer['management_status']==='MANAGED'?'관리 중':htmlspecialchars((string)$customer['management_status']) ?></td><td><?= $state===''?'<span class="ai-status is-muted">분석 없음</span>':'<span class="ai-status is-'.$statusTone($state).'">'.htmlspecialchars($statusLabels[$state]??$state).'</span>' ?></td><td><?= $displayDate((string)($customer['analysis_updated_at']?:$customer['updated_at'])) ?></td></tr><?php endforeach; ?>
                        </tbody></table></div>
                    <?php endif; ?>
                </section>

            <?php elseif ($section === 'customers'): ?>
                <?php $pageTitle='고객';$pageCopy='등록 고객과 최근 AI 분석 상태를 확인합니다.'; include __DIR__ . '/_PageHeader.php'; ?>
                <section class="ai-workspace__panel">
                    <div class="ai-workspace__toolbar"><label><i class="bi bi-search" aria-hidden="true"></i><input type="search" data-ai-table-search placeholder="고객명으로 검색" aria-label="고객명으로 검색"></label><span>관리 고객 <?= number_format((int)($summary['customers']??0)) ?>명</span></div>
                    <?php if ($customers === []): ?><div class="ai-workspace__empty"><i class="bi bi-people" aria-hidden="true"></i><p>표시할 고객이 없습니다.</p></div><?php else: ?><div class="ai-workspace__table-wrap"><table data-ai-search-table><thead><tr><th>고객</th><th>관리 상태</th><th>AI 분석</th><th>갱신일</th></tr></thead><tbody><?php foreach($customers as $customer):$state=(string)($customer['analysis_status']??'');?><tr><td><strong><?=htmlspecialchars((string)$customer['display_name'])?></strong><small><?=htmlspecialchars($shortId((string)$customer['customer_id']))?></small></td><td><?=htmlspecialchars((string)$customer['management_status'])?></td><td><span class="ai-status is-<?=$state===''?'muted':$statusTone($state)?>"><?=htmlspecialchars($state===''?'분석 없음':($statusLabels[$state]??$state))?></span></td><td><?=$displayDate((string)($customer['analysis_updated_at']?:$customer['updated_at']))?></td></tr><?php endforeach;?></tbody></table></div><?php endif; ?>
                </section>

            <?php elseif ($section === 'analysis'): ?>
                <?php $pageTitle='AI 분석';$pageCopy='전체·증분 분석의 처리 상태와 확인 필요 항목을 봅니다.'; include __DIR__ . '/_PageHeader.php'; ?>
                <section class="ai-workspace__panel"><?php if($batches===[]):?><div class="ai-workspace__empty"><i class="bi bi-stars" aria-hidden="true"></i><p>진행된 분석이 없습니다.</p><span>Android 앱에서 분석할 고객을 선택해 주세요.</span></div><?php else:?><div class="ai-workspace__table-wrap"><table><thead><tr><th>분석 ID</th><th>목적</th><th>진행</th><th>상태</th><th>갱신일</th></tr></thead><tbody><?php foreach($batches as $batch):$state=(string)$batch['display_status'];?><tr><td><strong class="ai-workspace__mono"><?=htmlspecialchars($shortId((string)$batch['batch_id']))?></strong></td><td><?=htmlspecialchars((string)$batch['purpose'])?></td><td><?=number_format((int)$batch['terminal_count'])?> / <?=number_format((int)$batch['run_count'])?></td><td><span class="ai-status is-<?=$statusTone($state)?>"><?=htmlspecialchars($statusLabels[$state]??$state)?></span></td><td><?=$displayDate((string)$batch['updated_at'])?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>

            <?php elseif ($section === 'schedules'): ?>
                <?php $pageTitle='일정·자동 발신';$pageCopy='전화·문자·카카오톡 예약과 FCM 전달 상태를 확인합니다.'; include __DIR__ . '/_PageHeader.php'; ?>
                <section class="ai-workspace__panel"><?php if($schedules===[]):?><div class="ai-workspace__empty"><i class="bi bi-calendar2-check" aria-hidden="true"></i><p>등록된 예약 일정이 없습니다.</p></div><?php else:?><div class="ai-workspace__table-wrap"><table><thead><tr><th>고객</th><th>채널</th><th>예약 시각</th><th>발신 상태</th><th>FCM</th></tr></thead><tbody><?php foreach($schedules as $schedule):$meta=$channelMeta[(string)$schedule['channel']]??[(string)$schedule['channel'],'bi-send','purple'];$state=(string)($schedule['device_status']?:$schedule['status']);$outbox=(string)($schedule['outbox_status']??'');?><tr><td><strong><?=htmlspecialchars((string)$schedule['display_name'])?></strong><small><?=htmlspecialchars($shortId((string)$schedule['schedule_id']))?></small></td><td><span class="ai-channel is-<?=$meta[2]?>"><i class="bi <?=$meta[1]?>" aria-hidden="true"></i><?=$meta[0]?></span></td><td><?=$displayDate((string)$schedule['scheduled_at'])?></td><td><span class="ai-status is-<?=$statusTone($state)?>"><?=htmlspecialchars($statusLabels[$state]??$state)?></span></td><td><?=htmlspecialchars($statusLabels[$outbox]??($outbox?:'-'))?><?php if((int)($schedule['attempt_count']??0)>0):?><small><?=number_format((int)$schedule['attempt_count'])?>회 시도</small><?php endif;?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>

            <?php elseif ($section === 'devices'): ?>
                <?php $pageTitle='기기·동기화';$pageCopy='FCM과 앱 연결 상태를 확인하고 불필요한 상시 실행을 줄입니다.'; include __DIR__ . '/_PageHeader.php'; ?>
                <div class="ai-workspace__device-grid"><?php if($devices===[]):?><section class="ai-workspace__panel ai-workspace__empty"><i class="bi bi-phone" aria-hidden="true"></i><p>등록된 기기가 없습니다.</p><span>Android 앱에서 로그인하면 기기가 연결됩니다.</span></section><?php else:foreach($devices as $device):$active=(string)$device['status']==='ACTIVE';?><article><div class="ai-workspace__device-icon"><i class="bi bi-phone-fill" aria-hidden="true"></i><span class="<?=$active?'is-online':''?>"></span></div><div class="ai-workspace__device-head"><div><h2><?=htmlspecialchars((string)$device['name'])?></h2><p><?=htmlspecialchars((string)$device['platform'])?> · Android <?=htmlspecialchars((string)$device['os_version'])?></p></div><span class="ai-status is-<?=$active?'success':'muted'?>"><?=$active?'정상':'비활성'?></span></div><dl><div><dt>앱 버전</dt><dd><?=htmlspecialchars((string)$device['app_version'])?></dd></div><div><dt>FCM 예약</dt><dd><?=(int)$device['fcm_ready']===1?'연결됨':'확인 필요'?></dd></div><div><dt>마지막 접속</dt><dd><?=$displayDate($device['last_seen_at']===null?null:(string)$device['last_seen_at'])?></dd></div></dl><p class="ai-workspace__device-tip"><i class="bi bi-leaf" aria-hidden="true"></i> 예약 시각에만 FCM으로 앱을 호출합니다.</p></article><?php endforeach;endif;?></div>

            <?php elseif ($section === 'company'): ?>
                <?php $pageTitle='회사·구독';$pageCopy='회사 구성원과 관리 고객 사용량을 확인합니다.'; include __DIR__ . '/_PageHeader.php'; ?>
                <div class="ai-workspace__company-grid">
                    <section class="ai-workspace__plan"><span>현재 요금제</span><h2><?=htmlspecialchars((string)($subscription['name']??'확인 필요'))?></h2><p>관리 고객을 <?=number_format((int)($subscription['customer_limit']??0))?>명까지 등록할 수 있습니다.</p><?php $used=(int)($summary['customers']??0);$limit=(int)($subscription['customer_limit']??0);$percent=$limit>0?min(100,(int)round($used/$limit*100)):0;?><div><span><i style="width:<?=$percent?>%"></i></span><strong><?=$used?> / <?=$limit?>명</strong></div><small>요금제 변경과 결제 기능은 준비 중입니다.</small></section>
                    <section class="ai-workspace__panel"><div class="ai-workspace__panel-head"><div><h2>회사 구성원</h2><p>현재 AI 비서 회사에 연결된 계정입니다.</p></div><span><?=count($companyUsers)?>명</span></div><div class="ai-workspace__member-list"><?php foreach($companyUsers as $companyUser):?><article><span><?=htmlspecialchars(mb_substr((string)($companyUser['nickname']?:$companyUser['login_id']),0,1))?></span><div><strong><?=htmlspecialchars((string)($companyUser['nickname']?:$companyUser['login_id']))?></strong><small><?=htmlspecialchars((string)$companyUser['login_id'])?></small></div><em><?=$roleLabels[(string)$companyUser['role']]??htmlspecialchars((string)$companyUser['role'])?></em></article><?php endforeach;?></div></section>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</div>

<script>
(function () {
    var root = document.querySelector('.ai-workspace');
    if (!root) return;
    var button = root.querySelector('.ai-workspace__mobile-head button');
    if (button) button.addEventListener('click', function () {
        var open = root.classList.toggle('is-menu-open');
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    var search = root.querySelector('[data-ai-table-search]');
    var table = root.querySelector('[data-ai-search-table]');
    if (search && table) search.addEventListener('input', function () {
        var keyword = search.value.trim().toLocaleLowerCase('ko');
        table.querySelectorAll('tbody tr').forEach(function (row) {
            row.hidden = keyword !== '' && !row.textContent.toLocaleLowerCase('ko').includes(keyword);
        });
    });
})();
</script>
