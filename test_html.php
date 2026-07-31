<?php
$crole = ['id' => 1, 'name' => 'Owner', 'permissions' => json_encode(['view_dashboard', 'manage_rooms'])];
$cperms = json_decode($crole['permissions'] ?? '[]', true);
$html = sprintf(
    '<button onclick="editRole(%d, \'%s\', %s)">',
    $crole['id'],
    htmlspecialchars(addslashes($crole['name'])),
    htmlspecialchars(json_encode($cperms))
);
echo $html . "\n";
