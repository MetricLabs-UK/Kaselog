<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Firm suspended</title>
    <style>
        html, body {
            height: 100%;
            margin: 0;
        }
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            background: #F3F5F8;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            color: #1F2937;
        }
        .card {
            max-width: 28rem;
            margin: 1.5rem;
            padding: 2.5rem;
            background: #ffffff;
            border-radius: 0.75rem;
            box-shadow: 0 10px 30px rgba(11, 79, 158, 0.08);
            text-align: center;
        }
        .icon {
            width: 3rem;
            height: 3rem;
            margin: 0 auto 1.25rem;
            border-radius: 9999px;
            background: #FDF0E3;
            color: #E0932E;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        h1 {
            font-size: 1.25rem;
            margin: 0 0 0.75rem;
            color: #0B4F9E;
        }
        p {
            margin: 0;
            font-size: 0.9375rem;
            line-height: 1.6;
            color: #4B5563;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">&#9888;</div>
        <h1>{{ $tenant->name }} is currently suspended</h1>
        <p>
            Access to this firm's workspace has been temporarily suspended.
            If you believe this is a mistake, please contact Kase support.
        </p>
    </div>
</body>
</html>
