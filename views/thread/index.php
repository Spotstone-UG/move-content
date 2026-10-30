<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
use humhub\helpers\Html;
use humhub\widgets\form\ActiveForm;
use humhub\widgets\bootstrap\Button;
use humhub\modules\post\models\Post;
?>
<div class="panel panel-default">
    <div class="panel-heading"><strong>Beitrag oder Antwort umordnen</strong></div>
    <div class="panel-body">
        <p>Verschiebe diesen Inhalt an die passende Stelle. Text, Autor, ursprüngliches Datum, Anhänge und Reaktionen bleiben erhalten. Es wird keine neue Veröffentlichungsbenachrichtigung verschickt.</p>
        <blockquote>
            <strong><?= Html::encode(($source instanceof Post ? $source->createdBy : $source->user)?->displayName ?? '') ?></strong>
            <div><?= nl2br(Html::encode($source->message)) ?></div>
        </blockquote>
        <?php $form = ActiveForm::begin(); ?>
        <?= $form->field($model, 'operation')->dropDownList($source instanceof Post
            ? ['reply' => 'Diesen Beitrag als Antwort unter einen anderen Beitrag verschieben']
            : ['move' => 'Diese Antwort unter einen anderen Beitrag verschieben', 'promote' => 'Diese Antwort als eigenen Beitrag herauslösen'])->label('Was soll passieren?') ?>
        <?= $form->field($model, 'targetUrl')->textInput(['placeholder' => 'Permalink des Zielbeitrags'])->label('Link zum Zielbeitrag')->hint('Beim Herauslösen ist kein Ziel nötig. Quelle und Ziel müssen im selben Space liegen und dieselbe Sichtbarkeit haben.') ?>
        <?php if ($preview): ?>
            <div class="alert alert-info">
                <?php if ($target): ?>
                    <strong>Neue Stelle: als Antwort unter diesem Beitrag</strong>
                    <div><?= nl2br(Html::encode($target->message)) ?></div>
                <?php else: ?>
                    <strong>Neue Stelle: eigener Beitrag im bisherigen Space</strong>
                <?php endif; ?>
                <p>Vorhandene direkte Antworten ziehen mit. Beim Umwandeln kann sich die zeitliche Reihenfolge entsprechend dem ursprünglichen Datum ändern.</p>
            </div>
            <?= Html::hiddenInput('fingerprint', $fingerprint) ?>
            <?= Html::submitButton('Jetzt umordnen', ['class' => 'btn btn-primary', 'name' => 'confirm', 'value' => '1']) ?>
        <?php endif; ?>
        <?= Html::submitButton($preview ? 'Vorschau aktualisieren' : 'Vorschau anzeigen', ['class' => 'btn btn-default']) ?>
        <?= Button::defaultType('Abbrechen')->link($source->getUrl()) ?>
        <?php ActiveForm::end(); ?>
    </div>
</div>
