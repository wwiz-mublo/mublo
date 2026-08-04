<?php
declare(strict_types=1);

namespace Mublo\Service\Notification;

use Mublo\Contract\Notification\CollectNotificationVariablesEvent;
use Mublo\Contract\Notification\NotificationTemplateContextInterface;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Infrastructure\Database\Database;

/**
 * 알림 템플릿 관리 UI 공용 헬퍼.
 *
 * 알림 게이트웨이 플러그인의 템플릿 작성 화면에서 공통으로 필요한
 *  - 변수 그룹 수집 (CollectNotificationVariablesEvent)
 *  - 활성 폼 + 필드 + 상태 옵션 (AutoForm)
 *  - 폼/트리거별 예문 본문 휴리스틱 생성
 *  - 도메인 기본 정보 (shop sample)
 *  - 미리보기 샘플 변수값
 * 을 한 곳에서 관리해 중복 제거.
 *
 * AutoForm 미설치 환경은 빈 배열로 안전 fallback.
 */
class NotificationTemplateUiHelper implements NotificationTemplateContextInterface
{
    public function __construct(
        private readonly Database $db,
        private readonly ?EventDispatcher $eventDispatcher = null,
    ) {}

    /**
     * 도메인 기본 정보 (샵 이름/도메인) — 미리보기 샘플 치환용.
     *
     * @return array{shop_name:string, customer_tel:string, customer_time:string, domain:string}
     */
    public function loadShopSample(int $domainId): array
    {
        $sample = ['shop_name' => '', 'customer_tel' => '', 'customer_time' => '', 'domain' => ''];
        try {
            $row = $this->db->selectOne(
                "SELECT domain, JSON_UNQUOTE(JSON_EXTRACT(site_config, '$.site_title')) AS site_title
                 FROM domain_configs WHERE domain_id = ?",
                [$domainId]
            );
            if ($row) {
                $sample['shop_name'] = (string) ($row['site_title'] ?? '');
                $sample['domain']    = (string) ($row['domain'] ?? '');
            }
        } catch (\Throwable) {}
        return $sample;
    }

    /**
     * 도메인의 사업자정보 + 사이트정보 로드 (domain_configs.company_config + site_config).
     *
     * @return array company_config + site_config 병합 결과
     */
    public function loadCompanyInfo(int $domainId): array
    {
        try {
            $row = $this->db->selectOne(
                "SELECT company_config, site_config FROM domain_configs WHERE domain_id = ?",
                [$domainId]
            );
            $cc = $row['company_config'] ?? null;
            $sc = $row['site_config'] ?? null;
            if (is_string($cc)) $cc = json_decode($cc, true) ?: [];
            if (is_string($sc)) $sc = json_decode($sc, true) ?: [];
            return [
                'company' => is_array($cc) ? $cc : [],
                'site'    => is_array($sc) ? $sc : [],
            ];
        } catch (\Throwable) {
            return ['company' => [], 'site' => []];
        }
    }

    /**
     * 알림 치환 변수 그룹 수집 (이벤트 기반 + 사업자/사이트 정보).
     *
     * 각 플러그인·패키지(AutoForm 의 폼별 필드 등) 가 등록한 변수에 더해,
     * 도메인의 사업자정보(domain_configs.company_config) 와 사이트정보를
     * 자동으로 별도 그룹("사업자/고객센터 정보") 으로 추가.
     *
     * @return array<string, array<string, string>>
     */
    public function collectVariableGroups(?int $domainId = null): array
    {
        $groups = [];

        // 1) 이벤트 기반 변수 (AutoForm 등)
        if ($this->eventDispatcher !== null) {
            $event = new CollectNotificationVariablesEvent();
            $this->eventDispatcher->dispatch($event);

            foreach ($event->getSources() as $source) {
                $label = (string) ($source['label'] ?? '');
                if ($label === '') {
                    continue;
                }
                $transformed = [];
                foreach ($source['variables'] as $key => $desc) {
                    $displayKey = str_starts_with((string) $key, '#{') ? (string) $key : '#{' . $key . '}';
                    $transformed[$displayKey] = (string) $desc;
                }
                $groups[$label] = $transformed;
            }
        }

        // 2) 사업자/고객센터 정보 그룹 (실제 등록된 항목만)
        if ($domainId !== null) {
            $bizGroup = $this->buildCompanyVariableGroup($domainId);
            if (!empty($bizGroup)) {
                $groups['사업자/고객센터 정보'] = $bizGroup;
            }
        }

        return $groups;
    }

