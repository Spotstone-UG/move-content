<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
namespace humhub\modules\moveContent\controllers;

use humhub\components\Controller;
use humhub\modules\moveContent\services\ThreadMoveService;
use humhub\modules\post\models\Post;
use Yii;
use yii\base\DynamicModel;
use yii\web\BadRequestHttpException;

final class ThreadController extends Controller
{
    public function actionIndex(string $type, int $id)
    {
        $service = new ThreadMoveService();
        $source = $service->load($type, $id);
        $model = new DynamicModel(['operation' => $type === 'post' ? 'reply' : 'move', 'targetUrl' => '']);
        $model->addRule('operation', 'in', ['range' => $type === 'post' ? ['reply'] : ['move', 'promote']]);
        $model->addRule('targetUrl', 'string', ['max' => 2048]);
        $target = null;
        $preview = false;
        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            try {
                if ($model->operation !== 'promote') {
                    $target = $this->resolveTarget($model->targetUrl, $service);
                }
                $service->validateMove($source, $target);
                $preview = true;
                if (Yii::$app->request->post('confirm') === '1') {
                    $result = $service->move($type, $id, $target ? (int)$target->id : null, (string)Yii::$app->request->post('fingerprint'));
                    $this->view->success('Der Inhalt wurde umgeordnet. Autor, Datum, Anhänge und Reaktionen wurden beibehalten.');
                    return $this->redirect($result->getUrl());
                }
            } catch (BadRequestHttpException $e) {
                $model->addError('targetUrl', $e->getMessage());
            }
        }
        return $this->render('index', [
            'source' => $source, 'target' => $target, 'model' => $model,
            'preview' => $preview && !$model->hasErrors(),
            'fingerprint' => $service->fingerprint($source, $target),
        ]);
    }

    private function resolveTarget(string $url, ThreadMoveService $service): Post
    {
        $parts = parse_url(trim($url));
        if (!$parts || (isset($parts['host']) && strcasecmp($parts['host'], (string)parse_url(Yii::$app->request->hostInfo, PHP_URL_HOST)) !== 0)) {
            throw new BadRequestHttpException('Bitte einen Beitragslink aus dieser Community einfügen.');
        }
        parse_str($parts['query'] ?? '', $query);
        $route = trim($query['r'] ?? ($parts['path'] ?? ''), '/');
        // Space-aware pretty URLs prepend /s/<space>/ to the controller route.
        if (preg_match('~(?:^|/)(post/post/view|content/perma(?:/index)?)$~', $route, $match)) { $route = $match[1]; }
        if (in_array($route, ['content/perma', 'content/perma/index'], true) && ctype_digit((string)($query['id'] ?? ''))) {
            $content = \humhub\modules\content\models\Content::findOne((int)$query['id']);
            if (!$content || $content->object_model !== Post::class) { throw new BadRequestHttpException('Das Ziel muss ein normaler Beitrag sein.'); }
            return $service->load('post', (int)$content->object_id);
        }
        if ($route !== 'post/post/view' || !ctype_digit((string)($query['id'] ?? ''))) {
            throw new BadRequestHttpException('Bitte im Menü des Zielbeitrags „Permalink“ wählen und diesen Link einfügen.');
        }
        return $service->load('post', (int)$query['id']);
    }
}
