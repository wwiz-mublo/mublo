<?php
declare(strict_types=1);

/** @var string $section */
/** @var array<string,mixed> $principal */
/** @var array<string,int> $summary */
/** @var list<array<string,mixed>> $customers */
/** @var list<array<string,mixed>> $batches */
/** @var list<array<string,mixed>> $schedules */
/** @var list<array<string,mixed>> $devices */
/** @var list<array<string,mixed>> $companyUsers */
/** @var array<string,mixed>|null $subscription */
/** @var list<array<string,mixed>>|null $workers */

$this->assets->addCss('/serve/package/AiAssistant/views/Admin/Operations/_assets/css/operations.css');
$labels = [
    'customers' => ['고객·동기화', '암호화된 고객 directory와 최근 분석 상태를 확인합니다.', 'bi-people'],
    'analysis' => ['AI 분석', '고객 단위 전체·증분 분석 배치의 진행 상태입니다.', 'bi-stars'],
    'schedules' => ['일정·FCM', '예약 발신과 FCM outbox의 전달·단말 수신 상태입니다.', 'bi-send'],
    'devices' => ['앱·기기', '회사에 등록된 Android 기기와 FCM 연결 상태입니다.', 'bi-phone'],
    'company' => ['회사·구독', '회사 구성원과 요금제 사용량을 확인합니다.', 'bi-building'],
    'workers' => ['Worker', 'AI 분석 Worker의 heartbeat와 현재 작업 상태입니다.', 'bi-cpu'],
];
[$title,$description,$icon] = $labels[$section];
$statusLabels = [
    'COMPLETED'=>'완료','INSUFFICIENT_DATA'=>'데이터 부족','ACTION_REQUIRED'=>'확인 필요','FAILED_FINAL'=>'최종 실패',
    'IN_PROGRESS'=>'진행 중','QUEUED'=>'대기 중','LEASED'=>'처리 중','ACTIVE'=>'정상','APPROVED'=>'예약됨',
    'ACKED'=>'단말 수신','PENDING'=>'대기 중','WAITING_DEVICE_READY'=>'단말 준비 대기','SENT'=>'발신 완료',
    'FAILED'=>'실패','CANCELED'=>'취소','EXPIRED'=>'만료',
];
$badge = static fn(string $status): string => match($status){'COMPLETED','ACTIVE','ACKED','SENT'=>'success','ACTION_REQUIRED','FAILED_FINAL','FAILED'=>'danger','WAITING_DEVICE_READY'=>'warning','INSUFFICIENT_DATA','CANCELED','EXPIRED'=>'secondary',default=>'primary'};
$date = static function(?string $value): string { if(!$value)return '-';$time=strtotime($value.' UTC');return $time===false?$value:date('Y.m.d H:i',$time); };
$short = static fn(string $id): string => substr($id,0,8);
$roleLabels=['OWNER'=>'소유자','MANAGER'=>'관리자','STAFF'=>'직원'];
?>
<div class="page-container ai-operations">
    <section class="ai-operations__head mb-4">
        <div class="ai-operations__head-icon"><i class="bi <?= htmlspecialchars($icon) ?>" aria-hidden="true"></i></div>
        <div><span>MUBLO AI ASSISTANT</span><h2><?= htmlspecialchars($title) ?></h2><p><?= htmlspecialchars($description) ?></p></div>
        <div class="ai-operations__company"><small>현재 회사</small><strong><?= htmlspecialchars((string)$principal['company_name']) ?></strong><span><?= htmlspecialchars((string)$principal['nickname']) ?> · <?= htmlspecialchars($roleLabels[(string)$principal['role']]??(string)$principal['role']) ?></span></div>
    </section>

    <?php if($section==='customers'): ?>
        <div class="row g-3 mb-4"><div class="col-md-4"><div class="ai-operations__metric"><span><i class="bi bi-people-fill"></i></span><div><small>관리 고객</small><strong><?=number_format((int)$summary['customers'])?>명</strong></div></div></div><div class="col-md-4"><div class="ai-operations__metric"><span><i class="bi bi-check2-circle"></i></span><div><small>분석 완료</small><strong><?=number_format((int)$summary['analysis_completed'])?>건</strong></div></div></div><div class="col-md-4"><div class="ai-operations__metric"><span><i class="bi bi-exclamation-circle"></i></span><div><small>확인 필요</small><strong><?=number_format((int)$summary['analysis_action_required'])?>건</strong></div></div></div></div>
        <section class="ai-operations__panel"><div class="ai-operations__toolbar"><div><h5>최근 고객</h5><small>대화 원문과 전화번호는 표시하지 않습니다.</small></div><label><i class="bi bi-search"></i><input type="search" placeholder="고객명 검색" data-ai-admin-search></label></div><?php if($customers===[]):?><div class="ai-operations__empty">관리 중인 고객이 없습니다.</div><?php else:?><div class="table-responsive"><table class="table align-middle" data-ai-admin-table><thead><tr><th>고객</th><th>관리 상태</th><th>AI 분석</th><th>갱신일</th></tr></thead><tbody><?php foreach($customers as $customer):$state=(string)($customer['analysis_status']??'');?><tr><td><strong><?=htmlspecialchars((string)$customer['display_name'])?></strong><small class="d-block text-muted ai-operations__mono"><?=htmlspecialchars($short((string)$customer['customer_id']))?></small></td><td><?=htmlspecialchars((string)$customer['management_status'])?></td><td><?=$state===''?'<span class="badge text-bg-light">분석 없음</span>':'<span class="badge text-bg-'.$badge($state).'">'.htmlspecialchars($statusLabels[$state]??$state).'</span>'?></td><td class="text-muted"><?=$date((string)($customer['analysis_updated_at']?:$customer['updated_at']))?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
    <?php elseif($section==='analysis'): ?>
        <section class="ai-operations__panel"><?php if($batches===[]):?><div class="ai-operations__empty">분석 배치가 없습니다.</div><?php else:?><div class="table-responsive"><table class="table align-middle"><thead><tr><th>배치 ID</th><th>목적</th><th>진행</th><th>상태</th><th>생성/갱신</th></tr></thead><tbody><?php foreach($batches as $batch):$state=(string)$batch['display_status'];?><tr><td class="ai-operations__mono"><?=htmlspecialchars($short((string)$batch['batch_id']))?></td><td><?=htmlspecialchars((string)$batch['purpose'])?></td><td><?=number_format((int)$batch['terminal_count'])?> / <?=number_format((int)$batch['run_count'])?></td><td><span class="badge text-bg-<?=$badge($state)?>"><?=htmlspecialchars($statusLabels[$state]??$state)?></span></td><td class="text-muted"><?=$date((string)$batch['updated_at'])?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
    <?php elseif($section==='schedules'): ?>
        <div class="alert alert-info ai-operations__notice"><i class="bi bi-leaf me-2"></i>예약 발신은 분석 Worker가 아니라 API 서버의 FCM outbox가 처리합니다. FCM에는 본문과 전화번호를 넣지 않습니다.</div>
        <section class="ai-operations__panel"><?php if($schedules===[]):?><div class="ai-operations__empty">등록된 예약 발신이 없습니다.</div><?php else:?><div class="table-responsive"><table class="table align-middle"><thead><tr><th>고객</th><th>채널</th><th>예약 시각</th><th>일정/단말</th><th>FCM outbox</th><th>시도</th></tr></thead><tbody><?php foreach($schedules as $schedule):$state=(string)($schedule['device_status']?:$schedule['status']);$outbox=(string)($schedule['outbox_status']??'');?><tr><td><strong><?=htmlspecialchars((string)$schedule['display_name'])?></strong><small class="d-block text-muted ai-operations__mono"><?=htmlspecialchars($short((string)$schedule['schedule_id']))?></small></td><td><?=htmlspecialchars((string)$schedule['channel'])?></td><td><?=$date((string)$schedule['scheduled_at'])?></td><td><span class="badge text-bg-<?=$badge($state)?>"><?=htmlspecialchars($statusLabels[$state]??$state)?></span></td><td><?=htmlspecialchars($statusLabels[$outbox]??($outbox?:'-'))?></td><td><?=number_format((int)($schedule['attempt_count']??0))?>회</td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
    <?php elseif($section==='devices'): ?>
        <div class="row g-3"><?php if($devices===[]):?><div class="col-12"><section class="ai-operations__panel ai-operations__empty">등록된 기기가 없습니다.</section></div><?php else:foreach($devices as $device):$active=(string)$device['status']==='ACTIVE';?><div class="col-md-6 col-xl-4"><article class="ai-operations__device h-100"><div><span><i class="bi bi-phone-fill"></i></span><em class="badge text-bg-<?=$active?'success':'secondary'?>"><?=$active?'정상':'비활성'?></em></div><h5><?=htmlspecialchars((string)$device['name'])?></h5><p><?=htmlspecialchars((string)$device['platform'])?> · OS <?=htmlspecialchars((string)$device['os_version'])?> · 앱 <?=htmlspecialchars((string)$device['app_version'])?></p><dl><div><dt>FCM</dt><dd><?=(int)$device['fcm_ready']===1?'연결됨':'확인 필요'?></dd></div><div><dt>마지막 접속</dt><dd><?=$date($device['last_seen_at']===null?null:(string)$device['last_seen_at'])?></dd></div></dl></article></div><?php endforeach;endif;?></div>
    <?php elseif($section==='workers'): ?>
        <div class="alert alert-light border ai-operations__notice"><i class="bi bi-info-circle me-2"></i>Worker는 암호화 transcript의 복호화·AI 분석만 담당합니다. 메시지 예약과 자동 발신은 이곳의 작업 수에 포함되지 않습니다.</div>
        <section class="ai-operations__panel"><?php $workers=$workers??[];if($workers===[]):?><div class="ai-operations__empty">등록된 Worker heartbeat가 없습니다.</div><?php else:?><div class="table-responsive"><table class="table align-middle"><thead><tr><th>상태</th><th>Worker</th><th>버전</th><th>활성 작업</th><th>마지막 heartbeat</th></tr></thead><tbody><?php foreach($workers as $worker):$online=strtotime((string)$worker['received_at'].' UTC')>=time()-180;?><tr><td><span class="ai-operations__dot <?=$online?'':'is-offline'?>"></span><?=$online?'온라인':'오프라인'?></td><td class="ai-operations__mono"><?=htmlspecialchars((string)$worker['worker_id'])?></td><td><?=htmlspecialchars((string)$worker['version'])?></td><td><?=number_format((int)$worker['active_job_count'])?>건</td><td class="text-muted"><?=$date((string)$worker['received_at'])?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
    <?php elseif($section==='company'): ?>
        <div class="row g-4"><div class="col-xl-4"><section class="ai-operations__plan h-100"><small>현재 요금제</small><h3><?=htmlspecialchars((string)($subscription['name']??'확인 필요'))?></h3><p>관리 고객 <?=number_format((int)$summary['customers'])?> / <?=number_format((int)($subscription['customer_limit']??0))?>명</p><span>요금제 변경과 결제 관리는 전역 플랫폼 관리자 기능으로 분리할 예정입니다.</span></section></div><div class="col-xl-8"><section class="ai-operations__panel h-100"><div class="ai-operations__toolbar"><div><h5>회사 구성원</h5><small>OWNER, MANAGER, STAFF 역할별 계정입니다.</small></div><span><?=count($companyUsers)?>명</span></div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>회원</th><th>로그인 ID</th><th>역할</th><th>상태</th><th>갱신</th></tr></thead><tbody><?php foreach($companyUsers as $user):?><tr><td><strong><?=htmlspecialchars((string)($user['nickname']?:$user['login_id']))?></strong></td><td><?=htmlspecialchars((string)$user['login_id'])?></td><td><?=htmlspecialchars($roleLabels[(string)$user['role']]??(string)$user['role'])?></td><td><span class="badge text-bg-<?=((string)$user['status']==='ACTIVE')?'success':'secondary'?>"><?=htmlspecialchars((string)$user['status'])?></span></td><td class="text-muted"><?=$date((string)$user['updated_at'])?></td></tr><?php endforeach;?></tbody></table></div></section></div></div>
    <?php endif; ?>
</div>
<script>(function(){var input=document.querySelector('[data-ai-admin-search]'),table=document.querySelector('[data-ai-admin-table]');if(!input||!table)return;input.addEventListener('input',function(){var q=input.value.trim().toLocaleLowerCase('ko');table.querySelectorAll('tbody tr').forEach(function(row){row.hidden=q!==''&&!row.textContent.toLocaleLowerCase('ko').includes(q);});});})();</script>
