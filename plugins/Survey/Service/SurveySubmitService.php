<?php
declare(strict_types=1);
namespace Mublo\Plugin\Survey\Service;

use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\EventInterface;
use Mublo\Core\Result\Result;
use Mublo\Plugin\Survey\Entity\Survey;
use Mublo\Plugin\Survey\Entity\SurveyQuestion;
use Mublo\Plugin\Survey\Enum\QuestionType;
use Mublo\Plugin\Survey\Enum\SurveyStatus;
use Mublo\Plugin\Survey\Event\SurveySubmittedEvent;
use Mublo\Plugin\Survey\Repository\SurveyAnswerRepository;
use Mublo\Plugin\Survey\Repository\SurveyQuestionRepository;
use Mublo\Plugin\Survey\Repository\SurveyRepository;
use Mublo\Plugin\Survey\Repository\SurveyResponseRepository;

class SurveySubmitService
{
    public function __construct(
        private SurveyRepository         $surveyRepo,
        private SurveyQuestionRepository  $questionRepo,
        private SurveyResponseRepository  $responseRepo,
        private SurveyAnswerRepository    $answerRepo,
        private ?EventDispatcher          $eventDispatcher = null,
    ) {}

    /**
     * 참여 가능 여부 검사
     * 반환: Result::success (참여 가능) / Result::failure (참여 불가 사유)
     */
    public function canParticipate(int $surveyId, int $domainId, ?int $memberId, string $ip): Result
    {
        $row = $this->surveyRepo->findScoped($surveyId, $domainId);
        if (!$row) {
            return Result::failure('설문을 찾을 수 없습니다.');
        }

        $survey = Survey::fromArray($row);

        if (!$survey->isActive()) {
            return Result::failure('진행 중인 설문이 아닙니다.');
        }

        if (!$survey->isWithinPeriod()) {
            return Result::failure('설문 참여 기간이 아닙니다.');
        }

        // 비회원 참여 불가 설정
        if ($memberId === null && !$survey->allowsAnonymous()) {
            return Result::failure('회원만 참여할 수 있는 설문입니다.');
        }

        // 응답 한도 확인
        $currentCount = $this->responseRepo->countBySurvey($surveyId);
        if ($survey->isResponseLimitReached($currentCount)) {
            return Result::failure('설문 참여 인원이 마감되었습니다.');
        }

        // 중복 참여 확인
        if (!$survey->allowsDuplicate()) {
            if ($memberId !== null && $this->responseRepo->existsByMember($surveyId, $memberId)) {
                return Result::failure('이미 참여한 설문입니다.');
            }
            if ($memberId === null && $this->responseRepo->existsByIp($surveyId, $ip)) {
                return Result::failure('이미 참여한 설문입니다. (동일 IP)');
            }
        }

        return Result::success('참여 가능합니다.', ['survey' => $row]);
    }

    /**
     * 설문 제출
     * $answers: [question_id => value] (value는 문자열 또는 배열)
     */
    public function submit(int $surveyId, int $domainId, array $answers, ?int $memberId, string $ip): Result
    {
        $check = $this->canParticipate($surveyId, $domainId, $memberId, $ip);
        if ($check->isFailure()) {
            return $check;
        }

        $questions = $this->questionRepo->findBySurvey($surveyId);
        if (empty($questions)) {
            return Result::failure('질문이 없는 설문입니다.');
        }

        // 필수 질문 검증
        // 미응답은 키 자체가 없을 수 있으므로 반드시 키 존재부터 확인한다(없는 키 접근 시
        // Undefined array key 경고 → ErrorException → 500 이 되던 버그). '0'/0(옵션 인덱스·평점)은 유효 응답.
        foreach ($questions as $q) {
            if (empty($q['required'])) {
                continue;
            }
            $qid = (int) $q['question_id'];
            $val = $answers[$qid] ?? null;
            $unanswered = $val === null || $val === '' || (is_array($val) && count($val) === 0);
            if ($unanswered) {
                return Result::failure(sprintf('"%s" 항목은 필수입니다.', $q['title']));
            }
        }

        // 응답 세션 생성
        $responseId = $this->responseRepo->createResponse([
            'survey_id'    => $surveyId,
            'member_id'    => $memberId,
            'ip_address'   => $ip,
            'submitted_at' => date('Y-m-d H:i:s'),
        ]);

        // 개별 답변 저장
        $answerRows = [];
        foreach ($questions as $q) {
            $qid  = (int) $q['question_id'];
            $type = QuestionType::from($q['type']);
            $raw  = $answers[$qid] ?? null;

            if ($raw === null || $raw === '') {
                continue;
            }

            if ($type->hasOptions()) {
                $opts = is_array($raw) ? $raw : [$raw];
                $opts = array_map('intval', $opts);
                $answerRows[] = [
                    'response_id' => $responseId,
                    'question_id' => $qid,
                    'answer_text' => null,
                    'answer_opts' => json_encode($opts),
                ];
            } else {
                $answerRows[] = [
                    'response_id' => $responseId,
                    'question_id' => $qid,
                    'answer_text' => (string) $raw,
                    'answer_opts' => null,
                ];
            }
        }

        $this->answerRepo->createBulk($answerRows);

        $this->dispatch(new SurveySubmittedEvent($surveyId, $responseId, $memberId));

        return Result::success('설문에 참여해 주셔서 감사합니다.', ['response_id' => $responseId]);
    }

    /**
     * @template T of EventInterface
     * @param T $event
     * @return T
     */
    private function dispatch(EventInterface $event): EventInterface
    {
        return $this->eventDispatcher?->dispatch($event) ?? $event;
    }
}
