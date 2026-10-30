<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
namespace humhub\modules\moveContent\services;

use humhub\libs\UUID;
use humhub\modules\comment\models\Comment;
use humhub\modules\content\models\Content;
use humhub\modules\content\permissions\ManageContent;
use humhub\modules\content\services\ContentSearchService;
use humhub\modules\post\models\Post;
use humhub\modules\space\models\Space;
use Yii;
use yii\db\Query;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/** Reorganizes plain posts/comments without publishing them a second time. */
final class ThreadMoveService
{
    public const AUDIT_TABLE = '{{%move_content_thread_move}}';

    public function load(string $type, int $id): Post|Comment
    {
        $class = match ($type) { 'post' => Post::class, 'comment' => Comment::class, default => null };
        $record = $class ? $class::findOne($id) : null;
        if (!$record) { throw new NotFoundHttpException('Der Beitrag wurde nicht gefunden.'); }
        $this->authorize($record);
        return $record;
    }

    public function authorize(Post|Comment $record): void
    {
        $user = Yii::$app->user->identity;
        $space = $record->content->container;
        if (!$user || !$space instanceof Space || !$record->content->canView($user)
            || (!$user->canManageAllContent() && !$space->getPermissionManager($user)->can(ManageContent::class))) {
            throw new ForbiddenHttpException('Zum Umordnen sind Moderationsrechte im Space erforderlich.');
        }
        if (!$record->content->getStateService()->isPublished() || $record->content->archived || $record->content->hidden) {
            throw new BadRequestHttpException('Nur veröffentlichte, nicht archivierte Beiträge können umgeordnet werden.');
        }
    }

    public function validateMove(Post|Comment $source, ?Post $target): void
    {
        $this->authorize($source);
        if ($source->content->locked_comments) {
            throw new BadRequestHttpException('Bei gesperrten Antworten ist kein Umordnen möglich.');
        }
        if (!$target) {
            if (!$source instanceof Comment) { throw new BadRequestHttpException('Nur eine Antwort kann als eigener Beitrag herausgelöst werden.'); }
            return;
        }
        $this->authorize($target);
        if ($source instanceof Post && (int)$source->id === (int)$target->id) {
            throw new BadRequestHttpException('Ein Beitrag kann nicht seine eigene Antwort werden.');
        }
        if ((int)$source->content->contentcontainer_id !== (int)$target->content->contentcontainer_id
            || (int)$source->content->visibility !== (int)$target->content->visibility) {
            throw new BadRequestHttpException('Quelle und Ziel müssen im selben Space liegen und dieselbe Sichtbarkeit haben.');
        }
        if ($source->content->locked_comments || $target->content->locked_comments) {
            throw new BadRequestHttpException('Bei gesperrten Antworten ist kein Umordnen möglich.');
        }
        if ($source instanceof Comment && $source->object_model === Post::class && (int)$source->object_id === (int)$target->id) {
            throw new BadRequestHttpException('Diese Antwort gehört bereits zu diesem Beitrag.');
        }
        if ($source instanceof Post) {
            $children = Comment::find()->select('id')->where(['object_model' => Post::class, 'object_id' => $source->id])->column();
            if ($children && Comment::find()->where(['object_model' => Comment::class, 'object_id' => $children])->exists()) {
                throw new BadRequestHttpException('Dieser Beitrag hat bereits verschachtelte Antworten. Bitte diese zuerst umordnen, damit keine zusätzliche, unsichtbare Antwortebene entsteht.');
            }
            if ((new Query())->from('{{%content_tag_relation}}')->where(['content_id' => $source->content->id])->exists()) {
                throw new BadRequestHttpException('Bitte Themenmarkierungen vor der Umwandlung entfernen. Antworten können keine eigenen Themenmarkierungen tragen.');
            }
            if ($source->content->pinned) {
                throw new BadRequestHttpException('Bitte den Beitrag vor der Umwandlung lösen. Antworten können nicht separat angeheftet sein.');
            }
        }
    }

    public function fingerprint(Post|Comment $source, ?Post $target): string
    {
        return hash('sha256', json_encode([$source->getAttributes(), $source->content->getAttributes(),
            $target?->getAttributes(), $target?->content->getAttributes()], JSON_THROW_ON_ERROR));
    }

