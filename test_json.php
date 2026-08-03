<?php
require_once '/Users/lakaakhilyadav/Documents/s/pms_core/Database.php';
$_SESSION['user_id'] = 1;
$_SESSION['access_level'] = 'owner';
$_SESSION['property_id'] = 1;
require_once '/Users/lakaakhilyadav/Documents/s/pms_core/AuthHelper.php';
// Stub ApiHandler to run the closure immediately without checking auth
class ApiHandlerStub {
    public static function run($callback) {
        $db = Database::getInstance()->getConnection();
        $callback($db);
    }
}
class ApiResponseStub {
    public static function success($data) {
        echo json_encode($data, JSON_PRETTY_PRINT);
    }
}
// Override classes
class_alias('ApiHandlerStub', 'ApiHandler');
class_alias('ApiResponseStub', 'ApiResponse');

// We need to redefine the require statements in the file or just copy the file contents.
$code = file_get_contents('/Users/lakaakhilyadav/Documents/s/pms_core/api_endpoints/admin_actions.php');
// Remove require statements and ApiHandler::run...
$code = preg_replace('/require_once.*/', '', $code);
$code = str_replace('ApiHandler::run(function(\PDO $db) {', '$db = Database::getInstance()->getConnection();', $code);
$code = str_replace('AuthHelper::requirePermission(\'view_dashboard\');', '', $code);
$code = str_replace('}, true, false, false);', '', $code);

eval('?>' . $code);
