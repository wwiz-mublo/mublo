<?php
declare(strict_types=1);

namespace Mublo\Core\Install;

use Mublo\Core\ConfigFile;
use Mublo\Core\UploadConfig;
use PDO;
use PDOException;

/**
 * Installer
 *
 * 설치 메인 클래스 - 전체 설치 프로세스 관리
 */
class Installer
{
    private EnvironmentChecker $envChecker;
    private DatabaseConfigWriter $dbWriter;

    public function __construct()
    {
        $this->envChecker = new EnvironmentChecker();
        $this->dbWriter = new DatabaseConfigWriter();
    }

    /**
     * 설치 완료 여부 확인
     */
    public function isInstalled(): bool
    {
        $lockFile = MUBLO_STORAGE_PATH . '/installed.lock';
        $configFile = MUBLO_CONFIG_PATH . '/database.php';

        return file_exists($lockFile) && file_exists($configFile);
    }

    /**
     * 설치 전체 초기화
     *
     * DB 테이블 삭제 + 설치 중 생성된 config 파일 삭제 + 세션 초기화
     */
    public function resetInstallation(?array $dbConfig = null): array
    {
        $deleted = [];

        // 1. DB 테이블 삭제 (DB 설정이 있는 경우)
        if ($dbConfig) {
            try {
                $mysqli = $this->createMysqli($dbConfig);

                $mysqli->query('SET FOREIGN_KEY_CHECKS = 0');
                $result = $mysqli->query('SHOW TABLES');
                $tables = [];
                if ($result) {
                    while ($row = $result->fetch_row()) {
                        $tables[] = $row[0];
                    }
                    $result->free();
                }
                foreach ($tables as $table) {
                    $mysqli->query("DROP TABLE IF EXISTS `{$table}`");
                }
                $mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
                $mysqli->close();

                $deleted[] = count($tables) . '개 테이블 삭제';
            } catch (\Exception $e) {
                return [
                    'success' => false,
                    'message' => 'DB 초기화 실패: ' . $e->getMessage(),
                ];
            }
        }

        // 2. 설치 중 생성된 파일 삭제
        $installFiles = [
            MUBLO_CONFIG_PATH . '/database.php',
            MUBLO_CONFIG_PATH . '/app.php',
            MUBLO_CONFIG_PATH . '/security.php',
            MUBLO_CONFIG_PATH . '/mail.php',
            MUBLO_CONFIG_PATH . '/ai.php',
            MUBLO_CONFIG_PATH . '/upload.php',
            MUBLO_STORAGE_PATH . '/installed.lock',
        ];

        foreach ($installFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
                $deleted[] = basename($file);
            }
        }

