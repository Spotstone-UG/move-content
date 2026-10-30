<?php
$root = rtrim((string) ($argv[1] ?? ''), '/');
if (!str_ends_with($root, '/testcommunity.selbstsein.events')) {
    throw new RuntimeException('Use only the dedicated testcommunity.selbstsein.events installation.');
}
$protected = $root . '/protected';
$loader = require $protected . '/vendor/autoload.php';
Dotenv\Dotenv::createMutable($root, '.env')->safeLoad();
$loader->addClassMap(['humhub\\services\\BootstrapService' => $protected . '/humhub/services/BootstrapService.php']);
define('YII_DEBUG', false); define('YII_ENV', 'prod');
require $protected . '/vendor/yiisoft/yii2/Yii.php';
Yii::setAlias('@humhub', $protected . '/humhub');
$_SERVER['REQUEST_URI'] = '/';
$config = (new humhub\services\BootstrapService())->getConfig('web');
$config['components']['request']['scriptFile'] = $root . '/index.php';
$config['components']['request']['scriptUrl'] = '/index.php';
$config['components']['request']['hostInfo'] = 'https://testcommunity.selbstsein.events';
new humhub\components\Application($config);
set_exception_handler(static function ($e) { fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() . "\n"); exit(1); });
function verify($condition, $name) { if (!$condition) { throw new RuntimeException($name); } $GLOBALS['checks'][] = $name; }
$checks = [];
if (($argv[2] ?? '') === 'refresh-config') {
    Yii::$app->moduleManager->flushCache();
    echo json_encode(['moduleEnabled' => \humhub\models\ModuleEnabled::findOne(['module_id' => 'move-content']) !== null]) . "\n";
    exit;
}
use humhub\modules\post\models\Post;
use humhub\modules\comment\models\Comment;
use humhub\modules\user\models\User;
use humhub\modules\space\models\Space;
use humhub\modules\moveContent\services\ThreadMoveService;
use humhub\modules\conversations\models\ContentEmojiReaction;
use yii\db\Query;

