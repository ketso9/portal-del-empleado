<?php
require_once('../../../wp-load.php');
$users = get_users(['number' => 100, 'fields' => ['ID', 'display_name', 'user_email']]);
echo json_encode($users);