        // 3. 세션 초기화
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
            session_start();
        }

        return [
            'success' => true,
            'message' => '초기화 완료: ' . implode(', ', $deleted),
        ];
    }

    /**
     * 환경 체크
     */
    public function checkEnvironment(): array
    {
        return $this->envChecker->checkAll();
    }

    /**
     * 설치 가능 여부
     */
    public function canInstall(): bool
    {
        return $this->envChecker->canInstall();
    }

    /**
     * DB 연결 테스트
     */
    public function testDatabaseConnection(array $config): array
    {
        return $this->dbWriter->testConnection($config);
    }

    /**
     * DB 설정 파일 생성
     */
    public function saveDatabaseConfig(array $config): bool
    {
        return $this->dbWriter->writeConfig($config);
    }

    /**
     * DB 마이그레이션 실행
     */
    public function runMigrations(array $config): array
    {
        return $this->dbWriter->runMigrations($config);
    }

    /**
     * 시더 실행 (도메인 생성 후 초기 데이터 삽입)
     *
     * database/seeders/ 디렉토리의 SQL 파일을 순서대로 실행.
     * 마이그레이션과 달리 domain_configs 행이 존재해야 하는
     * INSERT 문(FK 참조)을 포함하므로 setupDomain() 이후에 호출.
     */
    public function runSeeders(array $dbConfig): array
    {
        try {
            $mysqli = $this->createMysqli($dbConfig);

            $executed = [];

            // Core 시더 실행 (번호 접두사 파일만 — 수동 실행용 시더 제외)
            $seederPath = MUBLO_ROOT_PATH . '/database/seeders';
            if (is_dir($seederPath)) {
                $sqlFiles = glob($seederPath . '/[0-9]*_*.sql') ?: [];
                $phpFiles = glob($seederPath . '/[0-9]*_*.php') ?: [];
                $files = array_merge($sqlFiles, $phpFiles);
                sort($files);

                foreach ($files as $file) {
                    $this->executeSeederFile($mysqli, $file, $dbConfig);
                    $executed[] = basename($file);
                }
            }

            // default:true 패키지 시더 실행
            $defaultPackages = $this->getDefaultPackages();
            foreach ($defaultPackages as $packageName) {
                $pkgSeederPath = MUBLO_PACKAGE_PATH . '/' . $packageName . '/database/seeders';
                if (!is_dir($pkgSeederPath)) {
                    continue;
                }

                $pkgSqlFiles = glob($pkgSeederPath . '/[0-9]*_*.sql') ?: [];
                $pkgPhpFiles = glob($pkgSeederPath . '/[0-9]*_*.php') ?: [];
                $pkgFiles = array_merge($pkgSqlFiles, $pkgPhpFiles);
                sort($pkgFiles);

                foreach ($pkgFiles as $file) {
                    $this->executeSeederFile($mysqli, $file, $dbConfig);
                    $executed[] = $packageName . '/' . basename($file);
                }
            }

            $mysqli->close();

            return [
                'success' => true,
                'message' => count($executed) . '개 시더 파일 실행 완료',
                'files' => $executed,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => '시더 실행 실패: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * 최고관리자 생성
     */
    public function createAdmin(array $dbConfig, array $adminData, int $hashCost = 12): array
    {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $dbConfig['host'],
                $dbConfig['port'],
                $dbConfig['database'],
                $dbConfig['charset']
            );

            $pdo = new PDO(
                $dsn,
                $dbConfig['username'],
                $dbConfig['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => true]
            );

            $now = date('Y-m-d H:i:s');

            // 비밀번호 해시 (보안 설정에서 지정한 비용 사용)
            $hashedPassword = password_hash($adminData['password'], PASSWORD_DEFAULT, ['cost' => $hashCost]);

            // 1. 기본 레벨 시드
            $this->seedDefaultLevels($pdo, $now);

            // 2. 관리자 회원 등록 (level_value=255)
            $sql = "INSERT INTO `members` (
                public_id, domain_id, origin_domain_id, user_id, password, nickname, level_value, domain_group, status, created_at, updated_at
            ) VALUES (
                :public_id, 1, 1, :user_id, :password, :nickname, 255, '1', 'active', :created_at, :updated_at
            )";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'user_id' => $adminData['user_id'],
                'public_id' => bin2hex(random_bytes(11)),
                'password' => $hashedPassword,
                'nickname' => '관리자',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $memberId = $pdo->lastInsertId();

            return [
                'success' => true,
                'message' => '최고관리자 계정 생성 완료',
                'member_id' => $memberId,
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => '관리자 생성 실패: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * 기본 회원 레벨 시드
     *
     * Core에 정의된 6가지 레벨 타입을 전역 member_levels 테이블에 삽입.
     * 이미 존재하는 level_value는 IGNORE로 건너뜀 (재설치 안전).
     *
     * level_value 체계 (200 미사용 — 커스텀 레벨 여유 공간):
     *   255 SUPER    — 최고관리자 (is_super=1, is_admin=1, can_operate_domain=1)
     *   230 STAFF    — 스태프/직원 (is_admin=1)
     *   220 PARTNER  — 파트너     (is_admin=1)
     *   215 SITE_MASTER — 사이트 운영자 (is_admin=1, can_operate_domain=1) — 해당 도메인의 최고 권한
     *   210 SUPPLIER — 공급처
     *     1 BASIC    — 일반회원
     */
    private function seedDefaultLevels(PDO $pdo, string $now): void
    {
        $levels = [
            // level_value, level_name, level_type, is_super, is_admin, can_operate_domain
            [255, '최고관리자', 'SUPER',    1, 1, 1],
            [230, '스태프',    'STAFF',    0, 1, 0],
            [220, '파트너',    'PARTNER',  0, 1, 0],
            [215, '사이트 운영자', 'SITE_MASTER', 0, 1, 1],
            [210, '공급처',    'SUPPLIER', 0, 0, 0],
            [  1, '일반회원',  'BASIC',    0, 0, 0],
        ];

        $sql = "INSERT IGNORE INTO `member_levels`
                    (level_value, level_name, level_type, is_super, is_admin, can_operate_domain,
                     created_at, updated_at)
                VALUES (:lv, :ln, :lt, :is_super, :is_admin, :cod, :ca, :ua)";

        $stmt = $pdo->prepare($sql);

        foreach ($levels as [$lv, $ln, $lt, $isSuper, $isAdmin, $cod]) {
            $stmt->execute([
                'lv'       => $lv,
                'ln'       => $ln,
                'lt'       => $lt,
                'is_super' => $isSuper,
                'is_admin' => $isAdmin,
                'cod'      => $cod,
                'ca'       => $now,
                'ua'       => $now,
            ]);
        }
    }

    /**
     * 기본 도메인 설정
     *
     * @param array $dbConfig DB 설정
     * @param array $domainData 도메인 데이터
     * @param int|null $memberId 소유자 회원 ID (최고관리자)
     */
    public function setupDomain(array $dbConfig, array $domainData, ?int $memberId = null, ?string $starterKit = null): array
    {
        try {
            // 시작 킷 선택 시: 킷의 레이아웃 설정과 필요 확장을 초기 설정에 반영
            $kit = $starterKit !== null ? $this->loadStarterKit($starterKit) : null;
            $kitSiteConfig = is_array($kit['site_settings']['site_config'] ?? null)
                ? $kit['site_settings']['site_config']
                : [];
            $kitExtensions = $starterKit !== null
                ? ($this->getStarterKits()[$starterKit]['extensions'] ?? null)
                : null;

            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $dbConfig['host'],
                $dbConfig['port'],
                $dbConfig['database'],
                $dbConfig['charset']
            );

            $pdo = new PDO(
                $dsn,
                $dbConfig['username'],
                $dbConfig['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => true]
            );

            $now = date('Y-m-d H:i:s');

            // 사이트 기본 설정 JSON
            // 시작 킷의 레이아웃 설정(use_main_layout, layout_type 등)이 기본값을 덮는다
            $siteConfig = json_encode(array_merge([
                'site_title' => $domainData['site_title'],
                'site_subtitle' => $domainData['site_subtitle'] ?? '',
                'admin_email' => $domainData['admin_email'],
                'timezone' => $domainData['timezone'] ?? 'Asia/Seoul',
                'language' => 'ko',
                'editor' => 'mublo-editor',
                // 레이아웃 기본 폭: 1200px 고정폭으로 시작 (미설정 시 테마가 wide로 렌더됨)
                'layout_max_width' => 1200,
            ], $kitSiteConfig), JSON_UNESCAPED_UNICODE);

            // 테마 기본 설정 JSON
            // 구조:
            // - 공통: partial(Head/Foot), header, layout, footer
            // - 코어: board, member, auth
            // - 비코어: index (플러그인/사용자 정의)
            $themeConfig = json_encode([
                // 공통 스킨
                'partial' => 'basic',
                'header' => 'basic',
                'layout' => 'basic',
                'footer' => 'basic',
                // 코어 기능 스킨
                'board' => 'basic',
                'member' => 'basic',
                'auth' => 'basic',
                // 비코어 (플러그인/사용자 정의)
                'index' => 'basic',
            ], JSON_UNESCAPED_UNICODE);

            // default:true 패키지 + 시작 킷 필요 확장을 extension_config에 자동 등록
            $extensionConfig = $this->buildDefaultExtensionConfig($kitExtensions);

            // 회사 정보 기본값: 운영자가 관리자 설정에서 변경 전까지
            // 푸터 오른쪽 고객센터 대표번호가 비는 것을 막기 위해 기본 번호를 넣는다.
            $companyConfig = json_encode([
                'cs_tel' => '0000-0000',
            ], JSON_UNESCAPED_UNICODE);

            // 기본 도메인 등록 (재시도 시 기존 데이터 갱신)
            $sql = "INSERT INTO `domain_configs` (
                domain,
                domain_group,
                member_id,
                status,
                site_config,
                theme_config,
                company_config,
                extension_config,
                created_at,
                updated_at
            ) VALUES (
                :domain,
                '1',
                :member_id,
                'active',
                :site_config,
                :theme_config,
                :company_config,
                :extension_config,
                :created_at,
                :updated_at
            ) ON DUPLICATE KEY UPDATE
                site_config = VALUES(site_config),
                theme_config = VALUES(theme_config),
                company_config = VALUES(company_config),
                extension_config = VALUES(extension_config),
                updated_at = VALUES(updated_at)";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'domain' => $domainData['domain_name'],
                'member_id' => $memberId,
                'site_config' => $siteConfig,
                'theme_config' => $themeConfig,
                'company_config' => $companyConfig,
                'extension_config' => $extensionConfig,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // ON DUPLICATE KEY UPDATE 시 lastInsertId가 0이므로 조회로 확보
            $domainId = $pdo->lastInsertId();
            if (!$domainId) {
                $stmt = $pdo->prepare("SELECT domain_id FROM domain_configs WHERE domain = :domain");
                $stmt->execute(['domain' => $domainData['domain_name']]);
                $domainId = $stmt->fetchColumn();
            }

            return [
                'success' => true,
                'message' => '도메인 설정 완료',
                'domain_id' => $domainId,
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => '도메인 설정 실패: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * 기본 도메인 소유자 설정
     *
     * 관리자 생성 후 기본 도메인(domain_id=1)의 소유자를 설정
     *
     * @param array $dbConfig DB 설정
     * @param int $memberId 소유자 회원 ID (최고관리자)
     */
    public function updateDomainOwner(array $dbConfig, int $memberId): array
    {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $dbConfig['host'],
                $dbConfig['port'],
                $dbConfig['database'],
                $dbConfig['charset']
            );

            $pdo = new PDO(
                $dsn,
                $dbConfig['username'],
                $dbConfig['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => true]
            );

            // 기본 도메인(domain_id=1)의 소유자 업데이트
            $sql = "UPDATE `domain_configs` SET member_id = :member_id WHERE domain_id = 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['member_id' => $memberId]);

            return [
                'success' => true,
                'message' => '도메인 소유자 설정 완료',
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => '도메인 소유자 설정 실패: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * 블록 시더 실행 (기본 메인페이지 블록 구성)
     *
     * database/seeders/block-templates/*.json 파일로부터 기본 블록 생성.
     * 실패해도 설치 전체를 중단하지 않음 (블록은 없어도 사이트 동작).
     */
    public function runBlockSeeder(array $dbConfig, int $domainId, ?string $starterKit = null): array
    {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $dbConfig['host'],
                $dbConfig['port'],
                $dbConfig['database'],
                $dbConfig['charset']
            );

            $pdo = new PDO(
                $dsn,
                $dbConfig['username'],
                $dbConfig['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => true]
            );

            $seeder = new \Mublo\Core\Block\BlockSeeder($pdo);

            // 시작 킷 선택 시: 기본 환영 블록 대신 킷의 메인 골격을 시딩.
            // 킷 시딩이 실패하면 기본 시딩으로 폴백 — 설치는 어떤 경우에도 계속된다.
            if ($starterKit !== null) {
                $kit = $this->loadStarterKit($starterKit);
                if ($kit !== null) {
                    $result = $seeder->seedStarterKit($kit, $domainId);
                    if ($result['success']) {
                        return $result;
                    }
                    error_log('[INSTALL] 시작 킷 시딩 실패, 기본 블록으로 폴백: ' . $result['message']);
                }
            }

            return $seeder->seed($domainId);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => '블록 시더 실행 실패: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * 번들 시작 킷 매니페스트 (설치 마법사 선택 UI 용)
     *
     * 킷 파일이 실제로 존재하는 항목만 반환한다.
     *
     * 미리보기(preview)는 킷 JSON 에 임베드된 screenshot(data URI)에서 추출한다 —
     * 썸네일의 단일 진실은 킷 JSON 하나다(#39 원칙 1). 설치 시점에는 DB 도
     * 시더 산출물도 없지만, 킷 JSON 은 디스크에 있으므로 그대로 읽어 쓴다.
     *
     * @return array<string, array{name: string, summary: string, file: string, preview: string, extensions?: array}>
     */
    public function getStarterKits(): array
    {
        $dir = MUBLO_ROOT_PATH . '/database/seeders/starter-kits';
        $manifestPath = $dir . '/kits.php';
        if (!is_file($manifestPath)) {
            return [];
        }

        $manifest = require $manifestPath;
        if (!is_array($manifest)) {
            return [];
        }

        $kits = array_filter(
            $manifest,
            fn($meta) => is_array($meta) && is_file($dir . '/' . ($meta['file'] ?? ''))
        );

        foreach ($kits as &$meta) {
            $kit = json_decode((string) @file_get_contents($dir . '/' . $meta['file']), true);
            $screenshot = is_array($kit) ? ($kit['screenshot'] ?? '') : '';
            $meta['preview'] = is_string($screenshot) && str_starts_with($screenshot, 'data:image/')
                ? $screenshot
                : '';
        }
        unset($meta);

        return $kits;
    }

    /**
     * 번들 시작 킷 JSON 로드 (없거나 깨졌으면 null)
     */
    public function loadStarterKit(string $slug): ?array
    {
        $kits = $this->getStarterKits();
        if (!isset($kits[$slug])) {
            return null;
        }

        $path = MUBLO_ROOT_PATH . '/database/seeders/starter-kits/' . $kits[$slug]['file'];
        $kit = json_decode((string) file_get_contents($path), true);

        return is_array($kit) ? $kit : null;
    }

    /**
     * 수집된 설정으로 설치를 한 번에 수행한다.
     *
     * 이전에는 각 step 이 제출될 때마다 DB·파일을 확정했다. 그래서 3단계에서 2단계로
     * 되돌아가 다시 제출하면 마이그레이션이 재실행되면서 기존 테이블을 DROP 하고,
     * 그때까지 만든 도메인·관리자 계정이 사라졌다. 뒤로가기가 파괴적 동작이 되는
     * 구조였다.
     *
     * 이제 step 은 입력을 검증해 세션에 모으기만 하고, 실제 쓰기는 여기서만 일어난다.
     * 왕복은 아무것도 바꾸지 않는다.
     *
     * 순서 제약은 둘뿐이다 — 마이그레이션이 도메인보다 먼저, 도메인이 관리자보다 먼저.
     * 설정 파일 쓰기는 DB 작업이 모두 끝난 뒤에 둔다. 중간에 실패했을 때 반쯤 설치된
     * 흔적을 남기지 않기 위해서다.
     *
     * @param array{db:array, domain:array, security:array, admin:array, starter_kit:?string} $data
     * @return array{
     *     success: bool,
     *     steps: list<array{key:string, label:string, success:bool, message:string}>,
     *     domain_id: int,
     *     member_id: int,
     *     migration: array
     * }
     */
    public function runInstallation(array $data): array
    {
        $steps = [];
        $domainId = 0;
        $memberId = 0;
        $migration = ['files' => []];

        $dbConfig = $data['db'];
        $starterKit = $data['starter_kit'] ?? null;

        // 각 단계를 같은 형태로 기록한다. 실패하면 즉시 멈추고 어디서 멈췄는지 남긴다.
        $run = function (string $key, string $label, callable $task) use (&$steps): bool {
            try {
                $result = $task();
            } catch (\Throwable $e) {
                $steps[] = ['key' => $key, 'label' => $label, 'success' => false, 'message' => $e->getMessage()];
                return false;
            }

            $ok = (bool) ($result['success'] ?? $result);
            $steps[] = [
                'key' => $key,
                'label' => $label,
                'success' => $ok,
                'message' => is_array($result) ? (string) ($result['message'] ?? '') : '',
            ];

            return $ok;
        };

        $ok = $run('migrations', '데이터베이스 테이블 생성', function () use ($dbConfig, &$migration): array {
            $migration = $this->runMigrations($dbConfig);
            return $migration;
        });

        if ($ok) {
            $ok = $run('domain', '도메인 설정', function () use ($dbConfig, $data, $starterKit, &$domainId): array {
                $result = $this->setupDomain($dbConfig, $data['domain'], null, $starterKit);
                $domainId = (int) ($result['domain_id'] ?? 1);
                return $result;
            });
        }

        if ($ok) {
            $ok = $run('seeders', '초기 데이터 입력', fn(): array => $this->runSeeders($dbConfig));
        }

        if ($ok) {
            // 블록 시딩은 실패해도 설치를 막지 않는다 — 화면 골격이 없을 뿐 사이트는 선다.
            $run('blocks', '기본 화면 구성', function () use ($dbConfig, $domainId, $starterKit): array {
                $result = $this->runBlockSeeder($dbConfig, $domainId, $starterKit);
                return ['success' => true, 'message' => (string) ($result['message'] ?? '')];
            });
        }

        if ($ok) {
            $ok = $run('admin', '관리자 계정 생성', function () use ($dbConfig, $data, &$memberId): array {
                $result = $this->createAdmin($dbConfig, $data['admin'], (int) ($data['security']['password_hash_cost'] ?? 12));
                $memberId = (int) ($result['member_id'] ?? 0);
                return $result;
            });
        }

        if ($ok && $memberId > 0) {
            // 소유자 지정이 실패해도 설치는 계속한다(기존 동작 유지) — 관리자 화면에서 고칠 수 있다.
            $run('owner', '도메인 소유자 지정', fn(): array => $this->updateDomainOwner($dbConfig, $memberId));
        }

        if ($ok) {
            $ok = $run('config_db', '데이터베이스 설정 파일 생성', fn(): bool => $this->saveDatabaseConfig($dbConfig));
        }

        if ($ok) {
            $ok = $run('config_security', '보안 설정 파일 생성', fn(): bool => $this->generateSecurityConfigWithData(
                $data['security'],
                (string) ($data['domain']['admin_email'] ?? '')
            ));
        }

        if ($ok) {
            $ok = $run('finish', '설치 완료 처리', fn(): bool => $this->finishInstallation());
        }

        return [
            'success' => $ok,
            'steps' => $steps,
            'domain_id' => $domainId,
            'member_id' => $memberId,
            'migration' => $migration,
        ];
    }

    /**
     * 설치 완료 처리
     */
    public function finishInstallation(): bool
    {
        $lockFile = MUBLO_STORAGE_PATH . '/installed.lock';

        $content = sprintf(
            "installed_at=%s\nversion=1.0.0\n",
            date('Y-m-d H:i:s')
        );

        $result = file_put_contents($lockFile, $content);

        if ($result === false) {
            return false;
        }

        @chmod($lockFile, 0600);

        return true;
    }

    /**
     * 전체 설정 파일 생성 (기본값 사용)
     */
    public function generateConfigFiles(array $dbConfig): bool
    {
        // ai.php - 운영자 관리 기본 설정
        if (!$this->generateAiConfig()) {
            return false;
        }

        // upload.php - 운영자 관리 기본 설정
        return $this->generateUploadConfig();
    }

    /**
     * 보안 설정 파일 생성 (사용자 입력값 사용)
     */
    public function generateSecurityConfigWithData(array $securityData, string $adminEmail = ''): bool
    {
        // 1. security.php 생성
        if (!$this->generateSecurityConfigWithValues($securityData)) {
            return false;
        }

        // 2. mail.php 생성 (관리자 이메일을 기본 발신 주소로 사용)
        if (!$this->generateMailConfig($adminEmail)) {
            return false;
        }

        // 3. ai.php 생성 (이미 존재하는 운영자 설정은 보존)
        if (!$this->generateAiConfig()) {
            return false;
        }

        // 4. upload.php 생성 (이미 존재하는 운영자 설정은 보존)
        if (!$this->generateUploadConfig()) {
            return false;
        }

        return true;
    }


    /**
     * security.php 생성 (사용자 입력값)
     *
     * cache_driver, session_driver, redis 설정은 .env에서 로드
     * 나머지 보안 설정은 설치 시 고정값
     */
    private function generateSecurityConfigWithValues(array $securityData): bool
    {
        $configPath = MUBLO_CONFIG_PATH . '/security.php';
        $csrfKey = $securityData['csrf_token_key'];
        $hashCost = $securityData['password_hash_cost'];
        $csrfTtl = $securityData['csrf_token_ttl'];
        $encryptionKey = $securityData['encryption_key'] ?? bin2hex(random_bytes(32));
        $searchPepper = $securityData['search_pepper'] ?? bin2hex(random_bytes(32));

        $content = <<<'PHP'
<?php
/**
 * Security Configuration
 * Auto-generated by Mublo Framework Installer
 * Created at: %s
 */

return [
    // 비밀번호 해싱 설정
    'password' => [
        'algo' => PASSWORD_DEFAULT,
        'cost' => %d,
    ],

    // CSRF 토큰 설정
    // 토큰 자체는 세션에 담은 난수를 대조하는 방식이라 키가 필요 없다(CsrfManager).
    'csrf' => [
        // 유휴 허용 시간(초). 검증에 성공할 때마다 다시 시작하는 슬라이딩 만료다.
        // 0 이면 만료 없이 세션 수명만 따른다.
        'token_ttl' => %d,
    ],

    // 파일 다운로드 토큰 서명 키
    // 바꾸면 이미 발급된 다운로드 링크가 전부 무효가 된다.
    'file' => [
        'download_signing_key' => '%s',
    ],

    // 세션 설정
    'session' => [
        'lifetime' => 120,  // 세션 타임아웃 (분)
        'cookie_secure' => false,  // HTTPS에서만 true로 설정
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ],

    // 필드 암호화 설정 (AES-256-GCM)
    'encryption' => [
        'key' => '%s',
        'cipher' => 'aes-256-gcm',
    ],

    // 검색 인덱스용 Pepper (Blind Index)
    'search' => [
        'pepper' => '%s',
    ],

    // 신뢰 프록시 설정
    // X-Forwarded-Proto, X-Forwarded-For 헤더를 신뢰할 프록시 IP 목록
    // ['*']: 모든 프록시 신뢰 (직접 접근이 방화벽으로 차단된 환경에서만 사용)
    // ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16']: 특정 IP/CIDR만 신뢰
    // 빈 문자열 설정 시(TRUSTED_PROXIES=''): 프록시 불신 (REMOTE_ADDR만 사용)
    'trusted_proxies' => array_filter(explode(',', env('TRUSTED_PROXIES', ''))),

    // 캐시 & 세션 드라이버 (env에서 로드)
    'cache_driver' => env('CACHE_DRIVER', 'file'),
    'session_driver' => env('SESSION_DRIVER', 'file'),

    // Redis 설정 (cache_driver 또는 session_driver가 redis일 때)
    'redis' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD', ''),
        'port' => (int) env('REDIS_PORT', 6379),
    ],
];
PHP;

        $content = sprintf(
            $content,
            $this->getCurrentDateTime(),
            $hashCost,
            $csrfTtl,
            $csrfKey,
            $encryptionKey,
            $searchPepper
        );

        $result = file_put_contents($configPath, $content . "\n");
        if ($result === false) {
            return false;
        }

        // 같은 요청에서 이미 "없음" 으로 캐시됐을 수 있다 — 방금 쓴 값이 보이게 비운다.
        ConfigFile::reset();

        @chmod($configPath, 0600);
        return true;
    }

    /**
     * mail.php 생성 (기본값)
     *
     * 이메일 드라이버, 발신자 정보, SMTP 설정은 .env에서 로드
     * 설치 시 기본 mail() 드라이버로 생성
     */
    private function generateMailConfig(string $adminEmail = ''): bool
    {
        $configPath = MUBLO_CONFIG_PATH . '/mail.php';
        $defaultFrom = !empty($adminEmail) ? $adminEmail : 'noreply@example.com';

        $content = <<<'PHP'
<?php
/**
 * Mail Configuration
 * Auto-generated by Mublo Framework Installer
 * Created at: %s
 *
 * driver: 'mail' (PHP mail 함수) 또는 'smtp' (SMTP 서버)
 */

return [
    'driver' => env('MAIL_DRIVER', 'mail'),

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', '%s'),
        'name'    => env('MAIL_FROM_NAME', 'Mublo'),
    ],

    'smtp' => [
        'host'       => env('MAIL_SMTP_HOST', ''),
        'port'       => (int) env('MAIL_SMTP_PORT', 587),
        'encryption' => env('MAIL_SMTP_ENCRYPTION', 'tls'),
        'username'   => env('MAIL_SMTP_USERNAME', ''),
        'password'   => env('MAIL_SMTP_PASSWORD', ''),
        'timeout'    => 30,
    ],
];
PHP;

        $content = sprintf($content, $this->getCurrentDateTime(), $defaultFrom);

        $result = file_put_contents($configPath, $content . "\n");
        if ($result === false) {
            return false;
        }

        // 같은 요청에서 이미 "없음" 으로 캐시됐을 수 있다 — 방금 쓴 값이 보이게 비운다.
        ConfigFile::reset();

        @chmod($configPath, 0600);
        return true;
    }

    /**
     * AI 설정 파일 생성
     *
     * 이 파일은 설치 후 운영자가 관리한다. 재설치 단계가 다시 호출되더라도
     * 기존 파일을 덮어쓰지 않는다.
     */
    public function generateAiConfig(): bool
    {
        $configPath = MUBLO_CONFIG_PATH . '/ai.php';
        if (is_file($configPath)) {
            return true;
        }

        $content = "<?php\n/**\n"
            . " * AI Configuration\n"
            . " * Auto-generated by Mublo Framework Installer\n"
            . ' * Created at: ' . $this->getCurrentDateTime() . "\n"
            . " *\n"
            . " * 설치 후에는 운영자가 관리하며 Core 업데이트가 자동으로 덮어쓰지 않습니다.\n"
            . " */\n\nreturn "
            . var_export($this->defaultAiConfig(), true)
            . ";\n";

        $result = file_put_contents($configPath, $content);
        if ($result === false) {
            return false;
        }

        // 같은 요청에서 이미 "없음" 으로 캐시됐을 수 있다 — 방금 쓴 값이 보이게 비운다.
        ConfigFile::reset();

        @chmod($configPath, 0600);
        return true;
    }

    /**
     * 업로드 설정 파일 생성
     *
     * 운영자가 직접 열어서 고치라고 만드는 파일이다. 그래서 var_export 대신 주석이
     * 붙은 형태로 쓴다 — 어느 값을 왜 고치는지 파일 안에서 읽을 수 있어야 한다.
     * 기본값은 UploadConfig 의 상수를 그대로 쓴다. 여기에 숫자를 다시 적으면
     * 파일이 있는 설치본과 없는 설치본의 동작이 조용히 갈라진다.
     */
    public function generateUploadConfig(): bool
    {
        $configPath = MUBLO_CONFIG_PATH . '/upload.php';
        if (is_file($configPath)) {
            return true;
        }

        $content = "<?php\n/**\n"
            . " * 업로드 설정\n"
            . " * Auto-generated by Mublo Framework Installer\n"
            . ' * Created at: ' . $this->getCurrentDateTime() . "\n"
            . " *\n"
            . " * 설치 후에는 운영자가 관리하며 Core 업데이트가 자동으로 덮어쓰지 않습니다.\n"
            . " * 이 파일을 직접 열어서 값을 고치면 즉시 적용됩니다.\n"
            . " *\n"
            . " * 여기서 정한 값보다 서버(php.ini)의 upload_max_filesize / post_max_size 가\n"
            . " * 작으면 그쪽이 먼저 막습니다. 큰 파일을 허용하려면 두 곳을 함께 올려야 합니다.\n"
            . " */\n\n"
            . "return [\n"
            . "    // 에디터 본문에 넣는 이미지 (POST /api/v1/editor/upload)\n"
            . "    'editor_image' => [\n"
            . "        // 로그인한 회원이 올릴 수 있는 이미지 한 장의 최대 크기(MB)\n"
            . "        'max_size_mb' => " . UploadConfig::DEFAULT_EDITOR_MAX_SIZE_MB . ",\n"
            . "\n"
            . "        // 로그인하지 않은 방문자(비회원 글쓰기)의 최대 크기(MB)\n"
            . "        //\n"
            . "        // 이 경로는 로그인 없이 호출할 수 있고, 저장된 파일은 공개 URL 로 열립니다.\n"
            . "        // 값을 키울수록 아무나 올린 파일로 디스크가 차는 속도도 함께 빨라집니다.\n"
            . "        // 임시 파일 정리는 자동으로 돌지 않으므로(관리자 → 시스템에서 수동 실행),\n"
            . "        // 값을 올리기 전에 정리 주기를 함께 정해두시길 권합니다.\n"
            . "        'guest_max_size_mb' => " . UploadConfig::DEFAULT_EDITOR_GUEST_MAX_SIZE_MB . ",\n"
            . "    ],\n"
            . "];\n";

        $result = file_put_contents($configPath, $content);
        if ($result === false) {
            return false;
        }

        // 같은 요청에서 이미 "없음" 으로 캐시됐을 수 있다 — 방금 쓴 값이 보이게 비운다.
        ConfigFile::reset();

        // security.php 와 달리 비밀값이 없고, 운영자가 FTP 로 열어 고치는 것을 전제한다.
        // 0600 이면 PHP 실행 계정과 FTP 계정이 다른 공유호스팅에서 열 수 없다.
        @chmod($configPath, 0644);
        return true;
    }

    /**
     * 신규 설치에 사용하는 AI 설정 기본값
     */
    private function defaultAiConfig(): array
    {
        return [
            'config_version' => 1,
            'providers' => [
                'openai' => [
                    'label' => 'OpenAI',
                    'default_model' => 'gpt-5.6-terra',
                    'models' => [
                        'gpt-5.6-terra', 'gpt-5.6-luna', 'gpt-5.6-sol',
                        'gpt-5.5',
                        'gpt-5.4', 'gpt-5.4-mini', 'gpt-5.4-nano',
                        'gpt-5.2',
                        'gpt-5', 'gpt-5-mini', 'gpt-5-nano',
                        'gpt-4.1', 'gpt-4.1-mini', 'gpt-4o-mini',
                    ],
                ],
                'anthropic' => [
                    'label' => 'Anthropic',
                    'default_model' => 'claude-sonnet-5',
                    'models' => [
                        'claude-sonnet-5', 'claude-opus-4-8', 'claude-fable-5',
                        'claude-sonnet-4-6', 'claude-opus-4-6',
                        'claude-sonnet-4-5', 'claude-haiku-4-5',
                    ],
                ],
                'gemini' => [
                    'label' => 'Google Gemini',
                    'default_model' => 'gemini-3.5-flash',
                    'models' => [
                        'gemini-3.5-flash', 'gemini-3.1-flash-lite',
                        'gemini-3.1-pro-preview', 'gemini-3-flash-preview',
                        'gemini-2.5-pro', 'gemini-2.5-flash', 'gemini-2.5-flash-lite',
                    ],
                ],
            ],
            'default_daily_request_limit' => 50,
            'max_daily_request_limit' => 1000,
            'connect_timeout_seconds' => 5,
            'request_timeout_seconds' => 45,
            'max_prompt_chars' => 4000,
            'max_existing_content_chars' => 60000,
            'max_response_bytes' => 500000,
            'assets' => [
                'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'md', 'csv', 'json', 'docx', 'xlsx', 'pptx'],
                'image_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
                'max_image_bytes' => 20 * 1024 * 1024,
                'max_pdf_bytes' => 32 * 1024 * 1024,
                'max_document_bytes' => 20 * 1024 * 1024,
                'max_selected_assets' => 10,
                'max_selected_images' => 6,
                'max_selected_bytes' => 50 * 1024 * 1024,
                'max_extracted_chars_per_asset' => 120000,
                'max_extracted_chars_per_request' => 240000,
                'max_zip_entries' => 2000,
                'max_zip_uncompressed_bytes' => 100 * 1024 * 1024,
                'max_zip_ratio' => 100,
            ],
        ];
    }

    /**
     * 시더 파일 실행 (SQL 또는 PHP)
     *
     * 재시도 안전: 중복 키 에러(23000)를 무시하여 이미 삽입된 데이터가 있어도 계속 진행.
     */
    /**
     * 시더 파일 실행 (SQL 또는 PHP)
     *
     * PHP 시더는 PDO를 받으므로 mysqli에서 PDO를 임시 생성.
     * SQL 시더는 mysqli의 multi_query로 실행.
     */
    private function executeSeederFile(\mysqli $mysqli, string $file, array $dbConfig = []): void
    {
        $ext = pathinfo($file, PATHINFO_EXTENSION);

        if ($ext === 'php') {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $dbConfig['host'] ?? 'localhost',
                (int) ($dbConfig['port'] ?? 3306),
                $dbConfig['database'] ?? '',
                $dbConfig['charset'] ?? 'utf8mb4'
            );
            $pdo = new PDO($dsn, $dbConfig['username'] ?? '', $dbConfig['password'] ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $seederFn = require $file;
            if (is_callable($seederFn)) {
                $seederFn($pdo);
            }
            $pdo = null;
            return;
        }

        $sql = file_get_contents($file);
        // 주석 제거
        $lines = explode("\n", $sql);
        $lines = array_filter($lines, fn($line) => !str_starts_with(trim($line), '--'));
        $sql = trim(implode("\n", $lines));

        if (empty($sql)) {
            return;
        }

        if (!$mysqli->multi_query($sql)) {
            // 중복 키 에러(1062) 무시
            if ($mysqli->errno === 1062) {
                return;
            }
            throw new \RuntimeException("시더 실행 실패 [{$file}]: " . $mysqli->error);
        }

        // 모든 결과셋 소비
        do {
            if ($result = $mysqli->store_result()) {
                $result->free();
            }
        } while ($mysqli->next_result());
    }

    /**
     * mysqli 연결 생성
     */
    private function createMysqli(array $dbConfig): \mysqli
    {
        $mysqli = new \mysqli(
            $dbConfig['host'],
            $dbConfig['username'],
            $dbConfig['password'],
            $dbConfig['database'],
            (int) ($dbConfig['port'] ?? 3306)
        );

        if ($mysqli->connect_error) {
            throw new \RuntimeException('DB 연결 실패: ' . $mysqli->connect_error);
        }

        $mysqli->set_charset($dbConfig['charset'] ?? 'utf8mb4');

        return $mysqli;
    }

    /**
     * manifest.json에서 default:true인 패키지 목록 반환
     */
    private function getDefaultPackages(): array
    {
        $packagePath = MUBLO_PACKAGE_PATH;
        if (!is_dir($packagePath)) {
            return [];
        }

        $defaults = [];
        foreach (glob($packagePath . '/*/manifest.json') as $manifestFile) {
            $manifest = json_decode(file_get_contents($manifestFile), true);
            if (!empty($manifest['default']) && !empty($manifest['name'])) {
                $defaults[] = $manifest['name'];
            }
        }

        return $defaults;
    }

    /**
     * default:true 패키지를 포함한 extension_config JSON 생성
     *
     * manifest.json에서 default:true인 패키지를 찾아 packages(활성)에만 등록한다.
     * installed에는 넣지 않는다 — 시딩은 install() 단일 경로로 통합돼 있고,
     * 첫 부팅의 reconcileDefaultExtensions()가 "default인데 미설치"인 패키지의
     * install()(→ seedBoards)을 태운 뒤 installed로 마킹한다.
     * (여기서 installed로 미리 마킹하면 reconcile이 건너뛰어 install()이 영영 안 돈다.)
     */
    private function buildDefaultExtensionConfig(?array $kitExtensions = null): string
    {
        $defaultPackages = $this->getDefaultPackages();

        // 시작 킷이 요구하는 확장도 활성 목록에 추가 (installed 에는 넣지 않음 —
        // default 패키지와 같은 규칙: 첫 부팅 reconcile 이 마이그레이션+install 수행)
        $plugins = array_values(array_unique(
            is_array($kitExtensions['plugins'] ?? null) ? $kitExtensions['plugins'] : []
        ));
        $packages = array_values(array_unique(array_merge(
            $defaultPackages,
            is_array($kitExtensions['packages'] ?? null) ? $kitExtensions['packages'] : []
        )));

        return json_encode([
            'plugins' => $plugins,
            'packages' => $packages,
            'installed' => [
                'plugins' => [],
                'packages' => [],
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 현재 시간 반환
     */
    private function getCurrentDateTime(): string
    {
        return date('Y-m-d H:i:s');
    }
}
