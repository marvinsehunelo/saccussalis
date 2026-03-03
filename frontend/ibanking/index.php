<?php
// index.php - Single Page Application entry
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>SaccusSalis iBanking</title>
  <!-- Tailwind CDN (for dev, later we use build pipeline) -->
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-950 text-white">
  <div id="root"></div>

  <!-- React (development setup using unpkg CDN, later replace with built build) -->
  <script crossorigin src="https://unpkg.com/react@18/umd/react.development.js"></script>
  <script crossorigin src="https://unpkg.com/react-dom@18/umd/react-dom.development.js"></script>
  <script crossorigin src="https://unpkg.com/@babel/standalone/babel.min.js"></script>

  <!-- Your App code (JSX via Babel for now) -->
  <script type="text/babel" src="src/App.jsx"></script>
</body>
</html>
