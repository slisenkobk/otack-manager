<?php
header('Content-Type: text/html; charset=utf-8');
http_response_code(404);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Otack Manager - Form not found</title>
<link rel="stylesheet" href="/assets/css/app.css">
<style>
  body { background: var(--paper); min-height: 100vh; display: grid; place-items: center; padding: 40px; }
  .pf-404 { max-width: 480px; text-align: center; color: var(--ink-3); }
  .pf-404 h1 { font-size: 22px; color: var(--ink-2); margin: 0 0 10px; }
</style>
</head>
<body>
  <div class="pf-404">
    <h1>This form is not available</h1>
    <p>The link may be wrong, or the form has been archived. Please contact the person who shared the link.</p>
  </div>
</body>
</html>
