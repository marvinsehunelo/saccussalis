<?php
// ----------------------------------------
// hash_password.php
// ----------------------------------------

// The password you want to hash
$password = "1111";

// Hash the password using bcrypt
$hashedPassword = password_hash($password, PASSWORD_BCRYPT);

// Output the result
echo "Original password: $password\n";
echo "Hashed password: $hashedPassword\n";
