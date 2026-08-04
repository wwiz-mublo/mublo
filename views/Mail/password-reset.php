<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:'Apple SD Gothic Neo','Malgun Gothic',sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:40px 0">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden">

    <!-- 헤더 -->
    <tr>
        <td style="background:#2c3e50;padding:24px 32px;color:#fff;font-size:18px;font-weight:bold">
            비밀번호 재설정
        </td>
    </tr>

    <!-- 본문 -->
    <tr>
        <td style="padding:32px">
            <p style="margin:0 0 16px;font-size:15px;color:#333">
                <strong><?= htmlspecialchars($member_name ?? '회원') ?></strong>님, 비밀번호 재설정이 요청되었습니다.
            </p>

            <p style="margin:0 0 24px;font-size:14px;color:#555">
                아래 버튼을 클릭하면 비밀번호를 재설정할 수 있습니다.
            </p>

            <table cellpadding="0" cellspacing="0" style="margin:0 auto 24px">
                <tr>
                    <td style="background:#3498db;border-radius:6px">
                        <a href="<?= htmlspecialchars($reset_url ?? '#') ?>"
                           style="display:inline-block;padding:14px 32px;color:#fff;text-decoration:none;font-size:15px;font-weight:bold">
                            비밀번호 재설정
                        </a>
                    </td>
                </tr>
            </table>

            <p style="margin:0 0 8px;font-size:13px;color:#999">
                버튼이 작동하지 않으면 아래 주소를 브라우저에 직접 붙여넣으세요.
            </p>
            <p style="margin:0 0 24px;font-size:12px;color:#3498db;word-break:break-all">
                <?= htmlspecialchars($reset_url ?? '') ?>
            </p>

            <?php if (!empty($expires_in)): ?>
            <p style="margin:0 0 8px;font-size:13px;color:#e74c3c">
                이 링크는 <?= htmlspecialchars($expires_in) ?> 후 만료됩니다.
            </p>
            <?php endif; ?>

            <hr style="border:none;border-top:1px solid #eee;margin:24px 0">

            <p style="margin:0;font-size:13px;color:#999">
                본인이 요청하지 않았다면 이 메일을 무시하세요. 비밀번호는 변경되지 않습니다.
            </p>
        </td>
    </tr>

    <!-- 푸터 -->
    <tr>
        <td style="padding:16px 32px;background:#f8f9fa;font-size:12px;color:#999;text-align:center">
            &copy; <?= date('Y') ?> Mublo. All rights reserved.
        </td>
    </tr>

</table>
</td></tr>
</table>
</body>
</html>
