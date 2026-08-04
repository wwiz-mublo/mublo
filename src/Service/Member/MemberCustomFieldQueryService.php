<?php
declare(strict_types=1);

namespace Mublo\Service\Member;

use Mublo\Contract\Member\MemberCustomFieldQueryInterface;
use Mublo\Repository\Member\MemberFieldRepository;

final class MemberCustomFieldQueryService implements MemberCustomFieldQueryInterface
{
    public function __construct(
        private MemberFieldRepository $fields,
        private MemberService $members
    ) {
    }

    public function findValue(int $memberId, int $domainId, string $fieldName): mixed
    {
        if ($memberId <= 0 || $domainId <= 0 || trim($fieldName) === '') {
            return null;
        }

        $field = $this->fields->findByDomainAndName($domainId, $fieldName);
        $fieldId = (int) ($field['field_id'] ?? 0);
        if ($fieldId <= 0) {
            return null;
        }

        foreach ($this->members->getFieldValues($memberId) as $row) {
            if ((int) ($row['field_id'] ?? 0) === $fieldId) {
                return $row['field_value'] ?? null;
            }
        }

        return null;
    }
}
