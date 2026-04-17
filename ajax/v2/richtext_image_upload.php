<?php

/**
 * ---------------------------------------------------------------------
 * ITSM-NG
 * Copyright (C) 2022 ITSM-NG and contributors.
 *
 * https://www.itsm-ng.org
 *
 * based on GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2003-2014 by the INDEPNET Development Team.
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of ITSM-NG.
 *
 * ITSM-NG is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * ITSM-NG is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with ITSM-NG. If not, see <http://www.gnu.org/licenses/>.
 * ---------------------------------------------------------------------
 */

$AJAX_INCLUDE = 1;

include('../../inc/includes.php');

header('Content-type: application/json');
Html::header_nocache();

Session::checkLoginUser();

if (!isset($_FILES['upload'])) {
    http_response_code(400);
    echo json_encode([
       'error'   => 'missing-upload',
       'message' => __('No file was uploaded')
    ]);
    return;
}

$upload = $_FILES['upload'];
if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode([
       'error'   => 'upload-error',
       'message' => __('File upload failed')
    ]);
    return;
}

$original_name = Toolbox::filename($upload['name'] ?? 'image');
if (Document::isValidDoc($original_name) === '') {
    http_response_code(400);
    echo json_encode([
       'error'   => 'invalid-extension',
       'message' => __('Invalid file extension')
    ]);
    return;
}

if (!is_dir(GLPI_TMP_DIR) && !@mkdir(GLPI_TMP_DIR, 0777, true)) {
    http_response_code(500);
    echo json_encode([
       'error'   => 'missing-temp-dir',
       'message' => __("Temporary directory doesn't exist")
    ]);
    return;
}

$prefix = uniqid() . '_';
$stored_name = $prefix . $original_name;
$stored_path = GLPI_TMP_DIR . '/' . $stored_name;

if (!@move_uploaded_file($upload['tmp_name'], $stored_path)) {
    http_response_code(500);
    echo json_encode([
       'error'   => 'store-failed',
       'message' => __('File upload failed')
    ]);
    return;
}

if (!Document::isImage($stored_path)) {
    @unlink($stored_path);
    http_response_code(400);
    echo json_encode([
       'error'   => 'invalid-image',
       'message' => __('The file is not an image')
    ]);
    return;
}

echo json_encode([
   'filename' => $stored_name,
   'prefix'   => $prefix,
   'tag'      => Rule::getUuid(),
]);
