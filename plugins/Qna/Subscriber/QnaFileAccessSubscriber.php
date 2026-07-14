<?php
namespace Mublo\Plugin\Qna\Subscriber;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Core\Event\Storage\SecureFileAccessEvent;
use Mublo\Plugin\Qna\Entity\QnaPost;
use Mublo\Plugin\Qna\Repository\QnaPostRepository;
use Mublo\Contract\Auth\AuthContextInterface;

/**
 * QnaFileAccessSubscriber
 *
 * QnA 첨부(category='qna')의 보안 다운로드 권한을 판정한다.
 * 코어 DownloadController 는 알지 못하는 카테고리를 이 이벤트로 위임하고,
 * 아무도 grant() 하지 않으면 관리자만 허용한다.
 *
 * 첨부의 entityId 는 스레드 헤드(질문) post_id 다. 따라서 질문의 열람 규칙
 * ([[QnaPost]]::canBeViewedBy — 작성자 본인/운영자만)을 그대로 재사용해
 * 문의자 본인이 자기 문의 첨부를 내려받게 한다.
 */
class QnaFileAccessSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private QnaPostRepository $postRepo,
        private AuthContextInterface       $auth,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SecureFileAccessEvent::class => 'onSecureFileAccess',
        ];
    }

    public function onSecureFileAccess(SecureFileAccessEvent $event): void
    {
        if ($event->getCategory() !== 'qna') {
            return;
        }

        $questionId = (int) $event->getEntityId();
        $row = $this->postRepo->findQuestion($questionId, $event->getDomainId());
        if (!$row) {
            return; // grant 안 함 → 관리자 폴백
        }

        $viewerId = $this->auth->check() ? (int) $this->auth->id() : null;

        if (QnaPost::fromArray($row)->canBeViewedBy($viewerId, $this->auth->isAdmin())) {
            $event->grant();
        }
    }
}
