<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Run only in an explicitly selected, disposable HumHub test database.
if (getenv('HUMHUB_COMPAT_TEST') !== '1' || !getenv('HUMHUB_ROOT')) {
    throw new RuntimeException('Set HUMHUB_COMPAT_TEST=1 and HUMHUB_ROOT to a disposable local installation.');
}
ob_start();
define('YII_ENABLE_ERROR_HANDLER', false);
$root = rtrim(getenv('HUMHUB_ROOT'), '/');
require $root . '/protected/vendor/autoload.php';
require $root . '/protected/vendor/yiisoft/yii2/Yii.php';
Yii::setAlias('@humhub', $root . '/protected/humhub');
$_SERVER['REQUEST_URI'] = '/'; $_SERVER['HTTP_HOST'] = 'localhost';
$config = (new humhub\services\BootstrapService())->getConfig('web');
$config['components']['request']['scriptFile'] = $root . '/index.php';
$config['components']['request']['scriptUrl'] = '/index.php';
$config['components']['request']['hostInfo'] = 'http://localhost';
new humhub\components\Application($config);
if (!str_contains(Yii::$app->db->dsn, 'test')) { throw new RuntimeException('Test database required.'); }
$count = 0;
function verifyCompat(bool $ok, string $message): void {
    global $count;
    if (!$ok) { throw new RuntimeException($message); }
    ++$count;
}
$admin = humhub\modules\user\models\User::findOne(['username' => 'admin']);
verifyCompat($admin !== null && $admin->isSystemAdmin(), 'Test administrator exists');
Yii::$app->user->switchIdentity($admin);
