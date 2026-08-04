# 이벤트 사용법

> **전체 이벤트 목록을 찾는다면 [08. Event](../architecture/08-event.md)를 봅니다.**
> Core 공식 Event 카탈로그와 Board Package의 공식 Event 표가 거기 있습니다.
> 이 문서는 목록이 아니라 **쓰는 법**을 다룹니다.

목록을 두 곳에 두면 반드시 갈라집니다. 이벤트가 추가될 때 갱신하는 문서는 8장 하나입니다.

## 페이로드를 확인하는 방법

이벤트가 무엇을 싣고 오는지는 **클래스가 진실**입니다. 문서를 믿지 말고 클래스를 봅니다.

```bash
ls src/Core/Event/              # Core 이벤트 (영역별 하위 디렉터리)
ls src/Service/Member/Event/    # Core\Event 밖에 있는 도메인 이벤트
ls src/Contract/Notification/   # 중립 계약 곁의 이벤트
ls packages/Board/Event/        # Package 공식 이벤트
```

생성자 인자와 getter가 그 이벤트의 계약입니다. 차단 가능 여부는 `setBlocked()`·
`stopPropagation()` 같은 메서드의 존재로 판별합니다.

## 이름으로 성격을 읽는다

| 형태 | 의미 |
|---|---|
| `-ingEvent` | 사전 이벤트. 검증·차단이 가능하다 |
| `-edEvent` | 사후 이벤트. 완료 후 통지이며 차단할 수 없다 |
| `...CollectEvent` | 수집형. 구독자가 항목을 채워 넣는다 |
| `...RenderingEvent` | 렌더 확장점. HTML·스크립트를 주입한다 |
| `...QueryEvent` | 질의형. 확장이 발행하고 코어가 채워 돌려준다 |

`FailFastEventInterface`를 구현한 이벤트는 구독자의 예외를 삼키지 않고 호출자에게 다시
던집니다. 차단 가능한 사전 이벤트와 트랜잭션 안에서 발행되는 이벤트가 여기 속합니다.

## 구독하기

구독자는 확장 Provider의 `boot()`에서 등록합니다. `register()`가 아닌 이유는
[14. Extension Runtime](../architecture/14-extension.md)에 있습니다.

```php
use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Core\Event\Member\MemberFormRenderingEvent;

class MySubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            MemberFormRenderingEvent::class => 'onMemberFormRendering',
            // 우선순위: ['메서드명', 우선순위]  (높을수록 먼저 실행)
            // 한 이벤트에 여러 메서드: [['method1', 10], ['method2', 0]]
        ];
    }

    public function onMemberFormRendering(MemberFormRenderingEvent $event): void
    {
        if ($event->isEdit()) {
            $event->addSection('<div>추가 HTML</div>', 500);   // order 기본 500
            $event->addScript('<script>...</script>', 500);
        }
    }
}
```

```php
// Provider::boot() 에서
$container->get(EventDispatcher::class)->addSubscriber(new MySubscriber());
```

## 발행하고 결과 회수하기

`dispatch()`는 처리를 마친 이벤트 객체를 그대로 돌려줍니다. 구독자가 채운 값은 반환된
이벤트에서 읽습니다.

```php
$event = $dispatcher->dispatch(new MemberFormRenderingEvent('edit', $member, $context));

$sections = $event->getSectionsSorted();   // order 순 정렬
$scripts  = $event->getScriptsSorted();
```

차단형 이벤트는 발행 직후 차단 여부를 확인합니다.

```php
$event = $dispatcher->dispatch(new SomethingDoingEvent(...));
if ($event->isBlocked()) {
    return Result::failure($event->getBlockReason());
}
```

## 자기 이벤트를 공개할 때

Package가 종속 Plugin에 이벤트를 공개하면 그 순간 **계약**이 됩니다.

- 발생 시점과 payload 의미를 유지합니다. 기존 getter는 최소 한 major 동안 남깁니다.
- payload 확장은 기본값 있는 후행 인자나 새 getter로만 합니다.
- 내부 Entity를 싣지 말고 readonly Snapshot DTO를 싣습니다.
- 차단 가능한 사전 이벤트라면 `FailFastEventInterface`를 구현합니다. 구독자 예외가 삼켜지면
  "검증을 통과한 것"과 구분되지 않습니다.
- **무엇을 공개했는지는 Package가 자기 문서에 밝힙니다.** Board는 `packages/Board/README.md`가
  그 역할을 합니다.

## 관련 문서

- [08. Event](../architecture/08-event.md) — 전체 이벤트 목록과 EventDispatcher 규약
- [이벤트 시스템](../dev-guide/event-system.md) — 발행 지점과 실제 Subscriber 사례
- [훅 포인트](hook-points.md) — 이벤트를 포함한 확장 지점 전반
- [호환성 정책](../compatibility-policy.md) — Event 안정성 조항

---

[< 레퍼런스 목록](README.md)
