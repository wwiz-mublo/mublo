<?php
declare(strict_types=1);

/** @var string $section */
/** @var array<string,int> $summary */
/** @var list<array<string,mixed>> $companies */
/** @var list<array<string,mixed>> $delivery */
/** @var list<array<string,mixed>> $analysis */
/** @var list<array<string,mixed>> $workers */
$this->assets->addCss('/serve/package/AiAssistant/views/Admin/Platform/_assets/css/platform.css');
$titles=[
    'dashboard'=>['플랫폼 통합 현황','전체 회사의 서비스 상태를 민감 원문 없이 집계합니다.'],
    'companies'=>['플랫폼 회사·회원','회사별 구독과 고객·기기 사용량을 확인합니다.'],
    'delivery'=>['플랫폼 발신 모니터','FCM outbox와 단말 ACK 상태를 회사 단위로 점검합니다.'],
    'infrastructure'=>['플랫폼 분석 인프라','분석 배치와 Worker heartbeat를 함께 확인합니다.'],
];
[$title,$description]=$titles[$section]??$titles['dashboard'];
$date=static function(?string $value):string{if(!$value)return '-';$time=strtotime($value.' UTC');return $time===false?$value:date('Y.m.d H:i',$time);};
$short=static fn(string $id):string=>substr($id,0,8);
$statusLabels=['ACTIVE'=>'정상','PENDING'=>'대기','LEASED'=>'처리 중','SENT'=>'FCM 전달','ACKED'=>'단말 수신','FAILED'=>'실패','COMPLETED'=>'완료','INSUFFICIENT_DATA'=>'데이터 부족','ACTION_REQUIRED'=>'확인 필요','FAILED_FINAL'=>'최종 실패','CANCELLED'=>'취소'];
$badge=static fn(string $status):string=>match($status){'ACTIVE','ACKED','COMPLETED'=>'success','FAILED','FAILED_FINAL','ACTION_REQUIRED'=>'danger','PENDING','LEASED','SENT'=>'primary',default=>'secondary'};
?>
<div class="page-container ai-platform">
    <header class="ai-platform__head">
        <div><span>SUPER ADMIN · METADATA ONLY</span><h2><?=htmlspecialchars($title)?></h2><p><?=htmlspecialchars($description)?></p></div>
        <div><i class="bi bi-shield-lock"></i><strong>민감 원문 미노출</strong><small>고객명·전화번호·상담 원문 없음</small></div>
    </header>

    <div class="ai-platform__metrics">
        <?php foreach([
            ['활성 회사',(int)$summary['active_companies'],(int)$summary['companies'],'bi-buildings','purple'],
            ['활성 회원',(int)$summary['active_users'],(int)$summary['users'],'bi-people','blue'],
            ['온라인 기기',(int)$summary['online_devices'],(int)$summary['devices'],'bi-phone','green'],
            ['분석 진행',(int)$summary['analysis_active'],(int)$summary['analysis_total'],'bi-stars','purple'],
            ['발신 대기',(int)$summary['delivery_pending'],(int)$summary['delivery_total'],'bi-send','blue'],
            ['발신 실패',(int)$summary['delivery_failed'],(int)$summary['delivery_total'],'bi-exclamation-diamond','red'],
        ] as [$label,$value,$total,$icon,$tone]):?><article><span class="is-<?=$tone?>"><i class="bi <?=$icon?>"></i></span><div><small><?=$label?></small><strong><?=number_format($value)?><em>/ <?=number_format($total)?></em></strong></div></article><?php endforeach;?>
    </div>

    <?php if($section==='dashboard'):?>
        <div class="row g-4">
            <div class="col-xl-7"><section class="ai-platform__panel"><div class="ai-platform__panel-head"><div><h5>최근 회사</h5><p>구독과 사용량 요약</p></div><a href="/admin/mublo-ai/platform/companies">전체 보기</a></div><?php $compact=true;include __DIR__.'/_CompaniesTable.php';?></section></div>
            <div class="col-xl-5"><section class="ai-platform__panel h-100"><div class="ai-platform__panel-head"><div><h5>Worker 상태</h5><p>3분 이내 heartbeat 기준</p></div><a href="/admin/mublo-ai/platform/infrastructure">인프라 보기</a></div><?php if($workers===[]):?><div class="ai-platform__empty">Worker heartbeat 없음</div><?php else:?><div class="ai-platform__worker-list"><?php foreach(array_slice($workers,0,8) as $worker):$online=strtotime((string)$worker['received_at'].' UTC')>=time()-180;?><article><span class="<?=$online?'':'is-offline'?>"></span><div><strong><?=htmlspecialchars((string)$worker['worker_id'])?></strong><small>v<?=htmlspecialchars((string)$worker['version'])?> · 작업 <?=number_format((int)$worker['active_job_count'])?>건</small></div><em><?=$online?'온라인':'오프라인'?></em></article><?php endforeach;?></div><?php endif;?></section></div>
        </div>
        <section class="ai-platform__panel mt-4"><div class="ai-platform__panel-head"><div><h5>주의가 필요한 발신</h5><p>실패·대기 상태를 우선 정렬합니다.</p></div><a href="/admin/mublo-ai/platform/delivery">전체 보기</a></div><?php $compact=true;include __DIR__.'/_DeliveryTable.php';?></section>
    <?php elseif($section==='companies'):?>
        <section class="ai-platform__panel"><div class="ai-platform__toolbar"><div><h5>회사 목록</h5><p>회사명·slug·요금제로 검색할 수 있습니다.</p></div><label><i class="bi bi-search"></i><input type="search" placeholder="회사 검색" data-platform-search></label></div><?php $compact=false;include __DIR__.'/_CompaniesTable.php';?></section>
    <?php elseif($section==='delivery'):?>
        <div class="alert alert-info ai-platform__notice"><i class="bi bi-info-circle me-2"></i>메시지 본문과 수신 전화번호는 이 화면과 FCM payload에 표시되지 않습니다.</div><section class="ai-platform__panel"><?php $compact=false;include __DIR__.'/_DeliveryTable.php';?></section>
    <?php elseif($section==='infrastructure'):?>
        <div class="row g-4"><div class="col-xl-8"><section class="ai-platform__panel"><div class="ai-platform__panel-head"><div><h5>최근 분석 배치</h5><p>회사별 분석 진행과 확인 필요 건수</p></div></div><?php if($analysis===[]):?><div class="ai-platform__empty">분석 배치 없음</div><?php else:?><div class="table-responsive"><table class="table align-middle"><thead><tr><th>회사</th><th>배치</th><th>목적</th><th>진행</th><th>확인 필요</th><th>갱신</th></tr></thead><tbody><?php foreach($analysis as $batch):?><tr><td><strong><?=htmlspecialchars((string)$batch['company_name'])?></strong></td><td class="ai-platform__mono"><?=htmlspecialchars($short((string)$batch['batch_id']))?></td><td><?=htmlspecialchars((string)$batch['purpose'])?></td><td><?=number_format((int)$batch['terminal_count'])?> / <?=number_format((int)$batch['total_customers'])?></td><td><span class="badge text-bg-<?=(int)$batch['action_count']>0?'danger':'light'?>"><?=number_format((int)$batch['action_count'])?>건</span></td><td class="text-muted"><?=$date((string)$batch['updated_at'])?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section></div><div class="col-xl-4"><section class="ai-platform__panel h-100"><div class="ai-platform__panel-head"><div><h5>Worker</h5><p>현재 heartbeat</p></div></div><?php if($workers===[]):?><div class="ai-platform__empty">Worker heartbeat 없음</div><?php else:?><div class="ai-platform__worker-list"><?php foreach($workers as $worker):$online=strtotime((string)$worker['received_at'].' UTC')>=time()-180;?><article><span class="<?=$online?'':'is-offline'?>"></span><div><strong><?=htmlspecialchars((string)$worker['worker_id'])?></strong><small>v<?=htmlspecialchars((string)$worker['version'])?> · 작업 <?=number_format((int)$worker['active_job_count'])?>건</small></div><em><?=$online?'온라인':'오프라인'?></em></article><?php endforeach;?></div><?php endif;?></section></div></div>
    <?php endif;?>
</div>
<script>(function(){var input=document.querySelector('[data-platform-search]'),table=document.querySelector('[data-platform-table]');if(!input||!table)return;input.addEventListener('input',function(){var q=input.value.trim().toLocaleLowerCase('ko');table.querySelectorAll('tbody tr').forEach(function(row){row.hidden=q!==''&&!row.textContent.toLocaleLowerCase('ko').includes(q);});});})();</script>
