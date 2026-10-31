<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
use humhub\modules\moveContent\widgets\ThreadMoveNotice;
?>
<div class="modal-dialog modal-dialog-scrollable">
    <div class="modal-content">
        <div class="modal-header"><h4 class="modal-title">Warum steht diese Nachricht hier?</h4></div>
        <div class="modal-body">
            <p>Dieser Inhalt wurde administrativ umgeordnet. Die Vermerke zeigen, was verschoben wurde, wer es verschoben hat und mit welcher Begründung. Der ursprüngliche Nachrichtentext bleibt unverändert.</p>
            <?= ThreadMoveNotice::widget(['object' => $object, 'details' => true]) ?>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-default" data-bs-dismiss="modal">Schließen</button></div>
    </div>
</div>
