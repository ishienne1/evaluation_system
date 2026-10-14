<?php
// Redirect old register page to the new combined login/register page
$school = $_GET['school'] ?? 'mcnp';
if (!in_array($school, ['mcnp', 'isap', 'mcnp-isap'])) $school = 'mcnp';
header("Location: login.php?school={$school}&panel=register#register");
exit();
