<?php
namespace Mublo\Core\App;

use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Core\Response\AbstractResponse;
use Mublo\Core\Middleware\MiddlewarePipeline;
use Mublo\Exception\HttpNotFoundException;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Class Dispatcher
 *
 * Router가 결정한 라우트 정보를 바탕으로 Controller를 실행하는 역할
 *
 * 책임:
 * - Controller 인스턴스 생성 (생성자 DI 지원)
 * - Controller Action 메서드 호출 (파라미터 자동 주입)
 * - Response 타입 검증
 *
 * 금지:
 * - 라우팅 판단 (Router의 역할)
 * - 인증 / 권한 판단 (Middleware의 역할)
 * - HTML 직접 출력 (Renderer의 역할)
 */
class Dispatcher
{
    /**
     * DI 컨테이너
     * 
     * Controller 생성 시 필요한 의존성을 해결하기 위해 사용
     */
    protected DependencyContainer $container;

    public function __construct(DependencyContainer $container)
    {
        $this->container = $container;
    }

    /**
     * Controller 실행
     *
     * @param array{
     *   controller: class-string,  // Controller 클래스명 (FQCN)
     *   method: string,            // 실행할 메서드명
     *   params?: array             // 라우트 파라미터 (예: ['id' => 123])
     * } $route Router가 반환한 라우트 정보
     * @param Context $context 현재 요청의 애플리케이션 상태
     * @return AbstractResponse
     * @throws HttpNotFoundException|\RuntimeException
     */
    public function dispatch(array $route, Context $context)
    {
        $controllerClass = $route['controller'];
        $method          = $route['method'];
        $params          = $route['params'] ?? [];
        $middlewares     = $route['middleware'] ?? [];

        // ==================================================
        // 1. Controller 클래스 존재 여부 확인
        // ==================================================
        if (!class_exists($controllerClass)) {
            throw new HttpNotFoundException(
                "Controller not found: {$controllerClass}"
            );
        }

        // ==================================================
        // 2. Controller 인스턴스 생성
        //
        // 우선순위:
        // 1) 컨테이너에 명시적으로 등록된 경우 → 컨테이너에서 가져옴
        // 2) 등록 안 된 경우 → createController()로 자동 생성 (생성자 DI)
        // ==================================================
        if ($this->container->has($controllerClass)) {
            $controller = $this->container->get($controllerClass);
        } else {
            $controller = $this->createController($controllerClass);
        }

        // ==================================================
        // 3. Action 메서드 존재 여부 확인 + public 접근 제어
        //
        // method_exists는 private/protected에도 true를 반환하므로
        // ReflectionMethod로 public만 허용하여 내부 메서드 노출 방지
        // ==================================================
        if (!method_exists($controller, $method)) {
            throw new HttpNotFoundException(
                "Method not found: {$controllerClass}::{$method}"
            );
        }

        $reflMethod = new \ReflectionMethod($controller, $method);
        if (!$reflMethod->isPublic() || $reflMethod->isStatic()) {
            throw new HttpNotFoundException(
                "Method not found: {$controllerClass}::{$method}"
            );
        }

        // ==================================================
        // 4. Middleware Pipeline 실행
        // - 라우트에 정의된 미들웨어를 순차적으로 실행
        // - 최종적으로 Controller Action 실행
        // ==================================================
        $pipeline = new MiddlewarePipeline($this->container);
        $pipeline->through($middlewares);

        $response = $pipeline->run(
            $context->getRequest(),
            $context,
            function ($request, $context) use ($controller, $method, $params) {
                return $this->invokeAction($controller, $method, $params, $context);
            }
        );

        // ==================================================
        // 5. Response 타입 검증
        // - Controller는 반드시 Response 객체를 반환해야 함
        // ==================================================
        if (!$response instanceof AbstractResponse) {
            throw new \RuntimeException(
                'Controller must return a Response object (AbstractResponse). Returned: ' .
                (is_object($response) ? get_class($response) : gettype($response))
            );
        }

        return $response;
    }

