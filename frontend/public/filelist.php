<?php
// Saccussalis GitHub Repository File Lister
// Usage: https://yourdomain.com/saccussalis-filelist.php

header('Content-Type: text/html; charset=utf-8');

$repo = 'marvinsehunelo/Saccussalis';
$path = $_GET['path'] ?? '';
$branch = $_GET['branch'] ?? 'main';

$apiUrl = "https://api.github.com/repos/{$repo}/contents/{$path}?ref={$branch}";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'Saccussalis-FileLister/1.0');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/vnd.github.v3+json'
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    die("Error: Unable to fetch repository contents. HTTP Code: $httpCode");
}

$files = json_decode($response, true);

if (!is_array($files)) {
    die("Error: Invalid response from GitHub API");
}

echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Saccussalis - File Lister</title>
    <style>
        body {
            background: #0d1117;
            color: #c9d1d9;
            font-family: 'Consolas', 'Monaco', monospace;
            padding: 20px;
            margin: 0;
        }
        h1 {
            color: #58a6ff;
            border-bottom: 1px solid #30363d;
            padding-bottom: 10px;
        }
        .repo-info {
            background: #161b22;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 6px;
        }
        .path {
            color: #ff7b72;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th {
            text-align: left;
            padding: 10px;
            background: #161b22;
            color: #58a6ff;
            border-bottom: 1px solid #30363d;
        }
        td {
            padding: 8px 10px;
            border-bottom: 1px solid #21262d;
        }
        tr:hover {
            background: #161b22;
        }
        .folder {
            color: #ff7b72;
            font-weight: bold;
        }
        .file {
            color: #7ee787;
        }
        .size {
            color: #8b949e;
            font-size: 12px;
        }
        .icon {
            font-size: 16px;
            margin-right: 8px;
        }
        a {
            color: inherit;
            text-decoration: none;
        }
        a:hover {
            text-decoration: underline;
        }
        .breadcrumb {
            margin-bottom: 20px;
            padding: 10px;
            background: #161b22;
            border-radius: 6px;
        }
        .breadcrumb a {
            color: #58a6ff;
        }
    </style>
</head>
<body>
    <h1>🏦 Saccussalis Repository</h1>
    <div class='repo-info'>
        <strong>Repository:</strong> {$repo}<br>
        <strong>Branch:</strong> {$branch}<br>
        <strong>Path:</strong> <span class='path'>{$path ?: '/'}</span>
    </div>";

$breadcrumb = "<div class='breadcrumb'>📂 ";
$parts = explode('/', $path);
$currentPath = '';
$breadcrumb .= "<a href='?branch=" . urlencode($branch) . "'>root</a> / ";
foreach ($parts as $i => $part) {
    if (empty($part)) continue;
    $currentPath .= ($i > 0 ? '/' : '') . $part;
    $breadcrumb .= "<a href='?path=" . urlencode($currentPath) . "&branch=" . urlencode($branch) . "'>" . htmlspecialchars($part) . "</a>";
    if ($i < count($parts) - 1) $breadcrumb .= " / ";
}
$breadcrumb .= "</div>";
echo $breadcrumb;

echo "<table>
    <thead>
        <tr><th>Name</th><th>Type</th><th>Size</th><th>Action</th></tr>
    </thead>
    <tbody>";

$totalSize = 0;
$fileCount = 0;
$dirCount = 0;

foreach ($files as $item) {
    $name = $item['name'];
    $type = $item['type'];
    $size = $item['size'] ?? 0;
    $downloadUrl = $item['download_url'] ?? '#';
    
    if ($type === 'dir') {
        $dirCount++;
        $icon = '📁';
        echo "<tr>
            <td><a href='?path=" . urlencode($path . ($path ? '/' : '') . $name) . "&branch=" . urlencode($branch) . "' class='folder'><span class='icon'>{$icon}</span> {$name}/</a></td>
            <td>Directory</td>
            <td class='size'>-</td>
            <td class='size'>-</td>
        </tr>";
    } else {
        $fileCount++;
        $totalSize += $size;
        $icon = '📄';
        $sizeText = formatSize($size);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        echo "<tr>
            <td><a href='{$downloadUrl}' target='_blank' class='file'><span class='icon'>{$icon}</span> {$name}</a></td>
            <td>" . strtoupper($ext ?: 'FILE') . "</td>
            <td class='size'>{$sizeText}</td>
            <td class='size'><a href='{$downloadUrl}' target='_blank' style='color:#58a6ff'>📥 Raw</a></td>
        </tr>";
    }
}

echo "</tbody>
</table>";

echo "<div style='margin-top: 20px; padding: 15px; background: #161b22; border-radius: 6px;'>
    <strong>📊 Summary:</strong><br>
    📁 Directories: {$dirCount}<br>
    📄 Files: {$fileCount}<br>
    💾 Total Size: " . formatSize($totalSize) . "
</div>";

echo "<div style='margin-top: 20px; padding: 15px; background: #161b22; border-radius: 6px;'>
    <strong>🔗 Quick Links:</strong><br>
    <a href='https://github.com/{$repo}' target='_blank' style='color:#58a6ff'>🌐 View Saccussalis on GitHub</a><br>
    <a href='?branch=main' style='color:#58a6ff'>📂 Repository Root</a>
</div>";

echo "</body></html>";

function formatSize($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}
?>
