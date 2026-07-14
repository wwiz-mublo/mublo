<?php
namespace Mublo\Plugin\Survey\Event;

use Mublo\Core\Event\AbstractEvent;

class SurveySubmittedEvent extends AbstractEvent
{
    public function __construct(
        public readonly int  $surveyId,
        public readonly int  $responseId,
        public readonly ?int $memberId,
    ) {}
}
