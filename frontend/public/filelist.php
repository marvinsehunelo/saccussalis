<?php
// GitHub Repository File Lister - PHP 7.x Compatible
// Usage: https://yourdomain.com/filelist.php?repo=marvinsehunelo/CazaCom&path=backend/public

header('Content-Type: text/html; charset=utf-8');

$repo = isset($_GET['repo']) ? $_GET['repo'] : 'marvinsehunelo/CazaCom';
$path = isset($_GET['path']) ? $_GET['path'] : '';
$branch = isset($_GET['branch']) ? $_GET['branch'] : 'main';

$apiUrl = "https://api.github.com/repos/{$repo}/contents/{$path}?ref={$branch}";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'CazaCom-FileLister/1.0');
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
    <title>GitHub File Lister - {$repo}</title>
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
    <h1>📁 GitHub Repository Files</h1>
    <div class='repo-info'>
        <strong>Repository:</strong> {$repo}<br>
        <strong>Branch:</strong> {$branch}<br>
        <strong>Path:</strong> <span class='path'>" . ($path ? $path : '/') . "</span>
    </div>";

// Build breadcrumb
$breadcrumb = "<div class='breadcrumb'>📂 ";
$parts = explode('/', $path);
$currentPath = '';
$breadcrumb .= "<a href='?repo=" . urlencode($repo) . "&branch=" . urlencode($branch) . "'>root</a> / ";
foreach ($parts as $i => $part) {
    if (empty($part)) continue;
    $currentPath .= ($i > 0 ? '/' : '') . $part;
    $breadcrumb .= "<a href='?repo=" . urlencode($repo) . "&path=" . urlencode($currentPath) . "&branch=" . urlencode($branch) . "'>" . htmlspecialchars($part) . "</a>";
    if ($i < count($parts) - 1) $breadcrumb .= " / ";
}
$breadcrumb .= "</div>";
echo $breadcrumb;

echo "<table>
    <thead>
        <tr><th>Name</th><th>Type</th><th>Size</th><th>Last Modified</th></tr>
    </thead>
    <tbody>";

$totalSize = 0;
$fileCount = 0;
$dirCount = 0;

foreach ($files as $item) {
    $name = $item['name'];
    $type = $item['type'];
    $size = isset($item['size']) ? $item['size'] : 0;
    $downloadUrl = isset($item['download_url']) ? $item['download_url'] : '#';
    $modified = isset($item['sha']) ? substr($item['sha'], 0, 7) : 'N/A';
    
    if ($type === 'dir') {
        $dirCount++;
        $icon = '📁';
        $link = "?repo=" . urlencode($repo) . "&path=" . urlencode($path . ($path ? '/' : '') . $name) . "&branch=" . urlencode($branch);
        echo "<tr>
            <td><a href='{$link}' class='folder'><span class='icon'>{$icon}</span> {$name}/</a></td>
            <td>Directory</td>
            <td class='size'>-</td>
            <td class='size'>{$modified}</td>
         </tr>";
    } else {
        $fileCount++;
        $totalSize += $size;
        $icon = '📄';
        $sizeText = formatSize($size);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $color = getFileColor($ext);
        echo "<tr>
            <td><a href='{$downloadUrl}' target='_blank' class='file' style='color:{$color}'><span class='icon'>{$icon}</span> {$name}</a></td>
            <td>" . strtoupper($ext ? $ext : 'TXT') . "</td>
            <td class='size'>{$sizeText}</td>
            <td class='size'>{$modified}</td>
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
    <a href='?repo=" . urlencode($repo) . "&branch=" . urlencode($branch) . "' style='color:#58a6ff'>📂 Repository Root</a><br>
    <a href='https://github.com/{$repo}' target='_blank' style='color:#58a6ff'>🌐 View on GitHub</a>
</div>";

echo "</body></html>";

function formatSize($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

function getFileColor($ext) {
    $colors = [
        'php' => '#7ee787',
        'js' => '#f1e05a',
        'html' => '#e34c26',
        'css' => '#563d7c',
        'json' => '#f1e05a',
        'sql' => '#e38c2a',
        'py' => '#3572A5',
        'md' => '#083fa1',
    ];
    return isset($colors[$ext]) ? $colors[$ext] : '#c9d1d9';
}
?>
