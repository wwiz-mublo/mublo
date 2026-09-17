<?php
declare(strict_types=1);

namespace Mublo\Contract\Member;

use Mublo\Core\Result\Result;

interface PolicyQueryInterface
{
    /** @return list<PolicyDocument> */
    public function activeDocuments(int $domainId): array;

    /**
     * 회원가입 화면에 표시할 약관 목록.
     *
     * activeDocuments 는 활성 약관 전부라 가입과 무관한 것도 섞인다. 확장이 자기 가입
     * 화면을 그릴 때는 코어 가입 폼과 같은 목록을 써야 한다.
     *
     * @return PolicyDocument[]
     */
    public function registerDocuments(int $domainId): array;

    /**
     * 선택한 약관이 가입 요건을 만족하는지 검증한다.
     *
     * 필수 약관을 빠뜨렸는지 판정하는 것은 코어의 몫이다 — 확장이 판단하면 사이트마다
     * 기준이 갈리고, 화면을 우회한 요청도 통과한다. 실패 메시지는 어떤 약관이 빠졌는지
     * 알려주므로 확장이 그대로 보여주면 된다.
     *
     * @param int[] $agreedPolicyIds
     */
    public function validateRegisterAgreements(int $domainId, array $agreedPolicyIds): Result;

    public function findDocument(int $domainId, int $policyId): ?PolicyDocument;

    public function renderDocument(PolicyDocument $document, array $domainConfig): string;
}
