#!/bin/bash

BASE="https://saccussalis-production.up.railway.app"

echo "🔍 TESTING DATABASE CONNECTION"
echo "================================"

echo -e "\n1. PHP Info (PostgreSQL extensions):"
curl -s $BASE/phpinfo.php | grep -E "pdo_pgsql|pgsql|PostgreSQL" | head -10

echo -e "\n2. Database Diagnostic:"
curl -s $BASE/backend/db-diagnostic.php

echo -e "\n3. Login Attempt:"
curl -s -X POST $BASE/backend/auth/login.php \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"test123"}' | json_pp 2>/dev/null || echo "Invalid JSON"

echo -e "\n4. Recent Logs:"
railway logs --latest --lines 10 | grep -i "database\|pdo\|connection"