    /**
     * Controller 인스턴스 생성 (생성자 의존성 자동 주입)
     *
     * Reflection을 사용하여 생성자 파라미터를 분석하고,
     * 타입 힌트를 기반으로 의존성을 자동으로 해결합니다.
     *
     * 예시:
     * ```php
     * class BoardController {
     *     public function __construct(BoardService $service) {}
     * }
     * ```
     * → BoardService를 컨테이너에서 가져오거나 자동 생성하여 주입
     *
     * @param string $controllerClass Controller FQCN
     * @return object Controller 인스턴스
     * @throws \RuntimeException 의존성 해결 실패 시
     */
    protected function createController(string $controllerClass): object
    {
        $reflection = new \ReflectionClass($controllerClass);
        $constructor = $reflection->getConstructor();

        // --------------------------------------------------
        // 생성자가 없으면 바로 생성
        // --------------------------------------------------
        if (!$constructor) {
            return new $controllerClass();
        }

        // --------------------------------------------------
        // 생성자 파라미터 분석 및 의존성 해결
        // --------------------------------------------------
        $parameters = $constructor->getParameters();
        $args = [];

        foreach ($parameters as $param) {
            $type = $param->getType();

            // 타입 힌트가 있고, 클래스/인터페이스 타입인 경우
            if ($type && !$type->isBuiltin()) {
                $typeName = $type->getName();
                
                // 컨테이너를 통해 의존성 해결 시도
                // 컨테이너가 자동으로:
                // 1) 명시적으로 등록된 서비스면 반환
                // 2) Service 네임스페이스면 auto-wiring
                try {
                    $args[] = $this->container->get($typeName);
                } catch (NotFoundExceptionInterface $e) {
                    // 미등록 서비스 → 폴백 처리 (등록된 서비스의 생성 에러는 전파)
                    if ($param->isDefaultValueAvailable()) {
                        $args[] = $param->getDefaultValue();
                    } elseif ($param->allowsNull()) {
                        $args[] = null;
                    } else {
                        throw new \RuntimeException(
                            "Cannot resolve dependency: {$typeName} for {$controllerClass}. Reason: " . $e->getMessage(),
                            0,
                            $e
                        );
                    }
                }
            } 
            // 기본값이 있는 파라미터
            elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            }
            // Nullable 파라미터
            elseif ($param->allowsNull()) {
                $args[] = null;
            } 
            // 해결 불가능한 파라미터
            else {
                throw new \RuntimeException(
                    "Cannot resolve parameter: {$param->getName()} for {$controllerClass}"
                );
            }
        }

        return new $controllerClass(...$args);
    }

    /**
     * Controller Action 메서드 실행 (파라미터 자동 주입)
     *
     * Reflection을 사용하여 메서드 파라미터를 분석하고,
     * 타입과 이름을 기반으로 적절한 값을 자동 주입합니다.
     *
     * 주입 규칙:
     * 1. Context 타입 → 현재 Context 객체 주입
     * 2. 'params' 이름 → 라우트 파라미터 배열 주입
     * 3. 기본값 있음 → 기본값 사용
     * 4. Nullable → null 주입
     * 5. 그 외 → 에러
     *
     * 예시:
     * ```php
     * // 다음 시그니처들이 모두 작동:
     * public function index(Context $context)
     * public function show(array $params, Context $context)
     * public function list(Context $context, array $params)
     * public function create()
     * ```
     *
     * @param object $controller Controller 인스턴스
     * @param string $method 실행할 메서드명
     * @param array $params 라우트 파라미터 (예: ['id' => 123])
     * @param Context $context 현재 요청 Context
     * @return mixed Action 메서드의 반환값 (Response 객체)
     * @throws \RuntimeException 파라미터 해결 실패 시
     */
    protected function invokeAction(
        object $controller, 
        string $method, 
        array $params, 
        Context $context
    ) {
        $reflection = new \ReflectionMethod($controller, $method);
        $methodParams = $reflection->getParameters();

        $args = [];

        foreach ($methodParams as $param) {
            $type = $param->getType();
            $name = $param->getName();
            
            // --------------------------------------------------
            // 1. Request 타입 파라미터 → Request 객체 주입
            // 예: public function index(Request $request)
            // --------------------------------------------------
            if ($type && !$type->isBuiltin() && $type->getName() === Request::class) {
                $args[] = $context->getRequest();
            }
            // --------------------------------------------------
            // 2. Context 타입 파라미터 → Context 객체 주입
            // 예: public function index(Context $context)
            // --------------------------------------------------
            elseif ($type && !$type->isBuiltin() && $type->getName() === Context::class) {
                $args[] = $context;
            }
            // --------------------------------------------------
            // 3. 'params' 이름 → 라우트 파라미터 배열 주입
            // 예: public function show(array $params)
            // --------------------------------------------------
            elseif ($name === 'params') {
                $args[] = $params;
            }
            // --------------------------------------------------
            // 3-1. 라우트 파라미터에 해당 이름이 있으면 주입
            // 예: public function package(string $name, string $path)
            // --------------------------------------------------
            elseif (array_key_exists($name, $params)) {
                $args[] = $params[$name];
            }
            // --------------------------------------------------
            // 4. 기본값이 있는 파라미터
            // 예: public function list(int $page = 1)
            // --------------------------------------------------
            elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            }
            // --------------------------------------------------
            // 5. Nullable 파라미터
            // 예: public function create(?string $template = null)
            // --------------------------------------------------
            elseif ($param->allowsNull()) {
                $args[] = null;
            }
            // --------------------------------------------------
            // 6. 해결 불가능 → 에러
            // --------------------------------------------------
            else {
                throw new \RuntimeException(
                    "Cannot resolve parameter: {$name} in " . 
                    get_class($controller) . "::{$method}"
                );
            }
        }

        // 메서드 실행
        return $controller->{$method}(...$args);
    }
}