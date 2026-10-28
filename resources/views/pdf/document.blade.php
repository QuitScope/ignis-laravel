<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
    @page { margin: 20mm 18mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 11pt; color: #1a1a1a; }
    p { margin: 0 0 0.75em; }
    table { border-collapse: collapse; width: 100%; margin: 0 0 0.75em; }
    th, td { border: 1px solid #999999; padding: 0.35em 0.5em; vertical-align: top; }
    th { background: #eeeeee; font-weight: bold; text-align: left; }
    .efe-page-break { page-break-after: always; }
</style>
</head>
<body>
{!! $body !!}
</body>
</html>
