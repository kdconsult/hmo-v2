<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Processing — {{ config('app.name') }}</title>
    <style>
        body { font-family: sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; background: #f9fafb; color: #111827; }
        .card { background: white; border-radius: 12px; padding: 48px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,.1); max-width: 400px; width: 100%; }
        .icon { font-size: 48px; margin-bottom: 16px; }
        h1 { font-size: 22px; margin: 0 0 8px; }
        p { color: #6b7280; line-height: 1.6; margin: 0 0 24px; }
        a { display: inline-block; background: #f59e0b; color: white; padding: 10px 24px; border-radius: 6px; text-decoration: none; font-weight: 600; }
        a:hover { background: #d97706; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">✅</div>
        <h1>Payment received!</h1>
        <p>
            Thank you for your payment. Your subscription is being activated — this usually takes a few seconds.
            Please return to your dashboard and refresh if access isn't restored immediately.
        </p>
        <a href="/admin">Go to Dashboard</a>
    </div>
</body>
</html>
