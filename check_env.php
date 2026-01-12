<?php
echo "<h1>Railway Environment Check</h1>";

$host = getenv('MYSQLHOST');
$user = getenv('MYSQLUSER');
$db   = getenv('MYSQLDATABASE');
$port = getenv('MYSQLPORT');

echo "<p><strong>Host:</strong> " . ($host ? $host : "❌ MISSING") . "</p>";
echo "<p><strong>User:</strong> " . ($user ? $user : "❌ MISSING") . "</p>";
echo "<p><strong>Database Name:</strong> " . ($db ? $db : "❌ MISSING (This is the problem)") . "</p>";
echo "<p><strong>Port:</strong> " . ($port ? $port : "❌ MISSING") . "</p>";

if (empty($db)) {
    echo "<h2 style='color:red'>⚠️ Fix: Go to Railway > Variables and check MYSQLDATABASE</h2>";
} else {
    echo "<h2 style='color:green'>✅ Variables look good. Testing Connection...</h2>";
    
    // Test connection
    $conn = new mysqli($host, $user, getenv('MYSQLPASSWORD'), $db, $port);
    if ($conn->connect_error) {
        echo "Connection Failed: " . $conn->connect_error;
    } else {
        echo "Connection Successful! Database selected.";
    }
}
?>
