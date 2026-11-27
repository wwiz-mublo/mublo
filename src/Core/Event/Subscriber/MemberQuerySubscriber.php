<?php
declare(strict_types=1);

namespace Mublo\Core\Event\Subscriber;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Core\Event\Member\MemberLevelListQueryEvent;
use Mublo\Core\Event\Member\MemberListQueryEvent;
use Mublo\Repository\Member\MemberLevelRepository;
use Mublo\Repository\Member\MemberRepository;

/**
 * Core 회원 조회 이벤트 구독자
 *
 * Plugin/Package에서 MemberListQueryEvent를 발행하면
 * MemberRepository를 통해 조건에 맞는 회원 목록을 조회하여 이벤트에 설정.
 *
 * 등록: ServiceProvider에서 EventDispatcher에 addSubscriber()
 */
class MemberQuerySubscriber implements EventSubscriberInterface
{
    /** @var MemberRepository|\Closure(): MemberRepository */
    private MemberRepository|\Closure $memberRepository;
    /** @var MemberLevelRepository|\Closure(): MemberLevelRepository */
    private MemberLevelRepository|\Closure $memberLevelRepository;

    public function __construct(
        MemberRepository|\Closure $memberRepository,
        MemberLevelRepository|\Closure $memberLevelRepository,
    ) {
        $this->memberRepository = $memberRepository;
        $this->memberLevelRepository = $memberLevelRepository;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            MemberListQueryEvent::class      => 'onMemberListQuery',
            MemberLevelListQueryEvent::class => 'onMemberLevelListQuery',
        ];
    }

    /**
     * 회원 목록 조회 이벤트 처리
     */
    public function onMemberListQuery(MemberListQueryEvent $event): void
    {
        $members = $this->memberRepository()->findByCriteria(
            $event->getDomainId(),
            $event->getCriteria()
        );

        $event->setMembers($members);
    }

    /**
     * 회원 등급 목록 조회 이벤트 처리
     */
    public function onMemberLevelListQuery(MemberLevelListQueryEvent $event): void
    {
        $memberOnly = $event->getFilter('member_only')
            || ($event->getFilter('exclude_admin') && $event->getFilter('exclude_super'));

        $entities = $memberOnly
            ? $this->memberLevelRepository()->getMemberLevels()   // is_admin=0 AND is_super=0
            : $this->memberLevelRepository()->getAll();

        $levelType = $event->getFilter('level_type');

        $levels = [];
        foreach ($entities as $level) {
            if ($levelType !== null && $level->getLevelType() !== $levelType) {
                continue;
            }

            $levels[] = [
                'level_id'    => $level->getLevelId(),
                'level_value' => $level->getLevelValue(),
                'level_name'  => $level->getLevelName(),
                'level_type'  => $level->getLevelType(),
                'is_admin'    => $level->canAccessAdmin(),
                'is_super'    => $level->isSuper(),
            ];
        }

        $event->setLevels($levels);
    }

    private function memberRepository(): MemberRepository
    {
        if ($this->memberRepository instanceof \Closure) {
            $this->memberRepository = ($this->memberRepository)();
        }
        return $this->memberRepository;
    }

    private function memberLevelRepository(): MemberLevelRepository
    {
        if ($this->memberLevelRepository instanceof \Closure) {
            $this->memberLevelRepository = ($this->memberLevelRepository)();
        }
        return $this->memberLevelRepository;
    }
}