    /**
     * 사업자/고객센터 변수 키 → 설명 맵 (등록된 항목만).
     *
     * @return array<string, string>
     */
    private function buildCompanyVariableGroup(int $domainId): array
    {
        $info = $this->loadCompanyInfo($domainId);
        $cc = $info['company'];
        $sc = $info['site'];

        $vars = [];
        if (!empty($sc['site_title']))       $vars['#{사이트명}']         = '사이트/쇼핑몰 이름';
        if (!empty($cc['name']))             $vars['#{회사명}']           = '사업자 회사명';
        if (!empty($cc['owner']))            $vars['#{대표자}']           = '대표자명';
        if (!empty($cc['tel']))              $vars['#{고객센터번호}']     = '대표 전화 (고객센터)';
        if (!empty($cc['fax']))              $vars['#{팩스}']             = '팩스 번호';
        if (!empty($cc['email']))            $vars['#{고객센터이메일}']   = '대표 이메일';
        if (!empty($cc['business_number']))  $vars['#{사업자번호}']       = '사업자등록번호';
        if (!empty($cc['tongsin_number']))   $vars['#{통신판매업번호}']   = '통신판매업 신고번호';
        if (!empty($cc['address']) || !empty($cc['address_detail'])) {
            $vars['#{회사주소}'] = '사업장 주소';
        }
        if (!empty($cc['zipcode']))          $vars['#{우편번호}']         = '사업장 우편번호';
        if (!empty($cc['privacy_officer']))  $vars['#{개인정보책임자}']   = '개인정보 책임자';
        if (!empty($cc['privacy_email']))    $vars['#{개인정보이메일}']   = '개인정보 책임자 이메일';

        return $vars;
    }

    /**
     * 사업자/고객센터 변수 키 → 실제값 맵 (미리보기 샘플용).
     *
     * @return array<string, string>
     */
    public function getCompanySampleValues(int $domainId): array
    {
        $info = $this->loadCompanyInfo($domainId);
        $cc = $info['company'];
        $sc = $info['site'];

        $samples = [];
        if (!empty($sc['site_title']))       $samples['사이트명']         = $sc['site_title'];
        if (!empty($cc['name']))             $samples['회사명']           = $cc['name'];
        if (!empty($cc['owner']))            $samples['대표자']           = $cc['owner'];
        if (!empty($cc['tel']))              $samples['고객센터번호']     = $cc['tel'];
        if (!empty($cc['fax']))              $samples['팩스']             = $cc['fax'];
        if (!empty($cc['email']))            $samples['고객센터이메일']   = $cc['email'];
        if (!empty($cc['business_number']))  $samples['사업자번호']       = $cc['business_number'];
        if (!empty($cc['tongsin_number']))   $samples['통신판매업번호']   = $cc['tongsin_number'];
        $addr = trim(($cc['address'] ?? '') . ' ' . ($cc['address_detail'] ?? ''));
        if ($addr !== '')                    $samples['회사주소']         = $addr;
        if (!empty($cc['zipcode']))          $samples['우편번호']         = $cc['zipcode'];
        if (!empty($cc['privacy_officer']))  $samples['개인정보책임자']   = $cc['privacy_officer'];
        if (!empty($cc['privacy_email']))    $samples['개인정보이메일']   = $cc['privacy_email'];

        return $samples;
    }

    /**
     * 활성 AutoForm 폼 + 필드 + 상태 옵션 조회.
     * AutoForm 미설치 (테이블 없음) 환경은 빈 배열 반환.
     *
     * @return array<int, array{form_id:int, form_name:string, fields:array, status_options:array}>
     */
    public function loadActiveFormsWithMeta(int $domainId): array
    {
        $forms = [];
        try {
            $formRows = $this->db->select(
                "SELECT form_id, form_name FROM forms
                 WHERE domain_id = ? AND status = 'active'
                 ORDER BY form_id ASC",
                [$domainId]
            );
        } catch (\Throwable) {
            return [];
        }

        foreach ($formRows as $f) {
            $formId = (int) ($f['form_id'] ?? 0);
            $formName = (string) ($f['form_name'] ?? '');
            if ($formId <= 0 || $formName === '') {
                continue;
            }

            $fields = [];
            $statusOptions = [];
            try {
                $fieldRows = $this->db->select(
                    "SELECT field_name, field_label, field_type, field_options_json, field_config_json
                     FROM form_fields WHERE domain_id = ? AND form_id = ? AND is_active = 1
                     ORDER BY sort_order ASC, field_id ASC",
                    [$domainId, $formId]
                );
                foreach ($fieldRows as $fld) {
                    $fields[] = [
                        'name'  => (string) ($fld['field_name'] ?? ''),
                        'label' => (string) ($fld['field_label'] ?? $fld['field_name'] ?? ''),
                        'type'  => (string) ($fld['field_type'] ?? 'text'),
                    ];

                    if (($fld['field_type'] ?? '') === 'status') {
                        $opts = $fld['field_options_json'] ?? null;
                        if (is_string($opts)) {
                            $opts = json_decode($opts, true) ?: [];
                        }
                        if (is_array($opts)) {
                            foreach ($opts as $opt) {
                                $val = (string) ($opt['value'] ?? '');
                                $lbl = (string) ($opt['label'] ?? $val);
                                if ($val !== '') {
                                    $statusOptions[] = ['value' => $val, 'label' => $lbl];
                                }
                            }
                        }
                    }
                }
            } catch (\Throwable) {}

            $forms[] = [
                'form_id'        => $formId,
                'form_name'      => $formName,
                'fields'         => $fields,
                'status_options' => $statusOptions,
            ];
        }
        return $forms;
    }

