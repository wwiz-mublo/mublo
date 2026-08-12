<?php
declare(strict_types=1);
namespace Mublo\Service\Extension;

use Mublo\Core\Install\EnvironmentChecker;

/**
 * ExtensionDirectoryProbe
 *
 * 확장 zip 을 풀어 넣을 plugins/·packages/ 에 지금 쓸 수 있는지 확인한다.
 *
 * 판정은 퍼미션 숫자가 아니라 실제 파일 생성·삭제라 ACL·open_basedir·PHP 실행 계정까지
 * 반영된다. 그만큼 비용이 있으므로 호출자는 필요한 순간에만 부른다.
 */
class ExtensionDirectoryProbe
{
    private string $pluginPath;
    private string $packagePath;
    /** @var callable|null 테스트에서 실제 파일 probe 를 대체할 때 사용 */
    private $writeProbe;

    public function __construct(
        ?string $pluginPath = null,
        ?string $packagePath = null,
        ?callable $writeProbe = null
    ) {
        $this->pluginPath = $pluginPath ?? MUBLO_PLUGIN_PATH;
        $this->packagePath = $packagePath ?? MUBLO_PACKAGE_PATH;
        $this->writeProbe = $writeProbe;
    }

    /**
     * @return array<string, array{directory: string, writable: bool, reason: string, guidance: string}>
     *         키는 확장 종류('plugin'/'package')
     */
    public function check(): array
    {
        $checker = new EnvironmentChecker(
            ['plugin' => $this->pluginPath, 'package' => $this->packagePath],
            $this->writeProbe
        );

        $result = [];
        foreach ($checker->checkAll()['permissions'] as $type => $info) {
            $writable = (bool) $info['writable'];
            $result[$type] = [
                'directory' => $type === 'plugin' ? 'plugins/' : 'packages/',
                'writable' => $writable,
                'reason' => $writable ? '' : (string) $info['message'],
                'guidance' => $writable ? '' : $this->guidance((string) $info['access_class']),
            ];
        }

        return $result;
    }

    /**
     * 707 을 권하지 않는 이유: 여기는 PHP 코드가 실행되는 디렉토리라, 웹 유저에게 상시 쓰기를
     * 열면 실행 코드를 언제든 덮어쓸 수 있게 된다. 업로드는 FTP 로 대체 가능한 편의이므로
     * 그만한 값을 치를 이유가 없다.
     */
    private function guidance(string $accessClass): string
    {
        return match ($accessClass) {
            'owner' => 'PHP가 소유자인데도 쓰지 못합니다. ACL·open_basedir·디스크 용량을 확인하세요. 해결이 어려우면 확장을 FTP로 직접 올리면 됩니다.',
            'group' => 'PHP가 디렉토리 그룹으로 실행됩니다. 호스팅이 허용하면 775를 적용하세요. 그대로 두고 FTP로 확장을 올려도 됩니다.',
            'other' => 'PHP가 소유자도 그룹도 아닙니다. 그룹 쓰기를 열거나(chgrp 로 PHP 그룹을 지정한 뒤 775) 확장을 FTP로 직접 올리세요. 코드 디렉토리에 707은 권하지 않습니다.',
            default => '관리자 화면에서 확장을 업로드하려면 이 디렉토리에 PHP 쓰기 권한이 필요합니다. 어려우면 FTP로 직접 올리세요.',
        };
    }
}
