<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Contract;

interface SchedulePushGatewayInterface
{
    /**
     * @param array<string, string|int|bool> $data
     * @return array{success:bool,message_id:string,error_code:string,token_invalid:bool,error:string}
     */
    public function send(string $fcmToken, array $data): array;
}
