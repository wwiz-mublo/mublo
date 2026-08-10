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
 *  - 도메인 기본 정보 (shop sample)
 *  - 사업자/고객센터 샘플 변수값
 * 을 한 곳에서 관리해 중복 제거.
 *
 * ## 확장의 변수는 이벤트로만 들어온다
 *
 * 여기서 다루는 것은 **코어가 소유한 문맥**(도메인 설정·사업자정보)뿐이다.
 * 확장이 제공하는 변수는 `CollectNotificationVariablesEvent` 를 구독해
 * 각 확장이 자기 저장소로 채워 넣는다 — 코어가 확장 테이블을 읽지 않는다.
 *
 * 과거에는 특정 폼 플러그인의 테이블(`forms`·`form_fields`)을 직접 조회해
 * 폼 목록·필드·상태 옵션을 얻고 예문 본문까지 생성하는 코드가 있었다.
 * 확장 스키마가 코어에 새어 든 것이라 걷어냈다(호출부는 처음부터 없었다).
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
     * 각 플러그인·패키지(주문 정보, 폼별 필드 등) 가 등록한 변수에 더해,
     * 도메인의 사업자정보(domain_configs.company_config) 와 사이트정보를
     * 자동으로 별도 그룹("사업자/고객센터 정보") 으로 추가.
     *
     * @return array<string, array<string, string>>
     */
    public function collectVariableGroups(?int $domainId = null): array
    {
        $groups = [];

        // 1) 확장이 등록한 변수
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
}
