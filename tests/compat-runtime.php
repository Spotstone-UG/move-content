<?php
// SPDX-License-Identifier: AGPL-3.0-only
require __DIR__ . '/compat-bootstrap.php';
use humhub\modules\comment\models\Comment;
use humhub\modules\post\models\Post;
use humhub\modules\space\models\Space;
use humhub\modules\content\models\Content;
use humhub\modules\moveContent\services\ThreadMoveService;
use humhub\modules\conversations\models\ContentEmojiReaction;
use yii\db\Query;
$db = Yii::$app->db;
$modern = isset(Comment::getTableSchema()->columns['parent_comment_id']);
$tx = $db->beginTransaction();
try {
    $space = new Space(['name' => 'Compatibility moves']);
    verifyCompat($space->save(), 'Test space saved');
    $makePost = static function (string $text) use ($space): Post {
        $post = new Post($space, ['message' => $text]);
        $post->silentContentCreation = true; $post->autoFollow = false;
        verifyCompat($post->save(), 'Test post saved');
        return Post::findOne($post->id);
    };
    $makeComment = static function (Post|Comment $parent, string $text) use ($db, $admin, $modern): Comment {
        $attributes = ['message' => $text, 'created_by' => $admin->id, 'updated_by' => $admin->id,
            'created_at' => '2026-01-02 12:34:56', 'updated_at' => '2026-01-03 12:34:56'];
        $attributes += $modern ? ['content_id' => $parent->content->id,
            'parent_comment_id' => $parent instanceof Comment ? $parent->id : null]
            : ['object_model' => $parent::class, 'object_id' => $parent->id];
        $db->createCommand()->insert(Comment::tableName(), $attributes)->execute();
        return Comment::findOne($db->getLastInsertID());
    };
    $a = $makePost('Source'); $b = $makePost('Target'); $c = $makePost('Other target');
    $reply = $makeComment($a, 'Original **formatted** reply');
    $child = $makeComment($reply, 'Child');
    $db->createCommand()->insert('{{%file}}', ['guid' => humhub\libs\UUID::v4(), 'state' => 1,
        'file_name' => 'compat.txt', 'mime_type' => 'text/plain', 'size' => 1,
        'object_model' => Comment::class, 'object_id' => $child->id, 'content_id' => $a->content->id,
        'created_by' => $admin->id, 'created_at' => '2026-01-02 12:34:56'])->execute();
    $fileId = $db->getLastInsertID();
    $like = ['created_by' => $admin->id, 'created_at' => '2026-01-02 12:34:56'];
    $like += $modern ? ['content_id' => $a->content->id, 'content_addon_record_id' => humhub\models\RecordMap::getId($reply)]
        : ['object_model' => Comment::class, 'object_id' => $reply->id];
    $db->createCommand()->insert('{{%like}}', $like)->execute(); $likeId = $db->getLastInsertID();
    $reaction = new ContentEmojiReaction(['content_model' => Comment::class, 'content_id' => $reply->id,
        'user_id' => $admin->id, 'emoji' => '👍', 'created_at' => '2026-01-02 12:34:56']);
    verifyCompat($reaction->save(), 'Emoji fixture saved');
    $activityId = null;
    if ($modern) {
        $activity = \humhub\modules\activity\services\ActivityManager::dispatch(
            \humhub\modules\comment\activities\NewCommentActivity::class, $reply, $admin);
        $activityId = (new Query())->from('{{%activity}}')->max('id');
    }
    $beforeActivities = (new Query())->from('{{%activity}}')->count();
    $beforeNotices = (new Query())->from('{{%notification}}')->count();
    $service = new ThreadMoveService();
    $moved = $service->move('comment', (int)$reply->id, (int)$c->id, $service->fingerprint($reply, $c), 'Same item, another thread');
    verifyCompat((int)$moved->content->id === (int)$c->content->id, 'Moved reply belongs to target');
    verifyCompat((int)Comment::findOne($child->id)->content->id === (int)$c->content->id, 'Child belongs to target');
    $promoted = $service->move('comment', (int)$moved->id, null, $service->fingerprint($moved, null), 'Independent post');
    verifyCompat($promoted instanceof Post && $promoted->message === $reply->message, 'Promotion preserves text');
    verifyCompat($promoted->created_at === $reply->created_at && $promoted->created_by === $reply->created_by, 'Promotion preserves authorship and date');
    $nativeLike = (new Query())->from('{{%like}}')->where(['id' => $likeId])->one();
    verifyCompat($modern ? ((int)$nativeLike['content_id'] === (int)$promoted->content->id && $nativeLike['content_addon_record_id'] === null)
        : ($nativeLike['object_model'] === Post::class && (int)$nativeLike['object_id'] === (int)$promoted->id), 'Native like follows promoted post');
    $asReply = $service->move('post', (int)$promoted->id, (int)$b->id, $service->fingerprint($promoted, $b), 'Back to reply');
    verifyCompat($asReply instanceof Comment && $asReply->message === $reply->message, 'Post becomes reply');
    $newChild = Comment::findOne($child->id);
    verifyCompat($modern ? (int)$newChild->parent_comment_id === (int)$asReply->id
        : ($newChild->object_model === Comment::class && (int)$newChild->object_id === (int)$asReply->id), 'Child stays attached after conversion');
    verifyCompat((int)(new Query())->from('{{%file}}')->select('content_id')->where(['id' => $fileId])->scalar() === (int)$b->content->id, 'Child file follows final target');
    verifyCompat(ContentEmojiReaction::find()->where(['content_model' => Comment::class, 'content_id' => $asReply->id, 'emoji' => '👍'])->exists(), 'Emoji reaction follows conversions');
    $nativeLike = (new Query())->from('{{%like}}')->where(['id' => $likeId])->one();
    verifyCompat($modern ? ((int)$nativeLike['content_id'] === (int)$b->content->id && (int)$nativeLike['content_addon_record_id'] === humhub\models\RecordMap::getId($asReply))
        : ($nativeLike['object_model'] === Comment::class && (int)$nativeLike['object_id'] === (int)$asReply->id), 'Native like follows final reply');
    verifyCompat((int)(new Query())->from(ThreadMoveService::AUDIT_TABLE)->count() === 3, 'Three moderation history rows retained');
    if ($activityId) {
        $activity = \humhub\modules\activity\services\ActivityManager::load(
            \humhub\modules\activity\models\Activity::findOne($activityId));
        verifyCompat($activity->getUrl() === $asReply->getUrl(), 'Existing activity renders and follows converted reply');
    }
    verifyCompat((new Query())->from('{{%activity}}')->count() === $beforeActivities, 'No duplicate activities');
    verifyCompat((new Query())->from('{{%notification}}')->count() === $beforeNotices, 'No duplicate notifications');
    try { $service->move('post', (int)$a->id, (int)$b->id, 'stale', 'Test'); throw new RuntimeException('Stale move accepted'); }
    catch (yii\web\BadRequestHttpException $e) { verifyCompat(true, 'Stale move rejected'); }
    try { $service->validateMove($b, $a); throw new RuntimeException('Third reply level accepted'); }
    catch (yii\web\BadRequestHttpException $e) { verifyCompat(true, 'Third reply level rejected'); }
    echo Yii::$app->version . ": $count thread move integration checks passed\n";
} finally { if ($tx->isActive) { $tx->rollBack(); } }
