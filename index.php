<?php
/*
 * This file redirects visitors to the login page.
 * We use a relative path ("login.php") so it stays on the correct server (Railway).
 */

// Use the 'test-school' slug we created earlier.
// Later, you can change this to your real school slug.
header('Location: login.php?school=test-school');
exit();
?>
