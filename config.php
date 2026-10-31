<?php

/**
 * Move content
 * @link https://www.cuzy.app
 * @license https://www.cuzy.app/cuzy-license
 * @author [Marc FARRE](https://marc.fun)
 */

use humhub\modules\admin\widgets\SpaceMenu;
use humhub\modules\admin\widgets\UserMenu;
use humhub\modules\moveContent\Events;

return [
    'id' => 'move-content',
    'class' => humhub\modules\moveContent\Module::class,
    'namespace' => 'humhub\modules\moveContent',
    'events' => [
        [
            'class' => \humhub\modules\content\widgets\WallEntryLinks::class,
            'event' => \humhub\modules\content\widgets\WallEntryLinks::EVENT_AFTER_RUN,
            'callback' => [Events::class, 'onEntryLinksAfterRun'],
        ],
        [
            'class' => \humhub\modules\comment\widgets\CommentEntryLinks::class,
            'event' => \humhub\modules\comment\widgets\CommentEntryLinks::EVENT_AFTER_RUN,
            'callback' => [Events::class, 'onEntryLinksAfterRun'],
        ],
        [
            'class' => \humhub\modules\content\widgets\WallEntryControls::class,
            'event' => \humhub\modules\content\widgets\WallEntryControls::EVENT_RUN,
            'callback' => [Events::class, 'onThreadMenuRun'],
        ],
        [
            'class' => \humhub\modules\comment\widgets\CommentControls::class,
            'event' => \humhub\modules\comment\widgets\CommentControls::EVENT_RUN,
            'callback' => [Events::class, 'onThreadMenuRun'],
        ],
        [
            'class' => \humhub\components\Controller::class,
            'event' => \humhub\components\Controller::EVENT_BEFORE_ACTION,
            'callback' => [Events::class, 'onBeforeAction'],
        ],
        [
            'class' => UserMenu::class,
            'event' => UserMenu::EVENT_INIT,
            'callback' => [Events::class, 'onAdminUserMenuInit'],
        ],
        [
            'class' => SpaceMenu::class,
            'event' => SpaceMenu::EVENT_INIT,
            'callback' => [Events::class, 'onAdminSpaceMenuInit'],
        ],
    ],
];
