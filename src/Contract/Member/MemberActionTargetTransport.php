<?php
declare(strict_types=1);

namespace Mublo\Contract\Member;

enum MemberActionTargetTransport: string
{
    case PrivateBody = 'private_body';
    case PublicQuery = 'public_query';
    case PublicPath = 'public_path';
}