    public function move(string $type, int $id, ?int $targetId, string $expected): Post|Comment
    {
        $db = Yii::$app->db;
        $transaction = $db->getTransaction()
            ? $db->beginTransaction()
            : $db->beginTransaction(\yii\db\Transaction::READ_COMMITTED);
        try {
            // Lock source and target before reloading; competing moderators cannot
            // convert the same record or change its destination concurrently.
            $initial = $this->load($type, $id);
            $contentIds = [(int)$initial->content->id];
            if ($targetId) {
                $targetInitial = $this->load('post', $targetId);
                $contentIds[] = (int)$targetInitial->content->id;
            }
            sort($contentIds);
            foreach (array_unique($contentIds) as $contentId) {
                $sql = 'SELECT * FROM {{%content}} WHERE [[id]]=:id';
                if (in_array($db->driverName, ['mysql', 'pgsql'], true)) { $sql .= ' FOR UPDATE'; }
                $db->createCommand($sql, [':id' => $contentId])->queryOne();
            }
            $source = $this->load($type, $id);
            $target = $targetId ? $this->load('post', $targetId) : null;
            $this->validateMove($source, $target);
            if (!hash_equals($this->fingerprint($source, $target), $expected)) {
                throw new BadRequestHttpException('Quelle oder Ziel wurde inzwischen geändert. Bitte die Vorschau neu öffnen.');
            }
            $oldContent = $source->content;
            $snapshot = json_encode(['record' => $source->getAttributes(), 'content' => $oldContent->getAttributes()], JSON_THROW_ON_ERROR);
            $oldParent = $source instanceof Comment ? [$source->object_model, $source->object_id] : null;
            if ($source instanceof Post) {
                $attributes = $source->getAttributes(['message', 'created_at', 'created_by', 'updated_at', 'updated_by']);
                $attributes += ['object_model' => Post::class, 'object_id' => $target->id];
                $db->createCommand()->insert(Comment::tableName(), $attributes)->execute();
                $newId = (int)$db->getLastInsertID();
                $this->retargetRelations(Post::class, (int)$source->id, Comment::class, $newId, (int)$target->content->id);
                // Keep an unpublished tombstone until HumHub's normal retention
                // cleanup. Its metadata is also retained in the move audit record.
                $db->createCommand()->update(Content::tableName(), ['state' => Content::STATE_DELETED], ['id' => $oldContent->id])->execute();
                $result = Comment::findOne($newId);
            } elseif ($target) {
                $db->createCommand()->update(Comment::tableName(), ['object_model' => Post::class, 'object_id' => $target->id], ['id' => $source->id])->execute();
                $this->updateFileContentIds(Comment::class, (int)$source->id, (int)$target->content->id);
                $result = Comment::findOne($source->id);
            } else {
                $attributes = $source->getAttributes(['message', 'created_at', 'created_by', 'updated_at', 'updated_by']);
                $attributes['url'] = preg_match('/http(.*?)(\s|$)/i', $source->message) ? '1' : null;
                $db->createCommand()->insert(Post::tableName(), $attributes)->execute();
                $newId = (int)$db->getLastInsertID();
                $contentAttributes = array_replace($oldContent->getAttributes(null, ['id']), [
                    'guid' => UUID::v4(), 'object_model' => Post::class, 'object_id' => $newId,
                    'contentcontainer_id' => $oldContent->contentcontainer_id,
                    'stream_channel' => Content::STREAM_CHANNEL_DEFAULT, 'stream_sort_date' => $source->created_at,
                    'visibility' => $oldContent->visibility, 'state' => Content::STATE_PUBLISHED, 'was_published' => 1,
                    'created_at' => $source->created_at, 'created_by' => $source->created_by,
                    'updated_at' => $source->updated_at, 'updated_by' => $source->updated_by,
                    'pinned' => 0, 'archived' => 0, 'hidden' => 0, 'locked_comments' => 0, 'scheduled_at' => null,
                ]);
                $db->createCommand()->insert(Content::tableName(), $contentAttributes)->execute();
                $newContentId = (int)$db->getLastInsertID();
                $this->retargetRelations(Comment::class, (int)$source->id, Post::class, $newId, $newContentId);
                // No model delete event: it would delete the transferred children,
                // files and reactions. All related object keys were migrated above.
                $db->createCommand()->delete(Comment::tableName(), ['id' => $source->id])->execute();
                $result = Post::findOne($newId);
            }
            $db->createCommand()->insert(self::AUDIT_TABLE, [
                'source_model' => $source::class, 'source_id' => $source->id,
                'target_model' => $result::class, 'target_id' => $result->id,
                'source_content_id' => $oldContent->id, 'snapshot' => $snapshot,
                'created_by' => Yii::$app->user->id, 'created_at' => date('Y-m-d H:i:s'),
            ])->execute();
            $transaction->commit();
        } catch (\Throwable $e) {
            if ($transaction->isActive) { $transaction->rollBack(); }
            throw $e;
        }
        if ($oldParent) { Comment::flushCommentCache($oldParent[0], $oldParent[1]); }
        Comment::flushCommentCache($source::class, $source->id);
        Comment::flushCommentCache($result::class, $result->id);
        if ($target) { Comment::flushCommentCache(Post::class, $target->id); }
        // Search work is queued only after a successful transaction.
        $oldContent->refresh();
        (new ContentSearchService($oldContent))->update();
        (new ContentSearchService($result->content))->update();
        ContentSearchService::flushCache();
        return $result;
    }

    private function retargetRelations(string $oldModel, int $oldId, string $newModel, int $newId, int $contentId): void
    {
        $db = Yii::$app->db;
        // HumHub and optional modules use polymorphic object keys. Moving all
        // matching keys also retains native likes, file GUIDs, follows and emoji
        // reactions without assuming a particular optional reaction module.
        foreach ($db->schema->getTableSchemas() as $schema) {
            if (in_array($schema->name, [$db->schema->getRawTableName(Content::tableName()), $db->schema->getRawTableName(self::AUDIT_TABLE)], true)) { continue; }
            foreach ([['object_model', 'object_id'], ['content_model', 'content_id'], ['source_class', 'source_pk']] as [$modelKey, $idKey]) {
                if (!isset($schema->columns[$modelKey], $schema->columns[$idKey])) { continue; }
                $attributes = [$modelKey => $newModel, $idKey => $newId];
                if ($schema->name === $db->tablePrefix . 'file') { $attributes['content_id'] = $contentId; }
                $db->createCommand()->update($schema->fullName, $attributes, [$modelKey => $oldModel, $idKey => $oldId])->execute();
            }
        }
        $this->updateFileContentIds($newModel, $newId, $contentId);
    }

    private function updateFileContentIds(string $model, int $id, int $contentId): void
    {
        $children = Comment::find()->select('id')->where(['object_model' => $model, 'object_id' => $id])->column();
        $ids = $model === Comment::class ? array_merge([$id], $children) : $children;
        if ($children) {
            $ids = array_merge($ids, Comment::find()->select('id')->where(['object_model' => Comment::class, 'object_id' => $children])->column());
        }
        Yii::$app->db->createCommand()->update('{{%file}}', ['content_id' => $contentId], ['object_model' => Comment::class, 'object_id' => $ids])->execute();
    }
}
