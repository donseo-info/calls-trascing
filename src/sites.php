<?php
/**
 * src/sites.php — хелперы для работы с сайтами (мультисайт)
 * Требует уже настроенный R::setup().
 */

/** Сайт по публичному ключу (для виджета/assign). Только активные. */
function site_by_key($key)
{
    if (!$key) return null;
    return R::getRow('SELECT * FROM sites WHERE site_key = ? AND is_active = 1 LIMIT 1', [$key]);
}

/** Сайт по id. */
function site_by_id($id)
{
    if (!$id) return null;
    return R::getRow('SELECT * FROM sites WHERE id = ? LIMIT 1', [(int)$id]);
}

/** Все сайты (для админки). */
function sites_all()
{
    return R::getAll('SELECT * FROM sites ORDER BY id ASC');
}