require_once $root . '/protected/modules/move-content/migrations/m261030_120000_thread_moves.php';
if (!Yii::$app->db->schema->getTableSchema(ThreadMoveService::AUDIT_TABLE)) {
    ob_start();
    (new m261030_120000_thread_moves())->safeUp();
    ob_end_clean();
    Yii::$app->db->schema->refresh();
}
require_once $root . '/protected/modules/move-content/migrations/m261030_180000_thread_move_notices.php';
if (!isset(Yii::$app->db->schema->getTableSchema(ThreadMoveService::AUDIT_TABLE)->columns['reason'])) {
    ob_start(); (new m261030_180000_thread_move_notices())->safeUp(); ob_end_clean();
    Yii::$app->db->schema->refresh();
}
$admin = null; $member = null;
foreach (User::find()->where(['status' => 1])->all() as $user) {
    if ($user->isSystemAdmin()) { $admin = $user; } else { $member = $user; }
}
verify($admin && $member, 'admin and ordinary member available');
Yii::$app->user->switchIdentity($admin);
$space = Space::find()->one();
verify($space !== null, 'test space available');
$db = Yii::$app->db;
$tx = $db->beginTransaction();
try {
    foreach ([[$admin, Space::USERGROUP_ADMIN], [$member, Space::USERGROUP_MEMBER]] as [$testUser, $group]) {
        if (!$space->isMember($testUser->id)) { $space->addMember($testUser->id, 1, true, $group); }
        \humhub\modules\space\models\Membership::updateAll(['group_id' => $group], ['space_id' => $space->id, 'user_id' => $testUser->id]);
    }
    $makePost = function ($text) use ($space, $admin, $db) {
        $p = new Post($space, ['message' => $text]);
        $p->silentContentCreation = true;
        $p->autoFollow = false;
        if (!$p->save()) { throw new RuntimeException(json_encode($p->errors)); }
        return Post::findOne($p->id);
    };
    $makeComment = function ($parent, $text) use ($db, $member) {
        $db->createCommand()->insert(Comment::tableName(), [
            'message' => $text, 'object_model' => $parent::class, 'object_id' => $parent->id,
            'created_by' => $member->id, 'updated_by' => $member->id,
            'created_at' => '2026-01-02 12:34:56', 'updated_at' => '2026-01-03 12:34:56',
        ])->execute();
        return Comment::findOne($db->getLastInsertID());
    };
    $a = $makePost('Thread move test A');
    $b = $makePost('Thread move test B');
    $c = $makePost('Thread move test C');
    verify($a->content->container instanceof Space, 'fixture belongs to space');
    verify($a->content->canView($admin), 'admin can view fixture');
    verify($admin->canManageAllContent() || $space->getPermissionManager($admin)->can(\humhub\modules\content\permissions\ManageContent::class), 'admin can moderate fixture');
    $service = new ThreadMoveService();
    $controller = new \humhub\modules\moveContent\controllers\ThreadController('thread', Yii::$app->getModule('move-content'));
    Yii::$app->controller = $controller;
    $html = $controller->actionIndex('post', (int)$a->id);
    verify(str_contains($html, 'Vorschau anzeigen') && str_contains($html, 'Link zum Zielbeitrag'), 'post move form renders');
    $resolve = new ReflectionMethod($controller, 'resolveTarget');
    verify((int)$resolve->invoke($controller, $b->getUrl(), $service)->id === (int)$b->id, 'native post permalink resolves');
    $contentUrl = '/index.php?r=content%2Fperma&id=' . $b->content->id;
    verify((int)$resolve->invoke($controller, $contentUrl, $service)->id === (int)$b->id, 'content permalink resolves');
    $reply = $makeComment($a, 'Original text **with formatting**');
    verify(str_contains($controller->actionIndex('comment', (int)$reply->id), 'Diese Antwort als eigenen Beitrag herauslösen'), 'reply move and promotion form renders');
    Yii::$app->request->setBodyParams(['DynamicModel' => ['operation' => 'move', 'targetUrl' => $c->getUrl(), 'reason' => 'Passt zum anderen Thema']]);
    verify(str_contains($controller->actionIndex('comment', (int)$reply->id), 'Jetzt umordnen'), 'target preview renders before confirmation');
    Yii::$app->request->setBodyParams([]);
    $child = $makeComment($reply, 'Nested reply that must move with its parent');
    $db->createCommand()->insert('{{%file}}', [
        'guid' => humhub\libs\UUID::v4(), 'state' => 1, 'file_name' => 'thread-move-test.txt',
        'mime_type' => 'text/plain', 'size' => 1, 'object_model' => Comment::class,
        'object_id' => $reply->id, 'content_id' => $a->content->id,
        'created_by' => $member->id, 'created_at' => '2026-01-02 12:34:56',
    ])->execute();
    $fileId = (int)$db->getLastInsertID();
    $childFile = (new Query())->from('{{%file}}')->where(['id' => $fileId])->one();
    unset($childFile['id']);
    $childFile['guid'] = humhub\libs\UUID::v4(); $childFile['object_id'] = $child->id;
    $db->createCommand()->insert('{{%file}}', $childFile)->execute();
    $childFileId = (int)$db->getLastInsertID();
    $db->createCommand()->insert('{{%like}}', ['object_model' => Comment::class, 'object_id' => $reply->id,
        'created_by' => $admin->id, 'target_user_id' => $member->id, 'created_at' => '2026-01-02 12:34:56'])->execute();
    $likeId = (int)$db->getLastInsertID();
    $reaction = new ContentEmojiReaction(['content_model' => Comment::class, 'content_id' => $reply->id,
        'user_id' => $admin->id, 'emoji' => '👍', 'created_at' => '2026-01-02 12:34:56']);
    verify($reaction->save(), 'emoji reaction fixture saved');
    $reactionId = $reaction->getPrimaryKey();
    $beforeNotifications = (new Query())->from('{{%notification}}')->count();
    $beforeActivities = (new Query())->from('{{%activity}}')->count();
    $moved = $service->move('comment', (int)$reply->id, (int)$c->id, $service->fingerprint($reply, $c), 'Passt zum anderen Thema');
    verify($moved->object_model === Post::class && (int)$moved->object_id === (int)$c->id, 'case 3: reply moves from A to C');
    verify((int)Comment::findOne($child->id)->object_id === (int)$moved->id, 'nested reply stays attached');
    verify((int)(new Query())->select('content_id')->from('{{%file}}')->where(['id' => $fileId])->scalar() === (int)$c->content->id, 'attachment follows new parent content');
    $promoted = $service->move('comment', (int)$moved->id, null, $service->fingerprint($moved, null), 'Ein eigenständiges Thema');
    verify($promoted instanceof Post && $promoted->message === $reply->message, 'case 2: reply becomes standalone post');
    verify((int)$promoted->created_by === (int)$member->id && $promoted->created_at === $reply->created_at && $promoted->updated_at === $reply->updated_at, 'author and original timestamps preserved');
    verify((int)$promoted->content->created_by === (int)$member->id, 'content metadata preserves original author');
    $childNow = Comment::findOne($child->id);
    verify($childNow->object_model === Post::class && (int)$childNow->object_id === (int)$promoted->id, 'nested reply becomes reply of promoted post');
    $file = (new Query())->from('{{%file}}')->where(['id' => $fileId])->one();
    verify($file['object_model'] === Post::class && (int)$file['object_id'] === (int)$promoted->id && (int)$file['content_id'] === (int)$promoted->content->id, 'attachment and file content ID preserved on promotion');
    verify((int)(new Query())->select('content_id')->from('{{%file}}')->where(['id' => $childFileId])->scalar() === (int)$promoted->content->id, 'child attachment content ID follows promotion');
    verify(ContentEmojiReaction::find()->where(['content_model' => Post::class, 'content_id' => $promoted->id, 'user_id' => $admin->id, 'emoji' => '👍'])->exists(), 'emoji reaction follows promoted post');
    verify(str_contains(\humhub\modules\content\widgets\WallEntryLinks::widget(['object' => $promoted]), 'Verschiebeverlauf'), 'post stream links render attached move history');
    $like = (new Query())->from('{{%like}}')->where(['id' => $likeId])->one();
    verify($like['object_model'] === Post::class && (int)$like['object_id'] === (int)$promoted->id, 'native like follows promoted post');
    $asReply = $service->move('post', (int)$promoted->id, (int)$b->id, $service->fingerprint($promoted, $b), 'Gehört hierher <script>alert(1)</script>');
    verify($asReply instanceof Comment && (int)$asReply->object_id === (int)$b->id && $asReply->message === $reply->message, 'case 1: standalone post becomes reply under B');
    verify(Comment::findOne($child->id)->object_model === Comment::class && (int)Comment::findOne($child->id)->object_id === (int)$asReply->id, 'existing reply moves with converted post');
    verify(ContentEmojiReaction::find()->where(['content_model' => Comment::class, 'content_id' => $asReply->id, 'user_id' => $admin->id, 'emoji' => '👍'])->exists(), 'emoji reaction follows converted reply');
    $promoted->content->refresh();
    verify($promoted->content->getStateService()->isDeleted(), 'converted post no longer appears as standalone post');
    verify((int)(new Query())->select('content_id')->from('{{%file}}')->where(['id' => $childFileId])->scalar() === (int)$b->content->id, 'child attachment content ID follows conversion to reply');
    $oldController = new \humhub\modules\post\controllers\PostController('post', Yii::$app->getModule('post'));
    $event = new \yii\base\ActionEvent($oldController->createAction('view'));
    Yii::$app->request->setQueryParams(['id' => $promoted->id]);
    \humhub\modules\moveContent\Events::onBeforeAction($event);
    verify(!$event->isValid && Yii::$app->response->headers->get('Location') === $asReply->getUrl(), 'old post permalink redirects to converted reply');
    Yii::$app->response->headers->remove('Location'); Yii::$app->response->statusCode = 200;
    $oldCommentController = new \humhub\modules\comment\controllers\PermaController('perma', Yii::$app->getModule('comment'));
    $event = new \yii\base\ActionEvent($oldCommentController->createAction('index'));
    Yii::$app->request->setQueryParams(['id' => $reply->id]);
    \humhub\modules\moveContent\Events::onBeforeAction($event);
    verify(!$event->isValid && Yii::$app->response->headers->get('Location') === $asReply->getUrl(), 'old comment permalink follows repeated conversion chain');
    Yii::$app->response->headers->remove('Location'); Yii::$app->response->statusCode = 200;
    verify((new Query())->from('{{%notification}}')->count() === $beforeNotifications, 'no duplicate publication notifications');
    verify((new Query())->from('{{%activity}}')->count() === $beforeActivities, 'no duplicate publication activities');
    $trigger = \humhub\modules\moveContent\widgets\ThreadMoveNotice::widget(['object' => $asReply]);
    verify(str_contains($trigger, '<svg') && str_contains($trigger, 'ui.modal.load') && !str_contains($trigger, 'Passt zum anderen Thema'), 'compact yellow triangle opens history without expanding it inline');
    $notice = \humhub\modules\moveContent\widgets\ThreadMoveNotice::widget(['object' => $asReply, 'details' => true]);
    verify(substr_count($notice, 'Von Admin ') === 3, 'three move notices accumulate across comment-post-comment conversions');
    verify(str_contains($notice, 'Passt zum anderen Thema') && str_contains($notice, 'Ein eigenständiges Thema'), 'earlier reasons remain visible');
    verify(str_contains($notice, '&lt;script&gt;') && !str_contains($notice, '<script>'), 'custom reason is escaped against script injection');
    verify(str_contains($notice, 'Ursprüngliche Nachricht von') && str_contains($notice, 'Einschließlich 1 untergeordneter Antworten'), 'notice includes original authorship and moved reply count');
    $historyRows = (new Query())->from(ThreadMoveService::AUDIT_TABLE)->where(['item_model' => Comment::class, 'item_id' => $asReply->id])->all();
    verify(count($historyRows) === 3 && (int)$historyRows[0]['created_by'] === (int)$admin->id && !empty($historyRows[0]['created_at']), 'audit stores acting admin and timestamp');
    verify($asReply->message === $reply->message, 'moderation notices never alter original message text');
    verify(str_contains(\humhub\modules\comment\widgets\CommentEntryLinks::widget(['object' => $asReply]), 'Verschiebeverlauf'), 'comment links render attached move history');
    try { $service->move('post', (int)$a->id, (int)$b->id, $service->fingerprint($a, $b), ' '); throw new RuntimeException('empty reason accepted'); }
    catch (yii\web\BadRequestHttpException $e) { $checks[] = 'empty reason rejected'; }
    try { $service->move('post', (int)$a->id, (int)$b->id, 'stale', 'Test'); throw new RuntimeException('stale preview accepted'); }
    catch (yii\web\BadRequestHttpException $e) { $checks[] = 'stale preview rejected'; }
    try { $service->validateMove($a, $a); throw new RuntimeException('self move accepted'); }
    catch (yii\web\BadRequestHttpException $e) { $checks[] = 'self move rejected'; }
    $db->createCommand()->update('{{%content}}', ['visibility' => $a->content->visibility ? 0 : 1], ['id' => $c->content->id])->execute();
    $c = Post::findOne($c->id);
    try { $service->validateMove($a, $c); throw new RuntimeException('visibility change accepted'); }
    catch (yii\web\BadRequestHttpException $e) { $checks[] = 'different visibility rejected'; }
    // Two levels already exist under B, so another demotion must be blocked.
    try { $service->validateMove($b, $a); throw new RuntimeException('third comment level accepted'); }
    catch (yii\web\BadRequestHttpException $e) { $checks[] = 'unsupported third reply level rejected'; }
    Yii::$app->user->switchIdentity($member);
    verify(str_contains($controller->actionHistory('comment', (int)$asReply->id), 'Passt zum anderen Thema'), 'ordinary member can open full history modal on readable content');
    try { $service->load('post', (int)$a->id); throw new RuntimeException('ordinary member accepted'); }
    catch (yii\web\ForbiddenHttpException $e) { $checks[] = 'ordinary member cannot reorganize without moderation permission'; }
    $membership = \humhub\modules\space\models\Membership::findOne(['space_id' => $space->id, 'user_id' => $member->id]);
    $membership->group_id = Space::USERGROUP_MODERATOR; $membership->save(false);
    try { $service->load('post', (int)$a->id); throw new RuntimeException('space moderator accepted'); }
    catch (yii\web\ForbiddenHttpException $e) { $checks[] = 'space moderator without admin role rejected'; }
    $membership->group_id = Space::USERGROUP_ADMIN; $membership->save(false);
    verify($service->load('post', (int)$a->id) instanceof Post, 'ordinary user with space admin role allowed');
    $memberNotice = \humhub\modules\moveContent\widgets\ThreadMoveNotice::widget(['object' => $asReply, 'details' => true]);
    verify(str_contains($memberNotice, 'Passt zum anderen Thema'), 'readable move history is visible to readers');
    Yii::$app->user->switchIdentity(null);
    verify(\humhub\modules\moveContent\widgets\ThreadMoveNotice::widget(['object' => $asReply]) === '', 'unreadable content exposes no history indicator');
    try { $controller->actionHistory('comment', (int)$asReply->id); throw new RuntimeException('unreadable history accepted'); }
    catch (yii\web\NotFoundHttpException $e) { $checks[] = 'history endpoint rejects readers without content access'; }
    try { $service->load('post', (int)$a->id); throw new RuntimeException('guest accepted'); }
    catch (yii\web\ForbiddenHttpException $e) { $checks[] = 'guest rejected'; }
} finally { $tx->rollBack(); }
echo json_encode($checks, JSON_UNESCAPED_SLASHES) . "\n";
