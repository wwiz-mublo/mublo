# Shop 종속 플러그인

이 디렉터리는 `ShopProvider`가 발견하는 Shop 전용 플러그인의 표준 위치입니다.

```text
packages/Shop/Plugins/{PluginName}/
├── manifest.json
├── {PluginName}Provider.php
├── routes.php                 # 선택
├── database/migrations/       # 선택
└── tests/                     # 권장
```

플러그인 이름은 `Shop/{PluginName}`으로 관리되며, Shop 패키지가 비활성화되면 함께 로드되지 않습니다.
Provider 네임스페이스는 `Mublo\Packages\Shop\Plugins\{PluginName}`을 사용합니다.

내부 `Service`, `Repository`, `Entity`에 직접 의존하지 말고
`Mublo\Packages\Shop\Contract\Extension\ShopExtensionApiInterface`를 주입받아 사용합니다.

`manifest.json`에는 부모와 검증한 Shop 버전 범위를 선언합니다.

```json
{
    "name": "ExampleShopPlugin",
    "label": "Shop 확장 예제",
    "version": "1.0.0",
    "type": "plugin",
    "parent": "Shop",
    "requires": {
        "core": ">=1.0.0 <2.0.0",
        "package:Shop": ">=1.0.0 <2.0.0"
    }
}
```
