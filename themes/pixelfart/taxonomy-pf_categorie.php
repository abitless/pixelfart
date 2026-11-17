<?php
/**
 * Categoriepagina (/categorie/arcade/): de homepage met die categorie voorgeselecteerd.
 */
$pf_tag = get_queried_object()->slug ?? '';
require __DIR__ . '/front-page.php';