    /**
     * 폼/트리거별 메시지 본문 예문 자동 생성 (PHP 휴리스틱).
     *
     * 키 형식: "form_{$formId}_submit" / "form_{$formId}_status_{$statusValue}"
     *
     * @return array<string, string>
     */
    public function buildExampleBodies(array $forms): array
    {
        $examples = [];
        foreach ($forms as $form) {
            $formId = $form['form_id'];

            $examples["form_{$formId}_submit"] = $this->renderSubmitExample($form);

            foreach ($form['status_options'] as $opt) {
                $key = "form_{$formId}_status_" . $opt['value'];
                $examples[$key] = $this->renderStatusChangeExample($form, $opt['label']);
            }
        }
        return $examples;
    }

    /**
     * 미리보기용 샘플값 — 시스템 변수 + 폼 필드 라벨별 휴리스틱 샘플.
     *
     * @return array<string, string>
     */
    public function buildSamplePreviewValues(int $domainId, array $forms, array $shopSample): array
    {
        $sampleShopVars = [
            '폼제목'   => '문의 폼',
            '제출번호' => '12345',
            '제출일시' => date('Y-m-d H:i'),
            '쇼핑몰명' => $shopSample['shop_name'] ?: '내 사이트',
            '도메인'   => $shopSample['domain'] ?: 'example.com',
        ];

        // 사업자/고객센터 샘플값 머지 (실제 등록된 값)
        $sampleShopVars = array_merge($sampleShopVars, $this->getCompanySampleValues($domainId));

        $sampleFieldVars = [];
        foreach ($forms as $f) {
            foreach (($f['fields'] ?? []) as $fld) {
                $label = $fld['label'] ?? '';
                if ($label === '' || isset($sampleFieldVars[$label])) continue;
                $type = $fld['type'] ?? 'text';
                $name = strtolower($fld['name'] ?? '');
                $sampleFieldVars[$label] = match (true) {
                    $type === 'email' || str_contains($name, 'email') || str_contains($name, 'mail')
                        => 'sample@test.com',
                    $type === 'tel' || $type === 'phone'
                        || str_contains($label, '연락처') || str_contains($label, '전화') || str_contains($label, '휴대')
                        => '010-1234-5678',
                    str_contains($label, '이름') || str_contains($label, '성함') || str_contains($label, '닉네임')
                        => '홍길동',
                    str_contains($label, '주소')   => '서울시 강남구 테헤란로 123',
                    str_contains($label, '날짜')   => date('Y-m-d'),
                    str_contains($label, '시간')   => '14:00',
                    $type === 'number'              => '1',
                    $type === 'textarea'            => '문의 내용 예시입니다.',
                    default                          => '예시값',
                };
            }
        }

        return array_merge($sampleShopVars, $sampleFieldVars);
    }

    private function renderSubmitExample(array $form): string
    {
        $nameLabel = $this->guessNameLabel($form['fields']);
        $detailLines = $this->buildDetailLines($form['fields']);

        $greeting = $nameLabel
            ? "#{{$nameLabel}}님, 접수가 완료되었습니다."
            : "[#{폼제목}] 접수가 완료되었습니다.";

        $body = $greeting;
        if (!empty($detailLines)) {
            $body .= "\n\n" . implode("\n", $detailLines);
        }
        $body .= "\n\n접수번호: #{제출번호}\n접수일시: #{제출일시}";
        return $body;
    }

    private function renderStatusChangeExample(array $form, string $statusLabel): string
    {
        $nameLabel = $this->guessNameLabel($form['fields']);
        $greeting = $nameLabel
            ? "#{{$nameLabel}}님, 상태가 변경되었습니다."
            : "접수 상태가 변경되었습니다.";

        return $greeting
            . "\n\n폼: #{폼제목}"
            . "\n변경된 상태: {$statusLabel}"
            . "\n\n접수번호: #{제출번호}";
    }

    private function guessNameLabel(array $fields): ?string
    {
        $namePatterns = ['이름', '성함', '닉네임', '고객명', '신청자'];
        foreach ($fields as $f) {
            $label = $f['label'] ?? '';
            if ($label === '') continue;
            foreach ($namePatterns as $p) {
                if (str_contains($label, $p)) {
                    return $label;
                }
            }
            $name = strtolower($f['name'] ?? '');
            if (in_array($name, ['name', 'username', 'customer_name'], true)) {
                return $label;
            }
        }
        return null;
    }

    private function buildDetailLines(array $fields): array
    {
        $lines = [];
        $skipPatterns = ['이름', '성함', '닉네임', '고객명', '신청자', '상태'];
        foreach ($fields as $f) {
            $label = $f['label'] ?? '';
            $type  = $f['type'] ?? 'text';
            if ($label === '' || $type === 'status' || $type === 'hidden') continue;
            $skip = false;
            foreach ($skipPatterns as $p) {
                if (str_contains($label, $p)) { $skip = true; break; }
            }
            if ($skip) continue;

            $lines[] = "- {$label}: #{{$label}}";
            if (count($lines) >= 4) break;
        }
        return $lines;
    }
}
