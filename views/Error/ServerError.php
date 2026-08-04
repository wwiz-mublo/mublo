<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>서버 오류</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .error-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            max-width: 600px;
            width: 100%;
            padding: 48px;
            text-align: center;
        }
        .error-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            font-size: 36px;
            color: white;
            font-weight: bold;
        }
        .error-code {
            font-size: 14px;
            font-weight: 600;
            color: #e53e3e;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 8px;
        }
        .error-title {
            font-size: 24px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 16px;
        }
        .error-message {
            font-size: 16px;
            color: #718096;
            line-height: 1.6;
            margin-bottom: 32px;
        }
        .error-detail {
            background: #f7fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 32px;
            text-align: left;
            font-family: 'Monaco', 'Consolas', monospace;
            font-size: 13px;
            color: #4a5568;
            max-height: 200px;
            overflow: auto;
            word-break: break-all;
        }
        .error-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s ease;
            cursor: pointer;
            border: none;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        .btn-secondary {
            background: #f7fafc;
            color: #4a5568;
            border: 1px solid #e2e8f0;
        }
        .btn-secondary:hover { background: #edf2f7; }
        @media (max-width: 480px) {
            .error-container { padding: 32px 24px; }
            .error-title { font-size: 20px; }
            .error-actions { flex-direction: column; }
            .btn { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon">500</div>
        <div class="error-code">Server Error</div>
        <h1 class="error-title">서버 오류가 발생했습니다</h1>
        <p class="error-message">
            요청을 처리하는 중 오류가 발생했습니다.<br>
            잠시 후 다시 시도해 주세요.
        </p>

        <?php if (isset($exception) && ($_ENV['APP_DEBUG'] ?? 'false') === 'true'): ?>
        <div class="error-detail">
            <strong><?= htmlspecialchars(get_class($exception)) ?></strong><br>
            <?= htmlspecialchars($exception->getMessage()) ?><br><br>
            <small><?= htmlspecialchars($exception->getFile()) ?>:<?= $exception->getLine() ?></small>
        </div>
        <?php endif; ?>

        <div class="error-actions">
            <button type="button" class="btn btn-secondary" onclick="history.back()">뒤로 가기</button>
            <a href="/" class="btn btn-primary">홈으로</a>
        </div>
    </div>
</body>
</html>
