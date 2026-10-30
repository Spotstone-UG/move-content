<?php

/**
 * Move content
 * @link https://www.cuzy.app
 * @license https://www.cuzy.app/cuzy-license
 * @author [Marc FARRE](https://marc.fun)
 */

namespace humhub\modules\moveContent;

use humhub\helpers\ControllerHelper;
use humhub\modules\admin\permissions\ManageUsers;
use humhub\modules\admin\widgets\UserMenu;
use humhub\modules\ui\menu\MenuLink;
use Throwable;
use Yii;
use yii\base\Event;
use yii\base\InvalidConfigException;

class Events
{
    public static function onEntryLinksAfterRun($event): void
    {
        $notice = \humhub\modules\moveContent\widgets\ThreadMoveNotice::widget(['object' => $event->sender->object]);
        if ($notice === '') { return; }
        $end = $event->sender instanceof \humhub\modules\content\widgets\WallEntryLinks ? strrpos($event->result, '</div>') : false;
        $event->result = $end === false ? $event->result . ' ' . $notice : substr_replace($event->result, ' ' . $notice, $end, 0);
    }

    public static function onThreadMenuRun($event): void
    {
        $menu = $event->sender;
        $record = $menu instanceof \humhub\modules\comment\widgets\CommentControls ? $menu->comment : $menu->object;
        if (!($record instanceof \humhub\modules\post\models\Post || $record instanceof \humhub\modules\comment\models\Comment)) { return; }
        try { (new \humhub\modules\moveContent\services\ThreadMoveService())->authorize($record); }
        catch (\yii\web\HttpException $e) { return; }
        $menu->addEntry(new MenuLink([
            'label' => 'Beitrag / Antwort umordnen', 'icon' => 'arrows', 'sortOrder' => 450,
            'url' => ['/move-content/thread', 'type' => $record instanceof \humhub\modules\post\models\Post ? 'post' : 'comment', 'id' => $record->id],
        ]));
    }

    /** Preserve old post/comment permalinks after a type conversion. */
    public static function onBeforeAction($event): void
    {
        $route = $event->action->uniqueId;
        $id = (int)Yii::$app->request->get('id');
        $model = match ($route) {
            'post/post/view' => \humhub\modules\post\models\Post::class,
            'comment/perma/index' => \humhub\modules\comment\models\Comment::class,
            default => null,
        };
        $table = \humhub\modules\moveContent\services\ThreadMoveService::AUDIT_TABLE;
        if (!$id || (!$model && $route !== 'content/perma/index') || !Yii::$app->db->schema->getTableSchema($table)) { return; }
        if (!$model) {
            $row = (new \yii\db\Query())->from($table)->where(['source_model' => \humhub\modules\post\models\Post::class, 'source_content_id' => $id])->orderBy(['id' => SORT_DESC])->one();
            if (!$row) { return; }
            $model = $row['source_model']; $id = (int)$row['source_id'];
        }
        $changed = false;
        for ($i = 0; $i < 20; $i++) {
            $row = (new \yii\db\Query())->from($table)->where(['source_model' => $model, 'source_id' => $id])->andWhere(['<>', 'target_model', $model])->orderBy(['id' => SORT_DESC])->one();
            if (!$row) { break; }
            $model = $row['target_model']; $id = (int)$row['target_id']; $changed = true;
        }
        if (!$changed || !in_array($model, [\humhub\modules\post\models\Post::class, \humhub\modules\comment\models\Comment::class], true)) { return; }
        $record = $model::findOne($id);
        if (!$record || !$record->content->canView()) { throw new \yii\web\NotFoundHttpException(); }
        Yii::$app->response->redirect($record->getUrl());
        $event->isValid = false;
    }

    /**
     * @param Event $event
     * @throws InvalidConfigException|Throwable
     */
    public static function onAdminUserMenuInit($event)
    {
        /** @var UserMenu $menu */
        $menu = $event->sender;

        if (Yii::$app->user->can(ManageUsers::class)) {
            $menu->addEntry(new MenuLink([
                'label' => Yii::t('MoveContentModule.base', 'Move content'),
                'url' => ['/move-content/user/content'],
                'sortOrder' => 2000,
                'isActive' => ControllerHelper::isActivePath('move-content', 'user', 'content'),
                'isVisible' => true,
            ]));
        }

        if (Yii::$app->user->can(ManageUsers::class)) {
            $menu->addEntry(new MenuLink([
                'label' => Yii::t('MoveContentModule.base', 'Move group users'),
                'url' => ['/move-content/user/group'],
                'sortOrder' => 2000,
                'isActive' => ControllerHelper::isActivePath('move-content', 'user', 'group'),
                'isVisible' => true,
            ]));
        }
    }

    /**
     * @param Event $event
     * @throws InvalidConfigException|Throwable
     */
    public static function onAdminSpaceMenuInit($event)
    {
        /** @var UserMenu $menu */
        $menu = $event->sender;

        if (Yii::$app->user->can(ManageUsers::class)) {
            $menu->addEntry(new MenuLink([
                'label' => Yii::t('MoveContentModule.base', 'Move content'),
                'url' => ['/move-content/space/content'],
                'sortOrder' => 2000,
                'isActive' => ControllerHelper::isActivePath('move-content', 'space', 'content'),
                'isVisible' => true,
            ]));
        }
    }
}
