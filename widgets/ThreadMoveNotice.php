<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
namespace humhub\modules\moveContent\widgets;

use humhub\components\Widget;
use humhub\helpers\Html;
use humhub\modules\comment\models\Comment;
use humhub\modules\moveContent\services\ThreadMoveService;
use humhub\modules\post\models\Post;
use humhub\modules\user\models\User;
use Yii;
use yii\db\Query;
use yii\helpers\Url;

/** Visible moderation history, kept separately from members' original messages. */
final class ThreadMoveNotice extends Widget
{
    public $object;
    public bool $details = false;

    public function run(): string
    {
        if (!($this->object instanceof Post || $this->object instanceof Comment) || !$this->object->content->canView()) { return ''; }
        $schema = Yii::$app->db->schema->getTableSchema(ThreadMoveService::AUDIT_TABLE);
        if (!$schema || !isset($schema->columns['item_model'])) { return ''; }
        $rows = (new Query())->from(ThreadMoveService::AUDIT_TABLE)
            ->where(['item_model' => $this->object::class, 'item_id' => $this->object->id])
            ->orderBy(['id' => SORT_ASC])->all();
        if (!$rows) { return ''; }
        if (!$this->details) {
            $label = 'Verschiebeverlauf öffnen (' . count($rows) . ' Umordnungen)';
            $icon = '<svg width="19" height="19" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 2 L23 22 H1 Z" fill="#f7c948" stroke="#8a6500" stroke-width="1.3"/><path d="M12 8v7" stroke="#473500" stroke-width="2.5" stroke-linecap="round"/><circle cx="12" cy="18.5" r="1.3" fill="#473500"/></svg>';
            return Html::button($icon, [
                'type' => 'button', 'class' => 'move-content-history-trigger',
                'data-action-click' => 'ui.modal.load',
                'data-action-url' => Url::to(['/move-content/thread/history', 'type' => $this->object instanceof Post ? 'post' : 'comment', 'id' => $this->object->id]),
                'aria-label' => $label, 'title' => 'Administrativ verschoben – Verlauf anzeigen',
                'style' => 'border:0;background:transparent;padding:3px 6px;vertical-align:middle;line-height:1;cursor:pointer;',
            ]);
        }
        $items = '';
        foreach ($rows as $row) {
            $actor = User::findOne($row['created_by']);
            $actorLabel = $actor ? Html::a(Html::encode($actor->displayName), $actor->getUrl()) : 'einem inzwischen gelöschten Admin';
            $date = Html::encode(Yii::$app->formatter->asDatetime($row['created_at'], 'php:d.m.Y H:i'));
            $reason = trim((string)$row['reason']);
            $operation = $row['target_model'] === Post::class ? 'Antwort als eigenen Beitrag herausgelöst.'
                : ($row['source_model'] === Post::class ? 'Beitrag als Antwort unter einen anderen Beitrag verschoben.' : 'Antwort einem anderen Beitrag zugeordnet.');
            $text = '<div>' . $operation . '</div>Von Admin ' . $actorLabel . ' am ' . $date . ' verschoben.';
            if ((int)$row['reply_count'] > 0) {
                $text .= ' Einschließlich ' . (int)$row['reply_count'] . ' untergeordneter Antworten.';
            }
            $text .= '<div><strong>Grund:</strong> ' . ($reason !== '' ? nl2br(Html::encode($reason)) : 'Bei dieser früheren Umordnung wurde noch keine Begründung erfasst.') . '</div>';
            $snapshot = json_decode($row['snapshot'], true);
            if (!empty($snapshot['destination']['id']) && ($snapshot['destination']['model'] ?? null) === Post::class) {
                $destination = Post::findOne((int)$snapshot['destination']['id']);
                if ($destination && $destination->content->canView()) {
                    $text .= '<div>' . Html::a('Zielbeitrag öffnen', $destination->getUrl()) . '</div>';
                }
            }
            $original = $snapshot['record'] ?? [];
            $author = isset($original['created_by']) ? User::findOne($original['created_by']) : null;
            if ($author && !empty($original['created_at'])) {
                $text .= '<small>Ursprüngliche Nachricht von ' . Html::a(Html::encode($author->displayName), $author->getUrl())
                    . ' am ' . Html::encode(Yii::$app->formatter->asDatetime($original['created_at'], 'php:d.m.Y H:i')) . '.</small>';
            }
            $items .= Html::tag('li', $text, ['class' => 'mb-2']);
        }
        return Html::tag('aside', '<strong>Verschiebeverlauf</strong><ol class="mb-0">' . $items . '</ol>', [
            'class' => 'move-content-history alert alert-info mt-2 mb-2', 'aria-label' => 'Verschiebeverlauf',
            'style' => 'font-weight:normal;white-space:normal;',
        ]);
    }
}
