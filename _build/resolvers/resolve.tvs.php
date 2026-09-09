<?php
/** @var xPDOObject $object */
/** @var array $options */
if ($options[xPDOTransport::PACKAGE_ACTION] === xPDOTransport::ACTION_UNINSTALL) {
    return true;
}

$tvs = [
    'webpush_enabled' => [
        'caption' => 'Web Push: отправлять',
        'description' => 'Отправить Web Push при первой публикации ресурса/товара',
        'type' => 'listbox',
        'elements' => 'Да==1||Нет==0',
        'default_text' => '1',
        'rank' => 0,
    ],
    'webpush_title' => [
        'caption' => 'Web Push: заголовок',
        'description' => 'Пусто = использовать pagetitle',
        'type' => 'text',
        'elements' => '',
        'default_text' => '',
        'rank' => 1,
    ],
    'webpush_body' => [
        'caption' => 'Web Push: текст',
        'description' => 'Пусто = description / introtext / content',
        'type' => 'textarea',
        'elements' => '',
        'default_text' => '',
        'rank' => 2,
    ],
    'webpush_image' => [
        'caption' => 'Web Push: изображение',
        'description' => 'Пусто = стандартное изображение товара/TV из настройки компонента',
        'type' => 'image',
        'elements' => '',
        'default_text' => '',
        'rank' => 3,
    ],
];

$category = $modx->getObject('modCategory', ['category' => 'WebPush']);
$categoryId = $category ? (int)$category->get('id') : 0;
$templateIds = $modx->getCollection('modTemplate');

foreach ($tvs as $name => $data) {
    $tv = $modx->getObject('modTemplateVar', ['name' => $name]);
    if (!$tv) {
        $tv = $modx->newObject('modTemplateVar');
        $tv->set('name', $name);
    }
    foreach ($data as $key => $value) {
        $tv->set($key, $value);
    }
    if ($categoryId > 0) {
        $tv->set('category', $categoryId);
    }
    if (!$tv->save()) {
        continue;
    }

    foreach ($templateIds as $template) {
        $templateId = (int)$template->get('id');
        $access = $modx->getObject('modTemplateVarTemplate', [
            'tmplvarid' => (int)$tv->get('id'),
            'templateid' => $templateId,
        ]);
        if (!$access) {
            $access = $modx->newObject('modTemplateVarTemplate');
            $access->set('tmplvarid', (int)$tv->get('id'));
            $access->set('templateid', $templateId);
            $access->set('rank', (int)$data['rank']);
            $access->save();
        }
    }
}

return true;
