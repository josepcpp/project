<?php
session_start();
session_unset();
session_destroy();
header("Location: /project/public/index.php");
exit();