<?php

/**
 * 확장 패키지 publisher trust store 예시.
 * 이 파일을 extension-publishers.php로 복사해 배포 환경별 정책을 설정한다.
 */
return [
    'require_signature' => false,
    'publishers' => [
        // 'vendor:key-2026' => [
        //     'name' => 'Vendor Name',
        //     'public_key' => "-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----",
        //     'sources' => ['official-marketplace'],
        // ],
    ],
];
