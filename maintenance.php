<?php
http_response_code(503);
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance Mode</title>
    <style>
        :root {
            color-scheme: light;
            --bg-start: #f4efe6;
            --bg-end: #fffaf3;
            --card: #ffffff;
            --ink: #1f2937;
            --muted: #6b7280;
            --accent: #0f766e;
            --accent-soft: #dff6f2;
            --border: rgba(15, 118, 110, 0.16);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            font-family: Arial, sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at top, rgba(15, 118, 110, 0.10), transparent 38%),
                linear-gradient(160deg, var(--bg-start), var(--bg-end));
        }

        .card {
            width: min(100%, 720px);
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 40px 32px;
            box-shadow: 0 24px 60px rgba(31, 41, 55, 0.12);
            text-align: center;
        }

        .badge {
            display: inline-block;
            margin-bottom: 18px;
            padding: 8px 14px;
            border-radius: 999px;
            background: var(--accent-soft);
            color: var(--accent);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        h1 {
            margin: 0 0 12px;
            font-size: clamp(2rem, 4vw, 3.25rem);
            line-height: 1.05;
        }

        p {
            margin: 0 auto;
            max-width: 560px;
            color: var(--muted);
            font-size: 1rem;
            line-height: 1.7;
        }

        .note {
            margin-top: 22px;
            font-size: 0.95rem;
        }
    </style>
</head>
<body>
    <main class="card">
        <div class="badge">Scheduled Maintenance</div>
        <h1>DVC Scholarship Portal is temporarily unavailable</h1>
        <p>
            We are currently performing maintenance to improve the website. Please check back later.
        </p>
        <p class="note">
            Thank you for your patience and understanding.
        </p>
    </main>
</body>
</html>
