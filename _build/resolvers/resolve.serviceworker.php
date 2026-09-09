<?php
if ($object->xpdo) {
    $modx =& $object->xpdo;
    switch ($options[xPDOTransport::PACKAGE_ACTION]) {
        case xPDOTransport::ACTION_INSTALL:
        case xPDOTransport::ACTION_UPGRADE:
            $source = $modx->getOption('core_path') . 'components/webpush/docs/webpush-sw.js';
            $target = $modx->getOption('base_path') . 'webpush-sw.js';
            if (is_file($source)) @copy($source, $target);
            break;
    }
}
return true;
