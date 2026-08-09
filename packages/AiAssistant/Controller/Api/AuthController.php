<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Controller\Api;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\JsonResponse;
use Mublo\Packages\AiAssistant\Exception\ApiException;

final class AuthController extends ApiController
{
    public function login(array $params, Context $context): JsonResponse
    {
        return $this->respond($context, function () use ($context): JsonResponse {
            $input = $this->json($context);
            $loginId = trim((string) ($input['user_id'] ?? ''));
            $password = (string) ($input['password'] ?? '');
            if ($loginId === '' || $password === '') {
                throw new ApiException('AUTH_INPUT_INVALID', '아이디와 비밀번호를 입력해 주세요.', 422);
            }
            $companySlug = isset($input['company_slug']) ? trim((string) $input['company_slug']) : null;
            $data = $this->auth->login($companySlug, $context->getDomainId(), $loginId, $password);
            return JsonResponse::success($data, '로그인했습니다.');
        });
    }

    public function refresh(array $params, Context $context): JsonResponse
    {
        return $this->respond($context, function () use ($context): JsonResponse {
            $input = $this->json($context);
            $data = $this->auth->refresh((string) ($input['refresh_token'] ?? ''));
            return JsonResponse::success($data, '인증을 갱신했습니다.');
        });
    }

    public function me(array $params, Context $context): JsonResponse
    {
        return $this->respond($context, function () use ($context): JsonResponse {
            $principal = $this->principal($context);
            unset($principal['token_id']);
            return JsonResponse::success(['principal' => $principal]);
        });
    }

    public function logout(array $params, Context $context): JsonResponse
    {
        return $this->respond($context, function () use ($context): JsonResponse {
            $this->auth->logout($context->getRequest()->bearerToken());
            return JsonResponse::success(null, '로그아웃했습니다.');
        });
    }
}
