<?php
session_start();
session_unset();
session_destroy();

// If in admin folder, redirect to admin login, otherwise student login.
if (strpos($_SERVER['REQUEST_URI'], '/admin/') !== false) {
    header('Location: index.php');
} else {
    header('Location: index.php');
}
exit();
